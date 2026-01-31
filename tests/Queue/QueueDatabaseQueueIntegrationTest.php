<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;

/**
 * @internal
 * @coversNothing
 */
class QueueDatabaseQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected ?DatabaseQueue $queue = null;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new DatabaseQueue(
            $this->app->get(ConnectionResolverInterface::class),
            null,
            'jobs'
        );
        $this->queue->setConnectionName('default');
        $this->queue->setContainer($this->app);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Uuid::setFactory(new UuidFactory());
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function connection(): ConnectionInterface
    {
        return $this->app
            ->get(ConnectionResolverInterface::class)
            ->connection();
    }

    /**
     * Test that jobs that are not reserved and have an available_at value less then now, are popped.
     */
    public function testAvailableAndUnReservedJobsArePopped()
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mockQueueName = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => Carbon::now()->subSeconds(1)->getTimestamp(),
                'created_at' => Carbon::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mockQueueName);

        $this->assertNotNull($poppedJob);
    }

    /**
     * Test that when jobs are popped, the attempts attribute is incremented.
     */
    public function testPoppedJobsIncrementAttempts()
    {
        $job = [
            'id' => 1,
            'queue' => 'mock_queue_name',
            'payload' => 'mock_payload',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => Carbon::now()->subSeconds(1)->getTimestamp(),
            'created_at' => Carbon::now()->getTimestamp(),
        ];

        $this->connection()->table('jobs')->insert($job);

        $poppedJob = $this->queue->pop($job['queue']);

        $database_record = $this->connection()->table('jobs')->find($job['id']);

        $this->assertEquals(1, $database_record->attempts, 'Job attempts not updated in the database!');
        $this->assertEquals(1, $poppedJob->attempts(), 'The "attempts" attribute of the Job object was not updated by pop!');
    }

    /**
     * Test that the queue can be cleared.
     */
    public function testThatQueueCanBeCleared()
    {
        $this->connection()
            ->table('jobs')
            ->insert([[
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => Carbon::now()->addDay()->getTimestamp(),
                'available_at' => Carbon::now()->subDay()->getTimestamp(),
                'created_at' => Carbon::now()->getTimestamp(),
            ], [
                'id' => 2,
                'queue' => $mock_queue_name,
                'payload' => 'mock_payload 2',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => Carbon::now()->subSeconds(1)->getTimestamp(),
                'created_at' => Carbon::now()->getTimestamp(),
            ]]);

        $this->assertEquals(2, $this->queue->clear($mock_queue_name));
        $this->assertEquals(0, $this->queue->size());
    }

    /**
     * Test that jobs that are not reserved and have an available_at value in the future, are not popped.
     */
    public function testUnavailableJobsAreNotPopped()
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => Carbon::now()->addSeconds(60)->getTimestamp(),
                'created_at' => Carbon::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mock_queue_name);

        $this->assertNull($poppedJob);
    }

    /**
     * Test that jobs that are reserved and have expired are popped.
     */
    public function testThatReservedAndExpiredJobsArePopped()
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => Carbon::now()->subDay()->getTimestamp(),
                'available_at' => Carbon::now()->addDay()->getTimestamp(),
                'created_at' => Carbon::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mock_queue_name);

        $this->assertNotNull($poppedJob);
    }

    /**
     * Test that jobs that are reserved and not expired and available are not popped.
     */
    public function testThatReservedJobsAreNotPopped()
    {
        $this->connection()
            ->table('jobs')
            ->insert([
                'id' => 1,
                'queue' => $mock_queue_name = 'mock_queue_name',
                'payload' => 'mock_payload',
                'attempts' => 0,
                'reserved_at' => Carbon::now()->addDay()->getTimestamp(),
                'available_at' => Carbon::now()->subDay()->getTimestamp(),
                'created_at' => Carbon::now()->getTimestamp(),
            ]);

        $poppedJob = $this->queue->pop($mock_queue_name);

        $this->assertNull($poppedJob);
    }

    public function testJobPayloadIsAvailableOnEvents()
    {
        $jobQueueingEvent = null;
        $jobQueuedEvent = null;

        $uuid = Str::uuid();

        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        $this->app->get(EventDispatcherInterface::class)->listen(function (JobQueueing $e) use (&$jobQueueingEvent) {
            $jobQueueingEvent = $e;
        });
        $this->app->get(EventDispatcherInterface::class)->listen(function (JobQueued $e) use (&$jobQueuedEvent) {
            $jobQueuedEvent = $e;
        });

        $this->queue->push('MyJob', [
            'laravel' => 'Framework',
        ]);

        $this->assertIsArray($jobQueueingEvent->payload());
        $this->assertSame((string) $uuid, $jobQueueingEvent->payload()['uuid']);

        $this->assertIsArray($jobQueuedEvent->payload());
        $this->assertSame((string) $uuid, $jobQueuedEvent->payload()['uuid']);
    }
}
