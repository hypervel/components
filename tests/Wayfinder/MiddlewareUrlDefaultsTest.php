<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Wayfinder\Fixtures\Middleware\GlobalUrlDefaultsMiddleware;
use Hypervel\Tests\Wayfinder\Fixtures\Middleware\UrlDefaultsMiddleware;
use Hypervel\Wayfinder\WayfinderServiceProvider;

use function Hypervel\Filesystem\join_paths;

class MiddlewareUrlDefaultsTest extends TestCase
{
    private string $tempPath;

    private Filesystem $files;

    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WayfinderServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempPath = ParallelTesting::tempDir('wayfinder-middleware');
        $this->files->deleteDirectory($this->tempPath);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    /**
     * Generate the named routes and return the given directory's index file.
     */
    private function generate(string $directory): string
    {
        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-actions' => true,
        ])->assertSuccessful();

        return $this->files->get(join_paths($this->tempPath, 'routes', $directory, 'index.ts'));
    }

    #[DefineEnvironment('aliasUrlDefaultsMiddleware')]
    public function testUrlDefaultsAreResolvedFromAnAliasedMiddleware(): void
    {
        Route::middleware('url-defaults')->get('/alias-defaults/{locale}', fn (): string => '')->name('alias.defaults');

        $this->assertStringContainsString("url: '/alias-defaults/{locale?}'", $this->generate('alias'));
    }

    #[DefineEnvironment('groupUrlDefaultsMiddleware')]
    public function testUrlDefaultsAreResolvedFromAKernelMiddlewareGroup(): void
    {
        Route::middleware('tenant')->get('/kernel-group-defaults/{locale}', fn (): string => '')->name('kernel.defaults');

        $this->assertStringContainsString("url: '/kernel-group-defaults/{locale?}'", $this->generate('kernel'));
    }

    #[DefineEnvironment('globalUrlDefaultsMiddleware')]
    public function testUrlDefaultsAreResolvedFromGlobalMiddleware(): void
    {
        // Global middleware never reaches the router, so this only works if the defaults
        // are read off the kernel itself.
        Route::get('/global-defaults/{locale}', fn (): string => '')->name('global.defaults');

        $this->assertStringContainsString("url: '/global-defaults/{locale?}'", $this->generate('global'));
    }

    public function testUrlDefaultsAreResolvedFromAMiddlewarePushedOntoAGroup(): void
    {
        Route::pushMiddlewareToGroup('web', UrlDefaultsMiddleware::class);

        Route::middleware('web')->get('/group-defaults/{locale}', fn (): string => '')->name('group.defaults');

        $this->assertStringContainsString("url: '/group-defaults/{locale?}'", $this->generate('group'));
    }

    #[DefineEnvironment('aliasUrlDefaultsMiddleware')]
    public function testUrlDefaultsAreDroppedWhenTheMiddlewareIsExcludedByAlias(): void
    {
        // Excluded middleware runs through the same alias map, so without the kernel's aliases
        // the exclusion is missed and the parameter is wrongly reported as optional.
        Route::middleware(UrlDefaultsMiddleware::class)
            ->withoutMiddleware('url-defaults')
            ->get('/excluded-defaults/{locale}', fn (): string => '')->name('excluded.defaults');

        $this->assertStringContainsString("url: '/excluded-defaults/{locale}'", $this->generate('excluded'));
    }

    #[DefineEnvironment('globalPrecedenceMiddleware')]
    public function testRouteMiddlewareDefaultsWinOverGlobalMiddlewareDefaults(): void
    {
        Route::middleware(UrlDefaultsMiddleware::class)
            ->get('/precedence/{locale}', fn (): string => '')->name('precedence.show');

        $this->assertStringContainsString("@param locale - Default: 'en'", $this->generate('precedence'));
    }

    /**
     * Alias the URL defaults middleware on the HTTP kernel, as withMiddleware() does.
     */
    protected function aliasUrlDefaultsMiddleware(ApplicationContract $app): void
    {
        $app->afterResolving(Kernel::class, fn (Kernel $kernel): Kernel => $kernel->setMiddlewareAliases([
            'url-defaults' => UrlDefaultsMiddleware::class,
        ]));
    }

    /**
     * Add a kernel middleware group containing the URL defaults middleware.
     */
    protected function groupUrlDefaultsMiddleware(ApplicationContract $app): void
    {
        $app->afterResolving(Kernel::class, fn (Kernel $kernel): Kernel => $kernel->setMiddlewareGroups([
            'tenant' => [UrlDefaultsMiddleware::class],
        ]));
    }

    /**
     * Run the URL defaults middleware globally.
     */
    protected function globalUrlDefaultsMiddleware(ApplicationContract $app): void
    {
        $app->afterResolving(Kernel::class, fn (Kernel $kernel): Kernel => $kernel->setGlobalMiddleware([
            UrlDefaultsMiddleware::class,
        ]));
    }

    /**
     * Run a global middleware whose defaults route middleware should override.
     */
    protected function globalPrecedenceMiddleware(ApplicationContract $app): void
    {
        $app->afterResolving(Kernel::class, fn (Kernel $kernel): Kernel => $kernel->setGlobalMiddleware([
            GlobalUrlDefaultsMiddleware::class,
        ]));
    }
}
