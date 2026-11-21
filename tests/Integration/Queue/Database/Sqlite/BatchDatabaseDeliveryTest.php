<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use ArrayObject;
use Closure;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchNotFoundException;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

#[RequiresDatabase('sqlite')]
#[WithConfig('queue.default', 'database')]
#[WithConfig('queue.connections.database.connection', 'delivery')]
#[WithConfig('database.connections.delivery', ['driver' => 'sqlite', 'database' => ':memory:'])]
class BatchDatabaseDeliveryTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Install the batch tables on the owning connection.
     */
    protected function migrateFreshUsing(): array
    {
        return ['--realpath' => true, '--path' => dirname(__DIR__, 4) . '/Bus/Fixtures/migrations'];
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function testOpenTransactionOnTheDeliveryConnectionRejectsEnrollment(bool $pooled, bool $afterCommit): void
    {
        if ($pooled) {
            $this->app->make('queue')->addPoolableDriver('database');
        }
        $job = CallQueuedClosure::create(static fn () => null);
        if ($afterCommit) {
            $job->afterCommit();
        }
        $target = DB::connection('delivery');
        $target->beginTransaction();
        try {
            Bus::batch([$job])->dispatch();
            $this->fail('Expected the independent delivery transaction to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Dispatch after the transaction on the queue database [delivery] commits', $exception->getMessage());
        } finally {
            $target->rollBack();
        }

        $this->assertSame(0, DB::table('job_batches')->count());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testSeparateDatabaseQueueWaitsForTheBatchOwner(bool $commit): void
    {
        $delivery = DB::connection('delivery');
        $delivery->statement('create temporary table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');

        DB::beginTransaction();
        try {
            $batch = Bus::batch([static fn () => null])->dispatch();
            $this->assertSame(0, $delivery->table('jobs')->count());
            $commit ? DB::commit() : DB::rollBack();
        } finally {
            DB::rollBack(0);
        }

        $this->assertSame((int) $commit, $delivery->table('jobs')->count());
        $this->assertSame($commit ? 1 : null, $batch->fresh()?->pendingJobs);
    }

    public function testDeferredRejectionReleasesTheWholeChainAndRunsNewlyEarnedCallbacks(): void
    {
        $callbacks = new ArrayObject;
        $this->app->instance(ArrayObject::class, $callbacks);
        $batch = Bus::batch([])
            ->then(static fn () => app(ArrayObject::class)->append('then'))
            ->finally(static fn () => app(ArrayObject::class)->append('finally'))
            ->dispatch();
        $this->app->make(BatchRepository::class)->incrementTotalJobs($batch->id, 1);

        DB::beginTransaction();
        $failure = new RuntimeException('Rejected before sending.');
        try {
            $batch->add([[
                CallQueuedClosure::create(static fn () => null)->afterCommit(),
                CallQueuedClosure::create(static fn () => null),
            ]]);
            $batch->recordSuccessfulJob('earlier-job');
            $this->assertSame(2, $batch->fresh()->pendingJobs);
            $this->app->make('events')->listen(JobQueueing::class, static fn () => throw $failure);
            try {
                DB::commit();
                $this->fail('Expected the original send failure at commit.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
        } finally {
            DB::rollBack(0);
        }
        $finished = $batch->fresh();
        $this->assertSame(1, $finished->totalJobs);
        $this->assertSame(0, $finished->pendingJobs);
        $this->assertTrue($finished->finished());
        $this->assertSame(['then', 'finally'], $callbacks->getArrayCopy());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testAfterResponseDispatchChecksTheLiveEnrollment(bool $rollback): void
    {
        $target = DB::connection('delivery');
        $target->statement('create table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        $done = new Channel(1);
        Coroutine::create(static function () use ($rollback, $done): void {
            Coroutine::defer(static fn () => $done->push(true));
            if ($rollback) {
                DB::beginTransaction();
                try {
                    Bus::batch([static fn () => null])->dispatchAfterResponse();
                } finally {
                    DB::rollBack();
                }
            } else {
                $batch = Bus::batch([new UnserializableBatchMember(static fn () => null)])->dispatchAfterResponse();
                $batch->add(static fn () => null);
            }
        });

        $this->assertTrue($done->pop(5));
        $this->assertSame($rollback ? 0 : 1, DB::table('job_batches')->count());
        $this->assertSame($rollback ? 0 : 1, $target->table('jobs')->count());
    }

    public function testAutomaticFailoverWaitsForEnrollmentAndIgnoresAnUnrelatedRollback(): void
    {
        $target = DB::connection('delivery');
        $target->statement('create temporary table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        config(['queue.connections.failover.connections' => ['database'], 'queue.connections.failover.after_commit' => false]);
        DB::beginTransaction();
        try {
            $target->beginTransaction();
            try {
                $batch = Bus::batch([static fn () => null])->onConnection('failover')->dispatch();
                $this->assertSame(0, $target->table('jobs')->count());
            } finally {
                $target->rollBack();
            }
            DB::commit();
        } finally {
            DB::rollBack(0);
        }
        $this->assertSame(1, $target->table('jobs')->count());
        $this->assertSame(1, $batch->fresh()->pendingJobs);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFailoverUsesOnlyAChildThatCanKeepItsJobAfterEnrollmentCommits(bool $afterCommit): void
    {
        config(['queue.connections.failover.connections' => ['database', 'sync'], 'queue.connections.failover.after_commit' => $afterCommit]);
        $delivery = DB::connection('delivery');
        $delivery->statement('create temporary table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        DB::beginTransaction();
        $delivery->beginTransaction();
        try {
            $batch = Bus::batch([static fn () => null])->onConnection('failover')->dispatch();
            DB::commit();
            $this->assertSame(0, $delivery->table('jobs')->count());
            $afterCommit ? $delivery->commit() : $delivery->rollBack();
            $this->assertSame((int) $afterCommit, $delivery->table('jobs')->count());
            $this->assertSame((int) $afterCommit, $batch->fresh()->pendingJobs);
        } finally {
            $delivery->rollBack(0);
            DB::rollBack(0);
        }
    }

    public function testInlineCancellationRollsBackEnrollment(): void
    {
        $failure = new CanceledException('Stopped before the handler ran.');
        $this->app->make('events')->listen(JobProcessing::class, static fn () => throw $failure);
        $batch = Bus::batch([])->onConnection('sync')->dispatch();
        try {
            $batch->add(static fn () => null);
            $this->fail('Expected cancellation to reach the caller.');
        } catch (CanceledException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(0, $batch->fresh()->totalJobs);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testBackgroundDispatchWaitsForTheOwningTransaction(bool $commit): void
    {
        config(['queue.connections.background.after_commit' => false]);
        $done = new Channel(1);
        $this->app->make('events')->listen(JobProcessed::class, static fn () => $done->push(true));
        DB::beginTransaction();
        try {
            $batch = Bus::batch([static fn () => null])->onConnection('background')->dispatch();
            $this->assertTrue($done->isEmpty());
            $commit ? DB::commit() : DB::rollBack();
        } finally {
            DB::rollBack(0);
        }
        if ($commit) {
            $this->assertTrue($done->pop(3));
            $this->assertSame(0, $batch->fresh()->pendingJobs);
        } else {
            $this->assertNull($batch->fresh());
            $this->assertTrue($done->isEmpty());
        }
    }

    public function testBeforeCallbackCancellationPreservesJobsItAlreadyAdded(): void
    {
        $target = DB::connection('delivery');
        $target->statement('create temporary table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        try {
            Bus::batch([static fn () => null])->before(static function ($batch): void {
                $batch->add(static fn () => null);
                throw new CanceledException('Dispatch cancelled.');
            })->dispatch();
            $this->fail('Expected dispatch to propagate cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame('Dispatch cancelled.', $exception->getMessage());
        }
        $this->assertSame(1, $target->table('jobs')->count());
        $this->assertSame(1, (int) DB::table('job_batches')->sole()->pending_jobs);
    }

    public function testPositiveEnrollmentSignalsAMissingParent(): void
    {
        $batch = Bus::batch([])->dispatch();
        $batch->delete();
        $this->expectException(BatchNotFoundException::class);
        $this->expectExceptionMessage($batch->id);
        $this->app->make(BatchRepository::class)->incrementTotalJobs($batch->id, 1);
    }

    public function testExplicitOptOutRejectsAMixedAdditionBeforeItPublishes(): void
    {
        $target = DB::connection('delivery');
        $target->statement('create temporary table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        config(['queue.connections.failover.connections' => ['database'], 'queue.connections.failover.after_commit' => false]);
        DB::beginTransaction();
        try {
            Bus::batch([static fn () => null, CallQueuedClosure::create(static fn () => null)->beforeCommit()])->onConnection('failover')->dispatch();
            $this->fail('Expected an explicit opt-out to reject the addition.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('beforeCommit()', $exception->getMessage());
        } finally {
            DB::commit();
        }
        $this->assertSame(0, $target->table('jobs')->count());
        $this->assertSame(0, DB::table('job_batches')->count());
    }

    public function testSyncCompletionCanDeleteTheBatchBeforeDispatchReturns(): void
    {
        $events = [];
        $this->app->make('events')->listen(BatchDispatched::class, static function ($event) use (&$events): void {
            $events[] = $event;
        });
        $batch = Bus::batch([static fn () => null])->onConnection('sync')->finally(static function ($batch): void {
            $batch->delete();
        })->dispatch();

        $this->assertNull($batch->fresh());
        $this->assertSame([], $events);
    }

    public function testThrowingQueryObserverPreservesAnIndependentlyStoredJobAndItsCount(): void
    {
        $target = DB::connection('delivery');
        $target->statement('create temporary table jobs (id integer primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        $failure = new RuntimeException('Listener failed after insert.');
        DB::listen(static function (QueryExecuted $query) use ($failure): void {
            if ($query->connectionName === 'delivery' && str_starts_with($query->sql, 'insert into')) {
                throw $failure;
            }
        });

        try {
            Bus::batch([static fn () => null])->dispatch();
            $this->fail('Expected the query observer to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $payload = json_decode($target->table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command']);
        $this->assertSame(1, Bus::findBatch($job->batchId)?->pendingJobs);
    }
}

class UnserializableBatchMember implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(public Closure $callback)
    {
    }
}
