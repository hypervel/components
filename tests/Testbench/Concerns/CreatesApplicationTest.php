<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Testbench\TestCase;

class CreatesApplicationTest extends TestCase
{
    protected array $registeredProviders = [];

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestServiceProvider::class,
        ];
    }

    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [
            'TestAlias' => TestFacade::class,
        ];
    }

    public function testGetPackageProvidersReturnsProviders(): void
    {
        $providers = $this->getPackageProviders($this->app);

        $this->assertContains(TestServiceProvider::class, $providers);
    }

    public function testGetPackageAliasesReturnsAliases(): void
    {
        $aliases = $this->getPackageAliases($this->app);

        $this->assertArrayHasKey('TestAlias', $aliases);
        $this->assertSame(TestFacade::class, $aliases['TestAlias']);
    }

    public function testRegisterPackageProvidersRegistersProviders(): void
    {
        // The provider should be registered via defineEnvironment
        // which calls registerPackageProviders
        $this->assertTrue(
            $this->app->providerIsLoaded(TestServiceProvider::class),
            'TestServiceProvider should be registered'
        );
    }

    public function testRegisterPackageAliasesAddsToConfig(): void
    {
        $aliases = $this->app->make('config')->get('app.aliases', []);

        $this->assertArrayHasKey('TestAlias', $aliases);
        $this->assertSame(TestFacade::class, $aliases['TestAlias']);
    }

    public function testAfterLoadingEnvironmentFiresThroughTestbenchPath()
    {
        // The bootstrapped event should have been dispatched by bootstrapWith()
        // in CreatesApplication::resolveApplicationConfiguration().
        $listeners = $this->app['events']->getListeners(
            'bootstrapped: ' . LoadEnvironmentVariables::class
        );

        // Register a callback now and verify it gets added to the listener list.
        $called = false;
        $this->app->afterLoadingEnvironment(function () use (&$called) {
            $called = true;
        });

        $updatedListeners = $this->app['events']->getListeners(
            'bootstrapped: ' . LoadEnvironmentVariables::class
        );

        $this->assertCount(count($listeners) + 1, $updatedListeners);
    }

    public function testParallelCachePathSanitizesParaTestWorkerToken(): void
    {
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousRoutesCache = $_SERVER['APP_ROUTES_CACHE'] ?? null;

        try {
            $_SERVER['TEST_TOKEN'] = 'worker/token:one';
            $_ENV['TEST_TOKEN'] = 'worker/token:one';

            $this->configureParallelCachePaths();

            $this->assertSame('cache/routes-v7-test-worker_token_one.php', $_SERVER['APP_ROUTES_CACHE']);
        } finally {
            if ($previousServerToken === null) {
                unset($_SERVER['TEST_TOKEN']);
            } else {
                $_SERVER['TEST_TOKEN'] = $previousServerToken;
            }

            if ($previousEnvironmentToken === null) {
                unset($_ENV['TEST_TOKEN']);
            } else {
                $_ENV['TEST_TOKEN'] = $previousEnvironmentToken;
            }

            if ($previousRoutesCache === null) {
                unset($_SERVER['APP_ROUTES_CACHE']);
            } else {
                $_SERVER['APP_ROUTES_CACHE'] = $previousRoutesCache;
            }
        }
    }
}

/**
 * Test service provider for testing.
 */
class TestServiceProvider extends \Hypervel\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('test.service', fn () => 'test_value');
    }
}

/**
 * Test facade for testing.
 */
class TestFacade
{
    // Empty facade class for testing
}
