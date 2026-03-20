<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Support\DeferrableProvider;
use Hypervel\Support\AggregateServiceProvider;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class DeferredProviderTest extends TestCase
{
    public function testIsDeferredReturnsTrueForDeferrableProvider()
    {
        $provider = new DeferredTestProvider($this->app);

        $this->assertTrue($provider->isDeferred());
    }

    public function testIsDeferredReturnsFalseForNormalProvider()
    {
        $provider = new EagerTestProvider($this->app);

        $this->assertFalse($provider->isDeferred());
    }

    public function testProvidesReturnsServiceKeys()
    {
        $provider = new DeferredTestProvider($this->app);

        $this->assertSame(['deferred.service'], $provider->provides());
    }

    public function testProvidesReturnsEmptyArrayByDefault()
    {
        $provider = new EagerTestProvider($this->app);

        $this->assertSame([], $provider->provides());
    }

    public function testBoundReturnsTrueForDeferredServiceBeforeResolution()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        $this->assertTrue($this->app->bound('deferred.service'));
    }

    public function testIsDeferredServiceReturnsTrueForRegisteredDeferredService()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        $this->assertTrue($this->app->isDeferredService('deferred.service'));
    }

    public function testIsDeferredServiceReturnsFalseForUnknownService()
    {
        $this->assertFalse($this->app->isDeferredService('nonexistent.service'));
    }

    public function testDeferredServiceIsResolvedLazily()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        // Provider should not be loaded yet
        $this->assertFalse($this->app->providerIsLoaded(DeferredTestProvider::class));

        // Resolving the service triggers the provider to load
        $result = $this->app->make('deferred.service');

        $this->assertSame('deferred-value', $result);
        $this->assertTrue($this->app->providerIsLoaded(DeferredTestProvider::class));
    }

    public function testDeferredServiceRemovedFromListAfterProviderLoads()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        $this->assertTrue($this->app->isDeferredService('deferred.service'));

        $this->app->make('deferred.service');

        $this->assertFalse($this->app->isDeferredService('deferred.service'));
    }

    public function testLoadDeferredProvidersLoadsAllRemaining()
    {
        $this->app->addDeferredServices([
            'deferred.service' => DeferredTestProvider::class,
        ]);

        $this->assertFalse($this->app->providerIsLoaded(DeferredTestProvider::class));

        $this->app->loadDeferredProviders();

        $this->assertTrue($this->app->providerIsLoaded(DeferredTestProvider::class));
        $this->assertEmpty($this->app->getDeferredServices());
    }

    public function testRegisterDeferredProviderRegistersAndBootsProvider()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        $this->app->registerDeferredProvider(DeferredTestProvider::class, 'deferred.service');

        $this->assertTrue($this->app->providerIsLoaded(DeferredTestProvider::class));
        $this->assertSame('deferred-value', $this->app->make('deferred.service'));
    }

    public function testRemoveDeferredServicesRemovesFromList()
    {
        $this->app->addDeferredServices([
            'service.a' => DeferredTestProvider::class,
            'service.b' => DeferredTestProvider::class,
        ]);

        $this->app->removeDeferredServices(['service.a']);

        $this->assertFalse($this->app->isDeferredService('service.a'));
        $this->assertTrue($this->app->isDeferredService('service.b'));
    }

    public function testSetDeferredServicesReplacesEntireList()
    {
        $this->app->addDeferredServices(['old.service' => DeferredTestProvider::class]);

        $this->app->setDeferredServices(['new.service' => EagerTestProvider::class]);

        $this->assertFalse($this->app->isDeferredService('old.service'));
        $this->assertTrue($this->app->isDeferredService('new.service'));
    }

    public function testFlushClearsDeferredServices()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        $this->app->flush();

        $this->assertEmpty($this->app->getDeferredServices());
    }

    public function testAggregateServiceProviderRegistersChildProviders()
    {
        $this->app->register(AggregateTestProvider::class);

        $this->assertTrue($this->app->providerIsLoaded(EagerTestProvider::class));
    }

    public function testAggregateServiceProviderAggregatesProvides()
    {
        $provider = new AggregateDeferredTestProvider($this->app);

        $this->assertSame(['deferred.service'], $provider->provides());
    }

    public function testDeferredSingletonIsCachedAfterFirstResolution()
    {
        $this->app->addDeferredServices(['deferred.service' => DeferredTestProvider::class]);

        $first = $this->app->make('deferred.service');
        $second = $this->app->make('deferred.service');

        $this->assertSame($first, $second);
    }

    public function testDeferredBindReturnsFreshInstanceEachTime()
    {
        $this->app->addDeferredServices(['deferred.bind' => DeferredBindProvider::class]);

        $first = $this->app->make('deferred.bind');
        $second = $this->app->make('deferred.bind');

        $this->assertNotSame($first, $second);
        $this->assertInstanceOf(stdClass::class, $first);
        $this->assertInstanceOf(stdClass::class, $second);
    }

    public function testDeferredInstanceRegistration()
    {
        $this->app->addDeferredServices(['deferred.instance' => DeferredInstanceProvider::class]);

        $result = $this->app->make('deferred.instance');

        $this->assertSame('instance-value', $result->value);
        $this->assertTrue($this->app->providerIsLoaded(DeferredInstanceProvider::class));
    }

    public function testDeferredScopedIsScopedNotSingleton()
    {
        $this->app->addDeferredServices(['deferred.scoped' => DeferredScopedProvider::class]);

        $first = $this->app->make('deferred.scoped');

        // Within the same coroutine/scope, should return same instance
        $second = $this->app->make('deferred.scoped');

        $this->assertSame($first, $second);

        // Should be registered as scoped
        $this->assertTrue($this->app->isScoped('deferred.scoped'));
    }

    public function testPlaceholderDoesNotOverrideExistingBinding()
    {
        // Pre-register a real binding
        $this->app->singleton('already.bound', fn () => 'real-value');

        // Adding deferred services should not overwrite the existing binding
        $this->app->addDeferredServices(['already.bound' => DeferredTestProvider::class]);

        $this->assertSame('real-value', $this->app->make('already.bound'));
    }

    public function testDeferredProviderResolvedBeforeBootIsBootedOnlyOnce()
    {
        DeferredBootCountProvider::$bootCount = 0;

        // Use a fresh unbooted application
        $app = new \Hypervel\Foundation\Application($this->app->basePath());

        $app->addDeferredServices(['boot.count' => DeferredBootCountProvider::class]);

        // Resolve before boot — provider registers but should NOT boot yet
        $app->make('boot.count');
        $this->assertSame(0, DeferredBootCountProvider::$bootCount);

        // Boot the app — provider should be booted exactly once
        $app->boot();
        $this->assertSame(1, DeferredBootCountProvider::$bootCount);
    }
}

class DeferredTestProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('deferred.service', fn () => 'deferred-value');
    }

    public function provides(): array
    {
        return ['deferred.service'];
    }
}

class EagerTestProvider extends ServiceProvider
{
    public function register(): void
    {
    }
}

class AggregateTestProvider extends AggregateServiceProvider
{
    protected array $providers = [
        EagerTestProvider::class,
    ];
}

class AggregateDeferredTestProvider extends AggregateServiceProvider
{
    protected array $providers = [
        DeferredTestProvider::class,
    ];
}

class DeferredBindProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->bind('deferred.bind', fn () => new stdClass());
    }

    public function provides(): array
    {
        return ['deferred.bind'];
    }
}

class DeferredInstanceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $object = new stdClass();
        $object->value = 'instance-value';
        $this->app->instance('deferred.instance', $object);
    }

    public function provides(): array
    {
        return ['deferred.instance'];
    }
}

class DeferredScopedProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->scoped('deferred.scoped', fn () => new stdClass());
    }

    public function provides(): array
    {
        return ['deferred.scoped'];
    }
}

class DeferredBootCountProvider extends ServiceProvider implements DeferrableProvider
{
    public static int $bootCount = 0;

    public function register(): void
    {
        $this->app->singleton('boot.count', fn () => 'boot-count-value');
    }

    public function boot(): void
    {
        ++static::$bootCount;
    }

    public function provides(): array
    {
        return ['boot.count'];
    }
}
