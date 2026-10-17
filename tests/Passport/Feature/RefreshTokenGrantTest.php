<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Encoding;
use Defuse\Crypto\Key;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use Mockery as m;

use function Hypervel\Testbench\artisan;

class RefreshTokenGrantTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register scopes and the authorization view.
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

    public function testRefreshingToken(): void
    {
        $client = ClientFactory::new()->create();

        $oldToken = $this->getNewAccessToken($client);

        $key = Key::loadFromAsciiSafeString(Encoding::saveBytesToChecksummedAsciiSafeString(
            Key::KEY_CURRENT_VERSION,
            hash_hkdf('sha256', $this->app->make('encrypter')->getKey(), Key::KEY_BYTE_SIZE, 'hypervel-passport'),
        ));
        $decrypted = json_decode(Crypto::decrypt($oldToken['refresh_token'], $key), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($client->getKey(), $decrypted['client_id']);

        $newToken = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
            'scope' => 'read delete',
        ])->assertOK()->json();

        $this->assertArrayHasKey('access_token', $newToken);
        $this->assertArrayHasKey('refresh_token', $newToken);
        $this->assertEqualsWithDelta(31536000, $newToken['expires_in'], 2);
        $this->assertSame('Bearer', $newToken['token_type']);

        Route::get('/foo', fn (Request $request) => $request->user()->currentAccessToken()->toJson())
            ->middleware('auth:passport');

        $this->getJson('/foo', [
            'Authorization' => $oldToken['token_type'] . ' ' . $oldToken['access_token'],
        ])->assertUnauthorized();

        $json = $this->getJson('/foo', [
            'Authorization' => $newToken['token_type'] . ' ' . $newToken['access_token'],
        ])->assertOk()->json();

        $this->assertSame(['read', 'delete'], $json['oauth_scopes']);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
        ])->assertStatus(400)->json();

        $this->assertSame('invalid_grant', $json['error']);
        $this->assertSame('The refresh token is invalid.', $json['error_description']);
        $this->assertSame('Token has been revoked', $json['hint']);

        $this->withToken($newToken['access_token'])->getJson('/foo')->assertUnauthorized();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $newToken['refresh_token'],
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertSame(0, Passport::refreshToken()->newQuery()->where('revoked', false)->count());
        $this->assertSame(1, Passport::refreshTokenFamily()->newQuery()->whereNotNull('revoked_at')->count());
    }

    #[WithConfig('passport.cache', ['enabled' => true, 'store' => 'file', 'ttl' => 300, 'prefix' => 'passport'])]
    public function testRefreshReturnsUsableTokensWhenInvalidationFailsAfterCommit(): void
    {
        $coordinator = m::mock(ModelCacheCoordinator::class, [$this->app])->makePartial();
        $this->app->instance(ModelCacheCoordinator::class, $coordinator);
        $client = ClientFactory::new()->create();
        $oldToken = $this->getNewAccessToken($client);
        $oldRefresh = Passport::refreshToken()->newQuery()->sole();
        $failure = new LockTimeoutException;
        $coordinator->shouldReceive('invalidate')->once()
            ->with($this->app->make('cache')->store('file'), 'passport:tokens:' . $oldRefresh->access_token_id)
            ->andThrow($failure);
        $coordinator->shouldReceive('invalidate')->withAnyArgs()->passthru()->byDefault();
        Exceptions::fake();

        $newToken = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
        ])->assertOk()->json();

        Exceptions::assertReported(fn (LockTimeoutException $exception): bool => $exception === $failure);
        Exceptions::assertReportedCount(1);
        $this->assertTrue($oldRefresh->fresh()->revoked);
        Route::get('/refresh-user', fn (Request $request) => $request->user()->getAuthIdentifier())
            ->middleware('auth:passport');
        $this->withToken($newToken['access_token'])->getJson('/refresh-user')->assertOk();

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $newToken['refresh_token'],
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        $this->assertNull(Passport::refreshTokenFamily()->newQuery()->sole()->revoked_at);
        Exceptions::assertReportedCount(1);
    }

    public function testRefreshingTokenWithoutRevoking(): void
    {
        Passport::$revokeRefreshTokenAfterUse = false;

        $client = ClientFactory::new()->create();

        $oldToken = $this->getNewAccessToken($client);
        $originalRefresh = Passport::refreshToken()->newQuery()->sole();

        $newToken = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
            'scope' => 'read delete',
        ])->assertOK()->json();

        $this->assertArrayHasKey('access_token', $newToken);
        $this->assertArrayHasKey('refresh_token', $newToken);
        $this->assertEqualsWithDelta(31536000, $newToken['expires_in'], 2);
        $this->assertSame('Bearer', $newToken['token_type']);

        Route::get('/foo', fn (Request $request) => $request->user()->currentAccessToken()->toJson())
            ->middleware('auth:passport');

        $this->getJson('/foo', [
            'Authorization' => $oldToken['token_type'] . ' ' . $oldToken['access_token'],
        ])->assertUnauthorized();

        $json = $this->getJson('/foo', [
            'Authorization' => $newToken['token_type'] . ' ' . $newToken['access_token'],
        ])->assertOk();

        $this->assertSame(['read', 'delete'], $json['oauth_scopes']);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayHasKey('refresh_token', $json);
        $this->assertEqualsWithDelta(31536000, $json['expires_in'], 2);
        $this->assertSame('Bearer', $json['token_type']);

        $originalRefresh->revoke();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->withToken($newToken['access_token'])->getJson('/foo')->assertUnauthorized();
        $this->withToken($json['access_token'])->getJson('/foo')->assertUnauthorized();
        $this->assertSame(3, Passport::refreshToken()->newQuery()->where('revoked', true)->count());
        $this->assertNotNull(Passport::refreshTokenFamily()->newQuery()->sole()->revoked_at);
    }

    public function testRefreshTokenCannotBeUsedAfterUserTokensAreRevokedAfterPurge(): void
    {
        $client = ClientFactory::new()->create();

        $oldToken = $this->getNewAccessToken($client);
        $user = auth('web')->user();
        $accessToken = Passport::token()->where('user_id', $user->getAuthIdentifier())->firstOrFail();
        $refreshToken = $accessToken->refreshToken;

        $accessToken->forceFill(['expires_at' => now()->subDays(8)])->save();
        $refreshToken->forceFill(['expires_at' => now()->addDays(30)])->save();

        artisan($this, 'passport:purge');

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $accessToken->getKey()]);
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'id' => $refreshToken->getKey(),
            'revoked' => false,
        ]);

        $user->tokens()->each(function ($token): void {
            $token->revoke();
            $token->refreshToken->revoke();
        });

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
        ])->assertStatus(400);
    }

    public function testRefreshingTokenWithAdditionalScopes(): void
    {
        $client = ClientFactory::new()->create();

        $oldToken = $this->getNewAccessToken($client);

        $json = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $oldToken['refresh_token'],
            'scope' => 'create update',
        ])->assertStatus(400)->json();

        $this->assertSame('invalid_scope', $json['error']);
        $this->assertSame('The requested scope is invalid, unknown, or malformed', $json['error_description']);
        $this->assertSame('Check the `update` scope', $json['hint']);
    }

    /**
     * Authorize a user and exchange the code for tokens.
     *
     * @return array<string, mixed>
     */
    private function getNewAccessToken(Client $client): array
    {
        $this->actingAs(UserFactory::new()->create(), 'web');

        $authToken = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read delete',
        ]))->assertOk()->json('authToken');

        $redirectUrl = $this->post('/oauth/authorize/consent', ['auth_token' => $authToken])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($redirectUrl, PHP_URL_QUERY), $params);

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $redirect,
            'code' => $params['code'],
        ])->assertOK()->json();
    }
}
