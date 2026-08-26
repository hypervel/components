<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Jwt\Contracts\ManagerContract;
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
