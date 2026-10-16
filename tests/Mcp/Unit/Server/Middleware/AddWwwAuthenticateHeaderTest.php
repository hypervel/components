<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Middleware;

use Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class AddWwwAuthenticateHeaderTest extends TestCase
{
    #[DataProvider('authenticationModes')]
    public function testAddsChallengeToSymfonyResponses(bool $oauth): void
    {
        if ($oauth) {
            Route::get('/.well-known/oauth-protected-resource/{path}', fn (): Response => new Response)
                ->where('path', '.*')
                ->name('mcp.oauth.protected-resource.nested');
        }

        Route::post('/mcp', fn (): Response => new Response('Unauthorized', 401))
            ->middleware(AddWwwAuthenticateHeader::class);

        $this->post('/mcp')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', $oauth
                ? 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/mcp') . '"'
                : 'Bearer realm="mcp", error="invalid_token"');
    }

    /**
     * Return the supported challenge modes.
     */
    public static function authenticationModes(): array
    {
        return ['OAuth' => [true], 'Sanctum' => [false]];
    }
}
