<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\OAuth;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Client\Destinations\DestinationResolutionException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Request;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\AuthServerDiscovery;
use Hypervel\Mcp\Client\OAuth\DynamicClientRegistration;
use Hypervel\Mcp\Client\OAuth\OAuthClient;
use Hypervel\Mcp\Client\OAuth\OAuthConfig;
use Hypervel\Mcp\Client\OAuth\OAuthDestinationPolicy;
use Hypervel\Mcp\Client\OAuth\TokenSet;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Session;
use Hypervel\Tests\Mcp\Fixtures\Client\OAuth\FakeDestinationPolicy;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;
use Throwable;

class OAuthClientTest extends TestCase
{
    /**
     * Resolve fake endpoints without external DNS.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy);
    }

    /**
     * Fake the resource and authorization metadata responses.
     */
    private function fakeDiscovery(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://mcp.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'registration_endpoint' => 'https://auth.test/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);
    }

    public function testBuildsAnAuthorizationRedirectWithPkceAndStashesSessionState(): void
    {
        $this->fakeDiscovery();

        $response = Client::web('https://mcp.test/mcp')
            ->withOAuth(
                clientId: 'client-123',
                scope: 'mcp:use',
                redirectUri: 'https://app.test/callback',
            )
            ->oAuthClient()
            ->redirect('/dashboard');

        $target = $response->getTargetUrl();
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://auth.test/authorize?', $target);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame('https://app.test/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('mcp:use', $query['scope']);
        $this->assertSame('https://mcp.test/mcp', $query['resource']);
        $this->assertArrayHasKey('code_challenge', $query);
        $this->assertArrayHasKey('state', $query);

        $stored = Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'));

        $this->assertSame($query['state'], $stored['state']);
        $this->assertSame('client-123', $stored['client_id']);
        $this->assertSame('/dashboard', $stored['return_to']);
        $this->assertIsString($stored['verifier']);
    }

    public function testDiscoversAnAuthorizationServerWhoseIssuerHasATrailingSlash(): void
    {
        // Real-world shape (e.g. Fireflies' MCP server): the issuer in the
        // protected resource metadata ends with "/", which must not be treated
        // as a meaningful path segment when building the metadata candidate
        // URLs - otherwise none of them resolve.
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test/'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test/',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        $this->assertStringStartsWith('https://auth.test/authorize?', $target);
    }

    public function testMergesQueryParamsOntoAnAuthorizationEndpointThatAlreadyHasAQueryString(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize?audience=api',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        $this->assertSame(1, substr_count($target, '?'));

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('api', $query['audience']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-123', $query['client_id']);
    }

    public function testDynamicallyRegistersAClientWhenNoClientIdIsConfigured(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/register' => Http::response(['client_id' => 'dcr-999', 'client_secret' => 'shh']),
        ]);

        Client::web('https://mcp.test/mcp')
            ->withOAuth(redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();

        $stored = Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'));

        $this->assertSame('dcr-999', $stored['client_id']);
        $this->assertSame('shh', $stored['client_secret']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/register'
            && ($request['redirect_uris'] ?? null) === ['https://app.test/callback']);
    }

    public function testExchangesAnAuthorizationCodeForATokenSet(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'mcp:use',
            ]),
        ]);

        $key = 'mcp.oauth.' . sha1('https://mcp.test/mcp');

        Session::put($key, [
            'state' => 'the-state',
            'verifier' => 'the-verifier',
            'client_id' => 'client-123',
            'client_secret' => null,
            'token_endpoint' => 'https://auth.test/token',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
        ]);

        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'the-state',
        ]));

        $token = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->exchangeCallback();

        $this->assertInstanceOf(TokenSet::class, $token);
        $this->assertSame('access-token', $token->accessToken);
        $this->assertSame('refresh-token', $token->refreshToken);
        $this->assertGreaterThan(time(), $token->expiresAt);

        $this->assertFalse(Session::has($key));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/token'
            && ($request['grant_type'] ?? null) === 'authorization_code'
            && ($request['code'] ?? null) === 'auth-code'
            && ($request['code_verifier'] ?? null) === 'the-verifier'
            && ($request['resource'] ?? null) === 'https://mcp.test/mcp');
    }

    public function testRejectsAMismatchedStateParameter(): void
    {
        $this->fakeDiscovery();

        $key = 'mcp.oauth.' . sha1('https://mcp.test/mcp');

        Session::put($key, [
            'state' => 'expected-state',
            'verifier' => 'verifier',
            'client_id' => 'client-123',
            'client_secret' => null,
            'token_endpoint' => 'https://auth.test/token',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
        ]);

        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'tampered-state',
        ]));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('state parameter did not match');

        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->exchangeCallback();
    }

    public function testRunsTheClientCredentialsGrant(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response([
                'access_token' => 'machine-token',
                'expires_in' => 7200,
            ]),
        ]);

        $token = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'svc', clientSecret: 'secret', scope: 'mcp:use')
            ->oAuthClient()
            ->clientCredentials();

        $this->assertSame('machine-token', $token->accessToken);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/token'
            && ($request['grant_type'] ?? null) === 'client_credentials'
            && ($request['client_id'] ?? null) === 'svc'
            && ($request['client_secret'] ?? null) === 'secret');
    }

    public function testThrowsWhenTheAuthorizationServerRedirectsBackWithAnError(): void
    {
        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'error' => 'access_denied',
            'error_description' => 'The user denied the request',
        ]));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('The authorization server returned an error [access_denied]: The user denied the request');

        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->exchangeCallback();
    }

    public function testRequiresAnAuthorizationCodeWhenExchangingACallback(): void
    {
        RequestContext::set(Request::create('https://app.test/callback', 'GET'));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not include an authorization code');

        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'svc', clientSecret: 'secret', scope: 'mcp:use')
            ->oAuthClient()
            ->exchangeCallback();
    }

    public function testRefreshesATokenUsingTheRefreshGrant(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response([
                'access_token' => 'fresh-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $token = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', scope: 'mcp:use')
            ->oAuthClient()
            ->refreshCredentials('old-refresh');

        $this->assertSame('fresh-token', $token->accessToken);
        $this->assertSame('new-refresh', $token->refreshToken);

        Http::assertSent(fn ($request): bool => ($request['grant_type'] ?? null) === 'refresh_token'
            && ($request['refresh_token'] ?? null) === 'old-refresh'
            && ($request['client_id'] ?? null) === 'client-123');
    }

    public function testFallsBackToTheResourceOriginWhenProtectedResourceMetadataIsUnavailable(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response('', 404),
            'https://mcp.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://mcp.test',
                'authorization_endpoint' => 'https://mcp.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://mcp.test/token',
            ]),
        ]);

        $response = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();

        $this->assertStringStartsWith('https://mcp.test/authorize?', $response->getTargetUrl());
    }

    public function testThrowsWhenTheTokenRequestFails(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('failed with status [400]');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', scope: 'mcp:use')
            ->oAuthClient()
            ->refreshCredentials('bad-token');
    }

    public function testThrowsWhenTheAuthorizationServerMetadataCannotBeDiscovered(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response('', 500),
            'https://auth.test/.well-known/openid-configuration' => Http::response('', 500),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unable to discover authorization server metadata');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', clientSecret: 'secret')
            ->oAuthClient()
            ->clientCredentials();
    }

    public function testThrowsWhenDynamicRegistrationIsNeededButUnsupported(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not support dynamic client registration');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testThrowsWhenOauthIsUsedWithoutConfiguration(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('No OAuth configuration');
        Client::web('https://mcp.test/mcp')->oAuthClient();
    }

    public function testRequiresARedirectUriBeforeRedirecting(): void
    {
        $this->fakeDiscovery();

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('redirect URI is required');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->redirect();
    }

    public function testUsesTheServerAdvertisedResourceMetadataUrlWhenProvided(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/custom-resource' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient('https://mcp.test/.well-known/custom-resource')
            ->redirect()
            ->getTargetUrl();

        $this->assertStringStartsWith('https://auth.test/authorize?', $target);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://mcp.test/.well-known/custom-resource');
    }

    public function testThrowsInsteadOfFallingBackToTheOriginWhenAnExplicitResourceMetadataUrlFails(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/custom-resource' => Http::response('', 404),
            'https://mcp.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://mcp.test',
                'authorization_endpoint' => 'https://mcp.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://mcp.test/token',
            ]),
        ]);

        try {
            Client::web('https://mcp.test/mcp')
                ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
                ->oAuthClient('https://mcp.test/.well-known/custom-resource')
                ->redirect();
            $this->fail('Expected the explicit metadata request to fail.');
        } catch (OAuthException $exception) {
            $this->assertStringContainsString('Protected resource metadata request to [https://mcp.test/.well-known/custom-resource] failed with status [404]', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://mcp.test/.well-known/oauth-authorization-server');
    }

    public function testThrowsWhenAnExplicitResourceMetadataUrlReturnsInvalidJson(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/custom-resource' => Http::response('not json', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not return a valid JSON object');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient('https://mcp.test/.well-known/custom-resource')
            ->redirect();
    }

    #[DataProvider('internalMetadataUrls')]
    public function testRejectsProtectedResourceMetadataUrlsOnInternalHosts(string $metadataUrl): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('private or internal host');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient($metadataUrl)
            ->redirect();
    }

    /**
     * Provide local endpoints advertised by a remote resource.
     */
    public static function internalMetadataUrls(): array
    {
        return [
            ['https://127.0.0.1/.well-known/oauth-protected-resource'],
            ['https://LOCALHOST./.well-known/oauth-protected-resource'],
            ['https://[::1]/.well-known/oauth-protected-resource'],
        ];
    }

    public function testRejectsProtectedResourceMetadataUrlsServedOverPlainHttp(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('must be served over HTTPS');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient('http://mcp.test/.well-known/oauth-protected-resource')
            ->redirect();
    }

    public function testRejectsAnAuthorizationServerWhoseIssuerDoesNotMatch(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://evil.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not match the expected issuer');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testRejectsProtectedResourceMetadataWhoseResourceDoesNotMatch(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://other.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not match the expected resource');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testRejectsAuthorizationServerMetadataThatOmitsTheIssuer(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not match the expected issuer');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testRejectsAnAuthorizationServerThatDoesNotAdvertiseTheS256PkceMethod(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'code_challenge_methods_supported' => ['plain'],
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not support the required S256');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testRejectsAnAuthorizationServerServedOverPlainHttp(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['http://auth.test'],
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('must be served over HTTPS');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testFallsBackToOpenidConnectDiscoveryWhenOauthMetadataIsAbsent(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response('', 404),
            'https://auth.test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        $this->assertStringStartsWith('https://auth.test/authorize?', $target);
    }

    public function testValidatesAMatchingIssParameterOnTheAuthorizationCallback(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'access-token']),
        ]);

        $key = 'mcp.oauth.' . sha1('https://mcp.test/mcp');

        Session::put($key, [
            'state' => 'the-state',
            'verifier' => 'the-verifier',
            'client_id' => 'client-123',
            'client_secret' => null,
            'token_endpoint' => 'https://auth.test/token',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
            'issuer' => 'https://auth.test',
            'iss_supported' => true,
        ]);

        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'the-state',
            'iss' => 'https://auth.test',
        ]));

        $token = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->exchangeCallback();

        $this->assertSame('access-token', $token->accessToken);
    }

    public function testRejectsAMismatchedIssParameterOnTheAuthorizationCallback(): void
    {
        $this->fakeDiscovery();

        $key = 'mcp.oauth.' . sha1('https://mcp.test/mcp');

        Session::put($key, [
            'state' => 'the-state',
            'verifier' => 'the-verifier',
            'client_id' => 'client-123',
            'client_secret' => null,
            'token_endpoint' => 'https://auth.test/token',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
            'issuer' => 'https://auth.test',
            'iss_supported' => true,
        ]);

        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'the-state',
            'iss' => 'https://evil.test',
        ]));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('iss) parameter did not match');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->exchangeCallback();
    }

    public function testRejectsAMissingIssParameterWhenTheServerAdvertisesSupport(): void
    {
        $this->fakeDiscovery();

        $key = 'mcp.oauth.' . sha1('https://mcp.test/mcp');

        Session::put($key, [
            'state' => 'the-state',
            'verifier' => 'the-verifier',
            'client_id' => 'client-123',
            'client_secret' => null,
            'token_endpoint' => 'https://auth.test/token',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
            'issuer' => 'https://auth.test',
            'iss_supported' => true,
        ]);

        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'the-state',
        ]));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing the required iss parameter');
        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->exchangeCallback();
    }

    public function testRecordsTheIssuerInTheSessionForCallbackValidation(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
                'authorization_response_iss_parameter_supported' => true,
            ]),
        ]);

        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();

        $stored = Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'));

        $this->assertSame('https://auth.test', $stored['issuer']);
        $this->assertTrue($stored['iss_supported']);
    }

    public function testDefaultsTheScopeToMcpUse(): void
    {
        $this->fakeDiscovery();

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('mcp:use', $query['scope']);
    }

    public function testDoesNotRequestEverySupportedScopeFromProtectedResourceMetadata(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
                'scopes_supported' => ['mcp:read', 'mcp:write'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('mcp:use', $query['scope']);
    }

    public function testPrefersTheChallengeScopeOverTheConfiguredScope(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
                'scopes_supported' => ['mcp:read'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', scope: 'mcp:use', redirectUri: 'https://app.test/callback')
            ->oAuthClient(challengeScope: 'files:read files:write')
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('files:read files:write', $query['scope']);
    }

    #[DataProvider('localCallbackUris')]
    public function testSendsANativeApplicationTypeWhenRegisteringALocalhostClient(string $redirectUri): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/register' => Http::response(['client_id' => 'dcr-1']),
        ]);

        Client::web('https://mcp.test/mcp')
            ->withOAuth(redirectUri: $redirectUri)
            ->oAuthClient()
            ->redirect();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/register'
            && ($request['application_type'] ?? null) === 'native');
    }

    /**
     * Provide loopback callbacks, including normalized DNS and IPv6 spellings.
     */
    public static function localCallbackUris(): array
    {
        return [
            ['http://localhost:3000/callback'],
            ['http://LOCALHOST.:3000/callback'],
            ['http://127.0.0.1:3000/callback'],
            ['http://[::1]:3000/callback'],
        ];
    }

    public function testSendsAWebApplicationTypeWhenRegisteringARemoteClient(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/register' => Http::response(['client_id' => 'dcr-1']),
        ]);

        Client::web('https://mcp.test/mcp')
            ->withOAuth(redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/register'
            && ($request['application_type'] ?? null) === 'web');
    }

    public function testStripsFragmentsButPreservesTrailingSlashesInTheResourceIdentifier(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp/' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp/#fragment')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('https://mcp.test/mcp/', $query['resource']);
    }

    public function testStripsTheFragmentButPreservesTheQueryStringInTheResourceIdentifier(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp?tenant=acme#section')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('https://mcp.test/mcp?tenant=acme', $query['resource']);
    }

    public function testLeavesAResourceIdentifierWithoutAFragmentUnchanged(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $target = Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('https://mcp.test/mcp', $query['resource']);
    }

    public function testRejectsProtectedResourceMetadataWhenATrailingSlashDiffers(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp/' => Http::response([
                'resource' => 'https://mcp.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not match the expected resource [https://mcp.test/mcp/]');
        Client::web('https://mcp.test/mcp/')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback')
            ->oAuthClient()
            ->redirect();
    }

    public function testIncludesTheResourceParameterOnARefreshTokenRequest(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'fresh-token']),
        ]);

        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'client-123')
            ->oAuthClient()
            ->refreshCredentials('old-refresh');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/token'
            && ($request['grant_type'] ?? null) === 'refresh_token'
            && ($request['resource'] ?? null) === 'https://mcp.test/mcp');
    }

    public function testDefaultsTheRedirectUriToThePackageCallbackRouteForARegisteredClient(): void
    {
        $this->fakeDiscovery();

        Route::get('mcp/oauth/github/callback', fn (): string => '')
            ->name('mcp.oauth.github.callback');

        $target = Client::web('https://mcp.test/mcp')
            ->setName('github')
            ->withOAuth(clientId: 'client-123', scope: 'mcp:use')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame(url('mcp/oauth/github/callback'), $query['redirect_uri']);
    }

    public function testLetsAnExplicitRedirectUriOverrideTheDefaultCallbackRoute(): void
    {
        $this->fakeDiscovery();

        Route::get('mcp/oauth/github/callback', fn (): string => '')
            ->name('mcp.oauth.github.callback');

        $target = Client::web('https://mcp.test/mcp')
            ->setName('github')
            ->withOAuth(clientId: 'client-123', scope: 'mcp:use', redirectUri: 'https://app.test/custom')
            ->oAuthClient()
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('https://app.test/custom', $query['redirect_uri']);
    }

    public function testSurfacesTheDynamicallyRegisteredCredentialsOnTheTokenSet(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'access-token', 'refresh_token' => 'refresh-token']),
        ]);

        $key = 'mcp.oauth.' . sha1('https://mcp.test/mcp');

        Session::put($key, [
            'state' => 'the-state',
            'verifier' => 'the-verifier',
            'client_id' => 'dcr-999',
            'client_secret' => 'dcr-secret',
            'token_endpoint' => 'https://auth.test/token',
            'token_auth_method' => 'client_secret_post',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
        ]);

        RequestContext::set(Request::create('https://app.test/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'the-state',
        ]));

        $token = Client::web('https://mcp.test/mcp')
            ->withOAuth()
            ->oAuthClient()
            ->exchangeCallback();

        $this->assertSame('dcr-999', $token->clientId);
        $this->assertSame('dcr-secret', $token->clientSecret);
    }

    public function testRefreshesUsingExplicitlyPassedClientCredentials(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'fresh-token']),
        ]);

        $token = Client::web('https://mcp.test/mcp')
            ->withOAuth(scope: 'mcp:use')
            ->oAuthClient()
            ->refreshCredentials('old-refresh', clientId: 'dcr-999', clientSecret: 'dcr-secret');

        $this->assertSame('dcr-999', $token->clientId);

        Http::assertSent(fn ($request): bool => ($request['grant_type'] ?? null) === 'refresh_token'
            && ($request['client_id'] ?? null) === 'dcr-999'
            && ($request['client_secret'] ?? null) === 'dcr-secret');
    }

    public function testUsesClientSecretBasicWhenTheServerOnlySupportsIt(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            ]),
            'https://auth.test/token' => Http::response(['access_token' => 'machine-token']),
        ]);

        Client::web('https://mcp.test/mcp')
            ->withOAuth(clientId: 'svc', clientSecret: 'secret', scope: 'mcp:use')
            ->oAuthClient()
            ->clientCredentials();

        Http::assertSent(function ($request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $request->url() === 'https://auth.test/token'
                && $authorization === 'Basic ' . base64_encode('svc:secret')
                && ! array_key_exists('client_secret', $request->data());
        });
    }

    /**
     * Fake discovery of support for client ID metadata documents.
     */
    private function fakeCimdDiscovery(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://mcp.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'registration_endpoint' => 'https://auth.test/register',
                'client_id_metadata_document_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
            ]),
            'https://auth.test/register' => Http::response(['client_id' => 'dcr-999', 'client_secret' => 'shh']),
        ]);
    }

    /**
     * Create a client with a metadata document URL.
     */
    private function cimdClient(string $clientIdMetadataUrl, ?string $clientId = null, ?string $clientSecret = null): OAuthClient
    {
        return new OAuthClient(
            new OAuthConfig(
                clientId: $clientId,
                clientSecret: $clientSecret,
                redirectUri: 'https://app.example.com/callback',
            ),
            'https://mcp.test/mcp',
            clientIdMetadataUrl: $clientIdMetadataUrl,
        );
    }

    public function testUsesTheClientIdMetadataDocumentInsteadOfDynamicRegistration(): void
    {
        $this->fakeCimdDiscovery();

        $target = $this->cimdClient('https://app.example.com/mcp/oauth/github/client-metadata.json')
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('https://app.example.com/mcp/oauth/github/client-metadata.json', $query['client_id']);

        $stored = Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'));

        $this->assertSame('https://app.example.com/mcp/oauth/github/client-metadata.json', $stored['client_id']);
        $this->assertNull($stored['client_secret']);
        $this->assertSame('none', $stored['token_auth_method']);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://auth.test/register');
    }

    public function testPrefersAPreRegisteredClientIdOverTheClientIdMetadataDocument(): void
    {
        $this->fakeCimdDiscovery();

        $target = $this->cimdClient('https://app.example.com/mcp/oauth/github/client-metadata.json', clientId: 'client-123')
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('client-123', $query['client_id']);
    }

    public function testFallsBackToDynamicRegistrationWhenTheAuthorizationServerDoesNotSupportMetadataDocuments(): void
    {
        $this->fakeDiscovery();

        Http::fake([
            'https://auth.test/register' => Http::response(['client_id' => 'dcr-999']),
        ]);

        $this->cimdClient('https://app.example.com/mcp/oauth/github/client-metadata.json')->redirect();

        $this->assertSame('dcr-999', Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'))['client_id']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/register');
    }

    #[DataProvider('invalidClientIdMetadataUrls')]
    public function testFallsBackToDynamicRegistrationWhenTheMetadataDocumentUrlIsNotAValidClientId(string $url): void
    {
        $this->fakeCimdDiscovery();

        $this->cimdClient($url)->redirect();

        $this->assertSame('dcr-999', Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'))['client_id']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/register');
    }

    /**
     * Provide metadata document URLs ineligible as client identifiers.
     */
    public static function invalidClientIdMetadataUrls(): array
    {
        return [
            'http scheme' => ['http://app.example.com/client-metadata.json'],
            'no path component' => ['https://app.example.com'],
            'empty path component' => ['https://app.example.com/'],
            'fragment component' => ['https://app.example.com/client-metadata.json#part'],
            'query component' => ['https://app.example.com/client-metadata.json?tenant=1'],
            'unparsable url' => ['https:///client-metadata.json'],
            'localhost host' => ['https://localhost/client-metadata.json'],
            'herd or valet test domain' => ['https://app.test/client-metadata.json'],
            'local domain' => ['https://app.local/client-metadata.json'],
            'loopback address' => ['https://127.0.0.1/client-metadata.json'],
            'private network address' => ['https://192.168.1.40/client-metadata.json'],
            'link local address' => ['https://169.254.10.1/client-metadata.json'],
            'ipv6 loopback' => ['https://[::1]/client-metadata.json'],
        ];
    }

    public function testAcceptsAClientIdMetadataDocumentUrlWithAPort(): void
    {
        $this->fakeCimdDiscovery();

        $target = $this->cimdClient('https://app.example.com:8443/client-metadata.json')
            ->redirect()
            ->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('https://app.example.com:8443/client-metadata.json', $query['client_id']);
    }

    public function testNeverSendsAClientSecretAlongsideAClientIdMetadataDocument(): void
    {
        $this->fakeCimdDiscovery();

        $this->cimdClient('https://app.example.com/client-metadata.json', clientSecret: 'leaked-secret')->redirect();

        $stored = Session::get('mcp.oauth.' . sha1('https://mcp.test/mcp'));

        $this->assertNull($stored['client_secret']);
        $this->assertSame('none', $stored['token_auth_method']);
    }

    public function testRefusesToProceedWhenTheAuthorizationServerDoesNotAdvertisePkceSupport(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'registration_endpoint' => 'https://auth.test/register',
            ]),
        ]);

        try {
            Client::web('https://mcp.test/mcp')
                ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.example.com/callback')
                ->oAuthClient()
                ->redirect();
            $this->fail('Expected missing PKCE support to prevent authorization.');
        } catch (OAuthException $exception) {
            $this->assertStringContainsString('code_challenge_methods_supported', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://auth.test/register');
    }

    #[DataProvider('outboundOAuthEndpoints')]
    public function testRejectsPrivateDnsTargetsBeforeSendingAtEveryOAuthBoundary(string $stage): void
    {
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy([
            'blocked.test' => ['10.0.0.5'],
        ]));

        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => [$stage === 'metadata' ? 'https://blocked.test' : 'https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => $stage === 'token' ? 'https://blocked.test/token' : 'https://auth.test/token',
                'registration_endpoint' => 'https://blocked.test/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);

        $client = Client::web('https://mcp.test/mcp')->withOAuth(
            clientId: $stage === 'registration' ? null : 'client-123',
            redirectUri: 'https://app.test/callback',
        )->oAuthClient($stage === 'resource' ? 'https://blocked.test/metadata' : null);

        try {
            $stage === 'token' ? $client->clientCredentials() : $client->redirect();
            $this->fail('Expected private DNS resolution to prevent the request.');
        } catch (OAuthException $exception) {
            $this->assertInstanceOf(DisallowedDestinationException::class, $exception->getPrevious());
            $this->assertStringContainsString('10.0.0.5', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'blocked.test'));
    }

    /**
     * Provide each outbound OAuth request boundary.
     */
    public static function outboundOAuthEndpoints(): array
    {
        return [['resource'], ['metadata'], ['token'], ['registration']];
    }

    public function testPrivateDnsAllowancePinsTheNormalizedEndpointAndPreservesProxyRouting(): void
    {
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy(
            ['auth.test' => ['10.0.0.5']],
            allowedNetworks: ['10.0.0.5'],
        ));
        $options = null;
        $url = null;
        Http::fake(function ($request, array $requestOptions) use (&$options, &$url) {
            $options = $requestOptions;
            $url = $request->url();

            return Http::response(['client_id' => 'local-client']);
        });

        $registration = $this->withEnvironmentValues(
            ['HTTPS_PROXY' => 'http://proxy.test:8080', 'NO_PROXY' => ''],
            fn () => (new DynamicClientRegistration)->register(
                'https://AUTH.test./register',
                'https://app.test/callback',
            ),
        );

        $this->assertSame('local-client', $registration->clientId);
        $this->assertSame('https://auth.test/register', $url);
        $this->assertSame(['auth.test:443:10.0.0.5'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('http://proxy.test:8080', $options['proxy']['https']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function testLocalResourceDiscoveryAllowsLoopbackEndpoints(): void
    {
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy([
            'localhost' => ['127.0.0.1', '::1'],
        ]));
        Http::fake([
            'http://localhost:3000/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['http://localhost:3000'],
            ]),
            'http://localhost:3000/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'http://localhost:3000',
                'authorization_endpoint' => 'http://localhost:3000/authorize',
                'token_endpoint' => 'http://localhost:3000/token',
            ]),
        ]);

        $discovered = (new AuthServerDiscovery)->discover('http://localhost:3000/mcp');

        $this->assertSame('http://localhost:3000/token', $discovered->server->tokenEndpoint);
        Http::assertSentCount(2);
    }

    #[DataProvider('resolutionFailures')]
    public function testResolutionFailuresAndCancellationPassThroughUnchanged(Throwable $failure): void
    {
        $this->app->instance(OAuthDestinationPolicy::class, new class($failure) extends FakeDestinationPolicy {
            /**
             * Capture a failure from DNS resolution.
             */
            public function __construct(private Throwable $failure)
            {
                parent::__construct();
            }

            /**
             * Fail during resolution before HTTP dispatch.
             */
            protected function resolveHost(string $host, float $timeoutSeconds): array
            {
                throw $this->failure;
            }
        });
        Http::fake();

        try {
            (new DynamicClientRegistration)->register('https://auth.test/register', 'https://app.test/callback');
            $this->fail('Expected the resolution failure to escape unchanged.');
        } catch (DestinationResolutionException|CanceledException $caught) {
            $this->assertSame($failure, $caught);
        }

        Http::assertNothingSent();
    }

    /**
     * Provide DNS and cancellation failures whose identity must be preserved.
     */
    public static function resolutionFailures(): array
    {
        return [
            [new DestinationResolutionException('DNS failed')],
            [new CanceledException('DNS cancelled')],
        ];
    }
}
