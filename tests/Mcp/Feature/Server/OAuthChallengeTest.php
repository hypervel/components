<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server\OAuthChallengeTest;

use Closure;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Routing\Middleware\SubstituteBindings;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\TestCase;
use Symfony\Component\HttpFoundation\Response;

class RejectsEveryone
{
    /**
     * Reject every request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }
}

class OAuthChallengeTest extends TestCase
{
    /**
     * Load Sanctum alongside the MCP server.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    /**
     * Register OAuth metadata routes.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Registrar::class)->oauthRoutes();
    }

    public function testChallengesWhenAuthenticationIsSortedAheadOfTheRouteMiddleware(): void
    {
        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Mcp::web('protected-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('protected-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/protected-mcp') . '"');
    }

    public function testChallengesWhenAuthenticationIsNotPartOfTheMiddlewarePriorityList(): void
    {
        Route::middleware(RejectsEveryone::class)->group(function (): void {
            Mcp::web('custom-auth-mcp', ExampleServer::class);
        });

        $this->postJson('custom-auth-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/custom-auth-mcp') . '"');
    }

    public function testChallengesWhenAuthenticationIsAppliedThroughTheAuthAlias(): void
    {
        config()->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Mcp::web('aliased-mcp', ExampleServer::class)->middleware('auth:api');
        });

        $this->postJson('aliased-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/aliased-mcp') . '"');
    }

    public function testDoesNotChallengeOnRoutesThatAreNotMcpServers(): void
    {
        Route::middleware(Authenticate::class)->post('not-mcp', fn () => response()->json([]));

        $this->postJson('not-mcp', [])
            ->assertStatus(401)
            ->assertHeaderMissing('WWW-Authenticate');
    }

    public function testSanctumRoutesDoNotAdvertiseAnotherServersOAuthDiscovery(): void
    {
        config(['auth.guards.personal' => ['driver' => 'sanctum', 'provider' => 'users', 'session_guards' => []]]);
        Mcp::web('oauth-mcp', ExampleServer::class)->middleware(Authenticate::class);
        Mcp::web('sanctum-mcp', ExampleServer::class)->middleware('auth:personal');

        $this->postJson('oauth-mcp', [])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/oauth-mcp') . '"');
        $this->postJson('sanctum-mcp', [])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    }

    public function testPreservesAnAuthenticationChallengeSuppliedByTheApplication(): void
    {
        Route::aliasMiddleware('custom-challenge', function () {
            return response('', 401, ['WWW-Authenticate' => 'Bearer realm="custom"']);
        });
        Mcp::web('custom-challenge', ExampleServer::class)->middleware('custom-challenge');

        $this->postJson('custom-challenge', [])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="custom"');
    }

    public function testDoesNotChallengeWhenTheMiddlewareIsExcludedFromTheRoute(): void
    {
        Route::middleware(Authenticate::class)->group(function (): void {
            Mcp::web('opted-out-mcp', ExampleServer::class)->withoutMiddleware(AddWwwAuthenticateHeader::class);
        });

        $this->postJson('opted-out-mcp', [])
            ->assertStatus(401)
            ->assertHeaderMissing('WWW-Authenticate');
    }
}
