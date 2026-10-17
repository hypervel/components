<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Request;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class DeviceAuthorizationGrantTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register the available scopes and device views.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::tokensCan([
            'create' => 'Create',
            'read' => 'Read',
            'update' => 'Update',
            'delete' => 'Delete',
        ]);

        Passport::deviceAuthorizationView(fn ($params) => $params);
        Passport::deviceUserCodeView(fn ($params) => $params);
    }

    public function testIssueDeviceCode(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        $json = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $this->assertArrayHasKey('device_code', $json);
        $this->assertArrayHasKey('user_code', $json);
        $this->assertSame(5, $json['interval']);
        $this->assertSame(600, $json['expires_in']);
        $this->assertSame('http://localhost/oauth/device', $json['verification_uri']);
        $this->assertSame('http://localhost/oauth/device?user_code=' . $json['user_code'], $json['verification_uri_complete']);
    }

    public function testVerificationUriUsesEachRequestsHost(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        foreach (['first.example.com', 'second.example.com'] as $host) {
            $json = $this->post('https://' . $host . '/oauth/device/code', [
                'client_id' => $client->getKey(),
            ])->assertOk()->json();

            $this->assertSame('https://' . $host . '/oauth/device', $json['verification_uri']);
            $this->assertSame($json['verification_uri'] . '?user_code=' . $json['user_code'], $json['verification_uri_complete']);
        }
    }

    #[WithConfig('passport.path', 'identity')]
    public function testCustomRoutePathHonorsTrustedProxyHostSchemeAndPrefix(): void
    {
        TrustProxies::at('*');
        Route::get('/oauth-links', static fn (): array => [
            'authorize' => route('passport.authorizations.authorize'),
            'token' => route('passport.token'),
        ]);
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        $this->withHeaders([
            'X-Forwarded-Host' => 'auth.example',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Prefix' => '/edge',
        ]);
        $json = $this->post('/identity/device/code', ['client_id' => $client->getKey()])->assertOk()->json();
        $this->assertSame('https://auth.example/edge/identity/device', $json['verification_uri']);
        $this->assertSame($json['verification_uri'] . '?user_code=' . $json['user_code'], $json['verification_uri_complete']);
        $this->get('http://localhost/oauth-links')->assertExactJson([
            'authorize' => 'https://auth.example/edge/identity/authorize',
            'token' => 'https://auth.example/edge/identity/token',
        ]);

        $this->get('http://localhost/identity/device')->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function testUserCodeAttemptsUseTheApplicationRateLimiter(): void
    {
        $this->app->make(RateLimiter::class)->for(
            'passport',
            static fn (Request $request): Limit => Limit::perMinute(1)->by($request->user()->getAuthIdentifier())
        );
        $this->actingAs(UserFactory::new()->create(), 'web');

        $this->get('/oauth/device/authorize?user_code=BCDFGHJK')->assertRedirectToRoute('passport.device');
        $this->get('/oauth/device/authorize?user_code=LMNPQRST')->assertTooManyRequests();
    }

    public function testRequestAccessTokenAuthorizationPending(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        $json = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $this->assertSame(5, $json['interval']);
        $deviceCode = $json['device_code'];

        $json = $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'device_code' => $deviceCode,
        ])->assertBadRequest()->json();

        $this->assertSame('authorization_pending', $json['error']);
        $this->assertArrayHasKey('error_description', $json);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'device_code' => $deviceCode,
        ])->assertBadRequest()->json();

        $this->assertSame('slow_down', $json['error']);
        $this->assertArrayHasKey('error_description', $json);
    }

    public function testAuthorizationWithoutUserCodeRedirects(): void
    {
        $this->actingAs(UserFactory::new()->create())
            ->get('/oauth/device/authorize')
            ->assertRedirect('/oauth/device')
            ->assertRedirectToRoute('passport.device');
    }

    public function testAuthorizationWithArrayUserCodeRedirects(): void
    {
        $this->actingAs(UserFactory::new()->create())
            ->get('/oauth/device/authorize?user_code[]=ABCD-EFGH')
            ->assertRedirectToRoute('passport.device');
    }

    public function testVerificationUrl(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        [
            'verification_uri' => $verificationUri,
            'verification_uri_complete' => $verificationUriComplete,
            'user_code' => $userCode,
        ] = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $json = $this->get($verificationUri)->assertOk()->json();
        $this->assertEqualsCanonicalizing(['request'], array_keys($json));

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $this->get($verificationUriComplete)
            ->assertRedirect('/oauth/device/authorize?user_code=' . $userCode)
            ->assertRedirectToRoute('passport.device.authorizations.authorize', ['user_code' => $userCode]);

        $this->get('/oauth/device/authorize?user_code=' . Str::substrReplace($userCode, '-', 4, 0))
            ->assertOk()
            ->assertSessionHas('passport.authorizations')
            ->assertSessionHasNoErrors();
    }

    public function testAuthorizationWithInvalidUserCode(): void
    {
        $this->actingAs(UserFactory::new()->create(), 'web');

        $this->get('/oauth/device/authorize?user_code=12345678')
            ->assertRedirectToRoute('passport.device')
            ->assertSessionHasInput('user_code', '12345678')
            ->assertSessionHasErrors(['user_code' => 'Incorrect code.']);

        $this->get('/oauth/device/authorize?user_code=ABCD-EFGH')
            ->assertRedirectToRoute('passport.device')
            ->assertSessionHasInput('user_code', 'ABCD-EFGH')
            ->assertSessionHasErrors(['user_code' => 'Incorrect code.']);
    }

    public function testIssueAccessToken(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
        ] = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $json = $this->get('/oauth/device/authorize?user_code=' . $userCode)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertSessionHas('passport.authorizations')
            ->json();
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        Passport::authorizationView(static fn (array $parameters): array => $parameters);
        $this->get('/oauth/authorize/resume/' . $json['authToken'])->assertForbidden();

        $this->post('/oauth/device/authorize', ['auth_token' => $json['authToken']])
            ->assertRedirectToRoute('passport.device')
            ->assertSessionHas('status', 'authorization-approved')
            ->assertSessionMissing('passport.authorizations.' . $json['authToken']);

        $this->get('/oauth/device/authorize?user_code=' . $userCode)
            ->assertRedirect()
            ->assertSessionHasInput('user_code', $userCode)
            ->assertSessionHasErrors(['user_code' => 'Incorrect code.']);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'device_code' => $deviceCode,
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertEqualsWithDelta(31536000, $json['expires_in'], 2);

        Route::get('/foo', fn (Request $request) => $request->user()->currentAccessToken()->toJson())
            ->middleware('auth:passport');

        $json = $this->withToken($json['access_token'], $json['token_type'])->get('/foo')->json();

        $this->assertSame($client->getKey(), $json['oauth_client_id']);
        $this->assertEquals($user->getAuthIdentifier(), $json['oauth_user_id']);
        $this->assertSame(['create', 'read'], $json['oauth_scopes']);

        $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'device_code' => $deviceCode,
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
    }

    public function testDenyAuthorization(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
        ] = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $authToken = $this->get('/oauth/device/authorize?user_code=' . $userCode)->assertOk()->json('authToken');

        $this->delete('/oauth/device/authorize', ['auth_token' => $authToken])
            ->assertRedirectToRoute('passport.device')
            ->assertSessionHas('status', 'authorization-denied')
            ->assertSessionMissing('passport.authorizations.' . $authToken);

        $this->get('/oauth/device/authorize?user_code=' . $userCode)
            ->assertRedirect()
            ->assertSessionHasInput('user_code', $userCode)
            ->assertSessionHasErrors(['user_code' => 'Incorrect code.']);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'device_code' => $deviceCode,
        ])->assertUnauthorized()->json();

        $this->assertArrayHasKey('error', $json);
        $this->assertArrayHasKey('error_description', $json);
        $this->assertSame('access_denied', $json['error']);
    }

    #[WithConfig('session.serialization', 'json')]
    public function testDeviceCodeSerialization(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();

        ['user_code' => $userCode] = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $json = $this->get('/oauth/device/authorize?user_code=' . $userCode)
            ->assertOk()
            ->assertSessionHas('passport.authorizations')
            ->json();
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        $this->post('/oauth/device/authorize', ['auth_token' => $json['authToken']])
            ->assertRedirectToRoute('passport.device')
            ->assertSessionHas('status', 'authorization-approved')
            ->assertSessionMissing('passport.authorizations.' . $json['authToken']);
    }

    public function testPublicClient(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->asPublic()->create();

        [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
        ] = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'create read',
        ])->assertOk()->json();

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $authToken = $this->get('/oauth/device/authorize?user_code=' . $userCode)->assertOk()->json('authToken');

        $this->post('/oauth/device/authorize', ['auth_token' => $authToken])->assertRedirect();

        $json = $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $client->getKey(),
            'device_code' => $deviceCode,
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertEqualsWithDelta(31536000, $json['expires_in'], 2);
    }

    public function testUnauthorizedClient(): void
    {
        $client = ClientFactory::new()->create();

        $json = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
        ])->assertBadRequest()->json();

        $this->assertSame('unauthorized_client', $json['error']);
        $this->assertArrayHasKey('error_description', $json);
    }

    #[WithConfig('passport.guard', null)]
    #[DataProvider('consentIdentityChanges')]
    public function testConsentCannotBeCompletedByAnotherUserOrProvider(string $method, bool $changeProvider): void
    {
        config([
            'auth.guards.staff' => ['driver' => 'session', 'provider' => 'staff'],
            'auth.providers.staff' => config('auth.providers.users'),
        ]);
        $client = ClientFactory::new()->asDeviceCodeClient()->create();
        $userCode = $this->post('/oauth/device/code', ['client_id' => $client->getKey()])->assertOk()->json('user_code');
        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $authToken = $this->get('/oauth/device/authorize?user_code=' . $userCode)->assertOk()->json('authToken');

        $this->actingAs($changeProvider ? $user : UserFactory::new()->create(), $changeProvider ? 'staff' : 'web');
        $this->{$method}('/oauth/device/authorize', ['auth_token' => $authToken])
            ->assertForbidden()
            ->assertSessionMissing('passport.authorizations.' . $authToken);
        $this->assertDatabaseHas('oauth_device_codes', ['user_code' => $userCode, 'user_id' => null]);
    }

    /**
     * Provide approval and denial after each identity change.
     */
    public static function consentIdentityChanges(): iterable
    {
        foreach (['post', 'delete'] as $method) {
            yield $method . ' user' => [$method, false];
            yield $method . ' provider' => [$method, true];
        }
    }

    #[DataProvider('changedClients')]
    public function testConsentRechecksTheClientBeforeCompleting(string $method, string $change, string $error): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();
        $userCode = $this->post('/oauth/device/code', [
            'client_id' => $client->getKey(),
            'scope' => 'read',
        ])->assertOk()->json('user_code');
        $this->actingAs(UserFactory::new()->create(), 'web');
        $authToken = $this->get('/oauth/device/authorize?user_code=' . $userCode)->assertOk()->json('authToken');

        match ($change) {
            'revoked' => $client->revoke(),
            'grant' => $client->forceFill(['grant_types' => []])->save(),
            'provider' => $client->forceFill(['provider' => 'staff'])->save(),
            'scope' => Passport::tokensCan([]),
        };

        $this->{$method}('/oauth/device/authorize', ['auth_token' => $authToken])
            ->assertBadRequest()
            ->assertHeaderMissing('Location')
            ->assertJsonPath('error', $error);
        $this->assertDatabaseHas('oauth_device_codes', ['user_code' => $userCode, 'user_id' => null]);
    }

    /**
     * Provide client changes that invalidate an open consent screen.
     */
    public static function changedClients(): iterable
    {
        foreach (['post', 'delete'] as $method) {
            yield $method . ' revoked' => [$method, 'revoked', 'unauthorized_client'];
            yield $method . ' grant' => [$method, 'grant', 'unauthorized_client'];
            yield $method . ' provider' => [$method, 'provider', 'unauthorized_client'];
            yield $method . ' scope' => [$method, 'scope', 'invalid_scope'];
        }
    }

    public function testAClientWithAProviderRejectsUsersOfAnotherProvider(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create(['provider' => 'staff']);
        $userCode = $this->post('/oauth/device/code', ['client_id' => $client->getKey()])->assertOk()->json('user_code');
        $this->actingAs(UserFactory::new()->create(), 'web');

        $this->get('/oauth/device/authorize?user_code=' . $userCode)
            ->assertRedirectToRoute('passport.device')
            ->assertSessionHasErrors(['user_code' => 'Incorrect code.']);
        $this->assertDatabaseHas('oauth_device_codes', ['user_code' => $userCode, 'user_id' => null]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testExpiredOrPreviouslyApprovedDeviceCodesCannotBeApproved(bool $approved): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();
        $userCode = $this->post('/oauth/device/code', ['client_id' => $client->getKey()])->assertOk()->json('user_code');
        $this->actingAs($user = UserFactory::new()->create(), 'web');
        $authToken = $this->get('/oauth/device/authorize?user_code=' . $userCode)->assertOk()->json('authToken');
        $code = Passport::deviceCode()->newQuery()->where('user_code', $userCode)->firstOrFail();
        $code->forceFill($approved
            ? ['user_id' => $user->getKey(), 'user_approved_at' => now()]
            : ['expires_at' => now()->subSecond()])->save();

        $this->post('/oauth/device/authorize', ['auth_token' => $authToken])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->assertSame($approved ? $user->getKey() : null, $code->fresh()->user_id);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }
}
