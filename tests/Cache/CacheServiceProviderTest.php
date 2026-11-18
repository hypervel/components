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
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Support\Facades\Cache;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Swoole\Server as SwooleServer;

class CacheServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testConsoleFinalizationRunsAfterEveryProviderCanContribute(): void
    {
        $manager = $this->manager();
        $manager->allowSerializableClassesUsing(
            static fn (): array => [CachePolicyEarlyContribution::class],
        );
        $events = Double::for(Dispatcher::class)->strict();
        $listeners = [];
        $events->expects('listen')
            ->times(2)
            ->resolves(function (mixed $event, mixed $listener) use (&$listeners): void {
                $listeners[$event][] = $listener;
            });
        $bootedCallback = null;
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')
            ->with(CacheManager::class)
            ->returns($manager);
        $application->expects('make')
            ->with('events')
            ->returns($events);
        $application->expects('runningInConsole')
            ->returns(true);
        $application->expects('booted')
            ->with(Argument::capture($bootedCallback));

        (new CacheServiceProvider($application->instance()))->boot();

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

    #[TestWith([false], 'server worker')]
    #[TestWith([true], 'custom server process')]
    public function testServerFinalizationResolvesTheWorkerManagerAtEventTime(bool $customProcess): void
    {
        $workerManager = $this->manager();
        $events = Double::for(Dispatcher::class)->strict();
        $listeners = [];
        $events->expects('listen')
            ->times(3)
            ->resolves(function (mixed $event, mixed $listener) use (&$listeners): void {
                foreach ((array) $event as $name) {
                    $listeners[$name][] = $listener;
                }
            });
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')
            ->with(CacheManager::class)
            ->returns($workerManager);
        $application->expects('make')
            ->with('events')
            ->returns($events);
        $application->expects('runningInConsole')
            ->returns(false);
        $application->expects('booted')->never();
        $provider = new CacheServiceProviderFixture($application->instance());

        $provider->boot();

        $this->assertTrue($provider->bootCalled);
        $this->assertCount(1, $listeners[BeforeServerStart::class]);
        $this->assertCount(2, $listeners[AfterWorkerStart::class]);

        if ($customProcess) {
            $event = new BeforeProcessHandle(Double::for(AbstractProcess::class)->strict(), 0);
        } else {
            $server = Double::for(SwooleServer::class)->strict();
            // Policy finalization runs in request workers and taskworkers, unlike Swoole timer registration.
            $server->taskworker = true;
            $event = new AfterWorkerStart($server, 9);
        }

        $this->assertArrayHasKey($event::class, $listeners);
        $listeners[$event::class][array_key_last($listeners[$event::class])]($event);

        $this->expectException(LogicException::class);
        $workerManager->allowSerializableClassesUsing(static fn (): array => []);
    }

    public function testFacadeCallsTheManagerExtensionWithoutResolvingAStore(): void
    {
        $resolver = static fn (): array => [CachePolicyEarlyContribution::class];
        $manager = Double::for(CacheManager::class)->strict();
        $manager->expects('allowSerializableClassesUsing')
            ->with(Argument::same($resolver));
        $manager->expects('store')->never();
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
