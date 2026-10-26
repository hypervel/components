<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\MaxAttemptsExceededException;
use Hypervel\Queue\Queue;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use RuntimeException;
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
