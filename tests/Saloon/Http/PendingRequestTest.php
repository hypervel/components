<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\MissingAuthenticatorException;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\HeaderAuthenticator;
use Hypervel\Saloon\Http\Auth\QueryAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Saloon\Traits\Auth\RequiresAuth;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Saloon\Traits\Body\HasStringBody;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\StreamInterface;

class PendingRequestTest extends TestCase
{
    public function testConstructionOnlySnapshotsOperationState(): void
    {
        $connector = new PendingRequestConnectorStub;
        $request = (new PendingRequestRequestStub)
            ->withHeader('X-Request', 'request')
            ->withQueryParameters(['page' => 2])
            ->withOptions(['allow_redirects' => ['max' => 3]])
            ->delay(20)
            ->withData(['name' => 'Taylor']);
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            ++PendingRequestRequestStub::$middlewareCalls;
        });

        $pendingRequest = $this->pendingRequest($connector, $request);

        $this->assertSame(0, PendingRequestConnectorStub::$bootCalls);
        $this->assertSame(0, PendingRequestRequestStub::$bootCalls);
        $this->assertSame(0, PendingRequestRequestStub::$middlewareCalls);
        $this->assertSame([
            'X-Connector' => 'connector',
            'X-Request' => 'request',
        ], $pendingRequest->headers());
        $this->assertSame(['version' => 1, 'page' => 2], $pendingRequest->queryParameters());
        $this->assertSame(['strict' => true, 'max' => 3], $pendingRequest->options()['allow_redirects']);
        $this->assertSame(20, $pendingRequest->delayMilliseconds());
        $this->assertSame(['connector' => true, 'name' => 'Taylor'], $pendingRequest->body());
    }

    public function testUriAndBodyAreFinalizedAfterRequestMiddleware(): void
    {
        $request = (new PendingRequestRequestStub)->withData(['initial' => true]);
        $request->middleware()->onRequest(static function (PendingRequest $pendingRequest): void {
            $pendingRequest
                ->withQueryParameters(['page' => 3])
                ->withData(['middleware' => true]);
        });
        $pendingRequest = $this->pendingRequest(new PendingRequestConnectorStub, $request);

        $pendingRequest->executeRequestPipeline()->finalizeUri()->prepareBody();

        $this->assertSame('https://api.example.com/v1/users?version=1&page=3', (string) $pendingRequest->uri());
        $this->assertSame(
            '{"connector":true,"initial":true,"middleware":true}',
            (string) $pendingRequest->preparedBody(),
        );
    }

    public function testRawQuerySnapshotsDefaultsAndReplacesBothUrlQueries(): void
    {
        $request = new PendingRawQueryRequestStub;
        $pendingRequest = $this->pendingRequest(new PendingRawQueryConnectorStub, $request);
        $request->withQueryString('changed=after-snapshot');

        $pendingRequest->finalizeUri();

        $this->assertSame('tag=a&tag=b&cursor=a%2fb', $pendingRequest->queryString());
        $this->assertSame('https://api.example.com/users?tag=a&tag=b&cursor=a%2fb', (string) $pendingRequest->uri());
        $this->assertSame([], $pendingRequest->queryParameters());

        $pendingRequest->withQueryString('')->finalizeUri();

        $this->assertSame('https://api.example.com/users', (string) $pendingRequest->uri());
        $this->assertSame('changed=after-snapshot', $request->queryString());
    }

    public function testRawQueryReplacementRetainsArrayAndAuthenticationOverlays(): void
    {
        $request = (new PendingRawQueryRequestStub)
            ->withQueryString('tag=a&tag=b&token=old&token=older')
            ->withQueryParameters(['limit' => 10]);
        $pendingRequest = $this->pendingRequest(new PendingRequestConnectorStub, $request);

        $pendingRequest->authenticate(new QueryAuthenticator('token', 'secret'))->finalizeUri();

        $this->assertSame('tag=a&tag=b&version=1&limit=10&token=secret', $pendingRequest->uri()->getQuery());

        $pendingRequest->withQueryString('cursor=next&token=stale')->finalizeUri();
        $pendingRequest->withQueryParameters(['tag' => 'replacement'])->finalizeUri();

        $this->assertSame('cursor=next&version=1&limit=10&token=secret&tag=replacement', $pendingRequest->uri()->getQuery());
        $this->assertSame(['version' => 1, 'limit' => 10, 'token' => 'secret', 'tag' => 'replacement'], $pendingRequest->queryParameters());
    }

    public function testNullRawQueryKeepsTheOriginalUrlQuery(): void
    {
        $pendingRequest = $this->pendingRequest(new PendingRawQueryConnectorStub, new PendingRequestRequestStub);

        $this->assertNull($pendingRequest->queryString());
        $this->assertSame('base=old', $pendingRequest->uri()->getQuery());
    }

    public function testConnectorAndRequestBodyTypesMustMatch(): void
    {
        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessage('Connector and request body types must be the same.');

        $this->pendingRequest(new PendingRequestConnectorStub, new PendingRequestStringBodyStub);
    }

    public function testLogicalPsrRequestReusesAnAlreadyPreparedBody(): void
    {
        CountingBodyRepository::$streamCalls = 0;
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorWithoutBodyStub,
            new PendingRequestCountingBodyStub,
        );

        $pendingRequest->finalizeUri()->prepareBody();
        $request = $pendingRequest->createPsrRequest();

        $this->assertSame('prepared', (string) $request->getBody());
        $this->assertSame(1, CountingBodyRepository::$streamCalls);
    }

    public function testRequiresAuthRetainsItsProtectedMessageHook(): void
    {
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorWithoutBodyStub,
            new CustomRequiresAuthRequestStub,
        );

        $this->expectException(MissingAuthenticatorException::class);
        $this->expectExceptionMessage('Custom authentication is required.');

        $pendingRequest->bootPlugins();
    }

    public function testAuthenticatorsReplaceLogicalHeadersRegardlessOfCase(): void
    {
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorWithoutBodyStub,
            new PendingRequestRequestStub,
        );

        $pendingRequest
            ->withHeader('authorization', 'Bearer old')
            ->authenticate(new TokenAuthenticator('first'))
            ->authenticate(new AccessTokenAuthenticator('second'));

        $this->assertSame(['Authorization' => 'Bearer second'], $pendingRequest->headers());

        $pendingRequest
            ->withHeader('x-api-key', 'old')
            ->authenticate(new HeaderAuthenticator('new', 'X-Api-Key'));

        $this->assertSame([
            'Authorization' => 'Bearer second',
            'X-Api-Key' => 'new',
        ], $pendingRequest->headers());
    }

    protected function setUp(): void
    {
        parent::setUp();

        PendingRequestConnectorStub::$bootCalls = 0;
        PendingRequestRequestStub::$bootCalls = 0;
        PendingRequestRequestStub::$middlewareCalls = 0;
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}

class PendingRequestConnectorStub extends Connector
{
    use HasJsonBody;

    public static int $bootCalls = 0;

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com/v1';
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        ++static::$bootCalls;
    }

    protected function defaultHeaders(): array
    {
        return ['X-Connector' => 'connector'];
    }

    protected function defaultQuery(): array
    {
        return ['version' => 1];
    }

    protected function defaultOptions(): array
    {
        return ['allow_redirects' => ['strict' => true]];
    }

    protected function defaultBody(): array
    {
        return ['connector' => true];
    }
}

class PendingRawQueryConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com?base=old';
    }
}

class PendingRawQueryRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users?endpoint=old';
    }

    /**
     * Resolve the default raw query string override.
     */
    protected function defaultQueryString(): ?string
    {
        return 'tag=a&tag=b&cursor=a%2fb';
    }
}

class PendingRequestRequestStub extends Request
{
    use HasJsonBody;

    public static int $bootCalls = 0;

    public static int $middlewareCalls = 0;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        ++static::$bootCalls;
    }
}

class PendingRequestStringBodyStub extends Request
{
    use HasStringBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/users';
    }
}

class PendingRequestConnectorWithoutBodyStub extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class PendingRequestCountingBodyStub extends Request
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultBodyRepository(): ?BodyRepository
    {
        return new CountingBodyRepository('prepared');
    }
}

class CountingBodyRepository extends StringBodyRepository
{
    public static int $streamCalls = 0;

    public function toStream(): StreamInterface
    {
        ++static::$streamCalls;

        return parent::toStream();
    }
}

class CustomRequiresAuthRequestStub extends Request
{
    use RequiresAuth;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function getRequiresAuthMessage(PendingRequest $pendingRequest): string
    {
        return 'Custom authentication is required.';
    }
}
