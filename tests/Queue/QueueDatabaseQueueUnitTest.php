<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\DatabaseJobRecord;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\Queue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use TypeError;

class QueueDatabaseQueueUnitTest extends TestCase
{
    public function testRetryAfterMustBeAnInteger(): void
    {
        $this->expectException(TypeError::class);

        new DatabaseQueue(
            resolver: m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            retryAfter: null,
        );
    }

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

    public function testLockForPoppingUsesThePdoConnectionCapability(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(PdoConnection::class);

        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection);
        $connection->shouldReceive('lockForPopping')->once()->andReturn('FOR UPDATE SKIP LOCKED');

        $queue = new TestDatabaseQueue(
            resolver: $resolver,
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );

        $this->assertSame('FOR UPDATE SKIP LOCKED', $queue->lockForPopping());
    }

    public function testLockForPoppingUsesAConservativeFallbackForNonPdoConnections(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->once()->with(null)->andReturn(m::mock(ConnectionInterface::class));

        $queue = new TestDatabaseQueue(
            resolver: $resolver,
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );

        $this->assertTrue($queue->lockForPopping());
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

        $container->shouldHaveReceived('bound')->with('events')->times(3);
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

    #[DataProvider('delayedJobDeadlineProvider')]
    public function testDelayedPushNeverRunsBeforeRequestedDeadline(DateInterval|DateTimeInterface|int $delay): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1000,
        );
        $queue->setContainer($container = m::spy(Container::class));
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->andReturn($connection);

        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function ($array) use ($uuid, $now) {
            $this->assertSame('default', $array['queue']);
            $this->assertSame(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => 1]), $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserved_at']);
            $this->assertSame(1002, $array['available_at']);

            return 1;
        });

        $queue->later($delay, 'foo', ['data']);

        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public static function delayedJobDeadlineProvider(): array
    {
        return [
            'integer' => [1],
            'interval' => [new DateInterval('PT1S')],
            'absolute date' => [CarbonImmutable::createFromTimestampUTC('1001.100000')],
        ];
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

        $container->shouldHaveReceived('bound')->with('events')->times(3);
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
        $queue->setContainer(new Container);
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

    public function testBulkUsesOneInsertWithoutATransactionAtTheBindingLimit(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);

        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection = m::mock(PdoConnection::class));
        $connection->shouldReceive('maxBindings')->once()->andReturn(12);
        $connection->shouldNotReceive('transaction');
        $connection->shouldReceive('table')->once()->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insert')->once()->with(m::on(static fn (array $rows): bool => count($rows) === 2))->andReturnTrue();

        $this->assertTrue($queue->bulk(['first', 'second']));
    }

    public function testBulkUsesTheMinimumChunksInsideOneTransactionAboveTheBindingLimit(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);

        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection = m::mock(PdoConnection::class));
        $connection->shouldReceive('maxBindings')->once()->andReturn(12);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(
            static fn (Closure $callback): mixed => $callback()
        );
        $connection->shouldReceive('table')->twice()->with('table')->andReturn($query = m::mock(Builder::class));
        $chunkSizes = [];
        $query->shouldReceive('insert')->twice()->andReturnUsing(static function (array $rows) use (&$chunkSizes): bool {
            $chunkSizes[] = count($rows);

            return true;
        });

        $this->assertTrue($queue->bulk(['first', 'second', 'third']));
        $this->assertSame([2, 1], $chunkSizes);
    }

    public function testBulkTreatsAnExplicitFalseInsertAsFailure(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);

        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection = m::mock(ConnectionInterface::class));
        $connection->shouldReceive('table')->once()->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insert')->once()->andReturnFalse();

        $this->expectExceptionObject(new RuntimeException('Unable to insert queued jobs into the database.'));

        $queue->bulk(['job']);
    }

    public function testBulkRetainsDispatchOwnershipUntilTheTransactionCommits(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);
        $first = new DatabaseBulkOwnedJob;
        $second = new DatabaseBulkOwnedJob;
        $cache = m::mock(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');

        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection = m::mock(PdoConnection::class));
        $connection->shouldReceive('maxBindings')->once()->andReturn(6);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function (Closure $callback) use ($first, $second): mixed {
            $this->assertTrue(DispatchLockContext::has($first));
            $this->assertTrue(DispatchLockContext::has($second));

            $result = $callback();

            $this->assertTrue(DispatchLockContext::has($first));
            $this->assertTrue(DispatchLockContext::has($second));

            return $result;
        });
        $connection->shouldReceive('table')->twice()->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insert')->twice()->andReturnTrue();

        $this->assertTrue($queue->bulk([$first, $second]));
        $this->assertFalse(DispatchLockContext::has($first));
        $this->assertFalse(DispatchLockContext::has($second));
    }

    public function testBulkAcceptsEveryDispatchBeforeRaisingSuccessEvents(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');
        $first = new DatabaseBulkOwnedJob;
        $second = new DatabaseBulkOwnedJob;
        $cache = m::mock(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');
        $exception = new RuntimeException('Listener failed.');

        $resolver->shouldReceive('connection')->once()->with(null)->andReturn($connection = m::mock(ConnectionInterface::class));
        $connection->shouldReceive('table')->once()->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insert')->once()->andReturnTrue();
        $dispatcher->listen(JobQueued::class, static function () use ($exception): never {
            throw $exception;
        });

        try {
            $queue->bulk([$first, $second]);
            $this->fail('Expected the success listener to fail.');
        } catch (RuntimeException $actual) {
            $this->assertSame($exception, $actual);
        }

        $this->assertFalse(DispatchLockContext::has($first));
        $this->assertFalse(DispatchLockContext::has($second));
    }

    public function testBulkHonorsTheDelayAttribute(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1732502704));

        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);
        $resolver->shouldReceive('connection')->andReturn($connection = m::mock(ConnectionInterface::class));
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $query->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function (array $records): bool {
                $this->assertSame(1732502713, $records[0]['available_at']);

                return true;
            });

        $queue->bulk([new DatabaseBulkAttributeDelayJob]);
    }

    public function testBulkRaisesExactSuccessEventsAroundOneInsert(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection)->once();
        $query->shouldReceive('insert')->once()->andReturnUsing(function (array $records): bool {
            $this->assertSame('first-final', json_decode($records[0]['payload'], true)['telemetry']);
            $this->assertSame('second-final', json_decode($records[1]['payload'], true)['telemetry']);

            return true;
        });

        $events = [];
        $dispatcher->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event) use (&$events): void {
            $events[] = $event;
            $payload = $event->payload();
            $payload['telemetry'] = $event->job . '-final';
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });
        $dispatcher->listen(JobQueueing::class, static function (JobQueueing $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(JobQueued::class, static function (JobQueued $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertTrue($queue->bulk(['first', 'second'], queue: 'emails'));
        $this->assertSame([
            JobPayloadFinalizing::class,
            JobPayloadFinalizing::class,
            JobQueueing::class,
            JobQueueing::class,
            JobQueued::class,
            JobQueued::class,
        ], array_map(static fn (object $event): string => $event::class, $events));
        $this->assertSame(['first', 'second', 'first', 'second', 'first', 'second'], array_column($events, 'job'));
        $this->assertSame('first-final', $events[2]->payload()['telemetry']);
        $this->assertSame('second-final', $events[3]->payload()['telemetry']);
        $this->assertNull($events[4]->id);
        $this->assertNull($events[5]->id);
    }

    public function testBulkRaisesExactFailureEventsWhenInsertThrows(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        $exception = new RuntimeException('Insert failed.');
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection)->once();
        $query->shouldReceive('insert')->once()->andThrow($exception);

        $events = [];
        $dispatcher->listen(JobQueueing::class, static function (JobQueueing $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $queue->bulk(['first', 'second'], queue: 'emails');
            $this->fail('Expected the bulk insert to fail.');
        } catch (RuntimeException $actual) {
            $this->assertSame($exception, $actual);
        }

        $this->assertSame([
            JobQueueing::class,
            JobQueueing::class,
            JobQueueingFailed::class,
            JobQueueingFailed::class,
        ], array_map(static fn (object $event): string => $event::class, $events));
        $this->assertSame(['first', 'second', 'first', 'second'], array_column($events, 'job'));
        $this->assertSame($exception, $events[2]->exception);
        $this->assertSame($exception, $events[3]->exception);
    }

    public function testBulkCancellationDoesNotRaiseFailureEvents(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->with('table')->andReturn($query = m::mock(Builder::class));
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection)->once();
        $gate = $this->armCurrentCoroutineCancellation();
        $query->shouldReceive('insert')->once()->andReturnUsing(static function () use ($gate): never {
            $gate->push(true);

            throw new RuntimeException('Cancellation was not delivered.');
        });

        $events = [];
        $dispatcher->listen(JobQueueing::class, static function (JobQueueing $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $queue->bulk(['first', 'second'], queue: 'emails');
            $this->fail('Expected cancellation to escape the bulk insert.');
        } catch (CanceledException) {
            $this->assertSame([
                JobQueueing::class,
                JobQueueing::class,
            ], array_map(static fn (object $event): string => $event::class, $events));
        }
    }

    public function testBulkSplitsImmediateAndAfterCommitJobsAndReacquiresTheQueue(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1732502704));

        $queue = new TestDatabaseQueue(
            resolver: $immediateResolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $deferredQueue = new TestDatabaseQueue(
            resolver: $deferredResolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502804,
        );

        $transactions = new DatabaseTransactionsManager;
        $transactions->begin('default', 1);
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $queue->setContainer($container);
        $deferredQueue->setContainer(new Container);

        $immediateConnection = m::mock(ConnectionInterface::class);
        $immediateConnection->shouldReceive('table')->with('table')->andReturn($immediateQuery = m::mock(Builder::class));
        $immediateResolver->shouldReceive('connection')->with(null)->andReturn($immediateConnection)->once();
        $immediateRecords = [];
        $immediateQuery->shouldReceive('insert')->once()->andReturnUsing(static function (array $records) use (&$immediateRecords): bool {
            $immediateRecords = $records;

            return true;
        });

        $deferredConnection = m::mock(ConnectionInterface::class);
        $deferredConnection->shouldReceive('table')->with('table')->andReturn($deferredQuery = m::mock(Builder::class));
        $deferredResolver->shouldReceive('connection')->with(null)->andReturn($deferredConnection)->once();
        $deferredRecords = [];
        $deferredQuery->shouldReceive('insert')->once()->andReturnUsing(static function (array $records) use (&$deferredRecords): bool {
            $deferredRecords = $records;

            return true;
        });

        $reacquired = false;
        $queue->setAfterCommitDispatcher(static function (Closure $callback) use ($deferredQueue, &$reacquired): mixed {
            $reacquired = true;

            return $callback($deferredQueue);
        });

        $this->assertTrue($queue->bulk(['immediate', new DatabaseBulkAfterCommitDelayJob], queue: 'emails'));
        $this->assertFalse($reacquired);
        $this->assertCount(1, $immediateRecords);
        $this->assertSame(1732502704, $immediateRecords[0]['available_at']);
        $this->assertSame([], $deferredRecords);

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1732502804));
        $transactions->commit('default', 1, 0);

        $this->assertTrue($reacquired);
        $this->assertCount(1, $deferredRecords);
        $this->assertSame(1732502813, $deferredRecords[0]['available_at']);
        $this->assertSame(9, json_decode($deferredRecords[0]['payload'], true)['delay']);
        $this->assertSame(1732502704, json_decode($deferredRecords[0]['payload'], true)['createdAt']);
    }

    public function testBulkRollbackDoesNotBeginAnEnqueueAttempt(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = m::mock(ConnectionResolverInterface::class),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $transactions = new DatabaseTransactionsManager;
        $transactions->begin('default', 1);
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('db.transactions', $transactions);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);

        $resolver->shouldNotReceive('connection');
        $dispatched = [];
        $dispatcher->listen(JobQueueing::class, static function (JobQueueing $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $reacquired = false;
        $queue->setAfterCommitDispatcher(static function () use (&$reacquired): never {
            $reacquired = true;

            throw new RuntimeException('The queue must not be reacquired after rollback.');
        });

        $this->assertNull($queue->bulk([new DatabaseBulkAfterCommitDelayJob], queue: 'emails'));
        $transactions->rollback('default', 0);

        $this->assertFalse($reacquired);
        $this->assertSame([], $dispatched);
    }

    public function testBuildDatabaseRecordWithPayloadAtTheEnd()
    {
        $queue = m::mock(DatabaseQueue::class);
        $record = $queue->buildDatabaseRecord('queue', 'any_payload', 0);
        $this->assertArrayHasKey('payload', $record);
        $this->assertArrayHasKey('payload', array_slice($record, -1, 1, true));
    }

    public function testReservedTimestampRoundsUpAtFractionalSecond(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $record = (object) ['attempts' => 0, 'reserved_at' => null];
        $job = new DatabaseJobRecord($record);

        $this->assertSame(1001, $job->touch());
        $this->assertSame(1001, $record->reserved_at);
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

    public function testTotalSize(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $database = m::mock(ConnectionInterface::class);
        $resolver->expects('connection')->with(null)->andReturn($database);
        $queue = new TestDatabaseQueue($resolver, null, 'table', 'default', 1732502704);
        $queue->setContainer(m::spy(Container::class));

        $query = m::mock(Builder::class);
        $database->expects('table')->with('table')->andReturn($query);
        $query->expects('count')->andReturn(9);

        $this->assertSame(9, $queue->totalSize());
    }

    public function testTotalPendingSize(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $database = m::mock(ConnectionInterface::class);
        $resolver->expects('connection')->with(null)->andReturn($database);
        $queue = new TestDatabaseQueue($resolver, null, 'table', 'default', 1732502704);
        $queue->setContainer(m::spy(Container::class));

        $query = m::mock(Builder::class);
        $database->expects('table')->with('table')->andReturn($query);
        $query->expects('whereNull')->with('reserved_at')->andReturnSelf();
        $query->expects('where')->with('available_at', '<=', 1732502704)->andReturnSelf();
        $query->expects('count')->andReturn(2);

        $this->assertSame(2, $queue->totalPendingSize());
    }

    public function testTotalDelayedSize(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $database = m::mock(ConnectionInterface::class);
        $resolver->expects('connection')->with(null)->andReturn($database);
        $queue = new TestDatabaseQueue($resolver, null, 'table', 'default', 1732502704);
        $queue->setContainer(m::spy(Container::class));

        $query = m::mock(Builder::class);
        $database->expects('table')->with('table')->andReturn($query);
        $query->expects('whereNull')->with('reserved_at')->andReturnSelf();
        $query->expects('where')->with('available_at', '>', 1732502704)->andReturnSelf();
        $query->expects('count')->andReturn(3);

        $this->assertSame(3, $queue->totalDelayedSize());
    }

    public function testTotalReservedSize(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $database = m::mock(ConnectionInterface::class);
        $resolver->expects('connection')->with(null)->andReturn($database);
        $queue = new TestDatabaseQueue($resolver, null, 'table', 'default', 1732502704);
        $queue->setContainer(m::spy(Container::class));

        $query = m::mock(Builder::class);
        $database->expects('table')->with('table')->andReturn($query);
        $query->expects('whereNotNull')->with('reserved_at')->andReturnSelf();
        $query->expects('count')->andReturn(4);

        $this->assertSame(4, $queue->totalReservedSize());
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

    /**
     * Arm exact cancellation of the current coroutine at a controlled channel handoff.
     */
    private function armCurrentCoroutineCancellation(): Channel
    {
        $gate = new Channel(1);
        $coroutineId = EngineCoroutine::id();

        EngineCoroutine::create(static function () use ($coroutineId, $gate): void {
            $gate->pop();
            EngineCoroutine::cancelById($coroutineId, throwException: true);
        });

        return $gate;
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

#[Delay(9)]
class DatabaseBulkAttributeDelayJob
{
}

#[Delay(9)]
class DatabaseBulkAfterCommitDelayJob implements ShouldQueueAfterCommit
{
}

class DatabaseBulkOwnedJob
{
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

    /**
     * Get the lock used when popping a job.
     */
    public function lockForPopping(): bool|string
    {
        return $this->getLockForPopping();
    }

    protected function availableAt(DateInterval|DateTimeInterface|int|null $delay = 0): int
    {
        return $this->availableAt ?? parent::availableAt($delay);
    }
}
