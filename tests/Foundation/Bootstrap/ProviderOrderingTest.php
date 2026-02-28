<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap\ProviderOrderingTest;

use Hypervel\Support\ServiceProvider;

// -- Framework providers (Hypervel namespace — tier 1) --------------------

class FrameworkAlphaProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class FrameworkBetaProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

// -- Application providers (non-Hypervel namespace — tier 3) --------------

namespace App\Providers\ProviderOrderingTest;

use Hypervel\Support\ServiceProvider;

class AppAlphaProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class AppBetaProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

// -- Discovered providers (third-party namespace, with priorities) --------

namespace Acme\ProviderOrderingTest;

use Hypervel\Support\ServiceProvider;

class DiscoveredDefaultProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class DiscoveredHighPriorityProvider extends ServiceProvider
{
    public int $priority = 30;

    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class DiscoveredMediumPriorityProvider extends ServiceProvider
{
    public int $priority = 10;

    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class DiscoveredNegativePriorityProvider extends ServiceProvider
{
    public int $priority = -10;

    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class DiscoveredDefaultAlphaProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

class DiscoveredDefaultBetaProvider extends ServiceProvider
{
    public function register(): void
    {
        $order = $this->app->make('registration_order');
        $order[] = static::class;
        $this->app->instance('registration_order', $order);
    }
}

// -- Test class -----------------------------------------------------------

namespace Hypervel\Tests\Foundation\Bootstrap\ProviderOrderingTest;

use Acme\ProviderOrderingTest\DiscoveredDefaultAlphaProvider;
use Acme\ProviderOrderingTest\DiscoveredDefaultBetaProvider;
use Acme\ProviderOrderingTest\DiscoveredDefaultProvider;
use Acme\ProviderOrderingTest\DiscoveredHighPriorityProvider;
use Acme\ProviderOrderingTest\DiscoveredMediumPriorityProvider;
use Acme\ProviderOrderingTest\DiscoveredNegativePriorityProvider;
use App\Providers\ProviderOrderingTest\AppAlphaProvider;
use App\Providers\ProviderOrderingTest\AppBetaProvider;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class ProviderOrderingTest extends TestCase
{
    use HasMockedApplication;

    protected function tearDown(): void
    {
        RegisterProviders::flushState();

        parent::tearDown();
    }

    public function testFrameworkProvidersLoadBeforeApplicationProviders()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
                FrameworkBetaProvider::class,
                AppAlphaProvider::class,
                AppBetaProvider::class,
            ],
            discoveredProviders: [],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            FrameworkAlphaProvider::class,
            FrameworkBetaProvider::class,
            AppAlphaProvider::class,
            AppBetaProvider::class,
        ], $order);
    }

    public function testDiscoveredProvidersLoadBetweenFrameworkAndApplicationProviders()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
                AppAlphaProvider::class,
            ],
            discoveredProviders: [
                DiscoveredDefaultProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            FrameworkAlphaProvider::class,
            DiscoveredDefaultProvider::class,
            AppAlphaProvider::class,
        ], $order);
    }

    public function testDiscoveredProvidersAreSortedByPriorityDescending()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
            ],
            discoveredProviders: [
                DiscoveredDefaultProvider::class,
                DiscoveredHighPriorityProvider::class,
                DiscoveredMediumPriorityProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            FrameworkAlphaProvider::class,
            DiscoveredHighPriorityProvider::class,    // priority 30
            DiscoveredMediumPriorityProvider::class,  // priority 10
            DiscoveredDefaultProvider::class,          // priority 0
        ], $order);
    }

    public function testNegativePriorityLoadsAfterDefaultPriority()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
            ],
            discoveredProviders: [
                DiscoveredNegativePriorityProvider::class,
                DiscoveredDefaultProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            FrameworkAlphaProvider::class,
            DiscoveredDefaultProvider::class,           // priority 0
            DiscoveredNegativePriorityProvider::class,  // priority -10
        ], $order);
    }

    public function testSamePriorityPreservesOriginalOrder()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
            ],
            discoveredProviders: [
                DiscoveredDefaultAlphaProvider::class,
                DiscoveredDefaultBetaProvider::class,
                DiscoveredDefaultProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        // All three are priority 0, so original discovery order is preserved
        $this->assertSame([
            FrameworkAlphaProvider::class,
            DiscoveredDefaultAlphaProvider::class,
            DiscoveredDefaultBetaProvider::class,
            DiscoveredDefaultProvider::class,
        ], $order);
    }

    public function testMixedPrioritiesWithAllThreeTiers()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
                FrameworkBetaProvider::class,
                AppAlphaProvider::class,
                AppBetaProvider::class,
            ],
            discoveredProviders: [
                DiscoveredDefaultProvider::class,
                DiscoveredHighPriorityProvider::class,
                DiscoveredNegativePriorityProvider::class,
                DiscoveredMediumPriorityProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            // Tier 1: Framework (Hypervel\*) from app.providers — array order
            FrameworkAlphaProvider::class,
            FrameworkBetaProvider::class,
            // Tier 2: Discovered — sorted by priority descending
            DiscoveredHighPriorityProvider::class,    // 30
            DiscoveredMediumPriorityProvider::class,  // 10
            DiscoveredDefaultProvider::class,          // 0
            DiscoveredNegativePriorityProvider::class, // -10
            // Tier 3: Application (non-Hypervel\*) from app.providers — array order
            AppAlphaProvider::class,
            AppBetaProvider::class,
        ], $order);
    }

    public function testDuplicateProviderIsOnlyRegisteredOnce()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
                AppAlphaProvider::class,
            ],
            discoveredProviders: [
                // Also discovered — should be deduplicated
                FrameworkAlphaProvider::class,
                DiscoveredDefaultProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        // FrameworkAlphaProvider appears only once (first occurrence wins)
        $this->assertSame([
            FrameworkAlphaProvider::class,
            DiscoveredDefaultProvider::class,
            AppAlphaProvider::class,
        ], $order);
    }

    public function testEmptyDiscoveredProvidersStillOrdersCorrectly()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
                AppAlphaProvider::class,
            ],
            discoveredProviders: [],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            FrameworkAlphaProvider::class,
            AppAlphaProvider::class,
        ], $order);
    }

    public function testOnlyDiscoveredProviders()
    {
        $app = $this->createAppWithProviders(
            configProviders: [],
            discoveredProviders: [
                DiscoveredDefaultProvider::class,
                DiscoveredHighPriorityProvider::class,
            ],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            DiscoveredHighPriorityProvider::class,  // priority 30
            DiscoveredDefaultProvider::class,        // priority 0
        ], $order);
    }

    public function testOnlyFrameworkProviders()
    {
        $app = $this->createAppWithProviders(
            configProviders: [
                FrameworkAlphaProvider::class,
                FrameworkBetaProvider::class,
            ],
            discoveredProviders: [],
        );

        $order = $app->make('registration_order');

        $this->assertSame([
            FrameworkAlphaProvider::class,
            FrameworkBetaProvider::class,
        ], $order);
    }

    public function testSortByPriorityWithEmptyArray()
    {
        $result = $this->callSortByPriority([]);

        $this->assertSame([], $result);
    }

    public function testSortByPriorityWithNonServiceProviderClass()
    {
        // Non-ServiceProvider classes get priority 0
        $result = $this->callSortByPriority([
            DiscoveredHighPriorityProvider::class,
            \stdClass::class,
            DiscoveredDefaultProvider::class,
        ]);

        $this->assertSame([
            DiscoveredHighPriorityProvider::class,  // priority 30
            \stdClass::class,                        // priority 0 (not a ServiceProvider)
            DiscoveredDefaultProvider::class,        // priority 0
        ], $result);
    }

    public function testDefaultPriorityIsZero()
    {
        $provider = new FrameworkAlphaProvider(
            $this->getApplication()
        );

        $this->assertSame(0, $provider->priority);
    }

    /**
     * Create an application with the given provider configuration and simulate
     * discovered providers via an Application subclass.
     *
     * @param array<int, class-string> $configProviders Providers for app.providers config
     * @param array<int, class-string> $discoveredProviders Providers to simulate as discovered
     */
    protected function createAppWithProviders(array $configProviders, array $discoveredProviders): Application
    {
        $mergedProviders = null;

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('app.providers')
            ->andReturn($configProviders);
        $config->shouldReceive('set')
            ->with('app.providers', m::type('array'))
            ->andReturnUsing(function (string $key, array $value) use (&$mergedProviders) {
                $mergedProviders = $value;
            });
        $config->shouldReceive('get')
            ->with('app.providers', [])
            ->andReturnUsing(function () use (&$mergedProviders) {
                return $mergedProviders ?? [];
            });

        // Use an Application subclass that returns our test discovered providers
        $app = new TestApplication('base_path', $discoveredProviders);

        $app->singleton(ConfigContract::class, fn () => $config);

        // Track registration order
        $app->instance('registration_order', []);

        // Run the bootstrapper (merges explicit providers into config)
        (new RegisterProviders())->bootstrap($app);

        return $app;
    }

    /**
     * Call the protected sortByPriority method via reflection.
     *
     * @param array<int, class-string> $providers
     * @return array<int, class-string>
     */
    protected function callSortByPriority(array $providers): array
    {
        $method = new ReflectionMethod(Application::class, 'sortByPriority');

        return $method->invoke(null, $providers);
    }
}

/**
 * Application subclass that overrides provider discovery for testing.
 */
class TestApplication extends Application
{
    /** @var array<int, class-string> */
    private array $testDiscoveredProviders;

    /**
     * @param array<int, class-string> $discoveredProviders
     */
    public function __construct(string $basePath, array $discoveredProviders)
    {
        $this->testDiscoveredProviders = $discoveredProviders;

        parent::__construct($basePath);
    }

    protected function discoverProviders(): array
    {
        return $this->testDiscoveredProviders;
    }
}
