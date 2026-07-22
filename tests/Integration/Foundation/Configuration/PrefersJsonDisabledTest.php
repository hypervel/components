<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Configuration;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class PrefersJsonDisabledTest extends TestCase
{
    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->withMiddleware()
            ->create();
    }

    public function testPlainStringRouteReturnsHtmlUnderWildcardAcceptWhenDisabled(): void
    {
        Route::get('plain', fn () => 'hello');

        $this->get('plain', ['Accept' => '*/*'])
            ->assertOk()
            ->assertSee('hello')
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function testUnauthenticatedWildcardStillRedirectsWhenDisabled(): void
    {
        Route::get('login', fn () => 'login page')->name('login');

        Route::get('protected', fn () => 'secret')->middleware(Authenticate::class);

        $this->get('protected', ['Accept' => '*/*'])
            ->assertRedirect();
    }
}
