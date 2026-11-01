<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Client;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Request as ClientRequest;
use Hypervel\Http\RedirectResponse;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

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
            'resource' => 'https://mcp.test/mcp',
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
            && ($request['code'] ?? null) === 'auth-code'
            && ($request['resource'] ?? null) === 'https://mcp.test/mcp');
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
            'https://mcp.test/mcp' => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.test/.well-known/custom-resource"',
            ]),
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

    #[DataProvider('unadvertisedMetadata')]
    public function testRejectsCallerSuppliedMetadataTheResourceDoesNotAdvertise(string $metadataUrl, int $status): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', $status, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://trusted.test/resource"',
            ]),
        ]);

        Mcp::registerClient('github', fn (): WebClient => Client::web('https://mcp.test/mcp')->withOAuth(
            clientId: 'confidential-client',
            clientSecret: 'confidential-secret',
        ));
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->assertThrows(
            fn () => $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => $metadataUrl])),
            OAuthException::class,
            'was not advertised by the resource',
        );

        $key = (fn (): string => $this->sessionKey())->call(Mcp::client('github')->oAuthClient());
        $this->assertFalse(session()->has($key));
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() !== 'https://mcp.test/mcp');
    }

    /**
     * Provide metadata substitutions and response statuses.
     */
    public static function unadvertisedMetadata(): iterable
    {
        foreach (['https://attacker.test/resource', 'https://mcp.test/attacker-controlled', 'https://trusted.test/resource?issuer=attacker', 'https://trusted.test/%72esource'] as $url) {
            foreach ([401, 403, 200] as $status) {
                yield $url . ' ' . $status => [$url, $status];
            }
        }
    }

    public function testRejectsCallerSuppliedMetadataWhenTheResourceSendsNoChallenge(): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake(['https://mcp.test/mcp' => Http::response('', 401)]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('was not advertised by the resource');
        $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://attacker.test/resource']));
    }

    #[TestWith(['client_secret_post'])]
    #[TestWith(['client_secret_basic'])]
    public function testCompletesTheConfidentialFlowWithAdvertisedCrossOriginMetadata(string $authMethod): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake([
            'https://mcp.test/mcp' => fn (ClientRequest $request) => $request->method() === 'POST' && $request['method'] === 'ping'
                ? Http::response('', 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"'])
                : Http::response('', 405),
            'https://metadata.test/resource' => Http::response([
                'resource' => 'https://mcp.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => [$authMethod],
            ]),
            'https://auth.test/token' => Http::response(['access_token' => 'access-token']),
        ]);

        Mcp::registerClient('github', fn (): WebClient => Client::web('https://mcp.test/mcp')->withOAuth(
            clientId: 'confidential-client',
            clientSecret: 'confidential-secret',
        ));
        Mcp::oAuthRoutesFor('github', fn (TokenSet $token): RedirectResponse => redirect('/connected/' . $token->accessToken));

        $response = $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource']));
        $response->assertRedirectContains('https://auth.test/authorize');
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get('/mcp/oauth/github/callback?' . http_build_query(['code' => 'auth-code', 'state' => $query['state']]))
            ->assertRedirect('/connected/access-token');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://auth.test/token' && $request['code'] === 'auth-code');
    }

    public function testSkipsTheAdvertisementCheckForTheDefaultWellKnownMetadataUrl(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://mcp.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->withSession([])
            ->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://mcp.test/.well-known/oauth-protected-resource/mcp']))
            ->assertRedirectContains('https://auth.test/authorize');

        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() === 'https://mcp.test/mcp');
    }

    public function testRejectsAdvertisedMetadataWhenTheResourceDoesNotChallenge(): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 200, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"',
            ]),
        ]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->assertThrows(
            fn () => $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource'])),
            OAuthException::class,
            'was not advertised by the resource',
        );
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() === 'https://metadata.test/resource');
    }

    public function testSendsTheClientCustomHeadersOnTheAdvertisementProbe(): void
    {
        Http::preventStrayRequests();
        $this->fakeAdvertisedMetadata([
            'https://mcp.test/mcp' => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"',
            ]),
        ]);

        Mcp::registerClient('github', fn (): WebClient => Client::web('https://mcp.test/mcp')
            ->withHeaders([
                'X-Api-Key' => 'gateway-key',
                'authorization' => 'Bearer custom',
                'Mcp-Session-Id' => 'session',
            ])
            ->withToken('stored-token')
            ->withOAuth(clientId: 'client-123', redirectUri: 'https://app.test/callback'));
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->withSession([])
            ->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource']))
            ->assertRedirectContains('https://auth.test/authorize');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://mcp.test/mcp'
            && $request->header('X-Api-Key') === ['gateway-key']
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Mcp-Session-Id'));
    }

    #[TestWith(['/mcp/'])]
    #[TestWith(['https://mcp.test/mcp/'])]
    #[TestWith(['HTTPS://MCP.TEST/mcp/'])]
    public function testFollowsSameOriginRedirectsOnTheAdvertisementProbe(string $location): void
    {
        Http::preventStrayRequests();
        $this->fakeAdvertisedMetadata([
            'https://mcp.test/mcp' => Http::response('', 307, ['Location' => $location]),
            'https://mcp.test/mcp/' => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"',
            ]),
        ]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->withSession([])
            ->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource']))
            ->assertRedirectContains('https://auth.test/authorize');
    }

    #[TestWith(['https://www.mcp.test/mcp'])]
    #[TestWith(['//www.mcp.test/mcp'])]
    public function testFollowsCrossOriginRedirectsChosenByTheResourceOnTheAdvertisementProbe(string $location): void
    {
        Http::preventStrayRequests();
        $this->fakeAdvertisedMetadata([
            'https://mcp.test/mcp' => Http::response('', 307, ['Location' => $location]),
            'https://www.mcp.test/mcp' => Http::response('', 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="https://metadata.test/resource"',
            ]),
        ]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->withSession([])
            ->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource']))
            ->assertRedirectContains('https://auth.test/authorize');
    }

    #[TestWith(['http://mcp.test/mcp/', 'must be served over HTTPS'])]
    #[TestWith(['https://127.0.0.1/mcp', 'private or internal host'])]
    #[TestWith(['https://10.0.0.1/mcp', 'disallowed address'])]
    #[TestWith(['http://:80', 'Unable to reach the resource'])]
    public function testRejectsRedirectsToInsecureOrInternalHostsOnTheAdvertisementProbe(string $location, string $message): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake(['https://mcp.test/mcp' => Http::response('', 302, ['Location' => $location])]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->assertThrows(
            fn () => $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource'])),
            OAuthException::class,
            $message,
        );
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() !== 'https://mcp.test/mcp');
    }

    public function testStopsFollowingRedirectsOnTheAdvertisementProbeAfterFiveHops(): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake(['https://mcp.test/*' => Http::response('', 302, ['Location' => '/loop'])]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->assertThrows(
            fn () => $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource'])),
            OAuthException::class,
            'Unable to reach the resource',
        );
        Http::assertNotSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://metadata.test'));
    }

    public function testReportsAnUnreachableResourceDuringTheAdvertisementProbeAsAnOAuthError(): void
    {
        $this->withoutExceptionHandling();
        Http::preventStrayRequests();
        Http::fake(['https://mcp.test/mcp' => fn () => throw new ConnectionException('Connection timed out')]);
        $this->registerGithubClient();
        Mcp::oAuthRoutesFor('github', fn (): null => null);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unable to reach the resource [https://mcp.test/mcp]');
        $this->withSession([])->get(URL::signedRoute('mcp.oauth.github.connect', ['resource_metadata' => 'https://metadata.test/resource']));
    }

    /**
     * Fake advertised resource and authorization server metadata.
     */
    private function fakeAdvertisedMetadata(array $resource): void
    {
        Http::fake([
            ...$resource,
            'https://metadata.test/resource' => Http::response([
                'resource' => 'https://mcp.test/mcp',
                'authorization_servers' => ['https://auth.test'],
            ]),
            'https://auth.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/authorize',
                'token_endpoint' => 'https://auth.test/token',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);
    }
}
