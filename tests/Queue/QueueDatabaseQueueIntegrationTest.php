<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\QueueDatabaseQueueIntegrationTest;

use Hypervel\Bus\Batchable;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\CountCrashesAsExceptions;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\MaxAttemptsExceededException;
use Hypervel\Queue\Queue;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

class QueueDatabaseQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected ?DatabaseQueue $queue = null;

    protected bool $migrateRefresh = true;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new DatabaseQueue(
            $this->app->make('db'),
            null,
            'jobs'
        );
        $this->queue->setConnectionName('default');
        $this->queue->setContainer($this->app);
    }

    /**
     * Get the migration options.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/Fixtures/migrations',
        ];
    }

    /**
     * Get the database connection.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->app
            ->make('db')
            ->connection();
    }

    /**
     * Test that jobs that are not reserved and have an available_at value less than now are popped.
     */
    public function testAvailableAndUnReservedJobsArePopped(): void
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mockQueueName = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => CarbonImmutable::now()->subSecond()->getTimestamp(),
                'created_at' => CarbonImmutable::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mockQueueName);

        $this->assertNotNull($poppedJob);
    }

    /**
     * Test that when jobs are popped, the attempts attribute is incremented.
     */
    public function testPoppedJobsIncrementAttempts(): void
    {
        $job = [
            'id' => 1,
            'queue' => 'mock_queue_name',
            'payload' => 'mock_payload',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => CarbonImmutable::now()->subSecond()->getTimestamp(),
            'created_at' => CarbonImmutable::now()->getTimestamp(),
        ];

        $this->connection()->table('jobs')->insert($job);

        $poppedJob = $this->queue->pop($job['queue']);

        $database_record = $this->connection()->table('jobs')->find($job['id']);

        $this->assertEquals(1, $database_record->attempts, 'Job attempts not updated in the database!');
        $this->assertEquals(1, $poppedJob->attempts(), 'The "attempts" attribute of the Job object was not updated by pop!');
    }

    public function testReleasedAttemptIsNotFailedAlongsideItsQueuedRetry(): void
    {
        $handler = new DatabaseQueueReleasedAttemptHandler;
        $this->app->instance(DatabaseQueueReleasedAttemptHandler::class, $handler);
        $failures = [];
        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobFailed::class, static function (JobFailed $event) use (&$failures): void {
            $failures[] = $event;
        });
        $worker = new Worker(
            $this->app->make(Factory::class),
            $events,
            $this->app->make(ExceptionHandler::class),
            static fn (): bool => false,
        );
        $this->queue->pushRaw(json_encode([
            'uuid' => (string) Str::uuid(),
            'job' => DatabaseQueueReleasedAttemptHandler::class . '@fire',
            'data' => [],
            'maxTries' => 1,
        ], JSON_THROW_ON_ERROR));
        $first = $this->queue->pop();

        try {
            $worker->process('default', $first, new WorkerOptions(maxTries: 1));
            $this->fail('Expected the error thrown after release.');
        } catch (RuntimeException $exception) {
            $this->assertSame($handler->exception, $exception);
        }

        $this->assertSame([], $handler->failures);
        $this->assertSame([], $failures);
        $this->assertSame(1, $this->queue->size());
        $retry = $this->queue->pop();
        $this->assertNotSame($first->getJobId(), $retry->getJobId());
        $this->assertSame(2, $retry->attempts());

        try {
            $worker->process('default', $retry, new WorkerOptions(maxTries: 1));
            $this->fail('Expected the retry to exhaust its attempt limit.');
        } catch (MaxAttemptsExceededException $exception) {
            $this->assertSame([$exception], $handler->failures);
            $this->assertCount(1, $failures);
            $this->assertSame($exception, $failures[0]->exception);
        }

        $this->assertSame(0, $this->queue->size());
    }

    /**
     * Test that the queue can be cleared.
     */
    public function testThatQueueCanBeCleared(): void
    {
        $this->connection()
            ->table('jobs')
            ->insert([[
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => CarbonImmutable::now()->addDay()->getTimestamp(),
                'available_at' => CarbonImmutable::now()->subDay()->getTimestamp(),
                'created_at' => CarbonImmutable::now()->getTimestamp(),
            ], [
                'id' => 2,
                'queue' => $mock_queue_name,
                'payload' => 'mock_payload 2',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => CarbonImmutable::now()->subSecond()->getTimestamp(),
                'created_at' => CarbonImmutable::now()->getTimestamp(),
            ]]);

        $this->assertEquals(2, $this->queue->clear($mock_queue_name));
        $this->assertEquals(0, $this->queue->size());
    }

    /**
     * Test that jobs that are not reserved and have an available_at value in the future, are not popped.
     */
    public function testUnavailableJobsAreNotPopped(): void
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => CarbonImmutable::now()->addMinute()->getTimestamp(),
                'created_at' => CarbonImmutable::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mock_queue_name);

        $this->assertNull($poppedJob);
    }

    /**
     * Test that jobs that are reserved and have expired are popped.
     */
    public function testThatReservedAndExpiredJobsArePopped(): void
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => CarbonImmutable::now()->subDay()->getTimestamp(),
                'available_at' => CarbonImmutable::now()->addDay()->getTimestamp(),
                'created_at' => CarbonImmutable::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mock_queue_name);

        $this->assertNotNull($poppedJob);
    }

    /**
     * Test that jobs that are reserved and not expired and available are not popped.
     */
    public function testThatReservedJobsAreNotPopped(): void
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => CarbonImmutable::now()->addDay()->getTimestamp(),
                'available_at' => CarbonImmutable::now()->subDay()->getTimestamp(),
                'created_at' => CarbonImmutable::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mock_queue_name);

        $this->assertNull($poppedJob);
    }

    public function testReservedJobIsNotReclaimedBeforeRetryAfterAtFractionalSecond(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $queue = new DatabaseQueue(
            $this->app->make('db'),
            null,
            'jobs',
            retryAfter: 1,
        );
        $queue->setConnectionName('default');
        $queue->setContainer($this->app);

        $this->connection()->table('jobs')->insert([
            'id' => 1,
            'queue' => 'fractional',
            'payload' => 'mock_payload',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => 1000,
            'created_at' => 1000,
        ]);

        $this->assertNotNull($queue->pop('fractional'));
        $this->assertSame(1001, $this->connection()->table('jobs')->find(1)->reserved_at);

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1001.900000'));

        $this->assertNull($queue->pop('fractional'));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1002.000000'));

        $this->assertNotNull($queue->pop('fractional'));
    }

    public function testCustomPayloadIsExposedOnInspectedJob(): void
    {
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            return ['context' => ['tenant' => 'acme']];
        });

        $this->queue->push('MyJob', []);

        $job = $this->queue->pendingJobs()->first();

        $this->assertSame(['tenant' => 'acme'], $job->payload['context']);
    }

    public function testJobPayloadIsAvailableOnEvents(): void
    {
        $jobQueueingEvent = null;
        $jobQueuedEvent = null;

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        $this->app->make(Dispatcher::class)->listen(function (JobQueueing $event) use (&$jobQueueingEvent): void {
            $jobQueueingEvent = $event;
        });
        $this->app->make(Dispatcher::class)->listen(function (JobQueued $event) use (&$jobQueuedEvent): void {
            $jobQueuedEvent = $event;
        });

        $this->queue->push('MyJob', [
            'hypervel' => 'Framework',
        ]);

        $this->assertIsArray($jobQueueingEvent->payload());
        $this->assertSame((string) $uuid, $jobQueueingEvent->payload()['uuid']);

        $this->assertIsArray($jobQueuedEvent->payload());
        $this->assertSame((string) $uuid, $jobQueuedEvent->payload()['uuid']);
    }

    #[DataProvider('pushJobsDataProvider')]
    public function testPushProperlyPushesJobOntoDatabase(Uuid $uuid, object|string $job, string $displayNameStartsWith, string $jobStartsWith): void
    {
        Str::createUuidsUsing(fn () => $uuid);

        $this->queue->push($job, ['data']);

        $row = $this->rows()->sole();
        $payload = json_decode($row->payload, true);

        $this->assertSame((string) $uuid, $payload['uuid']);
        $this->assertStringContainsString($displayNameStartsWith, $payload['displayName']);
        $this->assertStringContainsString($jobStartsWith, $payload['job']);

        $this->assertSame('default', $row->queue);
        $this->assertEquals(0, $row->attempts);
        $this->assertNull($row->reserved_at);
        $this->assertIsInt($row->available_at);
    }

    /**
     * Provide object, closure and string jobs.
     */
    public static function pushJobsDataProvider(): array
    {
        $uuid = Str::uuid();

        return [
            [$uuid, new MyTestJob, 'MyTestJob', 'CallQueuedHandler'],
            [$uuid, fn (): int => 0, 'Closure', 'CallQueuedHandler'],
            [$uuid, 'foo', 'foo', 'foo'],
        ];
    }

    public function testDelayedPushProperlyPushesJobOntoDatabase(): void
    {
        CarbonImmutable::setTestNow($time = CarbonImmutable::now()->startOfSecond());

        $this->queue->later(10, 'foo', ['data']);

        $row = $this->rows()->sole();
        $payload = json_decode($row->payload, true);

        $this->assertSame('default', $row->queue);
        $this->assertSame('foo', $payload['job']);
        $this->assertSame(['data'], $payload['data']);
        $this->assertSame(10, $payload['delay']);
        $this->assertEquals(0, $row->attempts);
        $this->assertNull($row->reserved_at);
        $this->assertEquals($time->getTimestamp() + 10, $row->available_at);
    }

    public function testPushIncludesBatchIdInPayloadForBatchableJob(): void
    {
        $this->queue->push((new MyBatchableJob)->withBatchId('test-batch-id'), ['data']);

        $payload = json_decode($this->rows()->sole()->payload, true);

        $this->assertSame('test-batch-id', $payload['data']['batchId']);
    }

    public function testPushUsesPropertiesDeclaredOnChildClassOverInheritedAttributes(): void
    {
        $this->queue->push(new ChildJobWithPropertiesOverridingParentAttributes, ['data']);

        $payload = json_decode($this->rows()->sole()->payload, true);

        $this->assertSame(1700, $payload['timeout']);
        $this->assertSame(7, $payload['maxTries']);
        $this->assertSame('13', $payload['backoff']);
        $this->assertSame(11, $payload['maxExceptions']);
        $this->assertFalse($payload['failOnTimeout']);
        $this->assertFalse($payload['countCrashesAsExceptions']);
    }

    public function testPushStillUsesAttributesDeclaredOnSameClassOverDefaultProperties(): void
    {
        $this->queue->push(new JobWithAttributesAndDefaultProperties, ['data']);

        $payload = json_decode($this->rows()->sole()->payload, true);

        $this->assertSame(40, $payload['timeout']);
        $this->assertSame(2, $payload['maxTries']);
        $this->assertSame('9', $payload['backoff']);
        $this->assertSame(3, $payload['maxExceptions']);
        $this->assertTrue($payload['failOnTimeout']);
        $this->assertTrue($payload['countCrashesAsExceptions']);
    }

    public function testBulkBatchPushesOntoDatabase(): void
    {
        $uuids = [Str::uuid(), Str::uuid()];
        Str::createUuidsUsingSequence($uuids);
        CarbonImmutable::setTestNow($time = CarbonImmutable::now()->startOfSecond());

        $inserts = 0;
        $this->connection()->listen(function (QueryExecuted $query) use (&$inserts): void {
            $inserts += str_starts_with($query->sql, 'insert') ? 1 : 0;
        });

        $this->queue->bulk(['foo', 'bar'], ['data'], 'queue');

        $rows = $this->rows()->values();

        $this->assertSame(1, $inserts);
        $this->assertCount(2, $rows);

        foreach ([['foo', $uuids[0]], ['bar', $uuids[1]]] as $index => [$name, $uuid]) {
            $payload = json_decode($rows[$index]->payload, true);

            $this->assertSame('queue', $rows[$index]->queue);
            $this->assertSame([
                'uuid' => (string) $uuid,
                'displayName' => $name,
                'job' => $name,
                'stringJob' => true,
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'data' => ['data'],
                'createdAt' => $time->getTimestamp(),
                'delay' => null,
            ], $payload);
            $this->assertEquals(0, $rows[$index]->attempts);
            $this->assertNull($rows[$index]->reserved_at);
            $this->assertEquals($time->getTimestamp(), $rows[$index]->available_at);
            $this->assertEquals($time->getTimestamp(), $rows[$index]->created_at);
        }
    }

    public function testDelayAttributeIsRespectedWhenBulkPushing(): void
    {
        CarbonImmutable::setTestNow($time = CarbonImmutable::now()->startOfSecond());

        $this->queue->bulk([new JobWithDelayAttribute, new MyTestJob], ['data'], 'queue');

        $rows = $this->rows()->values();

        $this->assertEquals($time->getTimestamp() + 15, $rows[0]->available_at);
        $this->assertEquals($time->getTimestamp(), $rows[1]->available_at);
    }

    public function testBulkDefersAfterCommitJobsUntilTheTransactionCommits(): void
    {
        // RefreshDatabase already installs the manager that ignores its wrapper transaction.
        $insertedDuringTransaction = null;

        $this->connection()->transaction(function () use (&$insertedDuringTransaction): void {
            $this->assertNull($this->queue->bulk([new AfterCommitJob]));

            $insertedDuringTransaction = $this->rows()->count();
        });

        $this->assertSame(0, $insertedDuringTransaction);
        $this->assertCount(1, $this->rows());
    }

    public function testPendingJobs(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(['default-pending'], $this->names($this->queue->pendingJobs()));
        $this->assertSame(['emails-pending'], $this->names($this->queue->pendingJobs('emails')));

        $job = $this->queue->pendingJobs()->sole();
        $this->assertInstanceOf(InspectedJob::class, $job);
        $this->assertSame('default-pending-uuid', $job->uuid);
        $this->assertSame($this->rows()->get(0)->id, $job->id);
        $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
        $this->assertSame(0, $job->attempts);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1000000, $job->createdAt->getTimestamp());
    }

    public function testDelayedJobs(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(['default-delayed'], $this->names($this->queue->delayedJobs()));
        $this->assertSame(['emails-delayed'], $this->names($this->queue->delayedJobs('emails')));

        $job = $this->queue->delayedJobs()->sole();
        $this->assertInstanceOf(InspectedJob::class, $job);
        $this->assertSame('default-delayed-uuid', $job->uuid);
        $this->assertSame($this->rows()->get(1)->id, $job->id);
        $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
        $this->assertSame(0, $job->attempts);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1000000, $job->createdAt->getTimestamp());
    }

    public function testReservedJobs(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(['default-reserved'], $this->names($this->queue->reservedJobs()));
        $this->assertSame(['emails-reserved'], $this->names($this->queue->reservedJobs('emails')));

        $job = $this->queue->reservedJobs()->sole();
        $this->assertInstanceOf(InspectedJob::class, $job);
        $this->assertSame('default-reserved-uuid', $job->uuid);
        $this->assertSame($this->rows()->get(2)->id, $job->id);
        $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
        $this->assertSame(1, $job->attempts);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1000000, $job->createdAt->getTimestamp());
    }

    public function testAllPendingJobs(): void
    {
        $this->seedJobsInEveryState();

        $jobs = $this->queue->allPendingJobs();

        $this->assertSame(['default-pending', 'emails-pending'], $this->names($jobs));
        foreach ($jobs as $index => $job) {
            $this->assertSame($job->name . '-uuid', $job->uuid);
            $this->assertSame($this->rows()->get($index * 3)->id, $job->id);
            $this->assertSame($index, $job->attempts);
            $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
            $this->assertSame(1000000, $job->createdAt->getTimestamp());
        }
        $this->assertSame(['default', 'emails'], $jobs->map->queue->all());
    }

    public function testAllDelayedJobs(): void
    {
        $this->seedJobsInEveryState();

        $jobs = $this->queue->allDelayedJobs();

        $this->assertSame(['default-delayed', 'emails-delayed'], $this->names($jobs));
        foreach ($jobs as $index => $job) {
            $this->assertSame($job->name . '-uuid', $job->uuid);
            $this->assertSame($this->rows()->get($index * 3 + 1)->id, $job->id);
            $this->assertSame(0, $job->attempts);
            $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
            $this->assertSame(1000000, $job->createdAt->getTimestamp());
        }
        $this->assertSame(['default', 'emails'], $jobs->map->queue->all());
    }

    public function testAllReservedJobs(): void
    {
        $this->seedJobsInEveryState();

        $jobs = $this->queue->allReservedJobs();

        $this->assertSame(['default-reserved', 'emails-reserved'], $this->names($jobs));
        foreach ($jobs as $index => $job) {
            $this->assertSame($job->name . '-uuid', $job->uuid);
            $this->assertSame($this->rows()->get($index * 3 + 2)->id, $job->id);
            $this->assertSame($index + 1, $job->attempts);
            $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
            $this->assertSame(1000000, $job->createdAt->getTimestamp());
        }
        $this->assertSame(['default', 'emails'], $jobs->map->queue->all());
        $this->assertSame([1, 2], $jobs->map->attempts->all());
    }

    public function testTotalSize(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(6, $this->queue->totalSize());
    }

    public function testTotalPendingSize(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(2, $this->queue->totalPendingSize());
    }

    public function testTotalDelayedSize(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(2, $this->queue->totalDelayedSize());
    }

    public function testTotalReservedSize(): void
    {
        $this->seedJobsInEveryState();

        $this->assertSame(2, $this->queue->totalReservedSize());
    }

    /**
     * Seed a pending (due right now), delayed (due a second from now) and reserved job on two queues.
     */
    protected function seedJobsInEveryState(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());

        foreach (['default' => 1, 'emails' => 2] as $queue => $attempts) {
            $this->seedJob($queue, "{$queue}-pending", availableAt: $now->getTimestamp(), attempts: $attempts - 1);
            $this->seedJob($queue, "{$queue}-delayed", availableAt: $now->getTimestamp() + 1);
            $this->seedJob($queue, "{$queue}-reserved", availableAt: $now->getTimestamp() - 10, reservedAt: $now->getTimestamp(), attempts: $attempts);
        }
    }

    /**
     * Insert a job in the requested state.
     */
    protected function seedJob(string $queue, string $name, int $availableAt, ?int $reservedAt = null, int $attempts = 0): void
    {
        $this->connection()->table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode(['uuid' => "{$name}-uuid", 'displayName' => $name, 'job' => 'foo', 'data' => [], 'createdAt' => 1000000]),
            'attempts' => $attempts,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => 1000000,
        ]);
    }

    /**
     * Get queued records in insertion order.
     */
    protected function rows(): Collection
    {
        return $this->connection()->table('jobs')->orderBy('id')->get();
    }

    /**
     * Get inspected job names.
     */
    protected function names(Collection $jobs): array
    {
        return $jobs->map->name->all();
    }
}

class DatabaseQueueReleasedAttemptHandler
{
    public RuntimeException $exception;

    /** @var list<Throwable> */
    public array $failures = [];

    /**
     * Prepare an identifiable error for the released attempt.
     */
    public function __construct()
    {
        $this->exception = new RuntimeException('The handler threw after releasing its reservation.');
    }

    /**
     * Publish a retry before an outer handler observes the error.
     */
    public function fire(Job $job, array $data): never
    {
        $job->release();

        throw $this->exception;
    }

    /**
     * Record actual failure callbacks independently of failure events.
     */
    public function failed(array $data, Throwable $exception, string $uuid, Job $job): void
    {
        $this->failures[] = $exception;
    }
}

class MyTestJob
{
    /**
     * Handle the queued job.
     */
    public function handle(): void
    {
        // ...
    }
}

class MyBatchableJob
{
    use Batchable;
}

#[Delay(15)]
class JobWithDelayAttribute
{
}

class AfterCommitJob implements ShouldQueue
{
    public bool $afterCommit = true;
}

#[Backoff(9)]
#[CountCrashesAsExceptions]
#[FailOnTimeout]
#[MaxExceptions(3)]
#[Timeout(40)]
#[Tries(2)]
abstract class ParentJobWithAttributes implements ShouldQueue
{
}

class ChildJobWithPropertiesOverridingParentAttributes extends ParentJobWithAttributes
{
    public int $backoff = 13;

    public bool $countCrashesAsExceptions = false;

    public bool $failOnTimeout = false;

    public int $maxExceptions = 11;

    public int $timeout = 1700;

    public int $tries = 7;
}

#[Backoff(9)]
#[CountCrashesAsExceptions]
#[FailOnTimeout]
#[MaxExceptions(3)]
#[Timeout(40)]
#[Tries(2)]
class JobWithAttributesAndDefaultProperties implements ShouldQueue
{
    public int $backoff = 13;

    public bool $countCrashesAsExceptions = false;

    public bool $failOnTimeout = false;

    public int $maxExceptions = 11;

    public int $timeout = 1700;

    public int $tries = 7;
}
