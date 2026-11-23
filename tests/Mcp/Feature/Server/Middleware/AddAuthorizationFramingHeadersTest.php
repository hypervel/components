<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server\Middleware;

use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Mcp\TestCase;

class AddAuthorizationFramingHeadersTest extends TestCase
{
    public function testDeniesFramingOfTheAuthorizePageThroughTheHttpKernel(): void
    {
        Route::get('oauth/authorize', fn (): string => 'consent')->name('passport.authorizations.authorize');

        $this->get('oauth/authorize')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function testDoesNotAddFramingHeadersToOtherRoutes(): void
    {
        Route::get('dashboard', fn (): string => 'ok')->name('dashboard');

        $this->get('dashboard')
            ->assertOk()
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeaderMissing('Content-Security-Policy');
    }
}
