<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Client;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\RedirectResponse;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\OAuth\OAuthDestinationPolicy;
use Hypervel\Mcp\Client\OAuth\TokenSet;
use Hypervel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Mcp\WebClient;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\URL;
use Hypervel\Tests\Mcp\Fixtures\Client\OAuth\FakeDestinationPolicy;
use Hypervel\Tests\Mcp\Fixtures\Client\OAuthCallbackController;
use Hypervel\Tests\Mcp\TestCase;

class OAuthCallbackRouteTest extends TestCase
{
    /**
     * Configure session encryption for the callback routes.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    /**
     * Resolve fake endpoints without external DNS.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OAuthDestinationPolicy::class, new FakeDestinationPolicy);
    }

    /**
     * Create the pending authorization session.
     */
    private function fakeOAuthSession(): array
    {
        $key = (fn (): string => $this->sessionKey())->call(Mcp::client('github')->oAuthClient());

        return [$key => [
            'state' => 'the-state',
            'verifier' => 'the-verifier',
            'client_id' => 'client-123',
            'client_secret' => null,
            'token_endpoint' => 'https://auth.test/token',
            'redirect_uri' => 'https://app.test/callback',
            'return_to' => null,
        ]];
    }

    /**
     * Register the test resource client.
     */
    private function registerGithubClient(): void
    {
        Mcp::registerClient('github', fn (): WebClient => Client::web('https://mcp.test/mcp')->withOAuth(
            clientId: 'client-123',
            redirectUri: 'https://app.test/callback',
        ));
    }

    public function testExchangesTheCodeAndInvokesTheHandlerWithTheClientName(): void
    {
        Http::fake([
            'https://auth.test/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->registerGithubClient();

        $capturedProvider = null;
        $capturedToken = null;

        Mcp::oAuthRoutesFor('github', function (string $provider, TokenSet $token) use (&$capturedProvider, &$capturedToken): RedirectResponse {
            $capturedProvider = $provider;
            $capturedToken = $token;

            return redirect('/dashboard');
        });

        $this->withSession($this->fakeOAuthSession())
            ->get('/mcp/oauth/github/callback?code=auth-code&state=the-state')
            ->assertRedirect('/dashboard');

        $this->assertSame('github', $capturedProvider);
        $this->assertInstanceOf(TokenSet::class, $capturedToken);
        $this->assertSame('access-token', $capturedToken->accessToken);
        $this->assertSame('refresh-token', $capturedToken->refreshToken);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.test/token'
            && ($request['grant_type'] ?? null) === 'authorization_code'
            && ($request['code'] ?? null) === 'auth-code');
    }

    public function testRegistersAConnectRouteThatRedirectsToTheAuthorizationServer(): void
    {
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://mcp.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
            ]),
        ]);

        $this->registerGithubClient();

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): RedirectResponse => redirect('/dashboard'));

        $response = $this->withSession([])->get('/mcp/github/connect');

        $response->assertRedirectContains('https://auth.test/authorize');
    }

    public function testSupportsControllerArraySyntaxForTheHandler(): void
    {
        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'access-token']),
        ]);

        $this->registerGithubClient();

        Mcp::oAuthRoutesFor('github', [OAuthCallbackController::class, 'callback']);

        $this->withSession($this->fakeOAuthSession())
            ->get('/mcp/oauth/github/callback?code=auth-code&state=the-state')
            ->assertRedirect('/connected/github');
    }

    public function testAppliesTheWebMiddlewareGroupByDefaultToBothRoutes(): void
    {
        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null);

        $this->assertContains('web', Route::getRoutes()->getByName('mcp.oauth.github.connect')->gatherMiddleware());
        $this->assertContains('web', Route::getRoutes()->getByName('mcp.oauth.github.callback')->gatherMiddleware());
    }

    public function testKeepsTheClientIdMetadataDocumentPubliclyFetchable(): void
    {
        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null, middleware: ['web', 'auth']);

        $middleware = Route::getRoutes()->getByName('mcp.oauth.github.client-metadata')->gatherMiddleware();
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains('auth', $middleware);

        $this->get('/mcp/oauth/github/client-metadata.json')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function testAllowsTheMiddlewareToBeOverriddenOnBothRoutes(): void
    {
        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null, middleware: ['web', 'auth']);

        $this->assertContains('web', Route::getRoutes()->getByName('mcp.oauth.github.connect')->gatherMiddleware());
        $this->assertContains('auth', Route::getRoutes()->getByName('mcp.oauth.github.connect')->gatherMiddleware());
        $this->assertContains('web', Route::getRoutes()->getByName('mcp.oauth.github.callback')->gatherMiddleware());
        $this->assertContains('auth', Route::getRoutes()->getByName('mcp.oauth.github.callback')->gatherMiddleware());
    }

    public function testFallsBackToRedirectingHomeWhenTheHandlerReturnsNothing(): void
    {
        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'access-token']),
        ]);

        $this->registerGithubClient();

        Mcp::oAuthRoutesFor('github', function (string $provider, TokenSet $token): void {
        });

        $this->withSession($this->fakeOAuthSession())
            ->get('/mcp/oauth/github/callback?code=auth-code&state=the-state')
            ->assertRedirect('/');
    }

    public function testPassesTheStoredReturnDestinationToTheHandlerAndFallbackRedirect(): void
    {
        Http::fake([
            'https://auth.test/token' => Http::response(['access_token' => 'access-token']),
        ]);

        $this->registerGithubClient();

        $capturedReturnTo = null;

        Mcp::oAuthRoutesFor('github', function (string $provider, TokenSet $token, ?string $returnTo) use (&$capturedReturnTo): void {
            $capturedReturnTo = $returnTo;
        });

        $session = $this->fakeOAuthSession();
        $session[array_key_first($session)]['return_to'] = '/connected';

        $this->withSession($session)
            ->get('/mcp/oauth/github/callback?code=auth-code&state=the-state')
            ->assertRedirect('/connected');

        $this->assertSame('/connected', $capturedReturnTo);
    }

    public function testForwardsChallengeMetadataAndScopeFromTheConnectRouteIntoDiscovery(): void
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

        Mcp::registerClient('github', fn (): WebClient => Client::web('https://mcp.test/mcp')->withOAuth(
            clientId: 'client-123',
            redirectUri: 'https://app.test/callback',
        ));

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null);

        $exception = new AuthorizationRequiredException(challenge: new WwwAuthenticateChallenge(
            resourceMetadataUrl: 'https://mcp.test/.well-known/custom-resource',
            scope: 'files:read',
        ));

        $this->withSession([])
            ->get(URL::signedRoute('mcp.oauth.github.connect', $exception->query()))
            ->assertRedirectContains('https://auth.test/authorize')
            ->assertRedirectContains('scope=files%3Aread');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://mcp.test/.well-known/custom-resource');
    }

    public function testRejectsUnsignedOrTamperedHintsBeforeDiscovery(): void
    {
        Http::fake();
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null);

        $exception = new AuthorizationRequiredException(challenge: new WwwAuthenticateChallenge(
            resourceMetadataUrl: 'https://attacker.test/metadata',
            scope: 'files:read',
        ));

        $this->get(route('mcp.oauth.github.connect', $exception->query()))->assertForbidden();
        $this->get(route('mcp.oauth.github.connect', ['scope' => 'admin']))->assertForbidden();

        $signed = URL::signedRoute('mcp.oauth.github.connect', $exception->query());
        $this->get(str_replace('attacker.test', 'another.test', $signed))->assertForbidden();
        $this->get(str_replace('files%3Aread', 'admin', $signed))->assertForbidden();

        Http::assertNothingSent();
    }

    public function testServesAClientIdMetadataDocumentMatchingItsOwnUrl(): void
    {
        $this->registerGithubClient();

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null);

        $document = $this->get('/mcp/oauth/github/client-metadata.json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->json();

        $this->assertSame(route('mcp.oauth.github.client-metadata'), $document['client_id']);
        $this->assertSame([route('mcp.oauth.github.callback')], $document['redirect_uris']);
        $this->assertSame(['authorization_code', 'refresh_token'], $document['grant_types']);
        $this->assertSame(['code'], $document['response_types']);
        $this->assertSame('none', $document['token_endpoint_auth_method']);
        $this->assertIsString($document['client_name']);
        $this->assertNotEmpty($document['client_name']);
    }

    public function testDefaultsTheClientIdMetadataUrlToThePublishedRoute(): void
    {
        config(['app.url' => 'https://app.example.com']);

        Mcp::registerClient('github', fn (): WebClient => Client::web('https://mcp.test/mcp')->withOAuth());

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null);

        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint' => 'https://auth.test/token',
                'client_id_metadata_document_supported' => true,
            ]),
        ]);

        $this->withSession([])
            ->get('http://localhost/mcp/github/connect')
            ->assertRedirectContains(urlencode('https://app.example.com/mcp/oauth/github/client-metadata.json'))
            ->assertRedirectContains(urlencode('https://app.example.com/mcp/oauth/github/callback'));
    }

    public function testDeclaresACustomRedirectUriAlongsideThePublishedCallbackRoute(): void
    {
        config(['app.url' => 'https://app.example.com']);

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null, clientMetadata: [
            'redirect_uris' => ['https://app.example.com/callback'],
        ]);

        $this->assertSame([
            'https://app.example.com/mcp/oauth/github/callback',
            'https://app.example.com/callback',
        ], $this->get('/mcp/oauth/github/client-metadata.json')->json('redirect_uris'));
    }

    public function testBuildsTheClientIdMetadataDocumentFromTheConfiguredApplicationUrl(): void
    {
        config(['app.url' => 'https://app.example.com/', 'app.name' => 'Acme']);

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null);

        $document = $this->get('http://spoofed.example.net/mcp/oauth/github/client-metadata.json')->json();

        $this->assertSame('https://app.example.com/mcp/oauth/github/client-metadata.json', $document['client_id']);
        $this->assertSame(['https://app.example.com/mcp/oauth/github/callback'], $document['redirect_uris']);
        $this->assertSame('https://app.example.com', $document['client_uri']);
        $this->assertSame('Acme MCP Client', $document['client_name']);
    }

    public function testAllowsThePublishedClientMetadataToBeCustomizedWithoutWeakeningIt(): void
    {
        config(['app.url' => 'https://app.example.com']);

        Mcp::oAuthRoutesFor('github', fn (string $provider, TokenSet $token): null => null, clientMetadata: [
            'client_name' => 'Acme Dashboard',
            'logo_uri' => 'https://app.example.com/logo.png',
            'client_id' => 'https://evil.example.net/impostor.json',
            'token_endpoint_auth_method' => 'client_secret_post',
            'client_secret' => 'nope',
        ]);

        $document = $this->get('/mcp/oauth/github/client-metadata.json')->json();

        $this->assertSame('Acme Dashboard', $document['client_name']);
        $this->assertSame('https://app.example.com/logo.png', $document['logo_uri']);
        $this->assertSame('https://app.example.com/mcp/oauth/github/client-metadata.json', $document['client_id']);
        $this->assertSame(['https://app.example.com/mcp/oauth/github/callback'], $document['redirect_uris']);
        $this->assertSame('none', $document['token_endpoint_auth_method']);
        $this->assertArrayNotHasKey('client_secret', $document);
    }
}
