<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Support\Providers;

use Hypervel\Auth\Events\Registered;
use Hypervel\Auth\Listeners\SendEmailVerificationNotification;
use Hypervel\Foundation\Support\Providers\EventServiceProvider;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventOne;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\AbstractListener;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\Listener;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\ListenerInterface;
use ReflectionMethod;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class EventServiceProviderTest extends TestCase
{
    public function testGetEventsMergesDiscoveredEventsWithListens()
    {
        if (! class_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\Listener', false)) {
            class_alias(Listener::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\Listener');
        }

        if (! class_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\AbstractListener', false)) {
            class_alias(AbstractListener::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\AbstractListener');
        }

        if (! interface_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\ListenerInterface', false)) {
            class_alias(ListenerInterface::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\ListenerInterface');
        }

        $provider = new EventServiceProviderWithDiscovery($this->app);

        EventServiceProvider::setEventDiscoveryPaths([
            __DIR__ . '/../../../Integration/Foundation/Fixtures/EventDiscovery/Listeners',
        ]);

        $events = $provider->getEvents();

        // Should have events from both discovery and $listen
        $this->assertArrayHasKey(EventOne::class, $events);
        $this->assertArrayHasKey('App\Events\CustomEvent', $events);
        $this->assertContains('App\Listeners\CustomListener', $events['App\Events\CustomEvent']);
    }

    public function testGetEventsReadsFromCacheWhenCached()
    {
        $cachePath = $this->app->getCachedEventsPath();
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cachedData = [
            EventServiceProvider::class => [
                'App\Events\CachedEvent' => ['App\Listeners\CachedListener'],
            ],
        ];

        file_put_contents($cachePath, '<?php return ' . var_export($cachedData, true) . ';');

        try {
            $provider = new EventServiceProvider($this->app);
            $events = $provider->getEvents();

            $this->assertSame(
                ['App\Events\CachedEvent' => ['App\Listeners\CachedListener']],
                $events
            );
        } finally {
            @unlink($cachePath);
        }
    }

    public function testGetEventsReturnsEmptyWhenCachedButProviderNotInCache()
    {
        $cachePath = $this->app->getCachedEventsPath();
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cachePath, '<?php return [];');

        try {
            $provider = new EventServiceProvider($this->app);
            $events = $provider->getEvents();

            $this->assertSame([], $events);
        } finally {
            @unlink($cachePath);
        }
    }

    public function testShouldDiscoverEventsReturnsTrueForBaseClass()
    {
        $provider = new EventServiceProvider($this->app);

        $this->assertTrue($provider->shouldDiscoverEvents());
    }

    public function testShouldDiscoverEventsReturnsFalseForSubclass()
    {
        $provider = new EventServiceProviderWithListens($this->app);

        $this->assertFalse($provider->shouldDiscoverEvents());
    }

    public function testDisableEventDiscovery()
    {
        EventServiceProvider::disableEventDiscovery();

        $provider = new EventServiceProvider($this->app);

        $this->assertFalse($provider->shouldDiscoverEvents());
    }

    public function testSetEventDiscoveryPaths()
    {
        EventServiceProvider::setEventDiscoveryPaths(['/custom/path']);

        $provider = new EventServiceProvider($this->app);

        $reflection = new ReflectionMethod($provider, 'discoverEventsWithin');
        $paths = $reflection->invoke($provider);

        $this->assertSame(['/custom/path'], $paths);
    }

    public function testAddEventDiscoveryPaths()
    {
        EventServiceProvider::setEventDiscoveryPaths(['/first/path']);
        EventServiceProvider::addEventDiscoveryPaths('/second/path');

        $provider = new EventServiceProvider($this->app);

        $reflection = new ReflectionMethod($provider, 'discoverEventsWithin');
        $paths = iterator_to_array($reflection->invoke($provider));

        $this->assertContains('/first/path', $paths);
        $this->assertContains('/second/path', $paths);
    }

    public function testAddEventDiscoveryPathsDeduplicates()
    {
        EventServiceProvider::setEventDiscoveryPaths(['/first/path']);
        EventServiceProvider::addEventDiscoveryPaths('/first/path');

        $provider = new EventServiceProvider($this->app);

        $reflection = new ReflectionMethod($provider, 'discoverEventsWithin');
        $paths = iterator_to_array($reflection->invoke($provider));

        $this->assertCount(1, $paths);
    }

    public function testDiscoverEventsWithinDefaultsToListenersDirectory()
    {
        $provider = new EventServiceProvider($this->app);

        $reflection = new ReflectionMethod($provider, 'discoverEventsWithin');
        $paths = $reflection->invoke($provider);

        $this->assertSame([$this->app->path('Listeners')], $paths);
    }

    public function testFlushStateResetsStaticProperties()
    {
        EventServiceProvider::disableEventDiscovery();
        EventServiceProvider::setEventDiscoveryPaths(['/custom/path']);

        EventServiceProvider::flushState();

        $provider = new EventServiceProvider($this->app);
        $this->assertTrue($provider->shouldDiscoverEvents());

        $reflection = new ReflectionMethod($provider, 'discoverEventsWithin');
        $paths = $reflection->invoke($provider);
        $this->assertSame([$this->app->path('Listeners')], $paths);
    }

    public function testRegisterRegistersListensAndSubscribersAndObservers()
    {
        Event::fake();

        $provider = new EventServiceProviderWithListens($this->app);
        $provider->register();

        // Trigger the booting callback
        $this->app->boot();

        // Dispatch and verify the listener was registered
        Event::dispatch(new stdClass());
        Event::assertDispatched('App\Events\CustomEvent', 0);

        event('App\Events\CustomEvent');
        Event::assertDispatched('App\Events\CustomEvent');
    }

    public function testConfigureEmailVerificationRegistersListenerWhenNotInListen()
    {
        $provider = new EventServiceProvider($this->app);

        $reflection = new ReflectionMethod($provider, 'configureEmailVerification');
        $reflection->invoke($provider);

        $dispatcher = $this->app->make('events');
        $this->assertTrue($dispatcher->hasListeners(Registered::class));
    }

    public function testConfigureEmailVerificationSkipsWhenAlreadyInListen()
    {
        $provider = new EventServiceProviderWithEmailVerification($this->app);

        $reflection = new ReflectionMethod($provider, 'configureEmailVerification');
        $reflection->invoke($provider);

        $dispatcher = $this->app->make('events');
        $listeners = $dispatcher->getListeners(Registered::class);

        // configureEmailVerification() should skip because Registered is already
        // in $listen with SendEmailVerificationNotification. No listener registered.
        $this->assertCount(0, $listeners);
    }
}

class EventServiceProviderWithListens extends EventServiceProvider
{
    protected array $listen = [
        'App\Events\CustomEvent' => [
            'App\Listeners\CustomListener',
        ],
    ];
}

class EventServiceProviderWithEmailVerification extends EventServiceProvider
{
    protected array $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];
}

class EventServiceProviderWithDiscovery extends EventServiceProvider
{
    protected array $listen = [
        'App\Events\CustomEvent' => [
            'App\Listeners\CustomListener',
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return true;
    }

    protected function eventDiscoveryBasePath(): string
    {
        return getcwd();
    }
}
