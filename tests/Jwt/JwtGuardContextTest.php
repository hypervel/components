<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Http\JsonResponse;
use Hypervel\Jwt\Contracts\ManagerContract;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\JwtServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class JwtGuardContextTest extends TestCase
{
    protected ManagerContract $jwtManager;

    protected UserProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jwtManager = m::mock(ManagerContract::class);
        $this->provider = m::mock(UserProvider::class);

        $this->app->instance('jwt', $this->jwtManager);
        $this->app->make(AuthManager::class)->provider(
            'jwt-context',
            fn (): UserProvider => $this->provider,
        );

        Route::get('/jwt-context/user', static function () {
            return response()->json([
                'id' => auth('jwt')->user()?->getAuthIdentifier(),
            ]);
        });

        Route::middleware('auth:jwt')->get('/jwt-context/protected', static function (): JsonResponse {
            return response()->json([
                'id' => auth('jwt')->id(),
            ]);
        });

        Route::middleware(EncryptCookies::class)->get('/jwt-context/cookie-user', static function (): JsonResponse {
            return response()->json([
                'id' => auth('jwt')->user()?->getAuthIdentifier(),
            ]);
        });

        Route::post('/jwt-context/login', static function () {
            return response()->json([
                'token' => auth('jwt')->login(new GenericUser([
                    'id' => 2,
                    'password' => '',
                    'remember_token' => null,
                ])),
            ]);
        });

        Route::post('/jwt-context/logout', static function () {
            auth('jwt')->logout();

            return response()->noContent();
        });
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [JwtServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'auth.guards.jwt' => [
                'driver' => 'jwt',
                'provider' => 'jwt-context-users',
            ],
            'auth.providers.jwt-context-users' => [
                'driver' => 'jwt-context',
            ],
            'jwt.lock_subject' => false,
            'jwt.parser' => [AuthHeaders::class, Cookie::class],
        ]);
    }

    public function testBearerResolutionRunsAgainForEachRequest(): void
    {
        $user = $this->user(1);
        $this->jwtManager->shouldReceive('decode')->with('request-token')->twice()->andReturn(['sub' => 1]);
        $this->provider->shouldReceive('retrieveById')->with(1)->twice()->andReturn($user);

        $this->withHeader('Authorization', 'Bearer request-token')
            ->getJson('/jwt-context/user')
            ->assertOk()
            ->assertJson(['id' => 1]);

        $this->getJson('/jwt-context/user')
            ->assertOk()
            ->assertJson(['id' => 1]);
    }

    public function testAuthMiddlewareAcceptsAValidToken(): void
    {
        $this->jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1]);
        $this->provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($this->user(1));

        $this->withHeader('Authorization', 'Bearer valid-token')
            ->getJson('/jwt-context/protected')
            ->assertOk()
            ->assertJson(['id' => 1]);
    }

    public function testRequestWithoutATokenIsRejectedByAuthMiddlewareAndAGuestElsewhere(): void
    {
        $this->jwtManager->shouldNotReceive('decode');
        $this->provider->shouldNotReceive('retrieveById');

        $this->assertRejectedByAuthMiddlewareAndAGuestElsewhere();
    }

    public function testInvalidTokenIsRejectedByAuthMiddlewareAndAGuestElsewhere(): void
    {
        $this->jwtManager->shouldReceive('decode')
            ->with('invalid-token')
            ->twice()
            ->andThrow(new TokenInvalidException('Token Signature could not be verified.'));
        $this->provider->shouldNotReceive('retrieveById');

        $this->withHeader('Authorization', 'Bearer invalid-token');

        $this->assertRejectedByAuthMiddlewareAndAGuestElsewhere();
    }

    public function testTokenForAMissingUserIsRejectedByAuthMiddlewareAndAGuestElsewhere(): void
    {
        $this->jwtManager->shouldReceive('decode')->with('orphan-token')->twice()->andReturn(['sub' => 404]);
        $this->provider->shouldReceive('retrieveById')->with(404)->twice()->andReturnNull();

        $this->withHeader('Authorization', 'Bearer orphan-token');

        $this->assertRejectedByAuthMiddlewareAndAGuestElsewhere();
    }

    public function testCookieTokenIsReadAfterEncryptCookiesDecryptsIt(): void
    {
        $this->jwtManager->shouldReceive('decode')->with('cookie-token')->once()->andReturn(['sub' => 1]);
        $this->provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($this->user(1));

        $this->withCredentials()
            ->withCookie('token', 'cookie-token')
            ->getJson('/jwt-context/cookie-user')
            ->assertOk()
            ->assertJson(['id' => 1]);
    }

    public function testCookieThatCannotBeDecryptedLeavesTheRequestAGuest(): void
    {
        $this->jwtManager->shouldNotReceive('decode');

        $this->withCredentials()
            ->withUnencryptedCookie('token', 'not-encrypted')
            ->getJson('/jwt-context/cookie-user')
            ->assertOk()
            ->assertExactJson(['id' => null]);
    }

    public function testExplicitUserSurvivesAcrossRequestsWithoutResolvingBearerTokens(): void
    {
        $this->jwtManager->shouldNotReceive('decode');
        $this->provider->shouldNotReceive('retrieveById');

        $this->app->make('auth')->guard('jwt')->setUser($this->user(10));

        $this->withHeader('Authorization', 'Bearer first-token')
            ->getJson('/jwt-context/user')
            ->assertOk()
            ->assertJson(['id' => 10]);

        $this->withHeader('Authorization', 'Bearer second-token')
            ->getJson('/jwt-context/user')
            ->assertOk()
            ->assertJson(['id' => 10]);
    }

    public function testRequestDrivenLogoutClearsParentExplicitUser(): void
    {
        $this->jwtManager->shouldNotReceive('hasBlacklistEnabled', 'invalidate');
        $this->app->make('auth')->guard('jwt')->setUser($this->user(10));

        $this->postJson('/jwt-context/logout')->assertNoContent();

        $this->assertGuest('jwt');
    }

    public function testRequestDrivenLoginDoesNotAuthenticateParentWithoutBearerToken(): void
    {
        $this->jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $this->postJson('/jwt-context/login')
            ->assertOk()
            ->assertJson(['token' => 'new-token']);

        $this->assertGuest('jwt');
    }

    /**
     * Assert that the protected route rejects the request and the open route treats it as a guest.
     */
    private function assertRejectedByAuthMiddlewareAndAGuestElsewhere(): void
    {
        $this->getJson('/jwt-context/protected')->assertUnauthorized();

        $this->getJson('/jwt-context/user')
            ->assertOk()
            ->assertExactJson(['id' => null]);
    }

    /**
     * Create a generic authenticatable user.
     */
    private function user(int $id): GenericUser
    {
        return new GenericUser([
            'id' => $id,
            'password' => '',
            'remember_token' => null,
        ]);
    }
}
