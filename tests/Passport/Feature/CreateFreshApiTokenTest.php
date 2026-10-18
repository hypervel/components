<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Http\Request;
use Hypervel\Passport\Http\Middleware\CreateFreshApiToken;
use Hypervel\Passport\Passport;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\User;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Cookie;

class CreateFreshApiTokenTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected User $user;

    /**
     * Prepare the user and routes inside the database test coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        $this->user = UserFactory::new()->create();

        Route::middleware(['web', CreateFreshApiToken::class])->group(function () {
            Route::get('/fresh', fn () => 'response');
            Route::post('/fresh', fn () => 'response');
            Route::get('/redirect', fn () => redirect('/fresh'));
            Route::get('/has-token', fn () => response('response')->withCookie(new Cookie(Passport::cookie(), 'existing')));
        });

        Route::get('/fresh-api', fn () => 'response')->middleware(['web', CreateFreshApiToken::using('passport')]);
        Route::get('/user', fn (Request $request) => $request->user()->getKey())->middleware('auth:passport');
    }

    public function testShouldReceiveAFreshToken(): void
    {
        $response = $this->actingAs($this->user)->get('/fresh');

        $response->assertOk()->assertCookie(Passport::cookie());

        $this->withCredentials()
            ->withCookie(Passport::cookie(), $response->getCookie(Passport::cookie())->getValue())
            ->withHeader('X-CSRF-TOKEN', session()->token())
            ->getJson('/user')
            ->assertOk()
            ->assertSee((string) $this->user->getKey());
    }

    public function testShouldNotReceiveAFreshTokenForOtherHttpVerbs(): void
    {
        $this->actingAs($this->user)->post('/fresh')->assertOk()->assertCookieMissing(Passport::cookie());
    }

    public function testShouldNotReceiveAFreshTokenForGuests(): void
    {
        $this->get('/fresh')->assertOk()->assertCookieMissing(Passport::cookie());
    }

    public function testShouldNotReceiveAFreshTokenForRedirects(): void
    {
        $this->actingAs($this->user)->get('/redirect')->assertRedirect()->assertCookieMissing(Passport::cookie());
    }

    public function testShouldNotReceiveAFreshTokenForResponseThatAlreadyHasToken(): void
    {
        $this->actingAs($this->user)
            ->get('/has-token')
            ->assertOk()
            ->assertCookie(Passport::cookie(), 'existing');
    }

    public function testShouldResolveTheUserFromTheGivenGuard(): void
    {
        $this->actingAs($this->user, 'web')->get('/fresh-api')->assertOk()->assertCookieMissing(Passport::cookie());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testLogoutExpiresTheExistingApiCookie(bool $hasCookie): void
    {
        config(['session.domain' => '.example.com']);
        StartSession::configureSessionCookieUsing(fn (Request $request, array $config): array => [
            ...$config,
            'path' => '/organization',
            'domain' => '',
        ]);
        Route::post('/organization/logout', function (): string {
            auth('web')->logout();

            return 'logged out';
        })->middleware('web');
        $this->actingAs($this->user, 'web');

        if ($hasCookie) {
            $fresh = $this->get('/fresh')->assertOk()->getCookie(Passport::cookie());
            $this->withCookie(Passport::cookie(), $fresh->getValue());
        }

        $response = $this->post('/organization/logout')->assertOk();

        if ($hasCookie) {
            $response->assertCookieExpired(Passport::cookie());
            $expired = $response->getCookie(Passport::cookie(), false);
            $this->assertSame('/organization', $expired->getPath());
            $this->assertSame('', $expired->getDomain());
        } else {
            $response->assertCookieMissing(Passport::cookie());
        }
    }
}
