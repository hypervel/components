<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Closure;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\Factory as QueueManager;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\Looping;
use Hypervel\Queue\Events\WorkerIdle;
use Hypervel\Queue\Events\WorkerPausing;
use Hypervel\Queue\Events\WorkerStarting;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Mockery as m;

class WorkerResourceLifetimeTest extends TestCase
{
    protected string $databaseDirectory;

    /**
     * Create the database owned by the worker lifecycle test.
     */
    protected function setUp(): void
    {
        $this->databaseDirectory = ParallelTesting::tempDir('WorkerResourceLifetimeTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->databaseDirectory);
        $files->ensureDirectoryExists($this->databaseDirectory);
        touch($this->databaseDirectory . '/database.sqlite');

        parent::setUp();
    }

    /**
     * Close the application before removing its database.
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->databaseDirectory);
        }
    }

    /**
     * Configure an isolated pooled SQLite connection.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'worker_resource');
        $config->set('database.connections.worker_resource', [
            'driver' => 'sqlite',
            'database' => $this->databaseDirectory . '/database.sqlite',
            'prefix' => '',
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 1,
                'max_connections' => 1,
            ],
        ]);
    }

    public function testLifecycleCallbacksReleasePooledConnectionsBeforeTheDaemonAdvances(): void
    {
        $connectionName = $this->app->make('config')->string('database.default');
        $poolManager = $this->app->make(PoolManager::class);
        $events = $this->app->make(EventDispatcher::class);
        $observed = [];

        $events->listen(WorkerStarting::class, static function () use (&$observed): void {
            DB::selectOne('SELECT 1');
            $observed[] = 'starting';
        });
        $events->listen(Looping::class, function () use ($poolManager, $connectionName, &$observed): void {
            $this->assertSame(1, $poolManager->pool($connectionName)->getIdleCount());
            DB::selectOne('SELECT 1');
            $observed[] = 'looping';
        });
        $events->listen(JobPopping::class, function () use ($poolManager, $connectionName, &$observed): void {
            $this->assertSame(1, $poolManager->pool($connectionName)->getIdleCount());
            DB::selectOne('SELECT 1');
            $observed[] = 'popping';
        });
        $events->listen(WorkerIdle::class, function () use ($poolManager, $connectionName, &$observed): void {
            $this->assertSame(0, $poolManager->pool($connectionName)->getIdleCount());
            DB::selectOne('SELECT 1');
            $observed[] = 'idle';
        });
        $events->listen(WorkerStopping::class, function () use ($poolManager, $connectionName, &$observed): void {
            $this->assertSame(1, $poolManager->pool($connectionName)->getIdleCount());
            $observed[] = 'stopping';
        });

        $connection = m::mock(Queue::class);
        $connection->expects('getConnectionName')->twice()->andReturn('default');
        $connection->expects('pop')->once()->with('queue')->andReturnNull();
        $manager = m::mock(QueueManager::class);
        $manager->expects('connection')->once()->with('default')->andReturn($connection);
        $worker = new class($manager, $events, $this->app->make(ExceptionHandlerContract::class), static fn (): bool => false) extends Worker {
            protected function supportsAsyncSignals(): bool
            {
                return false;
            }
        };

        $this->assertSame(
            Worker::EXIT_SUCCESS,
            $worker->daemon('default', 'queue', new WorkerOptions(stopWhenEmpty: true, memory: 1024)),
        );
        $this->assertSame(['starting', 'looping', 'popping', 'idle', 'stopping'], $observed);
        $this->assertSame(1, $poolManager->pool($connectionName)->getIdleCount());
    }

    public function testStoppingWaitsForAdmittedJobDeferredCleanup(): void
    {
        $connectionName = $this->app->make('config')->string('database.default');
        $pool = $this->app->make(PoolManager::class)->pool($connectionName);
        $events = $this->app->make(EventDispatcher::class);
        $stopped = false;

        $events->listen(WorkerStopping::class, function () use ($pool, &$stopped): void {
            $this->assertSame(1, $pool->getIdleCount());
            DB::selectOne('SELECT 1');
            $stopped = true;
        });

        $job = m::mock(JobContract::class);
        $job->expects('payload')->twice()->andReturn([]);
        $job->expects('maxTries')->once()->andReturnNull();
        $job->expects('retryUntil')->once()->andReturnNull();
        $job->expects('attempts')->once()->andReturn(1);
        $job->expects('isDeleted')->once()->andReturnFalse();
        $job->expects('timeout')->once()->andReturnNull();
        $job->expects('fire')->once()->andReturnUsing(static function (): void {
            DB::selectOne('SELECT 1');
        });

        $connection = m::mock(Queue::class);
        $connection->expects('getConnectionName')->times(3)->andReturn('default');
        $connection->expects('pop')->once()->with('queue')->andReturn($job);
        $manager = m::mock(QueueManager::class);
        $manager->expects('connection')->once()->with('default')->andReturn($connection);
        $worker = new class($manager, $events, $this->app->make(ExceptionHandlerContract::class), static fn (): bool => false) extends Worker {
            protected function supportsAsyncSignals(): bool
            {
                return false;
            }
        };

        $this->assertSame(
            Worker::EXIT_SUCCESS,
            $worker->daemon('default', 'queue', new WorkerOptions(maxJobs: 1, memory: 1024)),
        );
        $this->assertTrue($stopped);
    }

    public function testTimeoutAndSignalCallbacksReleasePooledConnectionsAfterEachBatch(): void
    {
        $connectionName = $this->app->make('config')->string('database.default');
        $pool = $this->app->make(PoolManager::class)->pool($connectionName);
        $events = $this->app->make(EventDispatcher::class);
        $timer = new WorkerResourceTimer;
        $events->listen(WorkerPausing::class, static function (): void {
            DB::selectOne('SELECT 1');
        });
        $worker = new class(m::mock(QueueManager::class), $events, $this->app->make(ExceptionHandlerContract::class), static fn (): bool => false, $timer) extends Worker {
            public function startMonitorForTest(WorkerOptions $options): void
            {
                $this->monitorTimeoutJobs($options);
            }

            public function pauseForTest(WorkerOptions $options): void
            {
                $this->handlePauseSignal('default', 'queue', $options);
                $this->drainPendingSignals(new Waiter(-1));
            }

            protected function terminateTimeoutJobs(WorkerOptions $options): void
            {
                DB::selectOne('SELECT 1');
            }
        };
        $options = new WorkerOptions;

        $worker->startMonitorForTest($options);
        $timer->fire();
        $this->assertSame(1, $pool->getIdleCount());

        $worker->pauseForTest($options);
        $this->assertSame(1, $pool->getIdleCount());
    }
}

class WorkerResourceTimer extends Timer
{
    protected ?Closure $callback = null;

    public function tick(
        float $timeout,
        callable $closure,
        string $identifier = Constants::WORKER_EXIT,
    ): int {
        $this->callback = Closure::fromCallable($closure);

        return 1;
    }

    public function fire(): void
    {
        ($this->callback)();
    }
}
