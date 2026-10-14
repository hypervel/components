<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
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
            'state' => $state = Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertOk();
        $response->assertSessionHas('authRequest');
        $response->assertSessionHas('authToken');
        $json = $response->json();
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        $response = $this->post('/oauth/authorize', ['auth_token' => $json['authToken']]);
        $response->assertRedirect();
        $response->assertSessionMissing(['authRequest', 'authToken']);

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

        $response = $this->delete('/oauth/authorize', ['auth_token' => $json['authToken']]);
        $response->assertRedirect();
        $response->assertSessionMissing(['authRequest', 'authToken']);

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

        $response = $this->post('/oauth/authorize', ['auth_token' => $json['authToken']]);
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

    public function testSkipsAuthorizationWhenHasActiveTokensAndEmptyScope(): void
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

        $response = $this->post('/oauth/authorize', ['auth_token' => $json['authToken']]);
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

        $response = $this->post('/oauth/authorize', ['auth_token' => $json['authToken']]);
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
        $response->assertSessionHas('authRequest');
        $response->assertSessionHas('authToken');
        $json = $response->json();
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'update']))->toArray(), $json['scopes']);
    }

    #[WithConfig('session.serialization', 'json')]
    public function testAuthRequestSerialization(): void
    {
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
            ->assertSessionHas('authRequest')
            ->assertSessionHas('authToken')
            ->json();

        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        $this->post('/oauth/authorize', ['auth_token' => $json['authToken']])
            ->assertRedirect()
            ->assertSessionMissing(['authRequest', 'authToken']);
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
        $response->assertSessionHas('promptedForLogin', true);
        $response->assertRedirectToRoute('login');
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

        $response = $this->post('/oauth/authorize', ['auth_token' => $json['authToken']]);
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
        $response->assertSessionHas('authRequest');
        $response->assertSessionHas('authToken');
        $json = $response->json();
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'request', 'authToken'], array_keys($json));
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);
    }

    public function testPromptLogin(): void
    {
        Route::get('/foo', fn () => '')->name('login');

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

        $response->assertSessionHas('promptedForLogin', true);
        $response->assertRedirectToRoute('login');
        $this->assertGuest('web');
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
        $response->assertSessionMissing(['authRequest', 'authToken']);

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertArrayHasKey('code', $params);
    }

    public function testAuthRequestMustBePresentInSession(): void
    {
        $this->actingAs(UserFactory::new()->create(), 'web');

        $this->withoutExceptionHandling();
        $this->expectExceptionMessage('Authorization request was not present in the session.');

        $this->withSession(['authToken' => 'token'])
            ->post('/oauth/authorize', ['auth_token' => 'token']);
    }

    public function testPromptLoginConsent(): void
    {
        Route::get('/foo', fn () => '')->name('login');

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

        $response = $this->post('/oauth/authorize', ['auth_token' => $json['authToken']]);
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
            ->assertSessionHas('promptedForLogin', true)
            ->assertRedirectToRoute('login');

        $intendedUrl = session()->get('url.intended');
        parse_str(parse_url($intendedUrl, PHP_URL_QUERY), $params);

        $this->actingAs($user, 'web');
        $json = $this->get($intendedUrl)
            ->assertOk()
            ->assertSessionHas('authRequest')
            ->assertSessionHas('authToken')
            ->json();

        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'request', 'authToken'], array_keys($json));
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
        $this->assertSame('consent_required', $params['error']);
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

        $json = $this->get('/oauth/authorize?' . $query)
            ->assertBadRequest()
            ->assertSessionMissing(['authRequest', 'authToken'])
            ->json();

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

        $response = $this->post('/oauth/authorize', ['auth_token' => $authToken])->assertRedirect();
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
        $this->{$method}('/oauth/authorize', ['auth_token' => $authToken])
            ->assertForbidden()
            ->assertSessionMissing(['authRequest', 'authToken', 'authAttributes']);
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

        $this->{$method}('/oauth/authorize', ['auth_token' => $authToken])
            ->assertBadRequest()
            ->assertHeaderMissing('Location')
            ->assertJsonPath('error', $error);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    /**
     * Provide client changes that invalidate an open consent screen.
     */
    public static function changedClients(): iterable
    {
        foreach (['post', 'delete'] as $method) {
            yield $method . ' revoked' => [$method, 'revoked', 'unauthorized_client'];
            yield $method . ' redirect' => [$method, 'redirect', 'invalid_request'];
            yield $method . ' grant' => [$method, 'grant', 'unauthorized_client'];
            yield $method . ' provider' => [$method, 'provider', 'unauthorized_client'];
            yield $method . ' scope' => [$method, 'scope', 'invalid_scope'];
        }
    }

    public function testAClientWithAProviderRejectsUsersOfAnotherProvider(): void
    {
        $client = ClientFactory::new()->create(['provider' => 'staff']);
        $this->actingAs(UserFactory::new()->create(), 'web');

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
    public function testAdditionalPromptsRespectSilentAuthorization(string $prompt, string $error): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        Passport::resolvePromptsUsing(function (Request $request, AuthorizationRequestInterface $authorization) use ($client, $prompt): array {
            $this->assertSame('none', $request->query('prompt'));
            $this->assertSame($client->getKey(), $authorization->getClient()->getIdentifier());

            return [$prompt];
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

    public function testAnAdditionalLoginPromptRequiresLoginWithoutLooping(): void
    {
        Route::get('/login', fn (): string => 'Login')->name('login');
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        Passport::resolvePromptsUsing(static fn (): array => ['login']);
        $url = '/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
        ]);

        $this->get($url)->assertRedirectToRoute('login')->assertSessionHas('promptedForLogin', true);
        $this->assertGuest('web');

        $this->actingAs($user, 'web');
        $this->get($url)->assertOk()->assertSessionMissing('promptedForLogin')->assertSessionHas('authRequest');
    }

    public function testAnAdditionalConsentPromptPreventsAutomaticApproval(): void
    {
        Passport::useClientModel(AuthorizationCodeGrantTestSkipsAuthorizationClient::class);
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        Passport::resolvePromptsUsing(static fn (): array => ['consent']);

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
        ]))->assertOk()->assertSessionHas('authRequest')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testPromptValidationErrorsBecomeOAuthResponses(): void
    {
        $client = ClientFactory::new()->create();
        Passport::resolvePromptsUsing(static fn (): never => throw OAuthServerException::invalidRequest('max_age'));

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
        ]))->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testConsentOnlyReusesScopesGrantedByTheSameUserProvider(): void
    {
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create();
        $token = Passport::token()->forceCreate([
            'id' => Str::random(80),
            'client_id' => $client->getKey(),
            'user_id' => $user->getKey(),
            'provider' => 'staff',
            'scopes' => ['read'],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
        $this->actingAs($user, 'web');
        $url = '/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
            'scope' => 'read',
        ]);

        $this->get($url)->assertOk()->assertSessionHas('authRequest');
        $this->assertDatabaseCount('oauth_auth_codes', 0);

        $token->forceFill(['provider' => 'users'])->save();
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
        $response = $this->post('/oauth/authorize', ['auth_token' => $authToken])->assertRedirect();

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
