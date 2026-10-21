<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server;

use Firebase\JWT\JWT;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Mcp\Server\McpServiceProvider;
use Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Hypervel\Passport\Client;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\CheckTokenAudience;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerSettings;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Passport\Feature\PassportTestCase;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;

class PassportIntegrationTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected bool $nativeRegistration = false;

    /**
     * Load MCP before Passport, as in normal package discovery.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [McpServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    /**
     * Configure the server's public issuer.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('passport.issuer', 'https://localhost');
        $app->make('config')->set('passport.registration.scopes', ['mcp:use']);
    }

    /**
     * Select native registration before providers register routes.
     */
    protected function useNativeRegistration(Application $app): void
    {
        $this->nativeRegistration = true;
        Passport::enableDynamicClientRegistration();
    }

    /**
     * Register a protected MCP resource and its consent view.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Mcp::oauthRoutes(registration: ! $this->nativeRegistration);
        Passport::tokensCanAccess([
            'https://localhost/mcp/weather' => 'Weather',
            'https://localhost/mcp/other' => 'Other',
        ]);
        Passport::authorizationView('mcp::authorize');
        Mcp::web('/mcp/weather', ExampleServer::class)->middleware([
            'auth:passport',
            CheckTokenAudience::using('https://localhost/mcp/weather'),
            CheckToken::using('mcp:use'),
        ]);
        $this->withoutVite();
    }

    #[TestWith(['https://localhost/mcp/weather', null, 200])]
    #[TestWith(['https://localhost/mcp/other', null, 401])]
    #[TestWith(['https://localhost/mcp/weather', [], 403])]
    public function testRegistrationConsentAndPkceProtectTheMcpResource(string $resource, ?array $scopes, int $status): void
    {
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Weather App', 'redirect_uris' => ['https://client.example/callback'],
        ])->assertCreated()->assertJsonPath('scope', 'mcp:use')->json('client_id');
        $this->assertFalse(Client::findOrFail($client)->firstParty());
        $this->actingAs(UserFactory::new()->create(), 'web');
        $verifier = Str::random(64);
        $consent = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client, 'response_type' => 'code', 'scope' => 'mcp:use', 'resource' => $resource,
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ]))->assertOk()->assertSee('client.example')->assertSee($resource)
            ->assertSee(route('passport.authorizations.approve'))->assertSee(route('passport.authorizations.deny'));
        $authorization = $consent->viewData('authToken');
        $location = $this->post(route('passport.authorizations.approve'), [
            'auth_token' => $authorization, ...($scopes === null ? [] : ['scopes' => $scopes]),
        ])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
        $token = $this->post('/oauth/token', [
            'client_id' => $client, 'grant_type' => 'authorization_code',
            'code' => $parameters['code'], 'code_verifier' => $verifier, 'resource' => $resource,
        ])->assertOk()->json('access_token');

        $response = $this->withToken($token)->postJson('/mcp/weather', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ])->assertStatus($status);

        if ($status === 200) {
            $response->assertJsonPath('result.tools.0.name', 'say-hi-tool')->assertHeaderMissing('WWW-Authenticate');
        } else {
            $response->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="' . url('/.well-known/oauth-protected-resource/mcp/weather')
                . '", error="' . ($status === 401 ? 'invalid_token' : 'insufficient_scope') . '"'
                . ($status === 403 ? ', scope="mcp:use"' : ''));
        }
    }

    public function testDenialReturnsTheClientErrorThroughTheNamedRoute(): void
    {
        $client = $this->postJson('/oauth/register', ['redirect_uris' => ['https://client.example/callback']])->assertCreated()->json('client_id');
        $this->actingAs(UserFactory::new()->create(), 'web');
        $consent = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client, 'response_type' => 'code', 'state' => 'original',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', Str::random(64), true)), 'code_challenge_method' => 'S256',
        ]))->assertOk();
        $location = $this->delete(route('passport.authorizations.deny'), ['auth_token' => $consent->viewData('authToken')])
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://client.example/callback?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
        $this->assertSame('access_denied', $parameters['error']);
        $this->assertSame('original', $parameters['state']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    #[TestWith([false, null, ''])]
    #[TestWith([false, 'Bearer invalid', ', error="invalid_token"'])]
    #[TestWith([true, 'DPoP invalid', ''])]
    public function testPassportChallengesIncludeDiscovery(bool $dpop, ?string $authorization, string $bearerError): void
    {
        config(['passport.dpop.enabled' => $dpop]);
        $metadata = 'resource_metadata="' . url('/.well-known/oauth-protected-resource/mcp/weather') . '"';
        $this->postJson('/mcp/weather', [], $authorization === null ? [] : ['Authorization' => $authorization])
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer ' . $metadata . $bearerError
                . ($dpop ? ', DPoP algs="RS256 ES256", ' . $metadata . ', error="invalid_token"' : ''));
    }

    public function testPassportRoutesWithoutMcpChallengesKeepTheirOwnHeader(): void
    {
        Mcp::web('/mcp/excluded', ExampleServer::class)->middleware('auth:passport')->withoutMiddleware(AddWwwAuthenticateHeader::class);
        Route::post('/api/private', static fn (): string => 'Allowed')->middleware('auth:passport');

        $this->postJson('/mcp/excluded')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->postJson('/api/private')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
    }

    #[DefineEnvironment('useNativeRegistration')]
    public function testNativeRegistrationCanBeSelectedAndAdvertised(): void
    {
        $this->getJson('https://localhost/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('registration_endpoint', 'https://localhost/oauth/register');
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://client.example/callback'], 'scope' => 'mcp:use', 'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->assertJsonPath('application_type', 'web');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Choose MCP or Passport client registration');
        Mcp::oauthRoutes();
    }

    public function testDiscoveryFollowsTheSelectedServerForEachRequest(): void
    {
        $this->app->instance(ServerSettingsResolver::class, new class implements ServerSettingsResolver {
            /**
             * Select settings from the current test request.
             */
            public function current(): ?string
            {
                return request()->header('X-Server');
            }

            /**
             * Return the selected issuer without signing keys for discovery.
             */
            public function resolve(string $identifier): ServerSettings
            {
                return new ServerSettings([], issuer: 'https://' . $identifier . '.example/auth');
            }
        });

        foreach (['first', 'second'] as $server) {
            $this->getJson('/.well-known/oauth-protected-resource/mcp/weather', ['X-Server' => $server])->assertOk()
                ->assertJsonPath('authorization_servers', ['https://' . $server . '.example/auth']);
            $this->getJson('https://' . $server . '.example/.well-known/oauth-authorization-server/auth', ['X-Server' => $server])
                ->assertOk()->assertJsonPath('issuer', 'https://' . $server . '.example/auth');
        }
    }

    public function testMissingIssuerFailsClearly(): void
    {
        config(['passport.issuer' => null]);
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Configure a Passport issuer or an external mcp.authorization_server');
        $this->getJson('/.well-known/oauth-protected-resource/mcp/weather');
    }
}
