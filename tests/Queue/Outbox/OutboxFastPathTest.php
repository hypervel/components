<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\Outbox\OutboxDispatcher;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\RedisQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use UnitEnum;

class OutboxFastPathTest extends TestCase
{
    /**
     * Configure isolated databases without installing any outbox tables.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('database.default', 'source');
        $config->set('database.connections.source', ['driver' => 'sqlite', 'database' => ':memory:']);
        $config->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:']);
        $config->set('queue.outbox.databases', ['source', 'secondary']);
        $config->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'source', 'table' => 'jobs',
            'queue' => 'default', 'after_commit' => true, 'outbox' => true,
        ]);
    }

    #[DataProvider('transactionOutcomes')]
    public function testLocalSelectionHonorsCommitAndRollbackWithoutSchema(bool $commit): void
    {
        $log = $this->app->make(OutboxExecutionLog::class);
        $queue = $this->app->make('queue')->connection('sync');
        DB::beginTransaction();
        $queue->push((new OutboxLocalJob)->viaOutbox()->beforeCommit());
        $this->assertSame(0, $log->executions);

        $commit ? DB::commit() : DB::rollBack();

        $this->assertSame($commit ? 1 : 0, $log->executions);
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
    }

    public function testLocalExplicitOwnerDoesNotWaitForAnUnrelatedTransaction(): void
    {
        $log = $this->app->make(OutboxExecutionLog::class);
        DB::beginTransaction();
        DB::connection('secondary')->beginTransaction();
        $this->app->make('queue')->connection('sync')->push((new OutboxLocalJob)->onOutboxDatabase('secondary'));

        DB::connection('secondary')->commit();
        $this->assertSame(1, $log->executions);
        DB::rollBack();
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
    }

    public function testLocalExplicitOwnerRejectsAnUnrelatedActiveTransaction(): void
    {
        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('does not own an active transaction');
            $this->app->make('queue')->connection('sync')->push((new OutboxLocalJob)->onOutboxDatabase('secondary'));
        } finally {
            DB::rollBack();
        }
    }

    public function testNullBulkAndQueueFakeDoNotResolveOutboxStorage(): void
    {
        $jobs = [new MarkedOutboxLocalJob, (new OutboxLocalJob)->viaOutbox()];
        $this->app->make('queue')->connection('null')->bulk($jobs);
        $fake = new QueueFake($this->app);
        $fake->bulk($jobs);

        $fake->assertPushed(OutboxLocalJob::class);
        $fake->assertPushed(MarkedOutboxLocalJob::class);
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
        $this->assertSame(0, $this->app->make(OutboxExecutionLog::class)->executions);
    }

    public function testDatabaseQueueUsesItsExistingAtomicStorageAndIgnoresAfterCommitDefault(): void
    {
        (require dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000006_testbench_create_jobs_table.php')->up();
        $queue = $this->app->make('queue')->connection('database');
        $id = $queue->push(new OutboxLocalJob);
        $this->assertIsInt($id);
        $this->assertSame(1, DB::table('jobs')->count());

        DB::beginTransaction();
        $queue->bulk([new OutboxLocalJob, (new OutboxLocalJob)->onOutboxDatabase('source::write')]);
        $this->assertSame(3, DB::table('jobs')->count());
        DB::rollBack();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
    }

    public function testDatabaseQueueOnAnotherOwnerStagesInsideTheBusinessTransaction(): void
    {
        (require dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php')->up();
        config(['queue.connections.database.connection' => 'secondary']);
        $queue = $this->app->make('queue')->connection('database');

        DB::beginTransaction();
        $id = $queue->push(new OutboxLocalJob);
        $this->assertSame($id, DB::table('job_outbox')->sole()->uuid);
        DB::rollBack();
        $this->assertSame(0, DB::table('job_outbox')->count());
    }

    public function testFailoverSuppressesChildDefaultsAndRestoresThemAfterNestedFailure(): void
    {
        $primary = new FailingOutboxRedisQueue(m::mock(Factory::class), 'orders');
        $primary->setContainer($this->app)->setConnectionName('primary')->setConfig(['outbox' => true]);
        $sync = $this->app->make('queue')->connection('sync');
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('primary')->andReturn($primary);
        $manager->shouldReceive('connection')->with('sync')->andReturn($sync);
        $inner = new FailoverQueue($manager, $this->app->make('events'), ['primary']);
        $inner->setContainer($this->app)->setConnectionName('inner');
        $manager->shouldReceive('connection')->with('inner')->andReturn($inner);
        $outer = new FailoverQueue($manager, $this->app->make('events'), ['inner', 'sync']);
        $outer->setContainer($this->app)->setConnectionName('outer');

        $outer->push(new OutboxLocalJob);

        $this->assertSame(1, $primary->attempts);
        $this->assertSame(1, $this->app->make(OutboxExecutionLog::class)->executions);
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));

        (require dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php')->up();
        $primary->push(new OutboxLocalJob);
        $this->assertSame(1, DB::table('job_outbox')->count());
        $this->assertSame(1, $primary->attempts);
    }

    #[DataProvider('transactionOutcomes')]
    public function testSelectedFailoverDefersTheWholeAttemptWithoutSchema(bool $commit): void
    {
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('sync')->andReturn($this->app->make('queue')->connection('sync'));
        $queue = new FailoverQueue($manager, $this->app->make('events'), ['sync']);
        $queue->setContainer($this->app)->setConnectionName('failover')->setConfig(['outbox' => true]);
        $log = $this->app->make(OutboxExecutionLog::class);
        DB::beginTransaction();
        $queue->push(new OutboxLocalJob);
        $this->assertSame(0, $log->executions);
        $commit ? DB::commit() : DB::rollBack();
        $this->assertSame($commit ? 1 : 0, $log->executions);
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
    }

    /**
     * Provide the two terminal transaction outcomes.
     */
    public static function transactionOutcomes(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    public function testNestedFailoverPublishesAtItsExplicitOwnersCommitAndPreservesLocksAcrossFailure(): void
    {
        $failure = new RuntimeException('Execution could not start.');
        $first = new class($failure) extends SyncQueue {
            /**
             * Configure a failing after-commit child.
             */
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(true);
            }

            /**
             * Fail before accepting the dispatch.
             */
            protected function executePayload(string $payload, ?string $queue = null): int
            {
                throw $this->failure;
            }
        };
        $first->setContainer($this->app)->setConnectionName('first');
        $target = new FastPathRecordingRedisQueue(m::mock(Factory::class), dispatchAfterCommit: true);
        $target->setContainer($this->app)->setConnectionName('target')->setConfig(['outbox' => true]);
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('first')->andReturn($first);
        $manager->shouldReceive('connection')->with('target')->andReturn($target);
        $inner = new FailoverQueue($manager, $this->app->make('events'), ['first', 'target'], true);
        $inner->setContainer($this->app)->setConnectionName('inner');
        $manager->shouldReceive('connection')->with('inner')->andReturn($inner);
        $outer = new FailoverQueue($manager, $this->app->make('events'), ['inner']);
        $outer->setContainer($this->app)->setConnectionName('outer');
        $job = (new UniqueOutboxLocalJob)->onOutboxDatabase('secondary')->afterCommit();
        $lock = new UniqueLock(new Repository(new ArrayStore));
        $this->assertTrue($lock->acquireForDispatch($job));
        $this->app->make('events')->listen(JobQueueing::class, function (JobQueueing $event) use ($job, $lock): void {
            $this->assertSame($job, $event->job);
            $this->assertTrue(DispatchLockContext::has($job));
            $this->assertFalse($lock->acquire($job));
        });

        DB::beginTransaction();
        DB::connection('secondary')->beginTransaction();
        $this->assertNull($outer->push($job));
        $this->assertSame(0, $target->publications);
        DB::connection('secondary')->commit();

        $this->assertSame(1, $target->publications);
        $this->assertFalse(DispatchLockContext::has($job));
        DB::rollBack();
        $this->assertFalse($lock->acquire($job));
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
    }

    #[DataProvider('continuationKinds')]
    public function testSyncFallbackDoesNotSuppressIndependentContinuation(string $kind): void
    {
        (require dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php')->up();
        $target = new FastPathRecordingRedisQueue(m::mock(Factory::class));
        $target->setContainer($this->app)->setConnectionName('target')->setConfig(['outbox' => true]);
        $this->app->instance(RedisQueue::class, $target);
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('sync')->andReturn($this->app->make('queue')->connection('sync'));
        $failover = new FailoverQueue($manager, $this->app->make('events'), ['sync']);
        $failover->setContainer($this->app)->setConnectionName('failover');

        $failover->push(
            $kind === 'object' ? new OutboxDispatchesContinuation : OutboxStringContinuation::class . '@fire',
            ['chunk' => 1],
        );

        $row = DB::table('job_outbox')->sole();
        $this->assertSame('target', $row->connection);
        $this->assertSame(0, $target->publications);

        if ($kind === 'string') {
            $payload = json_decode($row->payload, true);
            $this->assertSame(OutboxStringContinuation::class . '@fire', $payload['job']);
            $this->assertSame(['chunk' => 2], $payload['data']);
        }
    }

    /**
     * Provide supported object and string continuation dispatches.
     */
    public static function continuationKinds(): array
    {
        return ['object' => ['object'], 'string' => ['string']];
    }
}

class OutboxLocalJob
{
    use Queueable;

    /**
     * Record execution through the real synchronous job handler.
     */
    public function handle(OutboxExecutionLog $log): void
    {
        ++$log->executions;
    }
}

class MarkedOutboxLocalJob extends OutboxLocalJob implements ShouldQueueViaOutbox
{
}

class UniqueOutboxLocalJob extends OutboxLocalJob implements ShouldBeUnique
{
}

class OutboxDispatchesContinuation
{
    /**
     * Dispatch an independently selected job from the running handler.
     */
    public function handle(RedisQueue $queue): void
    {
        $queue->push((new OutboxLocalJob)->viaOutbox());
    }
}

class OutboxStringContinuation
{
    /**
     * Resolve the destination for subsequent chunks.
     */
    public function __construct(private RedisQueue $queue)
    {
    }

    /**
     * Queue the next chunk using the same string handler.
     */
    public function fire(Job $job, array $data): void
    {
        $this->queue->push(self::class . '@fire', ['chunk' => $data['chunk'] + 1]);
    }
}

class FastPathRecordingRedisQueue extends RedisQueue
{
    public int $publications = 0;

    /**
     * Record direct transport acceptance without an external Redis service.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        ++$this->publications;

        return 'accepted';
    }
}

class OutboxExecutionLog
{
    public int $executions = 0;
}

class FailingOutboxRedisQueue extends RedisQueue
{
    public int $attempts = 0;

    /**
     * Reject direct publication to exercise the ordinary failover path.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        ++$this->attempts;

        throw new RuntimeException('Broker unavailable.');
    }
}
