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
use RuntimeException;
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

    public function testChallengesWhenAuthenticationIsSortedAheadOfTheRouteMiddleware(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Mcp::web('protected-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('protected-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/protected-mcp') . '"');
    }

    public function testChallengesWhenAuthenticationIsNotPartOfTheMiddlewarePriorityList(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

        Route::middleware(RejectsEveryone::class)->group(function (): void {
            Mcp::web('custom-auth-mcp', ExampleServer::class);
        });

        $this->postJson('custom-auth-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/custom-auth-mcp') . '"');
    }

    public function testChallengesWhenAuthenticationIsAppliedThroughTheAuthAlias(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

        config()->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Mcp::web('aliased-mcp', ExampleServer::class)->middleware('auth:api');
        });

        $this->postJson('aliased-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/aliased-mcp') . '"');
    }

    public function testChallengesWithTheResourceMetadataUrlOfTheResolver(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

        AddWwwAuthenticateHeader::resolveResourceMetadataUrlUsing(
            fn (Request $request): string => 'https://acme.example.com/.well-known/oauth-protected-resource/' . $request->path()
        );

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Mcp::web('tenant-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('tenant-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="https://acme.example.com/.well-known/oauth-protected-resource/tenant-mcp"');
    }

    public function testChallengesWithTheResourceMetadataUrlOfTheNamedRouteWhenTheResolverReturnsNull(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

        AddWwwAuthenticateHeader::resolveResourceMetadataUrlUsing(fn (): ?string => null);

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Mcp::web('central-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('central-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/central-mcp') . '"');
    }

    public function testChallengesWithThePrefixParametersSharedByTheMcpAndMetadataRoutes(): void
    {
        Route::prefix('{tenant}')->group(function (): void {
            $this->app->make(Registrar::class)->oauthRoutes(registration: false);

            Mcp::web('mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('acme/mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/acme/.well-known/oauth-protected-resource/acme/mcp') . '"');
    }

    public function testChallengesWithTheHostParametersOfTheRoute(): void
    {
        Route::domain('{tenant}.example.com')->group(function (): void {
            $this->app->make(Registrar::class)->oauthRoutes(registration: false);

            Mcp::web('tenant-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('http://acme.example.com/tenant-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://acme.example.com/.well-known/oauth-protected-resource/tenant-mcp"');

        $this->postJson('http://globex.example.com/tenant-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://globex.example.com/.well-known/oauth-protected-resource/tenant-mcp"');
    }

    public function testDoesNotLeakHostParametersTheMetadataRouteDoesNotDeclare(): void
    {
        Route::domain('example.com')->group(fn () => $this->app->make(Registrar::class)->oauthRoutes(registration: false));

        Route::domain('{tenant}.example.com')->group(function (): void {
            Mcp::web('tenant-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('http://acme.example.com/tenant-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://example.com/.well-known/oauth-protected-resource/tenant-mcp"');
    }

    public function testDoesNotLeakHostParametersWhenTheMetadataRouteHasNoDomain(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

        Route::domain('{tenant}.example.com')->group(function (): void {
            Mcp::web('tenant-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('http://acme.example.com/tenant-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://acme.example.com/.well-known/oauth-protected-resource/tenant-mcp"');
    }

    public function testChallengesWithTheHostParametersOfTheMetadataRouteWhenTheMcpRouteHasNoDomain(): void
    {
        Route::domain('{tenant}.example.com')->group(fn () => $this->app->make(Registrar::class)->oauthRoutes(registration: false));

        Mcp::web('shared-mcp', ExampleServer::class)->middleware(Authenticate::class);

        $this->postJson('http://acme.example.com/shared-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://acme.example.com/.well-known/oauth-protected-resource/shared-mcp"');
    }

    public function testChallengesWithTheHostParametersOfTheMetadataRouteWhenTheRoutesNameThemDifferently(): void
    {
        Route::domain('{account}.example.com')->group(fn () => $this->app->make(Registrar::class)->oauthRoutes(registration: false));

        Route::domain('{tenant}.example.com')->group(function (): void {
            Mcp::web('tenant-mcp', ExampleServer::class)->middleware(Authenticate::class);
        });

        $this->postJson('http://acme.example.com/tenant-mcp', [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://acme.example.com/.well-known/oauth-protected-resource/tenant-mcp"');
    }

    public function testDoesNotChallengeOnRoutesThatAreNotMcpServers(): void
    {
        Route::middleware(Authenticate::class)->post('not-mcp', fn () => response()->json([]));

        $this->postJson('not-mcp', [])
            ->assertStatus(401)
            ->assertHeaderMissing('WWW-Authenticate');
    }

    public function testDoesNotResolveTheResourceMetadataUrlOnRoutesThatAreNotMcpServers(): void
    {
        AddWwwAuthenticateHeader::resolveResourceMetadataUrlUsing(fn (): string => throw new RuntimeException('The resolver should not be called.'));

        Route::middleware(Authenticate::class)->post('not-mcp', fn () => response()->json([]));

        $this->postJson('not-mcp', [])
            ->assertStatus(401)
            ->assertHeaderMissing('WWW-Authenticate');
    }

    public function testSanctumRoutesDoNotAdvertiseAnotherServersOAuthDiscovery(): void
    {
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

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
        $this->app->make(Registrar::class)->oauthRoutes(registration: false);

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
