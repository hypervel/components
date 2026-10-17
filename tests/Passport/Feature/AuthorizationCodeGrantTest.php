<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Consent;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Events\AuthorizationRequestValidated;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class AuthorizationCodeGrantTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register the available scopes and authorization view.
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

        Passport::authorizationView(fn ($params) => $params);
    }

    public function testIssueAccessToken(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => $state = ' original state ',
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertOk();
        $json = $response->json();
        $response->assertSessionHas('passport.authorizations.' . $json['authToken']);
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        $response->assertRedirect();
        $response->assertSessionMissing('passport.authorizations.' . $json['authToken']);

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertArrayHasKey('code', $params);

        $response = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ]);

        $response->assertOk();
        $json = $response->json();
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
    }

    public function testDenyAuthorization(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => '',
            'state' => $state = Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $json = $this->get('/oauth/authorize?' . $query)->json();

        $response = $this->delete('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        $response->assertRedirect();
        $response->assertSessionMissing('passport.authorizations.' . $json['authToken']);

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertSame('access_denied', $params['error']);
        $this->assertArrayHasKey('error_description', $params);
    }

    public function testSkipsAuthorizationWhenHasGrantedScopes(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read update',
            'state' => Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $json = $this->get('/oauth/authorize?' . $query)->json();

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ])->assertOk();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => $state = Str::random(40),
        ]);

        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertArrayHasKey('code', $params);
    }

    public function testSkipsAuthorizationWhenHasRememberedConsentAndEmptyScope(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => '',
            'state' => Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $json = $this->get('/oauth/authorize?' . $query)->json();

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ])->assertOk();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => '',
            'state' => $state = Str::random(40),
        ]);

        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertArrayHasKey('code', $params);
    }

    public function testPromptConsentForNewScope(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $json = $this->get('/oauth/authorize?' . $query)->json();

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ])->assertOk();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'create update',
            'state' => $state = Str::random(40),
        ]);

        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertOk();
        $json = $response->json();
        $response->assertSessionHas('passport.authorizations.' . $json['authToken']);
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'update']))->toArray(), $json['scopes']);
    }

    #[WithConfig('session.serialization', 'json')]
    #[TestWith([false])]
    #[TestWith([true])]
    public function testAuthRequestSerialization(bool $relative): void
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(23, 59));
        Passport::rememberConsentFor($relative ? DateInterval::createFromDateString('next weekday') : now()->addDays(30));
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => Str::random(40),
        ]);

        $this->actingAs(UserFactory::new()->create(), 'web');

        $json = $this->get('/oauth/authorize?' . $query)
            ->assertOk()
            ->assertSessionHas('passport.authorizations')
            ->json();

        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        $this->travel(2)->minutes();
        $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']])
            ->assertRedirect()
            ->assertSessionMissing('passport.authorizations.' . $json['authToken']);
        $this->assertTrue(Consent::sole()->remembered_until->equalTo(now()->add(Passport::rememberConsentFor())));
    }

    public function testValidateAuthorizationRequest(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => fake()->url(),
            'response_type' => 'code',
            'scope' => '',
            'state' => Str::random(40),
        ]);

        $json = $this->get('/oauth/authorize?' . $query)->json();
        $this->assertSame('invalid_client', $json['error']);
        $this->assertArrayHasKey('error_description', $json);
    }

    public function testValidateScopes(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'foo',
            'state' => $state = Str::random(40),
        ]);

        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertSame('invalid_scope', $params['error']);
        $this->assertArrayHasKey('error_description', $params);
    }

    public function testRedirectGuestUser(): void
    {
        Route::get('/foo', fn () => '')->name('login');

        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
        ]);

        $response = $this->get('/oauth/authorize?' . $query);
        $resumeUrl = $response->assertRedirect()->headers->get('Location');
        $this->get($resumeUrl)->assertRedirectToRoute('login')->assertSessionHas('url.intended', $resumeUrl);
    }

    public function testPromptNone(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'state' => $state = Str::random(40),
            'prompt' => 'none',
        ]);

        $this->actingAs(UserFactory::new()->create(), 'web');
        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertSame('consent_required', $params['error']);
        $this->assertArrayHasKey('error_description', $params);
    }

    public function testPromptNoneLoginRequired(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'state' => $state = Str::random(40),
            'prompt' => 'none',
        ]);

        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertSame('login_required', $params['error']);
        $this->assertArrayHasKey('error_description', $params);
    }

    #[TestWith(['consent'])]
    #[TestWith(["\tconsent\t"])]
    public function testPromptConsent(string $prompt): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read update',
            'state' => Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $json = $this->get('/oauth/authorize?' . $query)->json();

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ]);

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => Str::random(40),
            'prompt' => $prompt,
        ]);

        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertOk();
        $json = $response->json();
        $response->assertSessionHas('passport.authorizations.' . $json['authToken']);
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);
    }

    public function testPromptLogin(): void
    {
        Route::get('/confirm', fn () => '')->name('password.confirm');

        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read update',
            'state' => Str::random(40),
            'prompt' => 'login',
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertRedirectToRoute('password.confirm');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function testSkipsAuthorizationWhenClientSkipsAuthorization(): void
    {
        Passport::useClientModel(AuthorizationCodeGrantTestSkipsAuthorizationClient::class);
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => $state = Str::random(40),
        ]);

        $this->actingAs(UserFactory::new()->create(), 'web');

        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();
        $response->assertSessionMissing('passport.authorizations');

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertArrayHasKey('code', $params);
    }

    public function testAuthRequestMustBePresentInSession(): void
    {
        $this->actingAs(UserFactory::new()->create(), 'web');

        $this->post('/oauth/authorize/consent', ['auth_token' => 'token'])->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testPromptLoginConsent(): void
    {
        Route::get('/confirm', fn () => '')->name('password.confirm');

        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read update',
            'state' => Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $json = $this->get('/oauth/authorize?' . $query)->json();

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ]);

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => Str::random(40),
            'prompt' => 'login consent',
        ]);

        $this->get('/oauth/authorize?' . $query)
            ->assertRedirectToRoute('password.confirm');

        $intendedUrl = session()->get('url.intended');
        $json = $this->withSession([PasswordConfirmation::sessionKey('web') => now()->getTimestamp()])->get($intendedUrl)
            ->assertOk()
            ->assertSessionHas('passport.authorizations')
            ->json();

        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);
    }

    public function testPromptContainsNone(): void
    {
        $client = ClientFactory::new()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'state' => $state = Str::random(40),
            'prompt' => 'none login consent select_account',
        ]);

        $this->actingAs(UserFactory::new()->create(), 'web');
        $response = $this->get('/oauth/authorize?' . $query);
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertSame('invalid_request', $params['error']);
        $this->assertArrayHasKey('error_description', $params);
    }

    public function testUnauthorizedClient(): void
    {
        $client = ClientFactory::new()->create([
            'grant_types' => [],
        ]);

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $response = $this->get('/oauth/authorize?' . $query)
            ->assertRedirect()
            ->assertSessionMissing('passport.authorizations');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $json);

        $this->assertSame('unauthorized_client', $json['error']);
        $this->assertSame(
            'The authenticated client is not authorized to use this authorization grant type.',
            $json['error_description']
        );
    }

    public function testIssueAccessTokenWithoutRefreshToken(): void
    {
        $client = ClientFactory::new()->create([
            'grant_types' => ['authorization_code'],
        ]);

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $authToken = $this->get('/oauth/authorize?' . $query)
            ->assertOk()
            ->json('authToken');

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $authToken])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayNotHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertArrayHasKey('expires_in', $json);
    }

    #[WithConfig('passport.guard', null)]
    #[DataProvider('consentIdentityChanges')]
    public function testConsentCannotBeCompletedByAnotherUserOrProvider(string $method, bool $changeProvider): void
    {
        config([
            'auth.guards.staff' => ['driver' => 'session', 'provider' => 'staff'],
            'auth.providers.staff' => config('auth.providers.users'),
        ]);
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $authToken = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
        ]))->assertOk()->json('authToken');

        $this->actingAs($changeProvider ? $user : UserFactory::new()->create(), $changeProvider ? 'staff' : 'web');
        $this->{$method}('/oauth/authorize/consent', ['auth_token' => $authToken])
            ->assertForbidden()
            ->assertSessionMissing('passport.authorizations.' . $authToken);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
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
        $client = ClientFactory::new()->create(['redirect_uris' => ['https://client.example/callback']]);
        $this->actingAs(UserFactory::new()->create(), 'web');
        $authToken = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
            'scope' => 'read',
        ]))->assertOk()->json('authToken');

        match ($change) {
            'revoked' => $client->revoke(),
            'redirect' => $client->forceFill(['redirect_uris' => ['https://other.example/callback']])->save(),
            'grant' => $client->forceFill(['grant_types' => []])->save(),
            'provider' => $client->forceFill(['provider' => 'staff'])->save(),
            'scope' => Passport::tokensCan([]),
        };

        $response = $this->{$method}('/oauth/authorize/consent', ['auth_token' => $authToken]);

        if (in_array($change, ['revoked', 'redirect'], true)) {
            $response->assertUnauthorized()->assertHeaderMissing('Location')->assertJsonPath('error', $error);
        } else {
            $response->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
            $this->assertSame($error, $parameters['error']);
        }
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    /**
     * Provide client changes that invalidate an open consent screen.
     */
    public static function changedClients(): iterable
    {
        foreach (['post', 'delete'] as $method) {
            yield $method . ' revoked' => [$method, 'revoked', 'invalid_client'];
            yield $method . ' redirect' => [$method, 'redirect', 'invalid_client'];
            yield $method . ' grant' => [$method, 'grant', 'unauthorized_client'];
            yield $method . ' provider' => [$method, 'provider', 'unauthorized_client'];
            yield $method . ' scope' => [$method, 'scope', 'invalid_scope'];
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAClientWithAProviderRejectsUsersOfAnotherProvider(bool $authenticated): void
    {
        $client = ClientFactory::new()->create(['provider' => 'staff']);

        if ($authenticated) {
            $this->actingAs(UserFactory::new()->create(), 'web');
        }

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
        ]))->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testArrayPromptIsRejectedWithoutReportingAnError(): void
    {
        Exceptions::fake();
        $client = ClientFactory::new()->create();

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'prompt' => ['login'],
        ]))->assertBadRequest()->assertJsonPath('error', 'invalid_request');

        Exceptions::assertNothingReported();
    }

    #[TestWith(['login', 'login_required'])]
    #[TestWith(['consent', 'consent_required'])]
    #[TestWith(['select_account', 'account_selection_required'])]
    public function testAdditionalPromptsRespectSilentAuthorization(string $prompt, string $error): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        Event::listen(AuthorizationRequestValidated::class, function (AuthorizationRequestValidated $event) use ($client, $prompt): void {
            $this->assertSame('none', $event->parameters['prompt']);
            $this->assertSame($client->getKey(), $event->authorizationRequest->getClient()->getIdentifier());
            $event->prompts[] = $prompt;
        });

        $response = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'prompt' => 'none',
            'state' => 'original-state',
        ]))->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame($error, $parameters['error']);
        $this->assertSame('original-state', $parameters['state']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    public function testAccountSelectionWithoutARegisteredViewReturnsAProtocolError(bool $viewPrefix, bool $authenticated): void
    {
        if ($viewPrefix) {
            Passport::viewPrefix('auth');
        }

        $client = ClientFactory::new()->create();

        if ($authenticated) {
            $this->actingAs(UserFactory::new()->create(), 'web');
        }

        $response = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code',
            'prompt' => $authenticated ? 'login select_account' : 'select_account', 'state' => 'original-state',
        ]))->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('account_selection_required', $parameters['error']);
        $this->assertSame('original-state', $parameters['state']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testAnAdditionalLoginPromptRequiresLoginWithoutLooping(): void
    {
        Route::get('/confirm', fn (): string => 'Confirm')->name('password.confirm');
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        Event::listen(AuthorizationRequestValidated::class, static function (AuthorizationRequestValidated $event): void {
            $event->prompts[] = 'login';
        });
        $url = '/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
        ]);

        $this->get($url)->assertRedirectToRoute('password.confirm');
        $this->assertAuthenticatedAs($user, 'web');
        $resumeUrl = session()->get('url.intended');
        $this->withSession([PasswordConfirmation::sessionKey('web') => now()->getTimestamp()])
            ->get($resumeUrl)->assertOk()->assertSessionHas('passport.authorizations');
    }

    public function testAnAdditionalConsentPromptPreventsAutomaticApproval(): void
    {
        Passport::useClientModel(AuthorizationCodeGrantTestSkipsAuthorizationClient::class);
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        Event::listen(AuthorizationRequestValidated::class, static function (AuthorizationRequestValidated $event): void {
            $event->prompts[] = 'consent';
        });

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
        ]))->assertOk()->assertSessionHas('passport.authorizations')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testPromptValidationErrorsBecomeOAuthResponses(): void
    {
        $client = ClientFactory::new()->create();
        Event::listen(AuthorizationRequestValidated::class, static fn (): never => throw OAuthServerException::invalidRequest('max_age'));

        $response = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
        ]))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('invalid_request', $parameters['error']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testConsentOnlyReusesScopesGrantedByTheSameUserProvider(): void
    {
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create();
        $consent = Consent::factory()->create([
            'client_id' => $client->getKey(),
            'user_id' => $user->getKey(),
            'provider' => 'staff',
            'scopes' => ['read'],
            'remembered_until' => now()->addHour(),
        ]);
        $this->actingAs($user, 'web');
        $url = '/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
            'scope' => 'read',
        ]);

        $this->get($url)->assertOk()->assertSessionHas('passport.authorizations');
        $this->assertDatabaseCount('oauth_auth_codes', 0);

        $consent->forceFill(['provider' => 'users'])->save();
        $this->get($url)->assertRedirect();
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }

    #[TestWith(['https://client.example/callback', null, 'https://client.example/callback'])]
    #[TestWith(['http://127.0.0.1/callback', 'http://127.0.0.1:54321/callback', 'http://127.0.0.1:54321/callback'])]
    #[TestWith(['http://[::1]/callback', 'http://[::1]:54321/callback', 'http://[::1]:54321/callback'])]
    public function testConsentRevalidationPreservesDefaultAndLoopbackRedirects(string $registered, ?string $requested, string $destination): void
    {
        $client = ClientFactory::new()->create(['redirect_uris' => [$registered]]);
        $this->actingAs(UserFactory::new()->create(), 'web');
        $query = ['client_id' => $client->getKey(), 'response_type' => 'code'];

        if ($requested !== null) {
            $query['redirect_uri'] = $requested;
        }

        $authToken = $this->get('/oauth/authorize?' . http_build_query($query))->assertOk()->json('authToken');
        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $authToken])->assertRedirect();

        $this->assertStringStartsWith($destination . '?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertArrayHasKey('code', $parameters);
        $this->assertArrayNotHasKey('error', $parameters);
    }
}

class AuthorizationCodeGrantTestSkipsAuthorizationClient extends Client
{
    protected ?string $table = 'oauth_clients';

    /**
     * Approve requests without displaying consent.
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}
