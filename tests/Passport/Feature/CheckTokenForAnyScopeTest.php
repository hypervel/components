<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Http\Middleware\CheckTokenForAnyScope;
use Hypervel\Passport\Passport;
use Hypervel\Passport\TransientToken;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\User;
use Hypervel\Tests\Passport\Fixtures\UserFactory;

class CheckTokenForAnyScopeTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected User $user;

    /**
     * Prepare credentials after the coroutine's lazy migration hooks are registered.
     */
    protected function setUpInCoroutine(): void
    {
        Passport::tokensCan([
            'foo' => 'Foo',
            'bar' => 'Bar',
            'baz' => 'Baz',
        ]);

        ClientFactory::new()->asPersonalAccessTokenClient()->create();

        $this->user = UserFactory::new()->create();

        Route::get('/bearer', fn () => 'response')->middleware(CheckTokenForAnyScope::using('foo', 'bar'));
        Route::get('/guarded', fn () => 'response')->middleware(['auth:passport', CheckTokenForAnyScope::using('foo', 'bar')]);
    }

    public function testRequestIsPassedAlongIfTokenHasAnyRequiredScopes(): void
    {
        $token = $this->user->createToken('test', ['bar'])->accessToken;

        $this->withToken($token)->getJson('/bearer')->assertOk()->assertSee('response');
    }

    public function testRequestIsPassedAlongIfTokenHasAllScopes(): void
    {
        $token = $this->user->createToken('test', ['*'])->accessToken;

        $this->withToken($token)->getJson('/bearer')->assertOk();
    }

    public function testRequestIsForbiddenIfTokenHasNoneOfTheRequiredScopes(): void
    {
        $token = $this->user->createToken('test', ['baz'])->accessToken;

        $this->withToken($token)->getJson('/bearer')->assertForbidden();
    }

    public function testRequestIsUnauthorizedIfTokenIsInvalid(): void
    {
        $this->withToken('invalid')->getJson('/bearer')->assertUnauthorized();
    }

    public function testRequestIsUnauthorizedIfTokenIsRevoked(): void
    {
        $result = $this->user->createToken('test', ['*']);
        $result->getToken()->revoke();

        $this->withToken($result->accessToken)->getJson('/bearer')->assertUnauthorized();
    }

    public function testAuthenticatedUserTokenIsCheckedForScopes(): void
    {
        $token = $this->user->createToken('test', ['bar'])->accessToken;

        $this->withToken($token)->getJson('/guarded')->assertOk();
    }

    public function testAuthenticatedUserTokenIsForbiddenWithoutScopes(): void
    {
        $token = $this->user->createToken('test', ['baz'])->accessToken;

        $this->withToken($token)->getJson('/guarded')->assertForbidden();
    }

    public function testRequestIsPassedAlongIfTokenIsTransient(): void
    {
        $this->actingAs($this->user->withAccessToken(new TransientToken), 'passport')
            ->getJson('/guarded')
            ->assertOk();
    }
}
