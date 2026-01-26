<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hyperf\Di\Container;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Queue;
use Hypervel\Support\Str;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;
use ReflectionClass;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class QueueDatabaseQueueUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Uuid::setFactory(new UuidFactory());
    }

    #[DataProvider('pushJobsDataProvider')]
    public function testPushProperlyPushesJobOntoDatabase($uuid, $job, $displayNameStartsWith, $jobStartsWith)
    {
        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        $queue = $this->getMockBuilder(DatabaseQueue::class)->onlyMethods(['currentTime'])->setConstructorArgs([$resolver = m::mock(ConnectionResolverInterface::class), null, 'table', 'default'])->getMock();
        $queue->expects($this->any())->method('currentTime')->willReturn(1732502704);
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

        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();
    }

    public static function pushJobsDataProvider()
    {
        $uuid = Str::uuid();

        return [
            [$uuid, new MyTestJob(), 'MyTestJob', 'CallQueuedHandler'],
            [$uuid, fn () => 0, 'Closure', 'CallQueuedHandler'],
            [$uuid, 'foo', 'foo', 'foo'],
        ];
    }

    public function testDelayedPushProperlyPushesJobOntoDatabase()
    {
        $uuid = Str::uuid();

        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        $queue = $this->getMockBuilder(DatabaseQueue::class)
            ->onlyMethods(['currentTime'])
            ->setConstructorArgs([$resolver = m::mock(ConnectionResolverInterface::class), null, 'table', 'default'])
            ->getMock();
        $queue->expects($this->any())->method('currentTime')->willReturn(1732502704);
        $queue->setContainer($container = m::spy(Container::class));
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->andReturn($connection);

        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) use ($uuid) {
            $this->assertSame('default', $array['queue']);
            $this->assertSame(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data']]), $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserved_at']);
            $this->assertIsInt($array['available_at']);

            return 1;
        });

        $queue->later(10, 'foo', ['data']);

        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();
    }

    public function testFailureToCreatePayloadFromObject()
    {
        $this->expectException('InvalidArgumentException');

        $job = new stdClass();
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

    public function testBulkBatchPushesOntoDatabase()
    {
        $uuid = Str::uuid();

        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $queue = $this->getMockBuilder(DatabaseQueue::class)->onlyMethods(['currentTime', 'availableAt'])->setConstructorArgs([$resolver, null, 'table', 'default'])->getMock();
        $queue->expects($this->any())->method('currentTime')->willReturn(1732502704);
        $queue->expects($this->any())->method('availableAt')->willReturn(1732502704);
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->andReturn($connection);
        $query->shouldReceive('insert')->once()->andReturnUsing(function ($records) use ($uuid) {
            $this->assertEquals([[
                'queue' => 'queue',
                'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data']]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => 1732502704,
                'created_at' => 1732502704,
            ], [
                'queue' => 'queue',
                'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'bar', 'job' => 'bar', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data']]),
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
}

class MyTestJob
{
    public function handle()
    {
        // ...
    }
}
