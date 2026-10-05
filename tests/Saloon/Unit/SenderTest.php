<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Auth\BasicAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Mockery as m;
use Psr\Http\Message\RequestInterface;

// Hypervel HTTP is Saloon's only transport. Connectors choose a named HTTP connection instead of a sender.
class SenderTest extends TestCase
{
    public function testTheDefaultConnectionOnAllConnectorsIsTheSaloonConnection(): void
    {
        $connector = new TestConnector;
        $sender = Saloon::sender();

        $pendingRequest = $connector->createPendingRequest(new UserRequest);

        $this->assertSame('saloon', $sender->resolveTransport($pendingRequest)['connection']);

        // Test the same instance is re-used

        $this->assertSame($sender, Saloon::sender());
    }

    // Upstream also overrides the sender through a connector property; connectors select their connection only
    // through resolveHttpConnection().
    public function testYouCanOverwriteTheConnectionOnAConnectorUsingTheResolveHttpConnectionMethod(): void
    {
        Http::registerConnection('secondary', ['timeout' => 12]);
        $timeout = null;
        Http::fake(function (HttpRequest $request, array $options) use (&$timeout): PromiseInterface {
            $timeout = $options['timeout'];

            return Http::response('Default', 200, ['X-Fake' => 'true']);
        });

        $response = (new SecondaryConnectionConnectorStub)->send(new UserRequest);

        $this->assertSame(12, $timeout);
        $this->assertSame('true', $response->header('X-Fake'));
        $this->assertSame('Default', $response->body());
    }

    // REMOVED: the case that sets a class which does not implement the sender contract. There is no sender contract.

    // laravel-plugin's Feature/TelescopeMiddlewareTest covers the plugin's own Telescope recording middleware. Saloon
    // requests reach Telescope's HTTP client watcher instead, so these cases check the tags and opt-out passed to it,
    // and Telescope's ClientRequestWatcherTest covers formatting and masking.
    // REMOVED: laravel-plugin's Feature/PulseMiddlewareTest and Feature/NightwatchMiddlewareTest. Hypervel has no Pulse
    // or Nightwatch package.

    public function testItSendsTheFinalOperationThroughTheSelectedHttpConnection(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon', [
            'telescope_tags' => ['connection'],
            'timeout' => 20,
        ]);
        $capturedRequest = null;
        $capturedOptions = null;
        $http->fake(function (HttpRequest $request, array $options) use (&$capturedRequest, &$capturedOptions): PromiseInterface {
            $capturedRequest = $request;
            $capturedOptions = $options;

            return Factory::response(['name' => 'Taylor'], 201);
        });

        $request = (new SenderRequestStub)
            ->withHeader('X-Request', 'request')
            ->withQueryParameters(['page' => 2])
            ->withCookies(['session' => 'secret'], '.example.com')
            ->withTelescopeTags(['operation'])
            ->authenticate(new BasicAuthenticator('taylor', 'secret'))
            ->withData(['name' => 'Taylor']);
        $pendingRequest = $this->pendingRequest(new SenderConnectorStub, $request)
            ->applyAuthentication()
            ->executeRequestPipeline()
            ->finalizeUri()
            ->prepareBody();
        $sender = new Sender($http, $this->config());

        $response = $sender->send($pendingRequest, $sender->resolveTransport($pendingRequest));

        $this->assertInstanceOf(SenderResponseStub::class, $response);
        $this->assertSame(201, $response->status());
        $this->assertSame('https://api.example.com/users?version=1&page=2', $capturedRequest->url());
        $this->assertSame('request', $capturedRequest->header('X-Request')[0]);
        $this->assertSame('handled', $capturedRequest->header('X-Psr-Hook')[0]);
        $this->assertSame('{"name":"Taylor"}', $capturedRequest->body());
        $this->assertSame('Basic ' . base64_encode('taylor:secret'), $capturedRequest->header('Authorization')[0]);
        $this->assertSame(0, $capturedOptions['delay']);
        $this->assertFalse($capturedOptions['http_errors']);
        $this->assertSame(
            [TelescopeTag::Saloon, 'connection', 'operation'],
            $capturedOptions['telescope_tags'],
        );
        $this->assertInstanceOf(CookieJarInterface::class, $capturedOptions['cookies']);
        $this->assertSame($pendingRequest->toPsrRequest(), $response->toPsrRequest());
        $this->assertSame(1, SenderRequestStub::$psrHookCalls);
    }

    public function testOperationCanDisableTelescopeWithoutLosingTheCanonicalTag(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $capturedOptions = null;
        $http->fake(function (HttpRequest $request, array $options) use (&$capturedOptions): PromiseInterface {
            $capturedOptions = $options;

            return Factory::response();
        });
        $request = (new SenderRequestStub)->withoutTelescope();
        $pendingRequest = $this->pendingRequest(new SenderConnectorStub, $request)
            ->finalizeUri()
            ->prepareBody();
        $sender = new Sender($http, $this->config());

        $sender->send($pendingRequest, $sender->resolveTransport($pendingRequest));

        $this->assertFalse($capturedOptions['telescope_enabled']);
        $this->assertSame([TelescopeTag::Saloon], $capturedOptions['telescope_tags']);
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        SenderRequestStub::$psrHookCalls = 0;
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

    /**
     * Create package configuration for the sender.
     */
    protected function config(): ConfigRepository
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');

        return $config;
    }
}

class SecondaryConnectionConnectorStub extends TestConnector
{
    /**
     * Resolve the HTTP connection used by this connector.
     */
    public function resolveHttpConnection(): ?string
    {
        return 'secondary';
    }
}

class SenderConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /**
     * Resolve the default query parameters.
     */
    protected function defaultQuery(): array
    {
        return ['version' => 1];
    }
}

class SenderRequestStub extends Request
{
    use HasJsonBody;

    public static int $psrHookCalls = 0;

    protected Method $method = Method::POST;

    protected ?string $response = SenderResponseStub::class;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Modify the final PSR request.
     */
    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        ++static::$psrHookCalls;

        return $request->withHeader('X-Psr-Hook', 'handled');
    }
}

class SenderResponseStub extends Response
{
}
