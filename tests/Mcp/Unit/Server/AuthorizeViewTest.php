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
     *
     * @param array<string, mixed> $client
     * @param array<int, object> $scopes
     * @param array<int, object> $resources
     */
    private function renderAuthorize(Request $request, array $client = [], array $scopes = [], array $resources = []): string
    {
        Route::delete('/oauth/authorize', fn (): null => null)->name('passport.authorizations.deny');
        Route::post('/oauth/authorize', fn (): null => null)->name('passport.authorizations.approve');

        RequestContext::set($request);

        return view('mcp::authorize', [
            'client' => (object) ['id' => 'client-1', 'name' => 'Example', ...$client],
            'user' => (object) ['email' => 'user@example.com'],
            'scopes' => $scopes,
            'resources' => $resources,
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

    public function testTranslatesThePageIntoTheApplicationLocale(): void
    {
        $this->withoutVite();

        app('translator')->addJsonPath(__DIR__ . '/../../Fixtures/lang');
        app()->setLocale('pl');

        $html = $this->renderAuthorize(
            Request::create('/oauth/authorize', 'GET', ['redirect_uri' => 'https://app.example.com/callback']),
            scopes: [(object) ['description' => 'Use MCP']],
            resources: [(object) ['id' => 'https://mcp.example.com', 'description' => 'MCP Resource']],
        );

        $this->assertStringContainsString('<title>Autoryzacja aplikacji - ', $html);
        $this->assertStringContainsString('content="Autoryzacja MCP"', $html);
        $this->assertStringContainsString('Autoryzuj Example', $html);
        $this->assertStringContainsString('Nastąpi przekierowanie do https://app.example.com/callback', $html);
        $this->assertStringContainsString('Zasoby:', $html);
        $this->assertStringContainsString('Zalogowano jako:', $html);
        $this->assertStringContainsString('Uprawnienia:', $html);
        $this->assertStringContainsString('Anuluj', $html);
        $this->assertStringContainsString('<span id="authorizeText">Autoryzuj</span>', $html);
        $this->assertStringContainsString('authorizeText.textContent = "Autoryzowanie...";', $html);
    }

    public function testEscapesTheRedirectUri(): void
    {
        $this->withoutVite();

        $html = $this->renderAuthorize(Request::create('/oauth/authorize', 'GET', ['redirect_uri' => 'https://x.test/<script>alert(1)</script>']));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
