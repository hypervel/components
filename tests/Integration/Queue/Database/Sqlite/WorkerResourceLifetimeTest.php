<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\Factory as QueueManager;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\PublishesOutboxMessages;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager as ObjectPoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\Looping;
use Hypervel\Queue\Events\OutboxBatchDelivered;
use Hypervel\Queue\Events\WorkerIdle;
use Hypervel\Queue\Events\WorkerPausing;
use Hypervel\Queue\Events\WorkerStarting;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\Outbox\OutboxRelay;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SyncQueue;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;
use Throwable;

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
        $config->set('queue.outbox.databases', ['worker_resource']);
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

    #[DataProvider('outboxPublicationOutcomes')]
    public function testOutboxPublicationReleasesOneSlotPoolsAcrossBlockingAndCancellation(bool $cancel): void
    {
        $waiter = new Waiter(-1);
        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $waiter->wait(function () use ($repository): void {
            $migration = require dirname(__DIR__, 5) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php';
            $migration->up();
            $repository->insert(DB::connection(), [[
                'uuid' => (string) Str::uuid(), 'connection' => 'transport', 'queue' => 'orders',
                'payload' => '{"displayName":"SendInvoice"}', 'options' => null,
                'deliver_at' => null, 'available_at' => 1, 'created_at' => 1,
            ]]);
        });
        $databasePool = $this->app->make(PoolManager::class)->pool('worker_resource');
        $objectPools = new ObjectPoolManager;
        $started = new Channel(1);
        $finish = new Channel(1);
        $transport = m::mock(SyncQueue::class . ', ' . PublishesOutboxMessages::class)->makePartial();
        $transport->expects('publishOutboxMessages')->andReturnUsing(function (array $messages) use ($started, $finish, $databasePool): OutboxBatchResult {
            $this->assertSame(1, $databasePool->getIdleCount());
            $started->push(true);
            $this->assertTrue($finish->pop(5));

            return new OutboxBatchResult(array_column($messages, 'id'));
        });
        $proxy = new QueuePoolProxy(
            new PoolDefinition('outbox-resource-test', 'queue', 'outbox-resource-test', PoolOptions::fromArray(['max_objects' => 1])),
            static fn (): SyncQueue => $transport,
            $objectPools,
        );
        $queues = m::mock(QueueManager::class);
        $queues->expects('connection')->with('transport')->andReturn($proxy);
        $events = $this->app->make(EventDispatcher::class);
        $settled = false;
        $observed = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use ($objectPools, &$settled): void {
            if (str_starts_with($event->sql, 'delete from "job_outbox"')) {
                $this->assertSame(0, $objectPools->get('outbox-resource-test')->getBorrowedCount());
                $settled = true;
            }
        });
        $events->listen(OutboxBatchDelivered::class, function () use ($databasePool, &$observed): void {
            $this->assertSame(1, $databasePool->getIdleCount());
            DB::selectOne('SELECT 1');
            $observed = true;
        });
        $relay = new OutboxRelay($repository, $queues, $events, m::mock(ExceptionHandlerContract::class));
        $messages = $relay->claim('worker_resource', 100);
        $failure = null;
        $result = null;
        $runner = Coroutine::create(static function () use ($relay, $messages, &$failure, &$result): void {
            try {
                $result = $relay->deliver('worker_resource', $messages);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });

        try {
            $this->assertTrue($started->pop(2));
            $this->assertSame(1, $objectPools->get('outbox-resource-test')->getBorrowedCount());
            $this->assertSame(1, $databasePool->getIdleCount());
            $this->assertSame(1, $waiter->wait(static fn (): int => (int) DB::selectOne('SELECT 1 AS value')->value));

            if ($cancel) {
                EngineCoroutine::cancelById($runner, throwException: true);
            } else {
                $finish->push(true);
            }

            Coroutine::join([$runner], 2);
            $this->assertFalse(Coroutine::exists($runner));
            $this->assertSame(1, $databasePool->getIdleCount());
            $this->assertSame(0, $objectPools->get('outbox-resource-test')->getBorrowedCount());
            $this->assertSame(! $cancel, $settled);
            $this->assertSame(! $cancel, $observed);
            $this->assertSame($cancel ? 1 : 0, $waiter->wait(static fn (): int => DB::table('job_outbox')->count()));

            if ($cancel) {
                $this->assertInstanceOf(CanceledException::class, $failure);
            } else {
                $this->assertNull($failure);
                $this->assertTrue($result);
            }
        } finally {
            $finish->close();
            Coroutine::join([$runner], 5);
            $objectPools->purgeAll();
        }
    }

    /**
     * Cover normal settlement and cancellation while the transport is blocked.
     */
    public static function outboxPublicationOutcomes(): array
    {
        return [[false], [true]];
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
        $job->expects('isReleased')->once()->andReturnFalse();
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

    public function testDaemonJobsHaveIndependentRecordModificationState(): void
    {
        (new Waiter(-1))->wait(static fn (): bool => DB::statement('CREATE TABLE records (id INTEGER)'));
        $modificationStates = [];
        $jobs = [];

        foreach ([
            static function () use (&$modificationStates): void {
                DB::table('records')->insert(['id' => 1]);
                $modificationStates[] = DB::connection()->hasModifiedRecords();
            },
            static function () use (&$modificationStates): void {
                $modificationStates[] = DB::connection()->hasModifiedRecords();
            },
        ] as $handler) {
            $job = m::mock(JobContract::class);
            $job->expects('payload')->twice()->andReturn([]);
            $job->expects('maxTries')->once()->andReturnNull();
            $job->expects('retryUntil')->once()->andReturnNull();
            $job->expects('attempts')->once()->andReturn(1);
            $job->expects('isDeleted')->once()->andReturnFalse();
            $job->expects('isReleased')->once()->andReturnFalse();
            $job->expects('timeout')->once()->andReturnNull();
            $job->expects('fire')->once()->andReturnUsing($handler);
            $jobs[] = $job;
        }

        $connection = m::mock(Queue::class);
        $connection->expects('getConnectionName')->times(6)->andReturn('default');
        $connection->expects('pop')->twice()->with('queue')->andReturn(...$jobs);
        $manager = m::mock(QueueManager::class);
        $manager->expects('connection')->twice()->with('default')->andReturn($connection);
        $worker = new class($manager, $this->app->make(EventDispatcher::class), $this->app->make(ExceptionHandlerContract::class), static fn (): bool => false) extends Worker {
            protected function supportsAsyncSignals(): bool
            {
                return false;
            }
        };

        $this->assertSame(
            Worker::EXIT_SUCCESS,
            $worker->daemon('default', 'queue', new WorkerOptions(maxJobs: 2, memory: 1024)),
        );
        $this->assertSame([true, false], $modificationStates);
    }

    public function testTimeoutAndSignalCallbacksReleasePooledConnectionsAfterEachBatch(): void
    {
        $connectionName = $this->app->make('config')->string('database.default');
        $pool = $this->app->make(PoolManager::class)->pool($connectionName);
        $events = $this->app->make(EventDispatcher::class);
        $timer = new Timer;
        $events->listen(WorkerPausing::class, static function (): void {
            DB::selectOne('SELECT 1');
        });
        $worker = new class(m::mock(QueueManager::class), $events, $this->app->make(ExceptionHandlerContract::class), static fn (): bool => false, $timer) extends Worker {
            public Channel $completed;

            /**
             * Start the timeout monitor.
             */
            public function startMonitorForTest(WorkerOptions $options): void
            {
                $this->monitorTimeoutJobs($options, 'default', 'queue');
            }

            /**
             * Dispatch and drain a pause signal.
             */
            public function pauseForTest(WorkerOptions $options): void
            {
                $this->handlePauseSignal('default', 'queue', $options);
                $this->drainPendingSignals(new Waiter(-1));
            }

            /**
             * Borrow a connection and signal after deferred cleanup.
             */
            protected function terminateTimeoutJobs(WorkerOptions $options): void
            {
                $succeeded = false;
                Coroutine::defer(function () use (&$succeeded): void {
                    $this->completed->push($succeeded);
                });
                DB::selectOne('SELECT 1');
                $succeeded = true;
            }
        };
        $worker->completed = new Channel(1);
        $options = new WorkerOptions(monitorInterval: 0);

        try {
            $worker->startMonitorForTest($options);
            $this->assertTrue($worker->completed->pop(1.0));
            $this->assertSame(1, $pool->getIdleCount());
        } finally {
            $timer->clearAll();
        }

        $worker->pauseForTest($options);
        $this->assertSame(1, $pool->getIdleCount());
    }
}
