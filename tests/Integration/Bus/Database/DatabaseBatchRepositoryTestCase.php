<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Bus\Database;

use Closure;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

#[ResetRefreshDatabaseState]
abstract class DatabaseBatchRepositoryTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * Give each competing operation its own session with a bounded lock wait.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $database = $config->string('database.default');
        $config->set('queue.batching.database', $database);
        $config->set("database.connections.{$database}.pool.testing_enabled", true);
        $config->set("database.connections.{$database}.pool.max_connections", 4);
        $config->set("database.connections.{$database}.pool.heartbeat_interval", null);
        if ($config->string("database.connections.{$database}.driver") === 'sqlite') {
            $config->set("database.connections.{$database}.busy_timeout", 1000);
        } else {
            $config->set("database.connections.{$database}.lock_timeout", 2);
        }
    }

    /**
     * Install the shipped batch schemas.
     */
    protected function migrateFreshUsing(): array
    {
        $directory = dirname(__DIR__, 4) . '/src/testbench/hypervel/migrations/';

        return ['--realpath' => true, '--path' => [
            $directory . '0001_01_01_000005_testbench_create_job_batches_table.php',
            $directory . '0001_01_01_000010_testbench_create_job_batch_results_table.php',
        ]];
    }

    public function testTransitionsKeepTheLatestRetryIdAndFinishTheLastRecovery(): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $batch = Bus::batch([])->dispatch();
        $repository->incrementTotalJobs($batch->id, 2);
        $first = str_repeat('prefix', 100) . 'A';
        $second = str_repeat('prefix', 100) . 'a';
        $repository->incrementFailedJobs($batch->id, $first, 'other-copy');
        $counts = $repository->incrementFailedJobs($batch->id, $first, '0');
        $this->assertSame(2, $counts->failedJobs);
        $this->assertSame(['0'], $repository->getFailedJobIds($batch->id));
        $this->assertTrue($repository->hasFailedJob($batch->id, $first));

        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $counts = $repository->decrementPendingJobs($batch->id, $second);
            $this->assertCount($connection->getDriverName() === 'pgsql' ? 2 : 4, $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
        $this->assertTrue($counts->completedFirstPass);
        $this->assertFalse($batch->fresh()->finished());
        $this->assertNull($repository->decrementPendingJobs($batch->id, $second));
        $this->assertNull($repository->incrementFailedJobs($batch->id, $second));
        $this->assertFalse($repository->hasFailedJob($batch->id, $second));
        $counts = $repository->decrementPendingJobs($batch->id, $first);
        $this->assertFalse($counts->completedFirstPass);
        $this->assertSame(0, $counts->pendingJobs);
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame([], $batch->fresh()->failedJobIds);
    }

    public function testOrdinarySuccessStatementCountAndRollback(): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $batch = Bus::batch([])->dispatch();
        $repository->incrementTotalJobs($batch->id, 2);
        $connection = DB::connection();
        $failure = new RuntimeException('Stop after updating the parent.');
        $armed = true;
        $connection->listen(static function (QueryExecuted $event) use (&$armed, $failure): void {
            if ($armed && (str_starts_with($event->sql, 'update') || str_starts_with($event->sql, 'with inserted')) && str_contains($event->sql, 'pending_jobs')) {
                $armed = false;
                throw $failure;
            }
        });
        try {
            $repository->decrementPendingJobs($batch->id, 'one');
            $this->fail('Expected the write to roll back.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame(2, $batch->fresh()->pendingJobs);
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $counts = $repository->decrementPendingJobs($batch->id, 'one');
            $this->assertCount($connection->getDriverName() === 'pgsql' ? 1 : 3, $connection->getQueryLog());
            $this->assertSame(1, $counts->pendingJobs);
        } finally {
            $connection->disableQueryLog();
        }
        $repository->delete($batch->id);
        $this->assertNull($repository->incrementFailedJobs($batch->id, 'one'));
        $this->assertNull($repository->decrementPendingJobs($batch->id, 'two'));
        $this->assertSame([], $repository->getFailedJobIds($batch->id));
        $this->assertFalse($repository->hasFailedJob($batch->id, 'one'));
        $this->assertSame(0, DB::table('job_batch_results')->count());
    }

    public function testConcurrentSuccessesCountTheSameJobOnce(): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $batch = Bus::batch([])->dispatch();
        $repository->incrementTotalJobs($batch->id, 2);
        $success = fn () => $repository->decrementPendingJobs($batch->id, 'one');
        [$first, $duplicate] = $this->overlap(
            $success,
            $success,
            static fn (string $sql) => str_contains($sql, 'job_batches')
                && (str_starts_with($sql, 'update') || str_starts_with($sql, 'with inserted')),
            waitFor: 'retry',
        );

        $this->assertSame(1, $first->pendingJobs);
        $this->assertNull($duplicate);
        $this->assertSame(1, $batch->fresh()->pendingJobs);
    }

    #[TestWith([true, false])]
    #[TestWith([true, true])]
    #[TestWith([false, false])]
    #[TestWith([false, true])]
    public function testOverlappingSuccessAndFailure(bool $successFirst, bool $alreadyFailed): void
    {
        $batch = Bus::batch([])->allowFailures()->dispatch();
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $repository->incrementTotalJobs($batch->id, 1);
        $success = fn () => $repository->decrementPendingJobs($batch->id, 'one');
        $failure = fn () => $repository->incrementFailedJobs($batch->id, 'one', 'retry');
        if ($alreadyFailed) {
            $failure();
        }
        [$first, $second] = $this->overlap(
            $successFirst ? $success : $failure,
            $successFirst ? $failure : $success,
            // The parent write runs after the first session owns the job result, including on retries.
            static fn (string $sql) => str_contains($sql, 'job_batches')
                && (str_starts_with($sql, 'update') || str_starts_with($sql, 'with inserted')),
        );

        $this->assertNotNull($first);
        $this->assertSame($successFirst, $second === null);
        $batch = $batch->fresh();
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame((int) $alreadyFailed + ($successFirst ? 0 : 1), $batch->failedJobs);
        $this->assertTrue($batch->finished());
        $this->assertSame([], $batch->failedJobIds);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testPruningACancelledBatchDuringSuccessLeavesNoResults(bool $successFirst): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $batch = Bus::batch([])->dispatch();
        $repository->incrementTotalJobs($batch->id, 1);
        $batch->cancel();
        $success = fn () => $repository->decrementPendingJobs($batch->id, 'one');
        $prune = fn () => $repository->pruneCancelled(now()->addMinute());
        [$first, $second] = $this->overlap(
            $successFirst ? $success : $prune,
            $successFirst ? $prune : $success,
            static fn (string $sql) => str_contains($sql, 'job_batch_results') && str_contains($sql, $successFirst ? 'insert' : 'delete'),
        );
        $this->assertSame(1, $successFirst ? $second : $first);
        $this->assertNull($batch->fresh());
        $this->assertSame(0, DB::table('job_batch_results')->count());
    }

    #[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
    public function testSecondSweepRemovesASuccessCommittedAfterTheFirstSweep(): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $batch = Bus::batch([])->dispatch();
        $repository->incrementTotalJobs($batch->id, 1);
        $this->overlap(
            function () use ($repository, $batch): void {
                if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    DB::statement('set transaction isolation level read committed');
                }
                $repository->delete($batch->id);
            },
            fn () => $repository->decrementPendingJobs($batch->id, 'late'),
            static fn (string $sql) => str_contains($sql, 'delete') && str_contains($sql, 'job_batch_results'),
            waitFor: 'commit',
        );
        $this->assertNull($batch->fresh());
        $this->assertSame(0, DB::table('job_batch_results')->count());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testChangedPruneCandidatesKeepTheirResults(bool $reopen): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $batch = Bus::batch([])->dispatch();
        $repository->incrementTotalJobs($batch->id, $reopen ? 1 : 2);
        $repository->decrementPendingJobs($batch->id, 'done');
        [$deleted] = $this->overlap(
            fn () => $reopen ? $repository->prune(now()->addMinute()) : $repository->pruneUnfinished(now()->addMinute()),
            fn () => $reopen ? $repository->incrementTotalJobs($batch->id, 1) : $repository->decrementPendingJobs($batch->id, 'other'),
            static fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'total_jobs'),
            waitFor: 'commit',
        );
        $this->assertSame(0, $deleted);
        $this->assertSame($reopen ? 1 : 0, $batch->fresh()->pendingJobs);
        $this->assertNull($repository->decrementPendingJobs($batch->id, 'done'));
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    #[TestWith([true])]
    #[TestWith([false])]
    public function testPruningInsideASnapshotAdvancesPastStaleCandidates(bool $reopen): void
    {
        $repository = $this->app->make(DatabaseBatchRepository::class);
        $stale = Bus::batch([])->dispatch();
        $valid = Bus::batch([])->dispatch();
        foreach ([$stale, $valid] as $batch) {
            $repository->incrementTotalJobs($batch->id, 1);
            $repository->decrementPendingJobs($batch->id, 'done');
        }
        [$deleted] = $this->overlap(
            function () use ($repository): int {
                DB::statement('set transaction isolation level repeatable read');
                return DB::transaction(fn () => $repository->prune(now()->addMinute()), attempts: 3);
            },
            fn () => $reopen ? $repository->incrementTotalJobs($stale->id, 1) : $repository->delete($stale->id),
            static fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'total_jobs'),
            waitFor: 'commit',
        );
        $this->assertSame(1, $deleted);
        $this->assertNull($valid->fresh());
        $this->assertSame($reopen ? 1 : 0, DB::table('job_batch_results')->count());
        $this->assertNull($repository->decrementPendingJobs($stale->id, 'done'));
    }

    /**
     * Pause the first session after the chosen statement until the competing session reaches the requested stage.
     *
     * @param 'commit'|'retry'|'write' $waitFor
     */
    private function overlap(Closure $first, Closure $second, Closure $pauseAfter, string $waitFor = 'write'): array
    {
        $ready = new Channel(1);
        $release = new Channel(1);
        try {
            return parallel([
                function () use ($first, $pauseAfter, $ready, $release) {
                    $connection = DB::connection();
                    $paused = false;
                    $connection->listen(function (QueryExecuted $event) use ($connection, $pauseAfter, $ready, $release, &$paused): void {
                        if (! $paused && $event->connection === $connection && $pauseAfter($event->sql)) {
                            $paused = true;
                            $this->assertTrue($ready->push(true, 5));
                            $this->assertTrue($release->pop(5));
                        }
                    });
                    return $first();
                },
                function () use ($second, $waitFor, $ready, $release) {
                    $this->assertTrue($ready->pop(5));
                    if ($waitFor === 'retry') {
                        // The first writer holds the row until the competing write actually times out.
                        $attempt = 0;
                        DB::connection()->beforeStartingTransaction(function () use (&$attempt, $release): void {
                            if (++$attempt === 2) {
                                $this->assertTrue($release->push(true, 5));
                            }
                        });
                    } elseif ($waitFor === 'write') {
                        $started = false;
                        DB::connection()->beforeExecuting(function (string $sql) use (&$started, $release): void {
                            if (! $started && str_contains($sql, 'job_batch_results')) {
                                $started = true;
                                $this->assertTrue($release->push(true, 5));
                            }
                        });
                    }
                    try {
                        return $second();
                    } finally {
                        if ($waitFor === 'commit') {
                            $release->push(true, 5);
                        }
                    }
                },
            ]);
        } finally {
            $ready->close();
            $release->close();
        }
    }
}
