<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Context\RequestContext;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Hypervel\Passport\Passport;
use Hypervel\Sanctum\HasApiTokens as HasSanctumTokens;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\TestWith;

class TokenGuardTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register routes that exercise the guard's public methods.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/guard', fn () => [
            'user' => auth('passport')->user()?->getKey(),
            'token' => auth('passport')->user()?->currentAccessToken()?->oauth_access_token_id,
            'client' => auth('passport')->client()?->getKey(),
        ]);

        Route::get('/guard-repeated', function () {
            $client = auth('passport')->client();

            DB::enableQueryLog();
            $sameClient = $client === auth('passport')->client();
            $clientQueries = count(DB::getQueryLog());

            $user = auth('passport')->user();

            DB::flushQueryLog();
            $sameUser = $user === auth('passport')->user();
            $userQueries = count(DB::getQueryLog());

            return [
                'same_client' => $sameClient,
                'client_queries' => $clientQueries,
                'same_user' => $sameUser,
                'user_queries' => $userQueries,
            ];
        });
    }

    public function testUserCanBePulledViaBearerToken(): void
    {
        $user = UserFactory::new()->create();
        $client = ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $result = $user->createToken('test');

        $this->withToken($result->accessToken)
            ->getJson('/guard')
            ->assertExactJson([
                'user' => $user->getKey(),
                'token' => $result->accessTokenId,
                'client' => $client->getKey(),
            ]);
    }

    public function testUserAndClientAreResolvedOnlyOnce(): void
    {
        $user = UserFactory::new()->create();
        ClientFactory::new()->asPersonalAccessTokenClient()->create();

        $this->withToken($user->createToken('test')->accessToken)
            ->getJson('/guard-repeated')
            ->assertExactJson([
                'same_client' => true,
                'client_queries' => 0,
                'same_user' => true,
                'user_queries' => 0,
            ]);
    }

    public function testNothingIsReturnedWhenOauthThrowsException(): void
    {
        Exceptions::fake();

        $this->withToken('invalid')
            ->getJson('/guard')
            ->assertJson(['user' => null, 'client' => null]);

        Exceptions::assertNothingReported();
    }

    public function testNullIsReturnedIfNoUserIsFound(): void
    {
        $user = UserFactory::new()->create();
        ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $token = $user->createToken('test')->accessToken;

        $user->delete();

        $this->withToken($token)
            ->getJson('/guard')
            ->assertJsonPath('user', null);
    }

    public function testNullIsReturnedForClientCredentialsToken(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->create();

        $token = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->json('access_token');

        $this->withToken($token)
            ->getJson('/guard')
            ->assertJsonPath('user', null)
            ->assertJsonPath('client', $client->getKey());
    }

    public function testNullIsReturnedIfNoClientIsFound(): void
    {
        $user = UserFactory::new()->create();
        $client = ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $token = $user->createToken('test')->accessToken;

        $client->forceFill(['revoked' => true])->save();

        $this->withToken($token)
            ->getJson('/guard')
            ->assertJson(['user' => null, 'client' => null]);
    }

    public function testNullProviderClientsDoNotAllowTokensToCrossUserProviders(): void
    {
        config([
            'auth.guards.passport-admins' => ['driver' => 'passport', 'provider' => 'admins'],
            'auth.providers.admins' => config('auth.providers.users'),
        ]);
        $user = UserFactory::new()->create();
        ClientFactory::new()->asPersonalAccessTokenClient()->create(['provider' => null]);
        $result = $user->createToken('user token');

        Route::get('/admins', fn (Request $request) => $request->user()->getKey())->middleware('auth:passport-admins');
        Route::get('/either', fn (Request $request) => $request->user()->getKey())->middleware('auth:passport-admins,passport');

        $this->assertSame('users', $result->getToken()->provider);
        $this->withToken($result->accessToken)->getJson('/admins')->assertUnauthorized();
        $this->getJson('/either')->assertOk()->assertSee((string) $user->getKey());
    }

    public function testExplicitValidationRechecksRevocationWithoutModifyingTheRequest(): void
    {
        $user = UserFactory::new()->create();
        ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $result = $user->createToken('user token');
        $request = Request::create('/');
        $request->headers->set('Authorization', $authorization = 'Bearer ' . $result->accessToken);
        RequestContext::set($request);
        $guard = auth('passport');

        $this->assertSame($user->getKey(), $guard->user()->getKey());
        $this->assertTrue($guard->validate(['request' => $request]));
        $result->getToken()->revoke();
        $this->assertFalse($guard->validate(['request' => $request]));
        $this->assertSame($authorization, $request->header('Authorization'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testWarmParentAuthenticationDoesNotHideRevocationInAChildRequest(bool $revokeClient): void
    {
        $user = UserFactory::new()->create();
        $client = ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $result = $user->createToken('user token');
        $request = Request::create('/guard');
        $request->headers->set('Authorization', 'Bearer ' . $result->accessToken);
        RequestContext::set($request);

        $this->assertSame($user->getKey(), auth('passport')->user()->getKey());
        $this->assertSame($client->getKey(), auth('passport')->client()->getKey());

        Route::post('/revoke', function () use ($revokeClient, $client, $result): void {
            DB::transaction(fn () => ($revokeClient ? $client : $result->getToken())->revoke());
        })->middleware('auth:passport');

        $this->withToken($result->accessToken)->postJson('/revoke')->assertSuccessful();
        $this->getJson('/guard')->assertExactJson(['user' => null, 'token' => null, 'client' => null]);
    }

    public function testRevokedClientsAreRejectedByBothTokenMiddlewarePaths(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->create();
        $token = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertOk()->json('access_token');
        Route::get('/scopes', fn () => 'response')->middleware(CheckToken::class);
        Route::get('/machine', fn () => 'response')->middleware(EnsureClientIsResourceOwner::class);

        $this->withToken($token)->getJson('/scopes')->assertOk();
        $this->getJson('/machine')->assertOk();
        $client->revoke();
        $this->getJson('/scopes')->assertUnauthorized();
        $this->getJson('/machine')->assertUnauthorized();
    }

    public function testTokenAuthenticationCallbackCannotAcceptARevokedToken(): void
    {
        $user = UserFactory::new()->create();
        ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $result = $user->createToken('user token');
        $result->getToken()->revoke();
        $observed = [];
        Passport::authenticateAccessTokensUsing(function ($token, bool $valid) use (&$observed): bool {
            $observed[] = [$token->getKey(), $valid];

            return true;
        });
        Route::get('/scopes', fn () => 'response')->middleware(CheckToken::class);

        $this->withToken($result->accessToken)->getJson('/guard')->assertJson(['user' => null, 'client' => null]);
        $this->getJson('/scopes')->assertUnauthorized();
        $this->assertSame([[$result->accessTokenId, false], [$result->accessTokenId, false]], $observed);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTokenMiddlewareValidatesBearersForNonPassportUsers(bool $sanctum): void
    {
        Passport::tokensCan(['read' => 'Read']);
        $client = ClientFactory::new()->asClientCredentials()->create();
        $token = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'scope' => 'read',
        ])->assertOk()->json('access_token');
        $user = $sanctum
            ? (new TokenGuardSanctumUser)->forceFill(['id' => 1])->withAccessToken(new PersonalAccessToken(['abilities' => ['*']]))
            : (new Authenticatable)->forceFill(['id' => 1]);
        $this->actingAs($user, 'web');
        Route::get('/scopes', fn () => 'response')->middleware(['web', CheckToken::using('read')]);
        Route::get('/machine', fn () => 'response')->middleware(['web', EnsureClientIsResourceOwner::using('read')]);

        $this->withToken($token)->getJson('/scopes')->assertOk();
        $this->getJson('/machine')->assertOk();
        $this->withToken('invalid')->getJson('/scopes')->assertUnauthorized();
        $this->getJson('/machine')->assertUnauthorized();
    }
}

class TokenGuardSanctumUser extends Authenticatable
{
    use HasSanctumTokens;
}
