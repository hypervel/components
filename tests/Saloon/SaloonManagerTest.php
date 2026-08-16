<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Contracts\FakeResponse;
use Hypervel\Saloon\Contracts\RequestMiddleware;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Events\SendingSaloonRequest;
use Hypervel\Saloon\Events\SentSaloonRequest;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\RequestInterface;

class SaloonManagerTest extends TestCase
{
    public function testEachRetryUsesFreshOperationStateAndRunsTheCompleteLifecycle(): void
    {
        Sleep::fake();
        $http = $this->http();
        $http->fake([
            '*' => $http->sequence()
                ->pushStatus(500)
                ->push('complete', 200),
        ]);
        $events = new Dispatcher;
        $sendingRequests = [];
        $sentResponses = [];
        $events->listen(SendingSaloonRequest::class, function (SendingSaloonRequest $event) use (&$sendingRequests): void {
            $sendingRequests[] = $event->pendingRequest;
        });
        $events->listen(SentSaloonRequest::class, function (SentSaloonRequest $event) use (&$sentResponses): void {
            $sentResponses[] = $event->response;
        });
        $connector = new ManagerConnectorStub;
        $request = (new ManagerRequestStub)->retry(2, 25);
        $manager = $this->manager($http, $events);
        $manager->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            ++ManagerRequestStub::$globalRequestMiddlewareCalls;
        });

        $response = $manager->send($connector, $request);

        $this->assertSame(200, $response->status());
        $this->assertSame('complete', $response->body());
        $this->assertSame(2, $connector->bootCalls);
        $this->assertSame(2, $request->bootCalls);
        $this->assertSame(2, ManagerRequestStub::$connectorRequestMiddlewareCalls);
        $this->assertSame(2, ManagerRequestStub::$requestMiddlewareCalls);
        $this->assertSame(2, ManagerRequestStub::$globalRequestMiddlewareCalls);
        $this->assertSame(2, ManagerRequestStub::$responseMiddlewareCalls);
        $this->assertCount(2, $sendingRequests);
        $this->assertCount(2, $sentResponses);
        $this->assertNotSame($sendingRequests[0], $sendingRequests[1]);
        Sleep::assertSlept(
            fn (CarbonInterval $duration): bool => (float) $duration->totalMilliseconds === 25.0,
        );
        $http->assertSentCount(2);
    }

    public function testFailedResponseIsReturnedWithoutAnExplicitThrowOrRetryPolicy(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('failed', 500)]);

        $response = $this->manager($http)->send(new ManagerConnectorStub, new ManagerRequestStub);

        $this->assertSame(500, $response->status());
        $this->assertSame('failed', $response->body());
        $http->assertSentCount(1);
    }

    public function testRetryCanReturnTheFinalFailedResponseWithoutThrowing(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('failed', 500)]);
        $request = (new ManagerRequestStub)->retry(2, throw: false);

        $response = $this->manager($http)->send(new ManagerConnectorStub, $request);

        $this->assertSame(500, $response->status());
        $http->assertSentCount(2);
    }

    public function testConnectorBootMayConfigureTheOperationRetryPolicy(): void
    {
        Sleep::fake();
        $http = $this->http();
        $http->fake([
            '*' => $http->sequence()
                ->pushStatus(500)
                ->push('complete', 200),
        ]);

        $response = $this->manager($http)->send(
            new RetryingManagerConnectorStub,
            new ManagerRequestStub,
        );

        $this->assertSame('complete', $response->body());
        $http->assertSentCount(2);
        Sleep::assertSlept(
            fn (CarbonInterval $duration): bool => (float) $duration->totalMilliseconds === 25.0,
        );
    }

    public function testRequestMiddlewareMayOverrideTheOperationRetryPolicy(): void
    {
        $http = $this->http();
        $http->fake([
            '*' => $http->sequence()
                ->pushStatus(500)
                ->push('complete', 200),
        ]);
        $request = (new ManagerRequestStub)->retry(1);
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            $pendingRequest->retry(2);
        });

        $response = $this->manager($http)->send(new ManagerConnectorStub, $request);

        $this->assertSame('complete', $response->body());
        $http->assertSentCount(2);
    }

    public function testResponseMiddlewareMayConfigureTheOperationRetryPolicy(): void
    {
        Sleep::fake();
        $http = $this->http();
        $http->fake([
            '*' => $http->sequence()
                ->pushStatus(500)
                ->push('complete', 200),
        ]);
        $request = new ManagerRequestStub;
        $request->middleware()->onResponse(function (Response $response): void {
            $response->pendingRequest()->retry(2, 25);
        });

        $response = $this->manager($http)->send(new ManagerConnectorStub, $request);

        $this->assertSame('complete', $response->body());
        $http->assertSentCount(2);
        Sleep::assertSlept(
            fn (CarbonInterval $duration): bool => (float) $duration->totalMilliseconds === 25.0,
        );
    }

    public function testExplicitResponseThrowRemainsAuthoritativeWithoutRetries(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('failed', 500)]);
        $request = new AlwaysThrowingManagerRequestStub;

        $this->expectException(RequestException::class);

        $this->manager($http)->send(new ManagerConnectorStub, $request);
    }

    public function testConnectionFailuresAreTranslatedAndRunFatalMiddleware(): void
    {
        $http = $this->http();
        $http->fake([
            '*' => $http->sequence()->pushFailedConnection('Connection failed.'),
        ]);
        $request = new ManagerRequestStub;
        $request->middleware()->onFatalException(function (FatalRequestException $exception): void {
            ++ManagerRequestStub::$fatalMiddlewareCalls;
        });

        try {
            $this->manager($http)->send(new ManagerConnectorStub, $request);
            $this->fail('A fatal request exception was not thrown.');
        } catch (FatalRequestException $exception) {
            $this->assertSame('Connection failed.', $exception->getPrevious()?->getMessage());
            $this->assertSame(1, ManagerRequestStub::$fatalMiddlewareCalls);
        }
    }

    public function testReturningAnotherPendingRequestCannotDivertBodyPreparation(): void
    {
        $http = $this->http();
        $capturedBody = null;
        $http->fake(function (HttpRequest $request) use (&$capturedBody) {
            $capturedBody = $request->body();

            return Factory::response();
        });
        $request = (new BodyManagerRequestStub)->withData(['name' => 'Taylor']);
        $request->middleware()->onRequest(function (): PendingRequest {
            return new PendingRequest(
                new ManagerConnectorStub,
                new ManagerRequestStub,
                m::mock(CacheFactory::class),
                m::mock(RateLimiter::class),
            );
        });

        $this->manager($http)->send(new ManagerConnectorStub, $request);

        $this->assertSame('{"name":"Taylor"}', $capturedBody);
    }

    public function testReturningAnotherPendingRequestCannotDivertALaterFake(): void
    {
        $http = $this->http();
        $http->preventStrayRequests();
        $request = new ManagerRequestStub;
        $request->middleware()
            ->onRequest(function (): PendingRequest {
                return new PendingRequest(
                    new ManagerConnectorStub,
                    new ManagerRequestStub,
                    m::mock(CacheFactory::class),
                    m::mock(RateLimiter::class),
                );
            })
            ->onRequest(static fn (): FakeResponse => new MockResponse('middleware'));

        $response = $this->manager($http)->send(new ManagerConnectorStub, $request);

        $this->assertSame('middleware', $response->body());
        $http->assertNothingSent();
    }

    public function testClassBasedRequestMiddlewareCanShortCircuitWithAFake(): void
    {
        $http = $this->http();
        $http->preventStrayRequests();
        $request = new ManagerRequestStub;
        $request->middleware()->onRequest(new ManagerFakeRequestMiddleware);

        $response = $this->manager($http)->send(new ManagerConnectorStub, $request);

        $this->assertSame('class middleware', $response->body());
        $http->assertNothingSent();
    }

    public function testConnectorAndRequestCanBothEnableAutomaticThrowing(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('failed', 500)]);

        $this->expectException(RequestException::class);

        $this->manager($http)->send(
            new AlwaysThrowingManagerConnectorStub,
            new AlwaysThrowingManagerRequestStub,
        );
    }

    public function testPluginAuthenticationIsAppliedOnce(): void
    {
        $http = $this->http();
        $authorization = null;
        $http->fake(function (HttpRequest $request) use (&$authorization) {
            $authorization = $request->header('Authorization');

            return Factory::response();
        });

        $this->manager($http)->send(new ManagerConnectorStub, new PluginAuthenticatedManagerRequestStub);

        $this->assertSame(['Bearer secret'], $authorization);
    }

    public function testSendingAndSentEventsArePairedForFakeResponses(): void
    {
        $http = $this->http();
        $http->preventStrayRequests();
        $events = new Dispatcher;
        $dispatched = [];
        $events->listen(SendingSaloonRequest::class, function () use (&$dispatched): void {
            $dispatched[] = 'sending';
        });
        $events->listen(SentSaloonRequest::class, function () use (&$dispatched): void {
            $dispatched[] = 'sent';
        });
        $request = new ManagerRequestStub;
        $request->middleware()->onRequest(static fn (): FakeResponse => new MockResponse('fake'));

        $response = $this->manager($http, $events)->send(new ManagerConnectorStub, $request);

        $this->assertSame('fake', $response->body());
        $this->assertSame(['sending', 'sent'], $dispatched);
        $http->assertNothingSent();
    }

    public function testSendingListenerMutationsAreFinalAndPsrInspectionHasNoSideEffects(): void
    {
        $http = $this->http();
        $capturedRequest = null;
        $http->fake(function (HttpRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return Factory::response();
        });
        $events = new Dispatcher;
        $logicalRequest = null;
        $observerCalls = 0;
        $events->listen(SendingSaloonRequest::class, function (SendingSaloonRequest $event) use (&$logicalRequest, &$observerCalls): void {
            $event->pendingRequest->observePsrRequest(function () use (&$observerCalls): void {
                ++$observerCalls;
            });
            $logicalRequest = $event->pendingRequest->toPsrRequest();
            $event->pendingRequest
                ->withHeader('X-Event', 'applied')
                ->withQueryParameters(['event' => 'applied'])
                ->withData(['event' => 'applied']);
        });

        $response = $this->manager($http, $events)->send(
            new PsrHookManagerConnectorStub,
            (new BodyManagerRequestStub)->withData(['initial' => true]),
        );

        $this->assertInstanceOf(RequestInterface::class, $logicalRequest);
        $this->assertFalse($logicalRequest->hasHeader('X-Event'));
        $this->assertFalse($logicalRequest->hasHeader('X-Psr-Hook'));
        $this->assertSame('https://api.example.com/users?event=applied', $capturedRequest->url());
        $this->assertSame(['applied'], $capturedRequest->header('X-Event'));
        $this->assertSame('handled', $capturedRequest->header('X-Psr-Hook')[0]);
        $this->assertSame('{"initial":true,"event":"applied"}', $capturedRequest->body());
        $this->assertSame(1, PsrHookManagerConnectorStub::$psrHookCalls);
        $this->assertSame(1, $observerCalls);
        $this->assertSame($response->toPsrRequest(), $response->pendingRequest()->toPsrRequest());
    }

    protected function setUp(): void
    {
        parent::setUp();

        ManagerRequestStub::$connectorRequestMiddlewareCalls = 0;
        ManagerRequestStub::$requestMiddlewareCalls = 0;
        ManagerRequestStub::$globalRequestMiddlewareCalls = 0;
        ManagerRequestStub::$responseMiddlewareCalls = 0;
        ManagerRequestStub::$fatalMiddlewareCalls = 0;
        PsrHookManagerConnectorStub::$psrHookCalls = 0;
    }

    /**
     * Create an isolated HTTP factory with the Saloon connection.
     */
    protected function http(): Factory
    {
        $http = new Factory;
        $http->registerConnection('saloon');

        return $http;
    }

    /**
     * Create the manager with isolated framework services.
     */
    protected function manager(Factory $http, ?Dispatcher $events = null): SaloonManager
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');

        return new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            $events ?? new Dispatcher,
        );
    }
}

class ManagerConnectorStub extends Connector
{
    public int $bootCalls = 0;

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        ++$this->bootCalls;
        $pendingRequest->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            ++ManagerRequestStub::$connectorRequestMiddlewareCalls;
        });
    }
}

class RetryingManagerConnectorStub extends ManagerConnectorStub
{
    public function boot(PendingRequest $pendingRequest): void
    {
        parent::boot($pendingRequest);

        $pendingRequest->retry(2, 25);
    }
}

class AlwaysThrowingManagerConnectorStub extends ManagerConnectorStub
{
    use AlwaysThrowOnErrors;
}

class PsrHookManagerConnectorStub extends ManagerConnectorStub
{
    public static int $psrHookCalls = 0;

    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        ++static::$psrHookCalls;

        return $request->withHeader('X-Psr-Hook', 'handled');
    }
}

class ManagerRequestStub extends Request
{
    public static int $connectorRequestMiddlewareCalls = 0;

    public static int $requestMiddlewareCalls = 0;

    public static int $globalRequestMiddlewareCalls = 0;

    public static int $responseMiddlewareCalls = 0;

    public static int $fatalMiddlewareCalls = 0;

    public int $bootCalls = 0;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        ++$this->bootCalls;
        $pendingRequest->middleware()
            ->onRequest(function (PendingRequest $pendingRequest): void {
                ++static::$requestMiddlewareCalls;
            })
            ->onResponse(function (Response $response): void {
                ++static::$responseMiddlewareCalls;
            });
    }
}

class AlwaysThrowingManagerRequestStub extends ManagerRequestStub
{
    use AlwaysThrowOnErrors;
}

class BodyManagerRequestStub extends ManagerRequestStub
{
    use HasJsonBody;

    protected Method $method = Method::POST;
}

trait AppliesManagerAuthentication
{
    public function bootAppliesManagerAuthentication(PendingRequest $pendingRequest): void
    {
        $pendingRequest->authenticate(new TokenAuthenticator('secret'));
    }
}

class PluginAuthenticatedManagerRequestStub extends ManagerRequestStub
{
    use AppliesManagerAuthentication;
}

class ManagerFakeRequestMiddleware implements RequestMiddleware
{
    public function __invoke(PendingRequest $pendingRequest): ?FakeResponse
    {
        return new MockResponse('class middleware');
    }
}
