<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use GuzzleHttp\Cookie\CookieJarInterface;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Auth\BasicAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\RequestInterface;

class SenderTest extends TestCase
{
    public function testItSendsTheFinalOperationThroughTheSelectedHttpConnection(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon', [
            'telescope_tags' => ['connection'],
            'timeout' => 20,
        ]);
        $capturedRequest = null;
        $capturedOptions = null;
        $http->fake(function (HttpRequest $request, array $options) use (&$capturedRequest, &$capturedOptions) {
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
        $this->assertSame(['taylor', 'secret'], $capturedOptions['auth']);
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
        $http->fake(function (HttpRequest $request, array $options) use (&$capturedOptions) {
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

class SenderConnectorStub extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

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

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        ++static::$psrHookCalls;

        return $request->withHeader('X-Psr-Hook', 'handled');
    }
}

class SenderResponseStub extends Response
{
}
