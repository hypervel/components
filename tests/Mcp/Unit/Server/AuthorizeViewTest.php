<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Mcp\TestCase;

class AuthorizeViewTest extends TestCase
{
    /**
     * Render consent using the view data supplied by Passport.
     */
    private function renderAuthorize(Request $request, array $client = []): string
    {
        Route::delete('/oauth/authorize', fn (): null => null)->name('passport.authorizations.deny');
        Route::post('/oauth/authorize', fn (): null => null)->name('passport.authorizations.approve');

        RequestContext::set($request);

        return view('mcp::authorize', [
            'client' => (object) ['id' => 'client-1', 'name' => 'Example', ...$client],
            'user' => (object) ['email' => 'user@example.com'],
            'scopes' => [],
            'resources' => [],
            'authToken' => 'token',
            'request' => $request,
            // Passport resolves and validates this value before displaying consent.
            'redirectUri' => $request->query('redirect_uri', $client['redirect_uris'][0] ?? null),
        ])->render();
    }

    public function testShowsTheRequestedRedirectUri(): void
    {
        $this->withoutVite();

        $html = $this->renderAuthorize(Request::create('/oauth/authorize', 'GET', ['redirect_uri' => 'https://app.example.com/callback']));

        $this->assertStringContainsString('You will be redirected to', $html);
        $this->assertStringContainsString('app.example.com', $html);
        $this->assertStringContainsString('https://app.example.com/callback', $html);
    }

    public function testFallsBackToTheRegisteredRedirectUri(): void
    {
        $this->withoutVite();

        $html = $this->renderAuthorize(Request::create('/oauth/authorize'), ['redirect_uris' => ['https://registered.example.com/cb']]);

        $this->assertStringContainsString('registered.example.com', $html);
    }

    public function testEscapesTheRedirectUri(): void
    {
        $this->withoutVite();

        $html = $this->renderAuthorize(Request::create('/oauth/authorize', 'GET', ['redirect_uri' => 'https://x.test/<script>alert(1)</script>']));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
