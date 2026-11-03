<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Auth\BasicAuthenticator;
use Hypervel\Saloon\Http\Auth\HeaderAuthenticator;
use Hypervel\Saloon\Http\Auth\MultiAuthenticator;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

// Hypervel HTTP is Saloon's only transport. Connectors choose a named HTTP connection instead of a sender.
class SenderTest extends TestCase
{
    public function testTheDefaultConnectionOnAllConnectorsIsTheSaloonConnection(): void
    {
        $connector = new TestConnector;
        $sender = $this->app->make(Sender::class);

        $pendingRequest = $connector->createPendingRequest(new UserRequest);

        $this->assertSame('saloon', $sender->resolveTransport($pendingRequest)['connection']);

        // Test the same instance is re-used

        $this->assertSame($sender, $this->app->make(Sender::class));
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
        $pendingRequest = (new PendingRequest(new SenderConnectorStub, $request))
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
        $pendingRequest = (new PendingRequest(new SenderConnectorStub, $request))
            ->finalizeUri()
            ->prepareBody();
        $sender = new Sender($http, $this->config());

        $sender->send($pendingRequest, $sender->resolveTransport($pendingRequest));

        $this->assertFalse($capturedOptions['telescope_enabled']);
        $this->assertSame([TelescopeTag::Saloon], $capturedOptions['telescope_tags']);
    }

    #[DataProvider('authenticationRedirects')]
    public function testAuthenticationHeadersStayOnTheirOrigin(
        string $endpoint,
        array $locations,
        ?string $rewriteHost,
        array $authenticatedRequests,
        string $authentication = 'built-in',
    ): void {
        $http = new class extends Factory {
            public array $requests = [];

            public array $locations = [];

            /**
             * Capture physical requests while exercising the real redirect middleware.
             */
            protected function createConnectionHandler(array $options): callable
            {
                return function (RequestInterface $request, array $options): PromiseInterface {
                    $this->requests[] = $request;
                    $location = array_shift($this->locations);

                    return Create::promiseFor($location === null
                        ? new PsrResponse(200)
                        : new PsrResponse(302, ['Location' => $location]));
                };
            }
        };
        $http->locations = $locations;
        $http->registerConnection('saloon');

        $request = new class($endpoint, $rewriteHost) extends Request {
            protected Method $method = Method::GET;

            public array $hookHeaders = [];

            /**
             * Create a request with an optional PSR host override.
             */
            public function __construct(private string $endpoint, private ?string $rewriteHost)
            {
            }

            /**
             * Resolve the request endpoint.
             */
            public function resolveEndpoint(): string
            {
                return $this->endpoint;
            }

            /**
             * Allow an absolute endpoint to replace the connector's base URL.
             */
            public function allowsBaseUrlOverride(): ?bool
            {
                return true;
            }

            /**
             * Inspect credentials before applying the application's host override.
             */
            public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
            {
                $this->hookHeaders[] = $request->getHeaderLine('X-Api-Key');

                return $this->rewriteHost === null
                    ? $request
                    : $request->withUri($request->getUri()->withHost($this->rewriteHost));
            }
        };
        $authenticator = $authentication === 'custom'
            ? new class implements Authenticator {
                /**
                 * Delegate one credential and add another through the ordinary header API.
                 */
                public function set(PendingRequest $pendingRequest): void
                {
                    $pendingRequest->authenticate(new HeaderAuthenticator('account-secret', 'X-Account-Secret'));
                    $pendingRequest->withHeaders(['X-Api-Key' => 'secret']);
                }
            }
        : new MultiAuthenticator(
            new HeaderAuthenticator('secret', 'X-Api-Key'),
            new HeaderAuthenticator('account-secret', 'X-Account-Secret'),
        );
        $connector = $authentication === 'existing'
            ? new class extends SenderConnectorStub {
                /**
                 * Supply values that authentication replaces without changing them.
                 */
                protected function defaultHeaders(): array
                {
                    return ['x-api-key' => 'secret', 'x-account-secret' => 'account-secret'];
                }
            }
        : new SenderConnectorStub;
        $request->authenticate($authenticator)
            ->withHeader('X-Trace', 'trace');
        $pendingRequest = (new PendingRequest($connector, $request))
            ->applyAuthentication()
            ->withHeader('X-After-Authentication', 'ordinary')
            ->finalizeUri()
            ->prepareBody();
        $sender = new Sender($http, $this->config());

        $response = $sender->send($pendingRequest, $sender->resolveTransport($pendingRequest));

        $this->assertCount(count($authenticatedRequests), $http->requests);
        foreach ($authenticatedRequests as $index => $authenticated) {
            $physicalRequest = $http->requests[$index];
            $this->assertSame($authenticated ? 'secret' : '', $physicalRequest->getHeaderLine('X-Api-Key'));
            $this->assertSame($authenticated ? 'account-secret' : '', $physicalRequest->getHeaderLine('X-Account-Secret'));
            $this->assertSame('trace', $physicalRequest->getHeaderLine('X-Trace'));
            $this->assertSame('ordinary', $physicalRequest->getHeaderLine('X-After-Authentication'));
            $this->assertSame($authenticated ? 'secret' : '', $request->hookHeaders[$index]);
        }
        $this->assertSame(end($http->requests), $response->toPsrRequest());
        $this->assertSame($rewriteHost ?? $pendingRequest->uri()->getHost(), $http->requests[0]->getUri()->getHost());
    }

    /**
     * Provide redirect and endpoint origins.
     */
    public static function authenticationRedirects(): array
    {
        return [
            'another host' => ['/users', ['https://other.example.com/download'], null, [true, false]],
            'custom authenticator' => ['/users', ['https://other.example.com/download'], null, [true, false], 'custom'],
            'unchanged credential values' => ['/users', ['https://other.example.com/download'], null, [true, false], 'existing'],
            'another scheme' => ['/users', ['http://api.example.com/download'], null, [true, false]],
            'another port' => ['/users', ['https://api.example.com:8443/download'], null, [true, false]],
            'same origin' => ['/users', ['/download'], null, [true, true]],
            'absolute endpoint' => ['https://uploads.example.com/files', ['/download'], null, [true, true]],
            'PSR host rewrite' => ['/users', [], 'proxy.example.com', [true]],
            'return to original origin' => ['/users', ['https://other.example.com/download', 'https://api.example.com/result'], null, [true, false, false]],
        ];
    }

    public function testARetrySendsCredentialsAgainAfterACrossOriginRedirectChain(): void
    {
        $http = new class extends Factory {
            public array $requests = [];

            /**
             * Fail a redirected attempt, then accept the next attempt at the original origin.
             */
            protected function createConnectionHandler(array $options): callable
            {
                return function (RequestInterface $request, array $options): PromiseInterface {
                    $this->requests[] = $request;

                    return Create::promiseFor(match (count($this->requests)) {
                        1 => new PsrResponse(302, ['Location' => 'https://other.example.com/download']),
                        2 => new PsrResponse(302, ['Location' => 'https://api.example.com/result']),
                        3 => new PsrResponse(503),
                        default => new PsrResponse(200),
                    });
                };
            }
        };
        $http->registerConnection('saloon');
        $this->app->instance(Factory::class, $http);
        $request = (new SenderRequestStub)
            ->authenticate(new HeaderAuthenticator('secret', 'X-Api-Key'))
            ->retry(2, 0);

        $response = (new SenderConnectorStub)->send($request);

        $this->assertSame(200, $response->status());
        $this->assertSame(['secret', '', '', 'secret'], array_map(
            static fn (RequestInterface $request): string => $request->getHeaderLine('X-Api-Key'),
            $http->requests,
        ));
        $this->assertSame('/users', $http->requests[3]->getUri()->getPath());
        $this->assertSame('secret', $response->toPsrRequest()->getHeaderLine('X-Api-Key'));
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
