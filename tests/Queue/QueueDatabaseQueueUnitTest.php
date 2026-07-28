<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\Batchable;
use Hypervel\Container\Container;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\Queue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use stdClass;

class QueueDatabaseQueueUnitTest extends TestCase
{
    public function testQueueNamesPreserveZeroAndDefaultEmptyString(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );

        $this->assertSame('default', $queue->getQueue(null));
        $this->assertSame('default', $queue->getQueue(''));
        $this->assertSame('0', $queue->getQueue('0'));
    }

    #[DataProvider('pushJobsDataProvider')]
    public function testPushProperlyPushesJobOntoDatabase($uuid, $job, $displayNameStartsWith, $jobStartsWith)
    {
        Str::createUuidsUsing(fn () => $uuid);

        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer($container = m::spy(Container::class));
        $resolver->shouldReceive('connection')->andReturn($connection = m::mock(ConnectionInterface::class));
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) use ($uuid, $displayNameStartsWith, $jobStartsWith) {
            $payload = json_decode($array['payload'], true);
            $this->assertSame((string) $uuid, $payload['uuid']);
            $this->assertStringContainsString($displayNameStartsWith, $payload['displayName']);
            $this->assertStringContainsString($jobStartsWith, $payload['job']);

            $this->assertSame('default', $array['queue']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserved_at']);
            $this->assertIsInt($array['available_at']);

            return 1;
        });

        $queue->push($job, ['data']);

        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public static function pushJobsDataProvider()
    {
        $uuid = Str::uuid();

        return [
            [$uuid, new MyTestJob, 'MyTestJob', 'CallQueuedHandler'],
            [$uuid, fn () => 0, 'Closure', 'CallQueuedHandler'],
            [$uuid, 'foo', 'foo', 'foo'],
        ];
    }

    public function testDelayedPushProperlyPushesJobOntoDatabase(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer($container = m::spy(Container::class));
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->andReturn($connection);

        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) use ($uuid, $now) {
            $this->assertSame('default', $array['queue']);
            $this->assertSame(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => 10]), $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserved_at']);
            $this->assertIsInt($array['available_at']);

            return 1;
        });

        $queue->later(10, 'foo', ['data']);

        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPushIncludesBatchIdInPayloadForBatchableJob()
    {
        $uuid = Str::uuid()->toString();

        Str::createUuidsUsing(fn () => $uuid);

        $job = (new MyBatchableJob)->withBatchId('test-batch-id');

        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer($container = m::spy(Container::class));
        $resolver->shouldReceive('connection')->andReturn($connection = m::mock(ConnectionInterface::class));
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) {
            $payload = json_decode($array['payload'], true);
            $this->assertSame('test-batch-id', $payload['data']['batchId']);

            return 1;
        });

        $queue->push($job, ['data']);

        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testFailureToCreatePayloadFromObject()
    {
        $this->expectException('InvalidArgumentException');

        $job = new stdClass;
        $job->invalid = "\xc3\x28";

        $queue = m::mock(Queue::class)->makePartial();
        $class = new ReflectionClass(Queue::class);

        $createPayload = $class->getMethod('createPayload');
        $createPayload->invokeArgs($queue, [
            $job,
            'queue-name',
        ]);
    }

    public function testFailureToCreatePayloadFromArray()
    {
        $this->expectException('InvalidArgumentException');

        $queue = m::mock(Queue::class)->makePartial();
        $class = new ReflectionClass(Queue::class);

        $createPayload = $class->getMethod('createPayload');
        $createPayload->invokeArgs($queue, [
            ["\xc3\x28"],
            'queue-name',
        ]);
    }

    public function testBulkBatchPushesOntoDatabase(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $queue = new TestDatabaseQueue(
            resolver: $resolver,
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
            availableAt: 1732502704,
        );
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->andReturn($connection);
        $query->shouldReceive('insert')->once()->andReturnUsing(function ($records) use ($uuid, $now) {
            $this->assertEquals([[
                'queue' => 'queue',
                'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => null]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => 1732502704,
                'created_at' => 1732502704,
            ], [
                'queue' => 'queue',
                'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'bar', 'job' => 'bar', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => null]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => 1732502704,
                'created_at' => 1732502704,
            ]], $records);

            return true;
        });

        $queue->bulk(['foo', 'bar'], ['data'], 'queue');
    }

    public function testBuildDatabaseRecordWithPayloadAtTheEnd()
    {
        $queue = m::mock(DatabaseQueue::class);
        $record = $queue->buildDatabaseRecord('queue', 'any_payload', 0);
        $this->assertArrayHasKey('payload', $record);
        $this->assertArrayHasKey('payload', array_slice($record, -1, 1, true));
    }

    public function testPendingJobs(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $payload = json_encode([
            'uuid' => 'uuid-11',
            'displayName' => 'PendingJob',
            'job' => 'handler',
            'data' => [],
            'createdAt' => 1000000,
        ]);

        $query->shouldReceive('where')->with('queue', 'default')->andReturnSelf();
        $query->shouldReceive('whereNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('where')->with('available_at', '<=', 1732502704)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            (object) [
                'id' => 11,
                'queue' => 'default',
                'payload' => $payload,
                'attempts' => 0,
            ],
        ]));

        $jobs = $queue->pendingJobs();

        $this->assertInspectedJob($jobs->sole(), 'PendingJob', 'default', 0, 11);
    }

    public function testDelayedJobs(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $payload = json_encode([
            'uuid' => 'uuid-12',
            'displayName' => 'DelayedJob',
            'job' => 'handler',
            'data' => [],
            'createdAt' => 1000000,
        ]);

        $query->shouldReceive('where')->with('queue', 'emails')->andReturnSelf();
        $query->shouldReceive('whereNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('where')->with('available_at', '>', 1732502704)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            (object) [
                'id' => 12,
                'queue' => 'emails',
                'payload' => $payload,
                'attempts' => 0,
            ],
        ]));

        $jobs = $queue->delayedJobs('emails');

        $this->assertInspectedJob($jobs->sole(), 'DelayedJob', 'emails', 0, 12);
    }

    public function testReservedJobs(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $payload = json_encode([
            'uuid' => 'uuid-13',
            'displayName' => 'ReservedJob',
            'job' => 'handler',
            'data' => [],
            'createdAt' => 1000000,
        ]);

        $query->shouldReceive('where')->with('queue', 'default')->andReturnSelf();
        $query->shouldReceive('whereNotNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            (object) [
                'id' => 13,
                'queue' => 'default',
                'payload' => $payload,
                'attempts' => 3,
            ],
        ]));

        $jobs = $queue->reservedJobs();

        $this->assertInspectedJob($jobs->sole(), 'ReservedJob', 'default', 3, 13);
    }

    public function testAllPendingJobs(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $query->shouldReceive('whereNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('where')->with('available_at', '<=', 1732502704)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            $this->inspectionRecord(21, 'default', 'FirstPendingJob', 0),
            $this->inspectionRecord(22, 'emails', 'SecondPendingJob', 1),
        ]));

        $jobs = $queue->allPendingJobs();

        $this->assertInspectedJob($jobs->first(), 'FirstPendingJob', 'default', 0, 21);
        $this->assertInspectedJob($jobs->last(), 'SecondPendingJob', 'emails', 1, 22);
    }

    public function testAllDelayedJobs(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $query->shouldReceive('whereNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('where')->with('available_at', '>', 1732502704)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            $this->inspectionRecord(31, 'default', 'FirstDelayedJob', 0),
            $this->inspectionRecord(32, 'emails', 'SecondDelayedJob', 0),
        ]));

        $jobs = $queue->allDelayedJobs();

        $this->assertInspectedJob($jobs->first(), 'FirstDelayedJob', 'default', 0, 31);
        $this->assertInspectedJob($jobs->last(), 'SecondDelayedJob', 'emails', 0, 32);
    }

    public function testAllReservedJobs(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $query->shouldReceive('whereNotNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            $this->inspectionRecord(41, 'default', 'FirstReservedJob', 1),
            $this->inspectionRecord(42, 'emails', 'SecondReservedJob', 2),
        ]));

        $jobs = $queue->allReservedJobs();

        $this->assertInspectedJob($jobs->first(), 'FirstReservedJob', 'default', 1, 41);
        $this->assertInspectedJob($jobs->last(), 'SecondReservedJob', 'emails', 2, 42);
    }

    public function testInvalidInspectedPayloadIdentifiesItsQueueAndRecord(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $query->shouldReceive('where')->with('queue', 'emails')->andReturnSelf();
        $query->shouldReceive('whereNull')->with('reserved_at')->andReturnSelf();
        $query->shouldReceive('where')->with('available_at', '<=', 1732502704)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect([
            (object) [
                'id' => 99,
                'queue' => 'emails',
                'payload' => 'not-json',
                'attempts' => 0,
            ],
        ]));

        try {
            $queue->pendingJobs('emails');
            $this->fail('Expected the invalid payload to be rejected.');
        } catch (InvalidPayloadException $exception) {
            $this->assertStringContainsString('on queue [emails] with record ID [99]', $exception->getMessage());
            $this->assertSame('not-json', $exception->value);
        }
    }

    private function createInspectionQueue(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);
        $connection->shouldReceive('table')->with('table')->andReturn($query);

        return [
            new TestDatabaseQueue(
                resolver: $resolver,
                connection: null,
                table: 'table',
                default: 'default',
                currentTime: 1732502704,
            ),
            $query,
        ];
    }

    private function inspectionRecord(
        int $id,
        string $queue,
        string $name,
        int $attempts,
    ): object {
        return (object) [
            'id' => $id,
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => "uuid-{$id}",
                'displayName' => $name,
                'job' => 'handler',
                'data' => [],
                'createdAt' => 1000000,
            ]),
            'attempts' => $attempts,
        ];
    }

    private function assertInspectedJob(
        InspectedJob $job,
        string $name,
        string $queue,
        int $attempts,
        int $id,
    ): void {
        $this->assertSame($name, $job->name);
        $this->assertSame("uuid-{$id}", $job->uuid);
        $this->assertSame($queue, $job->queue);
        $this->assertSame($attempts, $job->attempts);
        $this->assertSame($id, $job->id);
        $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
        $this->assertSame(1000000, $job->createdAt->getTimestamp());
    }
}

class MyTestJob
{
    public function handle()
    {
        // ...
    }
}

class MyBatchableJob
{
    use Batchable;
}

class TestDatabaseQueue extends DatabaseQueue
{
    public function __construct(
        ConnectionResolverInterface $resolver,
        ?string $connection,
        string $table,
        string $default,
        private readonly int $currentTime,
        private readonly ?int $availableAt = null,
    ) {
        parent::__construct($resolver, $connection, $table, $default);
    }

    protected function currentTime(): int
    {
        return $this->currentTime;
    }

    protected function availableAt(DateInterval|DateTimeInterface|int|null $delay = 0): int
    {
        return $this->availableAt ?? parent::availableAt($delay);
    }
}
