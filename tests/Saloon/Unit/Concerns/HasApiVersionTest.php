<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Enums\VersionMode;
use Hypervel\Saloon\Http\Auth\CookieAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Plugins\HasApiVersion;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class HasApiVersionTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testHeaderModeAddsTheVersionToTheOutgoingRequestHeaders(): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com', VersionMode::Header, 'anthropic-version'),
            $this->makeVersionedRequest(),
        );

        $this->assertSame('anthropic-version', $response->toPsrRequest()->getHeaderLine('api-version'));
    }

    public function testQueryParamModeAppendsTheVersionToTheQueryString(): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com', VersionMode::QueryParam, '2026-10'),
            $this->makeVersionedRequest(),
        );

        $this->assertSame('api-version=2026-10', $response->toPsrRequest()->getUri()->getQuery());
    }

    public function testUrlModeReplacesTheVersionPlaceholderInThePath(): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://generativelanguage.googleapis.com/{version}', VersionMode::Url, 'v1beta'),
            $this->makeVersionedRequest(),
        );

        $this->assertSame('https://generativelanguage.googleapis.com/v1beta', (string) $response->pendingRequest()->uri());
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta', (string) $response->toPsrRequest()->getUri());
    }

    public function testUrlModeReplacesTheVersionPlaceholderInTheHost(): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://{version}.api.provider.com', VersionMode::Url, 'v2'),
            $this->makeVersionedRequest(),
        );

        $this->assertSame('https://v2.api.provider.com', (string) $response->pendingRequest()->uri());
        $this->assertSame('https://v2.api.provider.com', (string) $response->toPsrRequest()->getUri());
    }

    #[DataProvider('commonVersionFormats')]
    public function testUrlModeAcceptsCommonVersionFormats(string $version): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com/{version}', VersionMode::Url, $version),
            $this->makeVersionedRequest(),
        );

        $this->assertSame('https://api.provider.com/' . $version, (string) $response->pendingRequest()->uri());
    }

    /**
     * Get common API version formats.
     *
     * @return array<int, array{string}>
     */
    public static function commonVersionFormats(): array
    {
        return [['v1'], ['v1beta'], ['v2.1'], ['2026-10-01'], ['v1_alpha']];
    }

    #[DataProvider('unsafeVersions')]
    public function testUrlModeRejectsVersionsThatCouldAlterTheSchemeHostOrPath(string $baseUrl, string $version): void
    {
        $mockClient = new MockClient([MockResponse::make()]);

        $connector = $this->makeVersionedConnector($baseUrl, VersionMode::Url, $version);

        try {
            $connector->send($this->makeVersionedRequest(), $mockClient);

            $this->fail('The unsafe version was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                sprintf('The API version "%s" is not safe to use in a URL. Versions may only contain letters, numbers, dashes, underscores and single dots.', $version),
                $exception->getMessage(),
            );
        }

        $mockClient->assertNothingSent();
    }

    /**
     * Get versions that could change the request target.
     *
     * @return array<string, array{string, string}>
     */
    public static function unsafeVersions(): array
    {
        return [
            'https scheme in path' => ['https://api.provider.com/{version}', 'https://evil.com'],
            'https scheme in subdomain' => ['https://{version}.api.provider.com', 'https://evil.com'],
            'file scheme' => ['{version}/api', 'file:///etc/passwd'],
            'bare scheme separator' => ['https://api.provider.com/{version}', '://evil.com'],
            'protocol relative host' => ['https://api.provider.com/{version}', '//evil.com'],
            'userinfo host takeover' => ['https://{version}.api.provider.com', 'evil.com@'],
            'path traversal' => ['https://api.provider.com/{version}', '..'],
            'nested path traversal' => ['https://api.provider.com/{version}', 'v1/../../admin'],
            'single dot' => ['https://api.provider.com/{version}', '.'],
            'query string' => ['https://api.provider.com/{version}', 'v1?admin=true'],
            'fragment' => ['https://api.provider.com/{version}', 'v1#admin'],
            'port' => ['https://{version}.api.provider.com', 'evil.com:8080'],
            'whitespace' => ['https://api.provider.com/{version}', 'v1 beta'],
            'crlf' => ['https://api.provider.com/{version}', "v1\r\nHost: evil.com"],
            'percent encoding' => ['https://api.provider.com/{version}', '%2e%2e'],
        ];
    }

    // Upstream changes the connector's version with setApiVersion(). Connectors are read-only, so a connector
    // supplies a different version by overriding getApiVersion().
    public function testTheConnectorCanOverrideTheApiVersion(): void
    {
        $connector = new class('https://api.provider.com', VersionMode::Header, 'anthropic-version') extends VersionedConnector {
            /**
             * Get the API version.
             */
            public function getApiVersion(): ?string
            {
                return 'override-version';
            }
        };

        $response = $this->sendVersionedRequest($connector, $this->makeVersionedRequest());

        $this->assertSame('override-version', $response->toPsrRequest()->getHeaderLine('api-version'));
    }

    public function testTheRequestCanOverrideTheApiVersionAtRuntime(): void
    {
        $request = $this->makeVersionedRequest('override-version', VersionMode::Header);

        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com'),
            $request,
        );

        $this->assertSame('override-version', $response->toPsrRequest()->getHeaderLine('api-version'));
    }

    #[DataProvider('versionModes')]
    public function testARequestVersionReplacesTheConnectorVersion(VersionMode $versionMode): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com/{version}', $versionMode, 'connector-version'),
            $this->makeVersionedRequest('request-version', $versionMode),
        );
        $psrRequest = $response->toPsrRequest();

        match ($versionMode) {
            VersionMode::Header => $this->assertSame(['request-version'], $psrRequest->getHeader('api-version')),
            VersionMode::QueryParam => $this->assertSame('api-version=request-version', $psrRequest->getUri()->getQuery()),
            VersionMode::Url => $this->assertSame('https://api.provider.com/request-version', (string) $psrRequest->getUri()),
        };
    }

    public function testAVersionOfZeroIsApplied(): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com', VersionMode::Header, '0'),
            $this->makeVersionedRequest(),
        );

        $this->assertSame('0', $response->toPsrRequest()->getHeaderLine('api-version'));
    }

    public function testRemovingAQueryParameterKeepsAUrlVersion(): void
    {
        $request = $this->makeVersionedRequest()->withQueryParameters(['page' => 2]);
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            $pendingRequest->withoutQueryParameters('page');
        });

        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com/{version}', VersionMode::Url, 'v1'),
            $request,
        );

        $this->assertSame('https://api.provider.com/v1', (string) $response->toPsrRequest()->getUri());
    }

    #[DataProvider('cookieAuthenticatedVersions')]
    public function testCookieAuthenticationUsesTheVersionedHost(string $connectorClass, ?string $requestVersion, string $url): void
    {
        $sent = null;
        Http::fake(function (HttpRequest $request) use (&$sent): PromiseInterface {
            $sent = $request;

            return Http::response();
        });

        $connector = new $connectorClass('https://{version}.api.provider.com', VersionMode::Url, 'v1');
        $connector->send($this->makeVersionedRequest($requestVersion, VersionMode::Url));

        $this->assertSame($url, $sent->url());
        $this->assertSame(['session=secret'], $sent->header('Cookie'));
    }

    /**
     * Get cookie-authenticated connectors and the request versions sent through them.
     *
     * @return array<string, array{class-string<VersionedConnector>, null|string, string}>
     */
    public static function cookieAuthenticatedVersions(): array
    {
        return [
            'configured authenticator' => [CookieVersionedConnector::class, null, 'https://v1.api.provider.com'],
            'configured authenticator and request version' => [CookieVersionedConnector::class, 'v2', 'https://v2.api.provider.com'],
            'plugin authenticator and request version' => [PluginCookieVersionedConnector::class, 'v2', 'https://v2.api.provider.com'],
        ];
    }

    #[DataProvider('versionModes')]
    public function testWithoutAVersionItDoesNotAddAHeaderQueryParameterOrReplaceTheUrl(VersionMode $versionMode): void
    {
        $response = $this->sendVersionedRequest(
            $this->makeVersionedConnector('https://api.provider.com/{version}', $versionMode),
            $this->makeVersionedRequest(null, $versionMode),
        );

        $this->assertSame('', $response->toPsrRequest()->getHeaderLine('api-version'));
        $this->assertStringNotContainsString('api-version', $response->toPsrRequest()->getUri()->getQuery());
        // URI parsing encodes the braces of the placeholder that was left in the path.
        $this->assertSame('https://api.provider.com/%7Bversion%7D', (string) $response->pendingRequest()->uri());
    }

    /**
     * Get the API version modes.
     *
     * @return array<string, array{VersionMode}>
     */
    public static function versionModes(): array
    {
        return [
            'header' => [VersionMode::Header],
            'query param' => [VersionMode::QueryParam],
            'url' => [VersionMode::Url],
        ];
    }

    /**
     * Create a connector that applies an API version.
     */
    protected function makeVersionedConnector(string $baseUrl, VersionMode $versionMode = VersionMode::Header, ?string $apiVersion = null): Connector
    {
        return new VersionedConnector($baseUrl, $versionMode, $apiVersion);
    }

    /**
     * Create a request that applies an API version.
     */
    protected function makeVersionedRequest(?string $apiVersion = null, VersionMode $versionMode = VersionMode::Header): Request
    {
        return new class($versionMode, $apiVersion) extends Request {
            use HasApiVersion;

            protected Method $method = Method::GET;

            /**
             * Create a new request instance.
             */
            public function __construct(
                VersionMode $versionMode,
                ?string $apiVersion,
            ) {
                $this->versionMode = $versionMode;
                $this->apiVersion = $apiVersion;
            }

            /**
             * Resolve the request endpoint.
             */
            public function resolveEndpoint(): string
            {
                return '';
            }
        };
    }

    /**
     * Send a versioned request through a mock client.
     */
    protected function sendVersionedRequest(Connector $connector, Request $request): Response
    {
        return $connector->send($request, new MockClient([
            MockResponse::make(),
        ]));
    }
}

class VersionedConnector extends Connector
{
    use HasApiVersion;

    /**
     * Create a new connector instance.
     */
    public function __construct(
        private readonly string $baseUrl,
        VersionMode $versionMode,
        ?string $apiVersion,
    ) {
        $this->versionMode = $versionMode;
        $this->apiVersion = $apiVersion;
    }

    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

class CookieVersionedConnector extends VersionedConnector
{
    /**
     * Resolve the default authenticator.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return new CookieAuthenticator('session', 'secret');
    }
}

trait AuthenticatesWithSessionCookie
{
    /**
     * Authenticate the pending request with a session cookie.
     */
    public function bootAuthenticatesWithSessionCookie(PendingRequest $pendingRequest): void
    {
        $pendingRequest->authenticate(new CookieAuthenticator('session', 'secret'));
    }
}

class PluginCookieVersionedConnector extends VersionedConnector
{
    use AuthenticatesWithSessionCookie;
}
