<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Hypervel\Contracts\Queue\EntityResolver;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\ConcurrencyErrorDetector;
use Hypervel\Database\DatabaseServiceProvider;
use Hypervel\Database\Eloquent\QueueEntityResolver;
use Hypervel\Events\Dispatcher;
use Hypervel\Testbench\TestCase;
use Swoole\Constant;
use Throwable;

class DatabaseServiceProviderTest extends TestCase
{
    public function testReloadConfigurationRebuildsTheConnectionResolverFromCurrentConfiguration(): void
    {
        config(['database.default' => 'first']);
        $resolver = $this->app->make('db.resolver');
        $directResolver = $this->app->make(ConnectionResolver::class);

        $this->assertNotSame($resolver, $directResolver);
        $this->assertSame('first', $resolver->getDefaultConnection());
        $this->assertSame('first', $directResolver->getDefaultConnection());

        config(['database.default' => 'second']);
        $this->app->getProvider(DatabaseServiceProvider::class)->reloadConfiguration();

        $refreshedResolver = $this->app->make('db.resolver');
        $refreshedDirectResolver = $this->app->make(ConnectionResolver::class);
        $this->assertNotSame($resolver, $refreshedResolver);
        $this->assertNotSame($directResolver, $refreshedDirectResolver);
        $this->assertNotSame($refreshedResolver, $refreshedDirectResolver);
        $this->assertSame('second', $refreshedResolver->getDefaultConnection());
        $this->assertSame('second', $refreshedDirectResolver->getDefaultConnection());
    }

    public function testConcurrencyErrorDetectorIsRegistered(): void
    {
        $this->assertInstanceOf(
            ConcurrencyErrorDetector::class,
            $this->app->make(ConcurrencyErrorDetectorContract::class),
        );
    }

    public function testConcurrencyErrorDetectorCanBeOverridden(): void
    {
        $detector = new class implements ConcurrencyErrorDetectorContract {
            public function causedByConcurrencyError(Throwable $e): bool
            {
                return false;
            }
        };

        $this->app->instance(ConcurrencyErrorDetectorContract::class, $detector);

        // Reproduce an application binding the contract before provider registration.
        (new DatabaseServiceProvider($this->app))->register();

        $this->assertSame($detector, $this->app->make(ConcurrencyErrorDetectorContract::class));
    }

    public function testQueueEntityResolverIsRegistered(): void
    {
        $this->assertInstanceOf(
            QueueEntityResolver::class,
            $this->app->make(EntityResolver::class),
        );
    }

    public function testNonCoroutineTaskLifecycleListenersAreRegistered(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(false);

        $this->assertTrue($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    public function testCoroutineTasksDoNotRegisterTerminalTaskCleanup(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(true);

        $this->assertFalse($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    /**
     * Boot a fresh provider against an isolated event dispatcher.
     */
    private function bootProviderWithTaskCoroutines(bool $enabled): Dispatcher
    {
        $this->app->make('config')->set(
            'server.settings.' . Constant::OPTION_TASK_ENABLE_COROUTINE,
            $enabled,
        );

        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);

        (new DatabaseServiceProvider($this->app))->boot();

        return $events;
    }
}
