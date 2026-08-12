<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\CacheServiceProvider;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Support\Facades\Cache;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Swoole\Server as SwooleServer;

class CacheServiceProviderTest extends TestCase
{
    public function testConsoleFinalizationRunsAfterEveryProviderCanContribute(): void
    {
        $manager = $this->manager();
        $manager->allowSerializableClassesUsing(
            static fn (): array => [CachePolicyEarlyContribution::class],
        );
        $events = m::mock(Dispatcher::class);
        $listeners = [];
        $events->shouldReceive('listen')
            ->times(2)
            ->andReturnUsing(function (mixed $event, mixed $listener) use (&$listeners): void {
                $listeners[$event][] = $listener;
            });
        $bootedCallback = null;
        $application = m::mock(Application::class);
        $application->shouldReceive('make')
            ->once()
            ->with(CacheManager::class)
            ->andReturn($manager);
        $application->shouldReceive('make')
            ->once()
            ->with('events')
            ->andReturn($events);
        $application->shouldReceive('runningInConsole')
            ->once()
            ->andReturnTrue();
        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$bootedCallback): bool {
                $bootedCallback = $callback;

                return $callback instanceof Closure;
            }));

        (new CacheServiceProvider($application))->boot();

        $manager->allowSerializableClassesUsing(
            static fn (): array => [CachePolicyLateContribution::class],
        );

        $this->assertCount(1, $listeners[BeforeServerStart::class]);
        $this->assertCount(1, $listeners[AfterWorkerStart::class]);
        $this->assertInstanceOf(Closure::class, $bootedCallback);

        $bootedCallback();

        $store = $manager->build(['driver' => 'array', 'serialize' => true]);
        $store->put('objects', [
            new CachePolicyEarlyContribution,
            new CachePolicyLateContribution,
        ], 60);
        $objects = $store->get('objects');

        $this->assertInstanceOf(CachePolicyEarlyContribution::class, $objects[0]);
        $this->assertInstanceOf(CachePolicyLateContribution::class, $objects[1]);

        $this->expectException(LogicException::class);
        $manager->allowSerializableClassesUsing(static fn (): array => []);
    }

    public function testServerFinalizationResolvesTheWorkerManagerAtEventTime(): void
    {
        $workerManager = $this->manager();
        $events = m::mock(Dispatcher::class);
        $listeners = [];
        $events->shouldReceive('listen')
            ->times(3)
            ->andReturnUsing(function (mixed $event, mixed $listener) use (&$listeners): void {
                $listeners[$event][] = $listener;
            });
        $application = m::mock(Application::class);
        $application->shouldReceive('make')
            ->once()
            ->with(CacheManager::class)
            ->andReturn($workerManager);
        $application->shouldReceive('make')
            ->once()
            ->with('events')
            ->andReturn($events);
        $application->shouldReceive('runningInConsole')
            ->once()
            ->andReturnFalse();
        $application->shouldNotReceive('booted');
        $provider = new CacheServiceProviderFixture($application);

        $provider->boot();

        $this->assertTrue($provider->bootCalled);
        $this->assertCount(1, $listeners[BeforeServerStart::class]);
        $this->assertCount(2, $listeners[AfterWorkerStart::class]);

        $server = m::mock(SwooleServer::class);
        // Policy finalization runs in request workers and taskworkers, unlike Swoole timer registration.
        $server->taskworker = true;
        $listeners[AfterWorkerStart::class][1](new AfterWorkerStart($server, 9));

        $this->expectException(LogicException::class);
        $workerManager->allowSerializableClassesUsing(static fn (): array => []);
    }

    public function testFacadeCallsTheManagerExtensionWithoutResolvingAStore(): void
    {
        $resolver = static fn (): array => [CachePolicyEarlyContribution::class];
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')
            ->once()
            ->with($resolver)
            ->andReturnSelf();
        $manager->shouldNotReceive('store');
        Cache::setFacadeApplication(null);
        Cache::swap($manager);

        try {
            $this->assertSame($manager, Cache::allowSerializableClassesUsing($resolver));
        } finally {
            Cache::clearResolvedInstance();
        }
    }

    /**
     * Create a cache manager with a restricted serializable-class policy.
     */
    private function manager(): CacheManager
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository([
            'cache' => [
                'serializable_classes' => false,
            ],
        ]));

        return new CacheManager($container);
    }
}

class CacheServiceProviderFixture extends CacheServiceProvider
{
    public bool $bootCalled = false;

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->bootCalled = true;

        parent::boot();
    }
}

class CachePolicyEarlyContribution
{
}

class CachePolicyLateContribution
{
}
