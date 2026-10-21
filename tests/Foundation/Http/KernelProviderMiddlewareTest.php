<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\KernelProviderMiddlewareTest;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class KernelProviderMiddlewareTest extends TestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [MiddlewareServiceProvider::class];
    }

    public function testProviderBootMiddlewareChangesSurviveTheHttpKernel(): void
    {
        Route::middleware(['api', 'auth'])->get('/provider-middleware', fn (): string => 'OK');

        $this->get('/provider-middleware')
            ->assertOk()
            ->assertHeader('X-Group-Middleware', 'applied')
            ->assertHeader('X-Auth-Middleware', 'replaced');
    }

    #[DefineEnvironment('useCustomHttpKernel')]
    public function testProviderBootMiddlewareChangesSurviveACustomHttpKernel(): void
    {
        Route::middleware(['api', 'auth'])->get('/provider-middleware', fn (): string => 'OK');

        $this->assertInstanceOf(CustomKernel::class, $this->app->make(HttpKernelContract::class));

        $this->get('/provider-middleware')
            ->assertOk()
            ->assertHeader('X-Group-Middleware', 'applied')
            ->assertHeader('X-Auth-Middleware', 'replaced');
    }

    /**
     * Bind a custom HTTP kernel before the application boots.
     */
    protected function useCustomHttpKernel(ApplicationContract $app): void
    {
        $app->singleton(HttpKernelContract::class, CustomKernel::class);
    }
}

class MiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Add to a kernel middleware group and replace a kernel alias, as packages do.
     */
    public function boot(): void
    {
        Route::pushMiddlewareToGroup('api', GroupMiddleware::class);
        Route::aliasMiddleware('auth', AuthMiddleware::class);
    }
}

class GroupMiddleware
{
    /**
     * Mark responses that pass through the group middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Group-Middleware', 'applied');

        return $response;
    }
}

class AuthMiddleware
{
    /**
     * Mark responses that pass through the replacement auth middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Auth-Middleware', 'replaced');

        return $response;
    }
}

class CustomKernel extends Kernel
{
}
