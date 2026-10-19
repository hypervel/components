<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Firebase\JWT\JWT;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Passport\ApiTokenCookieFactory;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Client;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\DpopProofValidator;
use Hypervel\Passport\Events\AuthorizationApproved;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\RefreshTokenFamily;
use Hypervel\Passport\ReplayCache;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerSettings;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Passport\Fixtures\JsonWebKeyPair;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

class DpopIssuanceTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected const string ORIGIN = 'https://auth.example';

    protected const string VERIFIER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Enable DPoP with the real database replay store.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set(['passport.dpop.enabled' => true, 'passport.replay.store' => 'database']);
    }

    /**
     * Present real authorization requests without a view dependency.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Passport::authorizationView(static fn (array $parameters): array => $parameters);
    }

    #[TestWith([60])]
    #[TestWith([3600])]
    public function testRequiredProofsBindMachineTokensAndCapTheirLifetime(int $lifetime): void
    {
        Passport::tokensExpireIn(new DateInterval('PT' . $lifetime . 'S'));
        $client = ClientFactory::new()->create(['grant_types' => ['client_credentials'], 'dpop_bound_access_tokens' => true]);
        $parameters = ['grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret];
        $this->token($parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_dpop_proof');
        $this->assertDatabaseCount('oauth_access_tokens', 0);

        $key = JsonWebKeyPair::generate();
        $tokens = $this->token($parameters, $key)->assertOk()->assertJsonPath('token_type', 'DPoP')->json();
        $claims = $this->app->make(ServerFactory::class)->tokenValidator()->validateToken($tokens['access_token'])->claims();
        $this->assertSame(['jkt' => $key->thumbprint()], $claims->get('cnf'));
        $this->assertSame($key->thumbprint(), Token::sole()->dpop_jkt);
        $this->assertLessThanOrEqual(min($lifetime, 300), $tokens['expires_in']);
        $this->assertGreaterThan(min($lifetime, 300) - 5, $tokens['expires_in']);
    }

    public function testCodeBindingCannotBeOverwrittenAndWrongProofDoesNotConsumeTheCode(): void
    {
        $client = ClientFactory::new()->create(['secret' => null]);
        $key = JsonWebKeyPair::generate();
        $other = JsonWebKeyPair::generate();
        Event::listen(AuthorizationApproved::class, static function (AuthorizationApproved $event) use ($other): void {
            $event->attributes['dpop_jkt'] = $other->thumbprint();
        });
        $code = $this->code($client, $key);
        $this->assertSame($key->thumbprint(), AuthCode::sole()->dpop_jkt);
        $parameters = $this->exchangeParameters($client, $code);

        $this->token($parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->token($parameters, $other)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertFalse(AuthCode::sole()->revoked);
        $tokens = $this->token($parameters, $key)->assertOk()->assertJsonPath('token_type', 'DPoP')->json();
        $this->assertSame($key->thumbprint(), RefreshToken::sole()->dpop_jkt);

        $this->token($parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->token($parameters, $other)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertFalse(Token::sole()->revoked);
        $this->assertNull(RefreshTokenFamily::sole()->revoked_at);

        $next = $this->token($this->refreshParameters($client, $tokens['refresh_token']), $key)->assertOk()->json();
        $this->token($this->refreshParameters($client, $tokens['refresh_token']), $other)
            ->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertNull(RefreshTokenFamily::sole()->revoked_at);
        $this->token($this->refreshParameters($client, $next['refresh_token']), $key)->assertOk();

        $this->token($parameters, $key)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertNotNull(RefreshTokenFamily::sole()->revoked_at);
        $this->assertSame(0, Token::where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::where('revoked', false)->count());
    }

    public function testUpgradingAnUnboundRefreshTokenDoesNotHideTheftOfItsPredecessor(): void
    {
        $client = ClientFactory::new()->create(['secret' => null]);
        $tokens = $this->token($this->exchangeParameters($client, $this->code($client)))->assertOk()->json();
        $old = RefreshToken::sole();
        $this->assertNull($old->dpop_jkt);

        $thief = JsonWebKeyPair::generate();
        $this->token($this->refreshParameters($client, $tokens['refresh_token']), $thief)->assertOk();
        $this->assertNull($old->fresh()->dpop_jkt);
        $this->assertSame($thief->thumbprint(), RefreshToken::where('id', '!=', $old->id)->sole()->dpop_jkt);

        $this->token($this->refreshParameters($client, $tokens['refresh_token']))
            ->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertNotNull(RefreshTokenFamily::sole()->revoked_at);
        $this->assertSame(0, Token::where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::where('revoked', false)->count());
    }

    public function testPublicRotationOffRequiresBindingAtBothInitialIssuanceAndRedemption(): void
    {
        Passport::$revokeRefreshTokenAfterUse = false;
        $client = ClientFactory::new()->create();
        $unbound = $this->token($this->exchangeParameters($client, $this->code($client)))->assertOk()->json();
        $client->forceFill(['secret' => null])->save();
        $key = JsonWebKeyPair::generate();

        $this->token($this->refreshParameters($client, $unbound['refresh_token']), $key)
            ->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->token($this->exchangeParameters($client, $this->code($client)))->assertOk()->assertJsonMissingPath('refresh_token');
        $bound = $this->token($this->exchangeParameters($client, $this->code($client, $key)), $key)->assertOk()->json();
        $this->assertArrayHasKey('refresh_token', $bound);
        $parameters = $this->refreshParameters($client, $bound['refresh_token']);
        $this->token($parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->token($parameters, $key)->assertOk();
        $this->token($parameters, $key)->assertOk();
        $this->assertNull(RefreshTokenFamily::where('id', RefreshToken::where('dpop_jkt', $key->thumbprint())->first()->family_id)->sole()->revoked_at);
    }

    public function testConfidentialRefreshTokensCanUseAnotherProofKey(): void
    {
        $client = ClientFactory::new()->create();
        $key = JsonWebKeyPair::generate();
        $tokens = $this->token($this->exchangeParameters($client, $this->code($client, $key)), $key)->assertOk()->json();
        $this->assertNull(RefreshToken::sole()->dpop_jkt);

        $other = JsonWebKeyPair::generate();
        $next = $this->token($this->refreshParameters($client, $tokens['refresh_token']), $other)->assertOk()->json();
        $claims = $this->app->make(ServerFactory::class)->tokenValidator()->validateToken($next['access_token'])->claims();
        $this->assertSame(['jkt' => $other->thumbprint()], $claims->get('cnf'));
        $this->assertSame(0, RefreshToken::whereNotNull('dpop_jkt')->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPushedProofBindingSurvivesBrowserOverrides(bool $explicit): void
    {
        $client = ClientFactory::new()->create();
        $key = JsonWebKeyPair::generate();
        $other = JsonWebKeyPair::generate();
        $parameters = [
            'client_id' => $client->id, 'client_secret' => $client->plainSecret, 'response_type' => 'code',
            'redirect_uri' => $client->redirect_uris[0], 'prompt' => 'consent',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true)), 'code_challenge_method' => 'S256',
            ...($explicit ? ['dpop_jkt' => $key->thumbprint()] : []),
        ];
        $uri = $this->call('POST', self::ORIGIN . '/oauth/par', server: [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_DPOP' => $this->proof($key, '/oauth/par'),
        ], content: RequestParameters::toQuery($parameters))->assertCreated()->json('request_uri');
        $this->assertSame($key->thumbprint(), Passport::pushedAuthorizationRequest()->sole()->parameters['dpop_jkt']);

        $code = $this->code($client, $other, $uri);
        $this->assertSame($key->thumbprint(), AuthCode::sole()->dpop_jkt);
        $this->token($this->exchangeParameters($client, $code), $key)->assertOk()->assertJsonPath('token_type', 'DPoP');
    }

    public function testDisabledProofSupportRejectsNewProofsAndCannotRedeemExistingBindings(): void
    {
        $client = ClientFactory::new()->create(['secret' => null]);
        $key = JsonWebKeyPair::generate();
        $code = $this->code($client, $key);
        config(['passport.dpop.enabled' => false]);
        $parameters = $this->exchangeParameters($client, $code);
        $this->token($parameters, $key)->assertBadRequest()->assertJsonPath('error', 'invalid_dpop_proof');
        $this->token($parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertFalse(AuthCode::sole()->revoked);
    }

    public function testResourceProofIsSharedByGuardEntryPointsButCannotBeReplayedInAnotherRequest(): void
    {
        $client = ClientFactory::new()->create(['secret' => null]);
        $key = JsonWebKeyPair::generate();
        $tokens = $this->token($this->exchangeParameters($client, $this->code($client, $key)), $key)->assertOk()->json();
        $userId = Token::sole()->user_id;
        Route::get('/proof-user', function (Request $request): array {
            $guard = $this->app->make('auth')->guard('passport');

            return [
                'user' => $request->user()->getAuthIdentifier(),
                'client' => $guard->client()->getKey(),
                'token' => $guard->token()->getKey(),
                'valid' => $guard->validate(['request' => $request]),
            ];
        })->middleware('auth:passport');
        $proof = $this->proof($key, '/proof-user', 'GET', $tokens['access_token']);
        $headers = ['Authorization' => 'DPoP ' . $tokens['access_token'], 'DPoP' => $proof];

        $this->getJson(self::ORIGIN . '/proof-user', $headers)->assertOk()
            ->assertJsonPath('user', $userId)->assertJsonPath('client', $client->id)->assertJsonPath('valid', true);
        $this->getJson(self::ORIGIN . '/proof-user', $headers)->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer, DPoP algs="RS256 ES256", error="invalid_dpop_proof"');
        $this->getJson(self::ORIGIN . '/proof-user', ['Authorization' => 'Bearer ' . $tokens['access_token']])->assertUnauthorized();
    }

    public function testResourceMiddlewareRequiresProofBindingAndIgnoresProofHeadersOnBearerRequests(): void
    {
        Passport::tokensCan(['read' => 'Read']);
        Route::get('/proof-machine', static fn (): string => 'Allowed')->middleware(CheckToken::using('read'));
        $client = ClientFactory::new()->create(['grant_types' => ['client_credentials']]);
        $parameters = ['grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret, 'scope' => 'read'];
        $key = JsonWebKeyPair::generate();
        $bound = $this->token($parameters, $key)->assertOk()->json('access_token');
        $unbound = $this->token($parameters)->assertOk()->json('access_token');

        $this->getJson(self::ORIGIN . '/proof-machine', [
            'Authorization' => 'dpop ' . $bound, 'DPoP' => $this->proof($key, '/proof-machine', 'GET', $bound),
        ])->assertOk();
        $this->getJson(self::ORIGIN . '/proof-machine', ['Authorization' => 'DPoP ' . $bound])->assertUnauthorized();
        $this->getJson(self::ORIGIN . '/proof-machine', [
            'Authorization' => 'DPoP ' . $bound, 'DPoP' => $this->proof($key, '/proof-machine', 'GET', $unbound),
        ])->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer, DPoP algs="RS256 ES256", error="invalid_dpop_proof"');
        $this->getJson(self::ORIGIN . '/proof-machine', [
            'Authorization' => 'DPoP ' . $bound, 'DPoP' => $this->proof(JsonWebKeyPair::generate(), '/proof-machine', 'GET', $bound),
        ])->assertUnauthorized();
        $this->getJson(self::ORIGIN . '/proof-machine', [
            'Authorization' => 'DPoP ' . $bound, 'DPoP' => $this->proof($key, '/proof-machine', 'GET'),
        ])->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer, DPoP algs="RS256 ES256", error="invalid_dpop_proof"');
        $this->getJson(self::ORIGIN . '/proof-machine', [
            'Authorization' => 'DPoP ' . $unbound, 'DPoP' => $this->proof($key, '/proof-machine', 'GET', $unbound),
        ])->assertUnauthorized();
        $this->getJson(self::ORIGIN . '/proof-machine', ['Authorization' => 'Bearer ' . $unbound, 'DPoP' => 'ignored'])->assertOk();

        config(['passport.dpop.enabled' => false]);
        $this->getJson(self::ORIGIN . '/proof-machine', [
            'Authorization' => 'DPoP ' . $bound, 'DPoP' => $this->proof($key, '/proof-machine', 'GET', $bound),
        ])->assertUnauthorized();
        $this->getJson(self::ORIGIN . '/proof-machine', ['Authorization' => 'Bearer ' . $unbound, 'DPoP' => 'ignored'])->assertOk();
    }

    public function testSelectedServerCapsOnlyProofBoundTokens(): void
    {
        $settings = new ServerSettings($this->app->make(ServerFactory::class)->fileKeys(), dpopTokensExpireIn: new DateInterval('PT30S'));
        $resolver = m::mock(ServerSettingsResolver::class);
        $resolver->shouldReceive('current')->andReturn('short-lived');
        $resolver->shouldReceive('resolve')->with('short-lived')->andReturn($settings);
        $this->app->instance(ServerSettingsResolver::class, $resolver);
        Passport::tokensExpireIn(new DateInterval('PT600S'));
        $client = ClientFactory::new()->asClientCredentials()->create();
        $parameters = ['grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret];

        $bound = $this->token($parameters, JsonWebKeyPair::generate())->assertOk()->json();
        $bearer = $this->token($parameters)->assertOk()->json();
        $this->assertEqualsWithDelta(30, $bound['expires_in'], 3);
        $this->assertEqualsWithDelta(600, $bearer['expires_in'], 3);
    }

    public function testParRejectsConflictingBindingsAndDisabledProofParametersWithoutPersistence(): void
    {
        $client = ClientFactory::new()->asPublic()->create();
        $key = JsonWebKeyPair::generate();
        $parameters = [
            'client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => $client->redirect_uris[0],
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true)), 'code_challenge_method' => 'S256',
            'dpop_jkt' => JsonWebKeyPair::generate()->thumbprint(),
        ];
        $this->post(self::ORIGIN . '/oauth/par', $parameters, [
            'Content-Type' => 'application/x-www-form-urlencoded', 'DPoP' => $this->proof($key, '/oauth/par'),
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_dpop_proof');
        config(['passport.dpop.enabled' => false]);
        $this->post(self::ORIGIN . '/oauth/par', $parameters, ['Content-Type' => 'application/x-www-form-urlencoded'])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        unset($parameters['dpop_jkt']);
        $this->post(self::ORIGIN . '/oauth/par', $parameters, [
            'Content-Type' => 'application/x-www-form-urlencoded', 'DPoP' => $this->proof($key, '/oauth/par'),
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_dpop_proof');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 0);
    }

    #[TestWith([['thumbprint']])]
    #[TestWith(['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'])]
    #[TestWith(['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAB'])]
    public function testParRejectsMalformedThumbprintsWithoutPersistence(array|string $thumbprint): void
    {
        $client = ClientFactory::new()->asPublic()->create();
        $this->post(self::ORIGIN . '/oauth/par', [
            'client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => $client->redirect_uris[0],
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true)), 'code_challenge_method' => 'S256',
            'dpop_jkt' => $thumbprint,
        ], ['Content-Type' => 'application/x-www-form-urlencoded'])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 0);
    }

    #[TestWith(['method'])]
    #[TestWith(['proof'])]
    public function testGuardDoesNotReuseAuthenticationAfterProofInputsChange(string $changed): void
    {
        $client = ClientFactory::new()->asPublic()->create();
        $key = JsonWebKeyPair::generate();
        $token = $this->token($this->exchangeParameters($client, $this->code($client, $key)), $key)->assertOk()->json('access_token');
        Route::get('/mutable-proof', static function (Request $request) use ($changed): array {
            $guard = auth('passport');
            $authenticated = $guard->user() !== null;
            if ($changed === 'method') {
                $request->setMethod('POST');
            } else {
                $request->headers->set('DPoP', 'invalid');
            }

            return ['authenticated' => $authenticated, 'changed' => $guard->user() !== null, 'token' => $guard->token()];
        });

        $this->getJson(self::ORIGIN . '/mutable-proof', [
            'Authorization' => 'DPoP ' . $token, 'DPoP' => $this->proof($key, '/mutable-proof', 'GET', $token),
        ])->assertOk()->assertExactJson(['authenticated' => true, 'changed' => false, 'token' => null]);
    }

    public function testDeviceAuthorizationIgnoresProofsAndPollingBindsTheIssuedTokens(): void
    {
        Passport::deviceAuthorizationView(static fn (array $parameters): array => $parameters);
        $client = ClientFactory::new()->asDeviceCodeClient()->asPublic()->create(['dpop_bound_access_tokens' => true]);
        $device = $this->post(self::ORIGIN . '/oauth/device/code', ['client_id' => $client->id], ['DPoP' => 'ignored'])->assertOk()->json();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $decision = $this->get(self::ORIGIN . '/oauth/device/authorize?user_code=' . $device['user_code'])->assertOk()->json('authToken');
        $this->post(self::ORIGIN . '/oauth/device/authorize', ['auth_token' => $decision])->assertRedirect();
        $parameters = ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $client->id, 'device_code' => $device['device_code']];

        $this->token($parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_dpop_proof');
        $key = JsonWebKeyPair::generate();
        $this->token($parameters, $key)->assertOk()->assertJsonPath('token_type', 'DPoP');
        $this->assertSame($key->thumbprint(), Token::sole()->dpop_jkt);
        $this->assertSame($key->thumbprint(), RefreshToken::sole()->dpop_jkt);
    }

    public function testIntrospectionReportsBindingAndRevocationNeedsNoResourceProof(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->create();
        $key = JsonWebKeyPair::generate();
        $token = $this->token(['grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret], $key)->assertOk()->json('access_token');
        $parameters = ['client_id' => $client->id, 'client_secret' => $client->plainSecret, 'token' => $token];
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'DPoP' => 'ignored'];

        config(['passport.dpop.enabled' => false]);
        $this->post(self::ORIGIN . '/oauth/introspect', $parameters, $headers)->assertOk()
            ->assertJsonPath('active', true)->assertJsonPath('token_type', 'DPoP')->assertJsonPath('cnf.jkt', $key->thumbprint());
        $this->post(self::ORIGIN . '/oauth/revoke', $parameters, $headers)->assertOk();
        $this->post(self::ORIGIN . '/oauth/introspect', $parameters, $headers)->assertOk()->assertExactJson(['active' => false]);
    }

    public function testFailedAuthorizationHeaderCannotFallBackToAnApiCookie(): void
    {
        Passport::withoutCookieEncryption();
        $user = UserFactory::new()->create();
        $cookie = $this->app->make(ApiTokenCookieFactory::class)->make($user->id, 'csrf', 'passport');
        Route::get('/cookie-user', static fn (Request $request): array => ['id' => $request->user()->getKey()])->middleware('auth:passport');
        $this->withCredentials()->withUnencryptedCookie(Passport::cookie(), $cookie->getValue());
        $this->getJson('/cookie-user', ['X-CSRF-TOKEN' => 'csrf'])->assertOk()->assertJsonPath('id', $user->id);

        $this->getJson('/cookie-user', ['X-CSRF-TOKEN' => 'csrf', 'Authorization' => 'DPoP invalid'])->assertUnauthorized();
    }

    #[DefineEnvironment('enableRegistration')]
    public function testProofBoundTokensCanProvisionClientsAndReceiveSchemeSpecificErrors(): void
    {
        Passport::tokensCan(['passport:register' => 'Register clients', 'read' => 'Read']);
        $client = ClientFactory::new()->asClientCredentials()->create();
        $key = JsonWebKeyPair::generate();
        $parameters = ['grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret];
        $token = $this->token([...$parameters, 'scope' => 'passport:register'], $key)->assertOk()->json('access_token');
        $metadata = ['client_name' => 'Registered', 'redirect_uris' => ['https://client.example/callback']];
        $headers = ['Authorization' => 'DPoP ' . $token, 'DPoP' => $this->proof($key, '/oauth/register', accessToken: $token)];

        $this->postJson(self::ORIGIN . '/oauth/register', $metadata, $headers)->assertCreated();
        $this->postJson(self::ORIGIN . '/oauth/register', $metadata, $headers)->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_dpop_proof')
            ->assertHeader('WWW-Authenticate', 'Bearer, DPoP algs="RS256 ES256", error="invalid_dpop_proof"');

        $unprivileged = $this->token([...$parameters, 'scope' => 'read'], $key)->assertOk()->json('access_token');
        $this->postJson(self::ORIGIN . '/oauth/register', $metadata, [
            'Authorization' => 'DPoP ' . $unprivileged, 'DPoP' => $this->proof($key, '/oauth/register', accessToken: $unprivileged),
        ])->assertForbidden()->assertHeader('WWW-Authenticate', 'Bearer, DPoP algs="RS256 ES256", error="insufficient_scope", scope="passport:register"');
    }

    /**
     * Enable the optional endpoint before routes are registered.
     */
    protected function enableRegistration(Application $app): void
    {
        Passport::enableDynamicClientRegistration();
    }

    #[TestWith([false, true])]
    #[TestWith([true, true])]
    #[TestWith([true, false])]
    #[WithConfig('passport.cache', ['enabled' => true, 'store' => 'file', 'ttl' => 300, 'prefix' => 'passport'])]
    public function testWarmCredentialValidationAddsNoDatabaseQueriesAndConsumesEachProofOnlyOnce(bool $proofBound, bool $replayProtection): void
    {
        config(['passport.dpop.replay_protection' => $replayProtection]);
        Passport::tokensCan(['read' => 'Read']);
        Route::get('/cached-proof', function (): array {
            $guard = auth('passport');

            return ['client' => $guard->client()->id, 'token' => $guard->token()->getKey()];
        })->middleware([CheckToken::using('read'), CheckToken::using('read')]);
        $client = ClientFactory::new()->asClientCredentials()->create();
        $key = $proofBound ? JsonWebKeyPair::generate() : null;
        $token = $this->token([
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret, 'scope' => 'read',
        ], $key)->assertOk()->json('access_token');
        $headers = ['Authorization' => ($proofBound ? 'DPoP ' : 'Bearer ') . $token];
        $this->getJson(self::ORIGIN . '/cached-proof', $headers + ($key === null ? [] : ['DPoP' => $this->proof($key, '/cached-proof', 'GET', $token)]))->assertOk();
        $replays = m::mock(ReplayCache::class);
        if ($proofBound && $replayProtection) {
            $replays->shouldReceive('consume')->once()->andReturnTrue();
        } else {
            $replays->shouldNotReceive('consume');
        }
        // DpopProofValidator already holds the resolved service; replace it to use the counted store.
        $this->app->forgetInstance(DpopProofValidator::class);
        $this->app->instance(ReplayCache::class, $replays);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson(self::ORIGIN . '/cached-proof', $headers + ($key === null ? [] : ['DPoP' => $this->proof($key, '/cached-proof', 'GET', $token)]))
            ->assertOk()->assertJsonPath('client', $client->id);
        $this->assertSame([], $queries);
    }

    /**
     * Approve a real browser authorization, including PKCE for public clients.
     */
    protected function code(Client $client, ?JsonWebKeyPair $key = null, ?string $requestUri = null): string
    {
        $this->actingAs(UserFactory::new()->create(), 'web');
        $parameters = [
            'client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => $client->redirect_uris[0], 'prompt' => 'consent',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true)), 'code_challenge_method' => 'S256',
            ...($key === null ? [] : ['dpop_jkt' => $key->thumbprint()]),
            ...($requestUri === null ? [] : ['request_uri' => $requestUri]),
        ];
        $decision = $this->get(self::ORIGIN . '/oauth/authorize?' . http_build_query($parameters))->assertOk()->json('authToken');
        $location = $this->post(self::ORIGIN . '/oauth/authorize/consent', ['auth_token' => $decision])
            ->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $result);

        return $result['code'];
    }

    /**
     * Build code exchange parameters for either kind of client.
     */
    protected function exchangeParameters(Client $client, string $code): array
    {
        return [
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'code' => $code, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => self::VERIFIER,
        ];
    }

    /**
     * Build refresh parameters for either kind of client.
     */
    protected function refreshParameters(Client $client, string $refresh): array
    {
        return [
            'grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'refresh_token' => $refresh,
        ];
    }

    /**
     * Exchange credentials with a fresh proof when requested.
     */
    protected function token(array $parameters, ?JsonWebKeyPair $key = null): TestResponse
    {
        return $this->call('POST', self::ORIGIN . '/oauth/token', $parameters, server: $key === null ? [] : [
            'HTTP_DPOP' => $this->proof($key, '/oauth/token'),
        ]);
    }

    /**
     * Sign a fresh proof with a stable client key.
     */
    protected function proof(JsonWebKeyPair $key, string $path, string $method = 'POST', ?string $accessToken = null): string
    {
        return JWT::encode([
            'jti' => Str::random(32), 'iat' => Date::now()->getTimestamp(), 'htm' => $method, 'htu' => self::ORIGIN . $path,
            ...($accessToken === null ? [] : ['ath' => JWT::urlsafeB64Encode(hash('sha256', $accessToken, true))]),
        ], $key->privateKey, 'ES256', head: ['typ' => 'dpop+jwt', 'jwk' => $key->jwk]);
    }
}
