<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Middleware;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class PreventRequestForgeryServerRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/csrf-cookie', fn () => 'cookie')->middleware([
            StartSession::class,
            PreventRequestForgery::class,
        ]);

        Route::post('/csrf-protected', fn () => 'ok')->middleware([
            StartSession::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        putenv('APP_RUNNING_IN_CONSOLE=false');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        $app->setRunningInConsole(false);
    }

    protected function tearDown(): void
    {
        PreventRequestForgery::flushState();

        putenv('APP_RUNNING_IN_CONSOLE');
        unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);

        parent::tearDown();
    }

    public function testServerRuntimeDoesNotBypassCsrfProtectionDuringTests(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        $response = $this->get('/csrf-cookie')->assertOk();

        $sessionCookie = $this->cookieFromResponse($response->headers->getCookies(), $this->app['config']->get('session.cookie'));

        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->post('/csrf-protected')
            ->assertStatus(419);
    }

    public function testServerRuntimeAcceptsMatchingCsrfToken(): void
    {
        $response = $this->get('/csrf-cookie')->assertOk();

        $cookies = $response->headers->getCookies();
        $sessionCookie = $this->cookieFromResponse($cookies, $this->app['config']->get('session.cookie'));
        $xsrfCookie = $this->cookieFromResponse($cookies, 'XSRF-TOKEN');

        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->post('/csrf-protected', ['_token' => $xsrfCookie->getValue()])
            ->assertOk()
            ->assertSeeText('ok');
    }

    /**
     * @param array<int, \Symfony\Component\HttpFoundation\Cookie> $cookies
     */
    protected function cookieFromResponse(array $cookies, string $name): \Symfony\Component\HttpFoundation\Cookie
    {
        return Collection::make($cookies)
            ->first(fn (\Symfony\Component\HttpFoundation\Cookie $cookie): bool => $cookie->getName() === $name);
    }
}
