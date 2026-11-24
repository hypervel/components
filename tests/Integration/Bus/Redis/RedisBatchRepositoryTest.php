<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Bus\Redis;

use ArrayObject;
use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Bus\Events\BatchFinished;
use Hypervel\Bus\RedisBatchRepository;
use Hypervel\Bus\RoutingBatchRepository;
use Hypervel\Bus\UpdatedBatchJobCounts;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RedisTestConfiguration;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Http\Controllers\BatchesController;
use Hypervel\Http\Request;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\Bus\Fixtures\TestsBatchReopening;
use Hypervel\Tests\Integration\Database\Queue\Fixtures\TimeOutJobWithNestedTransactions;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Testbench\remote;

#[ResetRefreshDatabaseState]
class RedisBatchRepositoryTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithRedis;
    use TestsBatchReopening;
    use VerifiesDoubles;

    protected static string $directory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$directory = ParallelTesting::tempDir('RedisBatchRepositoryTest');
        $files = new Filesystem;
        $files->deleteDirectory(static::$directory);
        $files->ensureDirectoryExists(static::$directory);
        touch(static::$directory . '/database.sqlite');
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(static::$directory);
        parent::tearDownAfterClass();
    }

    /**
     * Store batch results on the isolated Redis test connection.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('queue.batching.driver', 'redis');
        $config->set('queue.batching.redis_connection', 'default');
        $config->set('queue.batching.database', $config->string('database.default'));
        $config->set('queue.default', 'database');
        $database = $config->string('database.default');
        $config->set("database.connections.{$database}.pool.testing_enabled", true);
        $config->set("database.connections.{$database}.pool.max_connections", 4);
        $config->set("database.connections.{$database}.pool.heartbeat_interval", null);
        if ($config->string("database.connections.{$database}.driver") === 'sqlite') {
            $config->set("database.connections.{$database}.database", static::$directory . '/database.sqlite');
            $config->set("database.connections.{$database}.journal_mode", 'WAL');
        }
    }

    /**
     * Install the batch tables.
     */
    protected function migrateFreshUsing(): array
    {
        return ['--realpath' => true, '--path' => [
            dirname(__DIR__, 3) . '/Bus/Fixtures/migrations',
            dirname(__DIR__, 4) . '/src/testbench/hypervel/migrations/0001_01_01_000006_testbench_create_jobs_table.php',
        ]];
    }

    public function testResultsFollowTheOriginalDriverAndKeepTheLatestRetryId(): void
    {
        config(['queue.batching.redis_connection' => $this->createRedisConnectionWithOptions('batch-results', [
            'prefix' => 'batch-test:',
            'serializer' => Redis::SERIALIZER_PHP,
        ])]);
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([])->allowFailures());
        $this->assertSame(0, $batch->pendingJobs);
        $repository->incrementTotalJobs($batch->id, 3);
        config(['queue.batching.driver' => 'database']);
        $database = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($database->id, 1);
        $this->assertSame(1, $repository->incrementFailedJobs($batch->id, 'logical', 'old')->failedJobs);
        $this->assertSame(2, $repository->incrementFailedJobs($batch->id, 'logical', '0')->failedJobs);
        $batch->fresh()->recordSuccessfulJob('second');
        $batch->fresh()->recordSuccessfulJob('third');

        $finished = $batch->fresh();
        $this->assertTrue($finished->finished());
        $this->assertSame(['0'], $finished->failedJobIds);
        $this->assertTrue($finished->hasFailedJob('logical'));
        $this->assertSame(1, $finished->pendingJobs);
        $this->assertSame(2, $finished->failedJobs);
        $finished->recordSuccessfulJob('logical');
        $this->assertNull($repository->incrementFailedJobs($batch->id, 'logical'));
        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertEquals($finished->finishedAt, $batch->fresh()->finishedAt);
        $this->assertSame([], $repository->getFailedJobIds($batch->id));
        $page = $repository->get(50, null);
        $this->assertSame([$database->id, $batch->id], array_column($page, 'id'));
        $this->assertSame([1, 0], array_map(static fn ($batch) => $batch->toArray()['pendingJobs'], $page));
        $this->assertSame(0, DB::table('job_batch_results')->count());
    }

    public function testHorizonCanSerializeSearchAndDetailAfterPruning(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([])->name('Import users'));
        $repository->incrementTotalJobs($batch->id, 1);
        $batch->recordSuccessfulJob('first');

        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->expects('getJobs')->with([])->returns(collect());
        $this->app->instance(JobRepository::class, $jobs);
        $controller = new BatchesController($repository);
        $list = $controller->index(Request::create('/'));
        $search = $controller->index(Request::create('/?query=Import'));
        $detail = $controller->show($batch->id);
        $batch->delete();

        foreach ([$list['batches'][0], $search['batches'][0], $detail['batch']] as $view) {
            $snapshot = json_decode(json_encode($view, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($batch->id, $snapshot['id']);
            $this->assertSame(100, $snapshot['progress']);
        }
    }

    public function testHorizonSearchLoadsProgressThroughACustomFindMethod(): void
    {
        $repository = new class($this->app->make(BatchFactory::class), $this->app->make('db'), 'job_batches', config('database.default'), 'job_batch_results', $this->app, $this->app->make('config')) extends RoutingBatchRepository {
            public function find(string $batchId): ?Batch
            {
                return parent::find($batchId);
            }
        };
        $batch = $repository->store(Bus::batch([])->name('Import users'));
        $repository->incrementTotalJobs($batch->id, 1);
        $batch->recordSuccessfulJob('first');

        $search = (new BatchesController($repository))->index(Request::create('/?query=Import'));
        $batch->delete();

        $snapshot = json_decode(json_encode($search['batches'][0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($batch->id, $snapshot['id']);
        $this->assertSame(100, $snapshot['progress']);
    }

    public function testRedisPaginationKeepsWaitingAndActiveBatchesInCursorOrder(): void
    {
        $repository = $this->app->make(RedisBatchRepository::class);
        $older = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($older->id, 2);
        $older->recordSuccessfulJob('first');
        $newer = $repository->store(Bus::batch([]));

        $this->assertSame([$newer->id, $older->id], array_column($repository->get(), 'id'));
    }

    public function testLoadingAPageDoesNotConsumeWatchState(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        foreach (['first', 'second'] as $job) {
            $batch = $repository->store(Bus::batch([]));
            $repository->incrementTotalJobs($batch->id, 1);
            $batch->recordSuccessfulJob($job);
        }
        $redis = $this->app->make(RedisFactory::class)->connection('default');
        $redis->set('batch:watched', 'value');

        $this->assertTrue($redis->watch('batch:watched'));
        $watched = $this->nativeRedisClient($redis);
        $this->assertCount(2, $repository->get(50, null));

        $redis->releaseContextConnection();

        // A client that still watches a key must be discarded, or it would abort an unrelated transaction.
        $this->assertNotSame($watched, $this->nativeRedisClient($redis));
    }

    public function testWaitingPageDoesNotLockAndRetainedProgressLoadsOnlyOnce(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($batch->id, 2);
        $database = DB::connection();
        $database->enableQueryLog();
        $database->flushQueryLog();
        try {
            $this->assertSame(2, $repository->get(50, null)[0]->pendingJobs);
            $queries = array_column($database->getQueryLog(), 'query');
            $this->assertCount(2, $queries);
            foreach ($queries as $query) {
                $this->assertStringStartsWith('select', strtolower($query));
                $this->assertStringNotContainsString('for update', strtolower($query));
            }
        } finally {
            $database->disableQueryLog();
        }

        $retained = $batch->fresh();
        $batch->recordSuccessfulJob('first');
        $repository->incrementTotalJobs($batch->id, 1);
        $retained->failedJobs = 9;
        $this->assertSame(2, $retained->pendingJobs);
        $this->assertSame(3, $retained->totalJobs);
        $this->assertSame(9, $retained->failedJobs);
        $batch->recordSuccessfulJob('second');
        $this->assertSame(2, $retained->pendingJobs);
        $this->assertSame(1, $retained->fresh()->pendingJobs);
    }

    public function testTerminalEventKeepsItsCountsWhenAProgressCallbackDeletesTheBatch(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([])->progress(static fn ($view) => $view->delete()));
        $repository->incrementTotalJobs($batch->id, 1);
        $finished = null;
        $this->app->make('events')->listen(BatchFinished::class, static function (BatchFinished $event) use (&$finished): void {
            $finished = $event->batch->toArray();
        });

        $batch->fresh()->recordSuccessfulJob('last');
        $this->assertNull($batch->fresh());
        $this->assertSame(100, $finished['progress']);
        $this->assertNotNull($finished['finishedAt']);
    }

    #[RequiresDatabase('sqlite')]
    #[TestWith(['guard'])]
    #[TestWith(['then'])]
    #[TestWith(['finished'])]
    public function testPruningDuringLookupPreservesTheGuardAndCompletionSemantics(string $reader): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $pending = Bus::batch([]);
        $callbacks = new ArrayObject;
        if ($reader === 'then') {
            $this->app->instance(ArrayObject::class, $callbacks);
            $pending->then(static fn () => app(ArrayObject::class)->append('then'));
        }
        $batch = $repository->store($pending);
        $repository->incrementTotalJobs($batch->id, 1);
        $worker = $batch->fresh();
        $deleted = false;
        DB::listen(static function (QueryExecuted $query) use ($batch, &$deleted): void {
            if (! $deleted && str_starts_with($query->sql, 'select * from "job_batches"') && str_contains($query->sql, 'limit 1')) {
                $deleted = true;
                $batch->delete();
            }
        });

        if ($reader === 'guard') {
            $this->assertFalse((new TimeOutJobWithNestedTransactions)->withBatchId($batch->id)->batching());
        } else {
            $seen = null;
            if ($reader === 'finished') {
                $this->app->make('events')->listen(BatchFinished::class, static function (BatchFinished $event) use (&$seen): void {
                    $seen = $event->batch->pendingJobs;
                });
            }
            $worker->recordSuccessfulJob('last');
            if ($reader === 'finished') {
                $this->assertSame(0, $seen);
            } else {
                $this->assertCount(0, $callbacks);
            }
        }
        $this->assertTrue($deleted);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testDispatchReturnsAReadableBatchAcrossDeletion(bool $deleteDuringLookup): void
    {
        $seen = null;
        $events = $this->app->make('events');
        if ($deleteDuringLookup) {
            $repository = $this->app->make(BatchRepository::class);
            DB::listen(static function (QueryExecuted $query) use ($repository): void {
                if (str_starts_with($query->sql, 'select * from ') && str_contains($query->sql, 'job_batches')) {
                    $repository->delete($query->bindings[0]);
                }
            });
        } else {
            $events->listen(BatchDispatched::class, static fn (BatchDispatched $event) => $event->batch->delete());
        }
        $events->listen(BatchDispatched::class, static function (BatchDispatched $event) use (&$seen): void {
            $seen = $event->batch->pendingJobs;
        });
        $batch = Bus::batch([static fn () => null])->dispatch();
        $this->assertSame($deleteDuringLookup ? null : 1, $seen);
        $this->assertSame($deleteDuringLookup ? 0 : 1, $batch->pendingJobs);
    }

    public function testTimeoutUnwindsNestedTransactionsBeforeRecordingTheRedisFailure(): void
    {
        $batch = Bus::batch([new TimeOutJobWithNestedTransactions])->allowFailures()->dispatch();
        $uuid = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR)['uuid'];
        $token = $this->parallelTestingToken();
        $database = config('database.default');
        $driver = config("database.connections.{$database}.driver");
        $databaseName = DB::connection()->getDatabaseName();
        $worker = remote(static function () use ($token, $driver, $databaseName): void {
            RedisTestConfiguration::configure(app('config'), $token);
            // The parent has already selected its parallel worker's database.
            config(["database.connections.{$driver}.database" => $databaseName, 'queue.failed.driver' => 'null']);
            DB::purge($driver);
            Artisan::call('queue:work', ['--stop-when-empty' => true]);
        }, [
            'TESTBENCH_BASE_PATH' => false,
            'DB_CONNECTION' => $driver,
            'DB_DATABASE' => $databaseName,
            'DB_URL' => false,
            'DB_POOLED_URL' => false,
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
            'REDIS_QUEUE_BATCHING_CONNECTION' => 'default',
            'REDIS_PREFIX' => config('database.redis.options.prefix'),
        ]);
        try {
            $worker->setTimeout(20)->mustRun()->output();
        } catch (ProcessSignaledException $exception) {
            $this->assertSame(SIGKILL, $exception->getSignal());
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, $batch->fresh()->failedJobs);
        $this->assertSame([$uuid], $batch->fresh()->failedJobIds);
    }

    public function testRolledBackCorrectionLeavesCompletionEligibleOnRetry(): void
    {
        $repo = $this->app->make(BatchRepository::class);
        $batch = $repo->store(Bus::batch([]));
        $repo->incrementTotalJobs($batch->id, 2);
        $batch->recordSuccessfulJob('accepted');

        DB::beginTransaction();
        try {
            $this->assertTrue($repo->releaseJobs($batch->id, 1)->completedFirstPass);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $counts = $repo->releaseJobs($batch->id, 1);
        $this->assertTrue($counts->completedFirstPass);
        $this->assertSame(0, $counts->pendingJobs);
        $this->assertTrue($batch->fresh()->finished());

        $repo->incrementTotalJobs($batch->id, 1);
        $this->assertNull($repo->releaseJobs($batch->id, 1));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testLastWorkerCountsAgainstCommittedEnrollment(bool $commit): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($batch->id, 1);
        $enrolled = new Channel(1);
        $recording = new Channel(1);
        try {
            [, $counts] = parallel([
                function () use ($repository, $batch, $enrolled, $recording, $commit): void {
                    DB::beginTransaction();
                    try {
                        $repository->incrementTotalJobs($batch->id, 1);
                        $enrolled->push(true, 5);
                        $this->assertTrue($recording->pop(5));
                        if ($commit) {
                            DB::commit();
                        }
                    } finally {
                        DB::rollBack(0);
                    }
                },
                function () use ($batch, $enrolled, $recording) {
                    $this->assertTrue($enrolled->pop(5));
                    $signaled = false;
                    DB::connection()->beforeExecuting(static function (string $sql) use ($recording, &$signaled): void {
                        if (! $signaled && str_contains($sql, 'job_batches')) {
                            $signaled = true;
                            $recording->push(true, 5);
                        }
                    });
                    return $batch->decrementPendingJobs('worker-job');
                },
            ]);
        } finally {
            $enrolled->close();
            $recording->close();
        }
        $this->assertSame((int) $commit, $counts->pendingJobs);
        $this->assertSame(! $commit, $batch->fresh()->finished());
    }

    public function testPruneRechecksAReopenedBatchBeforeDeletingItsResults(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($batch->id, 1);
        $batch->recordSuccessfulJob('first');
        $reopened = false;
        DB::listen(static function (QueryExecuted $query) use (&$reopened, $batch, $repository): void {
            if (! $reopened && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'finished_at')
                && in_array('redis', $query->bindings, true) && ! str_contains($query->sql, 'is null')) {
                $reopened = true;
                $repository->incrementTotalJobs($batch->id, 1);
            }
        });
        $this->assertSame(0, $repository->prune(now()->addMinute()));
        $this->assertTrue($reopened);
        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertNull($batch->decrementPendingJobs('first'));
    }

    #[RequiresDatabase('sqlite')]
    public function testTerminalResultReservesTheWalWriterBeforeCertifyingTheTotal(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($batch->id, 1);
        $other = new PDO('sqlite:' . static::$directory . '/database.sqlite');
        $other->exec('pragma busy_timeout=0');
        $this->assertSame('wal', $other->query('pragma journal_mode')->fetchColumn());
        $blocked = false;
        DB::listen(static function (QueryExecuted $query) use ($other, &$blocked): void {
            if (! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'finished_at') || ! str_contains($query->sql, 'options')) {
                return;
            }
            try {
                $other->exec('begin immediate');
                $other->exec('rollback');
            } catch (PDOException $exception) {
                $blocked = $exception->errorInfo[1] === 5;
            }
        });

        $batch->recordSuccessfulJob('last');
        $this->assertTrue($blocked);
        $this->assertTrue($batch->fresh()->finished());
    }

    #[TestWith([false], 'prune')]
    #[TestWith([true], 'rejected addition')]
    public function testAnUnprojectedFinishIsRestoredAtItsOriginalTime(bool $reopen): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($batch->id, 1);
        DB::table('job_batches')->where('id', $batch->id)->update(['created_at' => now()->subDays(5)->getTimestamp()]);
        $failure = new RuntimeException('SQL finish could not be written.');
        $interrupt = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$interrupt, $failure): void {
            if ($interrupt && str_starts_with($sql, 'update') && str_contains($sql, 'finished_at')) {
                $interrupt = false;
                throw $failure;
            }
        });
        try {
            $batch->recordSuccessfulJob('last');
            $this->fail('Expected the SQL projection to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertNull(DB::table('job_batches')->where('id', $batch->id)->value('finished_at'));
        $finishedAt = $batch->fresh()->finishedAt;
        $this->assertNotNull($finishedAt);
        $redelivered = (new TimeOutJobWithNestedTransactions)->withBatchId($batch->id);
        $redelivered->batchPositionId = 'last';
        $this->assertFalse($redelivered->batching());
        if ($reopen) {
            $repository->incrementTotalJobs($batch->id, 1);
            $this->assertNull($repository->releaseJobs($batch->id, 1));
        } else {
            $this->assertSame(0, $repository->prune(now()->subDay()));
        }
        $this->assertSame($finishedAt->getTimestamp(), (int) DB::table('job_batches')->where('id', $batch->id)->value('finished_at'));
        $repository->incrementTotalJobs($batch->id, 1);
        $this->assertSame(0, $repository->prune(now()->addDay()));
        $this->assertFalse($batch->fresh()->finished());
    }

    public function testReboundRedisDriverHandlesHydratedAndIdOnlyCallsAndRetriesCleanup(): void
    {
        $driver = new class($this->app->make(BatchFactory::class), $this->app->make('db'), 'job_batches', config('database.default'), 'job_batch_results', $this->app->make(RedisFactory::class), 'default', 'job_batch_deletions') extends RedisBatchRepository {
            public int $countCalls = 0;

            public bool $interrupt = true;

            public function incrementFailedJobs(string $batchId, string $jobId, ?string $retryId = null): ?UpdatedBatchJobCounts
            {
                ++$this->countCalls;
                return parent::incrementFailedJobs($batchId, $jobId, $retryId);
            }

            protected function deleteJobResults(array $ids): void
            {
                if ($this->interrupt) {
                    throw new RuntimeException('Redis cleanup interrupted.');
                }
                parent::deleteJobResults($ids);
            }
        };
        $this->app->instance(RedisBatchRepository::class, $driver);
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([])->allowFailures());
        $repository->incrementTotalJobs($batch->id, 2);
        $batch->fresh()->incrementFailedJobs('first');
        $repository->incrementFailedJobs($batch->id, 'second');
        $this->assertSame(2, $driver->countCalls);
        try {
            $batch->delete();
            $this->fail('Expected the independent cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Redis cleanup interrupted.', $exception->getMessage());
        }
        $this->assertNull($batch->fresh());
        $this->assertSame('redis', DB::table('job_batch_deletions')->where('batch_id', $batch->id)->value('driver'));
        $this->assertTrue($driver->hasFailedJob($batch->id, 'first'));
        $driver->interrupt = false;
        $repository->prune(now()->subDay());
        $this->assertFalse($driver->hasFailedJob($batch->id, 'first'));
        $this->assertSame(0, DB::table('job_batch_deletions')->count());
    }

    public function testRedisOutcomesSurviveRolledBackDeletionAndCannotBeRecordedInsideTheOwnerTransaction(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = $repository->store(Bus::batch([]));
        $repository->incrementTotalJobs($batch->id, 3);
        $batch->recordSuccessfulJob('first');
        DB::beginTransaction();
        try {
            try {
                $batch->recordSuccessfulJob('second');
                $this->fail('Expected the Redis result to require a committed enrollment.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('inside its database transaction', $exception->getMessage());
            }
            $batch->delete();
            $repository->prune(now()->subDay());
            $this->assertNull($batch->fresh());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(2, $batch->fresh()->pendingJobs);
        $retained = $batch->fresh();
        $batch->delete();
        DB::table('job_batch_deletions')->insert(['batch_id' => 'other-batch', 'driver' => 'other']);
        $repository->prune(now()->subDay());
        $this->assertSame(['other-batch'], DB::table('job_batch_deletions')->pluck('batch_id')->all());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($batch->id);
        $retained->progress();
    }

    private function nativeRedisClient(RedisProxy $redis): object
    {
        return $redis->withConnection(static fn (RedisConnection $connection): object => $connection->client());
    }
}
