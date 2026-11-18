<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\QueueDatabaseQueueUnitTest;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\QueryException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingCanceled;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\DatabaseJobRecord;
use Hypervel\Queue\Queue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Hypervel\Tests\Queue\Fixtures\UnitQueueName;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TypeError;

class QueueDatabaseQueueUnitTest extends TestCase
{
    use VerifiesDoubles;

    public function testRetryAfterMustBeAnInteger(): void
    {
        $this->expectException(TypeError::class);

        new DatabaseQueue(
            resolver: Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            retryAfter: null,
        );
    }

    public function testQueueNamesPreserveZeroAndDefaultEmptyString(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );

        $this->assertSame('default', $queue->getQueue(null));
        $this->assertSame('default', $queue->getQueue(''));
        $this->assertSame('0', $queue->getQueue('0'));
        $this->assertSame('0', $queue->getQueue(IntegerQueueName::Zero));
        $this->assertSame('Emails', $queue->getQueue(UnitQueueName::Emails));
    }

    #[TestWith([true, 'FOR UPDATE SKIP LOCKED'])]
    #[TestWith([false, true])]
    public function testLockForPoppingUsesTheConnectionCapability(bool $supportsSkipLocked, bool|string $lock): void
    {
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();

        $resolver->expects('connection')->with(null)->returns($connection);
        $connection->expects('supportsSkipLocked')->returns($supportsSkipLocked);

        $queue = new TestDatabaseQueue(
            resolver: $resolver,
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );

        $this->assertSame($lock, $queue->lockForPopping());
    }

    #[DataProvider('transientReservationFailureProvider')]
    public function testTransientReservationFailuresDoNotFailTheJob(Throwable $failure): void
    {
        [$queue, $events] = $this->createFailingReservationQueue($failure);
        $queue->expects('deleteReserved')->never();
        $events->expects('dispatch')->never();

        try {
            $queue->pop();
            $this->fail('Expected the reservation failure.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * Provide reservation failures that do not indicate an invalid job.
     */
    public static function transientReservationFailureProvider(): array
    {
        return [
            'concurrency' => [new QueryException('database', 'update jobs', [], new PDOException('deadlock detected', 40001))],
            'lost connection' => [new QueryException('database', 'update jobs', [], new PDOException('server has gone away'))],
            'cancellation' => [new CanceledException('Reservation canceled.')],
        ];
    }

    #[DataProvider('reservationCleanupFailureProvider')]
    public function testReservationRecoveryPreservesFailureOrPropagatesCancellation(Throwable $cleanupFailure, bool $canceled): void
    {
        $failure = new QueryException('database', 'update jobs', [], new PDOException('Reservation failed.'));
        [$queue, $events] = $this->createFailingReservationQueue($failure);
        $queue->expects('causedByReservationQuery')->returns(true);
        $queue->expects('deleteReserved')->with('default', '1')->throws($cleanupFailure);

        if ($canceled) {
            $events->expects('dispatch')->never();
        } else {
            $events->expects('hasListeners')->with(JobFailed::class)->returns(false);
        }

        try {
            $queue->pop();
            $this->fail('Expected the reservation or cancellation failure.');
        } catch (Throwable $exception) {
            $this->assertSame($canceled ? $cleanupFailure : $failure, $exception);
        }
    }

    /**
     * Provide failures while deleting a job that could not be reserved.
     */
    public static function reservationCleanupFailureProvider(): array
    {
        return [
            'ordinary failure' => [new RuntimeException('Deletion failed.'), false],
            'cancellation' => [new CanceledException('Deletion canceled.'), true],
            'cleanup during cancellation' => [new RuntimeException('Deletion cleanup failed.', previous: new CanceledException), true],
        ];
    }

    #[DataProvider('delayedJobDeadlineProvider')]
    public function testDelayedPushRoundsUpFractionalDeadlines(DateInterval|DateTimeInterface|int $delay): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn (): Uuid => $uuid);

        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1000,
        );
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $connection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();
        $connection->expects('table')->with('table')->returns($query);
        $resolver->expects('connection')->returns($connection);

        $query->expects('insertGetId')->resolves(function (array $array) use ($uuid, $now): int {
            $this->assertSame('default', $array['queue']);
            $this->assertSame(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => 1]), $array['payload']);
            $this->assertEquals(0, $array['attempts']);
            $this->assertNull($array['reserved_at']);
            $this->assertSame(1002, $array['available_at']);

            return 1;
        });

        $queue->later($delay, 'foo', ['data']);

        $container->received('bound')->with('events')->times(3);
    }

    /**
     * Provide delays that cross a fractional-second deadline.
     */
    public static function delayedJobDeadlineProvider(): array
    {
        return [
            'integer' => [1],
            'interval' => [new DateInterval('PT1S')],
            'absolute date' => [CarbonImmutable::createFromTimestampUTC('1001.100000')],
        ];
    }

    public function testFailureToCreatePayloadFromObject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $job = new stdClass;
        $job->invalid = "\xc3\x28";

        $queue = new DatabaseQueue(Double::for(ConnectionResolverInterface::class)->strict(), null, 'table', 'default');
        $class = new ReflectionClass(Queue::class);

        $createPayload = $class->getMethod('createPayload');
        $createPayload->invokeArgs($queue, [
            $job,
            'queue-name',
        ]);
    }

    public function testFailureToCreatePayloadFromArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $queue = new DatabaseQueue(Double::for(ConnectionResolverInterface::class)->strict(), null, 'table', 'default');
        $class = new ReflectionClass(Queue::class);

        $createPayload = $class->getMethod('createPayload');
        $createPayload->invokeArgs($queue, [
            ["\xc3\x28"],
            'queue-name',
        ]);
    }

    public function testBulkUsesOneInsertWithoutATransactionAtTheBindingLimit(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);

        $resolver->expects('connection')->with(null)->returns($connection = Double::for(PdoConnection::class)->strict());
        $connection->expects('maxBindings')->returns(12);
        $connection->expects('transaction')->never();
        $connection->expects('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $query->expects('insert')->with(Argument::satisfies(static fn (array $rows): bool => count($rows) === 2))->returns(true);

        $this->assertTrue($queue->bulk(['first', 'second']));
    }

    public function testBulkUsesTheMinimumChunksInsideOneTransactionAboveTheBindingLimit(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);

        $resolver->expects('connection')->with(null)->returns($connection = Double::for(PdoConnection::class)->strict());
        $connection->expects('maxBindings')->returns(12);
        $connection->expects('transaction')->resolves(
            static fn (Closure $callback): mixed => $callback()
        );
        $connection->expects('table')->times(2)->with('table')->returns($query = Double::for(Builder::class)->strict());
        $chunkSizes = [];
        $query->expects('insert')->times(2)->resolves(static function (array $rows) use (&$chunkSizes): bool {
            $chunkSizes[] = count($rows);

            return true;
        });

        $this->assertTrue($queue->bulk(['first', 'second', 'third']));
        $this->assertSame([2, 1], $chunkSizes);
    }

    public function testBulkTreatsAnExplicitFalseInsertAsFailure(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);

        $resolver->expects('connection')->with(null)->returns($connection = Double::for(Connection::class)->strict());
        $connection->expects('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $query->expects('insert')->returns(false);

        $this->expectExceptionObject(new RuntimeException('Unable to insert queued jobs into the database.'));

        $queue->bulk(['job']);
    }

    public function testBulkRetainsDispatchOwnershipUntilTheTransactionCommits(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $queue->setContainer(new Container);
        $first = new DatabaseBulkOwnedJob;
        $second = new DatabaseBulkOwnedJob;
        $cache = Double::for(CacheRepository::class)->strict();
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');

        $resolver->expects('connection')->with(null)->returns($connection = Double::for(PdoConnection::class)->strict());
        $connection->expects('maxBindings')->returns(6);
        $connection->expects('transactionLevel')->times(2)->returns(0);
        $connection->expects('transaction')->resolves(function (Closure $callback) use ($first, $second): mixed {
            $this->assertTrue(DispatchLockContext::has($first));
            $this->assertTrue(DispatchLockContext::has($second));

            $result = $callback();

            $this->assertTrue(DispatchLockContext::has($first));
            $this->assertTrue(DispatchLockContext::has($second));

            return $result;
        });
        $connection->expects('table')->times(2)->with('table')->returns($query = Double::for(Builder::class)->strict());
        $query->expects('insert')->times(2)->returns(true);

        $this->assertTrue($queue->bulk([$first, $second]));
        $this->assertFalse(DispatchLockContext::has($first));
        $this->assertFalse(DispatchLockContext::has($second));
    }

    public function testBulkAcceptsEveryDispatchBeforeRaisingSuccessEvents(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
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
        $cache = Double::for(CacheRepository::class)->strict();
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');
        $exception = new RuntimeException('Listener failed.');
        $notified = [];

        $resolver->expects('connection')->with(null)->returns($connection = Double::for(Connection::class)->strict());
        $connection->expects('transactionLevel')->times(2)->returns(0);
        $connection->expects('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $query->expects('insert')->returns(true);
        $dispatcher->listen(JobQueued::class, static function (JobQueued $event) use (&$notified): void {
            $notified[] = $event->job;
        });
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
        $this->assertSame([$first, $second], $notified);
    }

    public function testBulkRaisesExactSuccessEventsAroundOneInsert(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        $connection = Double::for(Connection::class)->strict();
        $connection->allows('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $resolver->expects('connection')->with(null)->returns($connection);
        $query->expects('insert')->resolves(function (array $records): bool {
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
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
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
        $connection = Double::for(Connection::class)->strict();
        $connection->allows('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $resolver->expects('connection')->with(null)->returns($connection);
        $query->expects('insert')->throws($exception);

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
        $this->assertTrue($events[2]->sendAttempted);
        $this->assertTrue($events[3]->sendAttempted);
    }

    #[TestWith(['success'])]
    #[TestWith(['ordinary'])]
    #[TestWith(['cancellation'])]
    #[TestWith(['wrapped'])]
    public function testBulkInsertFailureListenerErrorReporting(string $reporting): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        Container::setInstance($container = new Container);
        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        $exception = new RuntimeException('Insert failed.');
        $connection = Double::for(Connection::class)->strict();
        $connection->allows('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $resolver->expects('connection')->with(null)->returns($connection);
        $query->expects('insert')->throws($exception);

        $listener = new RuntimeException('Listener failed.');
        $failed = [];
        $dispatcher->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event->job;
        });
        $dispatcher->listen(JobQueueingFailed::class, static fn () => throw $listener);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $report = $handler->expects('report')->with(Argument::same($listener));
        $reportingFailure = match ($reporting) {
            'ordinary' => new RuntimeException('Reporting failed.'),
            'cancellation' => new CanceledException,
            'wrapped' => new RuntimeException('Reporting cleanup failed.', previous: new CanceledException),
            default => null,
        };
        if ($reportingFailure !== null) {
            $report->throws($reportingFailure);
        }
        $container->instance(ExceptionHandler::class, $handler);
        $directory = ParallelTesting::tempDir('QueueDatabaseQueueReportingTest');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->makeDirectory($directory, recursive: true);
        $errorLog = $directory . '/errors.log';
        $previousErrorLog = ini_set('error_log', $errorLog);

        try {
            try {
                $queue->bulk(['first', 'second'], queue: 'emails');
                $this->fail('Expected the bulk insert to fail.');
            } catch (Throwable $actual) {
                $this->assertSame(in_array($reporting, ['cancellation', 'wrapped'], true) ? $reportingFailure : $exception, $actual);
            }

            if ($reporting === 'ordinary') {
                $contents = $files->get($errorLog);
                $this->assertStringContainsString('Listener failed.', $contents);
                $this->assertStringContainsString('Reporting failed.', $contents);
            } else {
                $this->assertFileDoesNotExist($errorLog);
            }
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }
            $files->deleteDirectory($directory);
        }

        $this->assertSame(['first', 'second'], $failed);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testOutboxSelectionCancellationRaisesACanceledEventInsteadOfAFailureEvent(bool $cleanupThrows): void
    {
        $queue = new DatabaseQueue(Double::for(ConnectionResolverInterface::class)->strict(), null, 'jobs', 'default');
        $queue->setConnectionName('database');
        $container = new Container;
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $queue->setContainer($container);
        $gate = $this->armCurrentCoroutineCancellation();
        $cleanup = new RuntimeException('Transaction resolution cleanup failed.');
        $container->bind('db.transactions', static function () use ($gate, $cleanup, $cleanupThrows): never {
            try {
                $gate->push(true);
            } finally {
                if ($cleanupThrows) {
                    throw $cleanup;
                }
            }

            throw new RuntimeException('Cancellation was not delivered.');
        });
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $canceled = [];
        $events->listen(JobQueueingCanceled::class, static function (JobQueueingCanceled $event) use (&$canceled): void {
            $canceled[] = $event;
        });

        try {
            $queue->push((object) ['viaOutbox' => true]);
            $this->fail('Expected cancellation to escape outbox selection.');
        } catch (Throwable $exception) {
            if ($cleanupThrows) {
                $this->assertSame($cleanup, $exception);
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception);
            }

            $this->assertSame([], $failed);
            $this->assertCount(1, $canceled);
            $this->assertSame($exception, $canceled[0]->cancellation);
            $this->assertFalse($canceled[0]->sendAttempted);
        } finally {
            $gate->close();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBulkCancellationRaisesCanceledEventsInsteadOfFailureEvents(bool $cleanupThrows): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $dispatcher = new Dispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        $connection = Double::for(Connection::class)->strict();
        $connection->allows('table')->with('table')->returns($query = Double::for(Builder::class)->strict());
        $resolver->expects('connection')->with(null)->returns($connection);
        $gate = $this->armCurrentCoroutineCancellation();
        $cleanup = new RuntimeException('Insert cleanup failed.');
        $query->expects('insert')->resolves(static function () use ($gate, $cleanup, $cleanupThrows): never {
            try {
                $gate->push(true);
            } finally {
                if ($cleanupThrows) {
                    throw $cleanup;
                }
            }

            throw new RuntimeException('Cancellation was not delivered.');
        });

        $events = [];
        $dispatcher->listen(JobQueueing::class, static function (JobQueueing $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(JobQueueingCanceled::class, static function (JobQueueingCanceled $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $queue->bulk(['first', 'second'], queue: 'emails');
            $this->fail('Expected cancellation to escape the bulk insert.');
        } catch (Throwable $exception) {
            if ($cleanupThrows) {
                $this->assertSame($cleanup, $exception);
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception);
            }
            $this->assertSame([
                JobQueueing::class,
                JobQueueing::class,
                JobQueueingCanceled::class,
                JobQueueingCanceled::class,
            ], array_map(static fn (object $event): string => $event::class, $events));
            $this->assertSame(['first', 'second'], array_column(array_slice($events, 2), 'job'));

            foreach (array_slice($events, 2) as $event) {
                $this->assertSame($exception, $event->cancellation);
                $this->assertTrue($event->sendAttempted);
                $this->assertFalse($event->queued);
                $this->assertNull($event->exception);
            }
        } finally {
            $gate->close();
        }
    }

    public function testBulkSplitsImmediateAndAfterCommitJobsAndReacquiresTheQueue(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1732502704));

        $queue = new TestDatabaseQueue(
            resolver: $immediateResolver = Double::for(ConnectionResolverInterface::class)->strict(),
            connection: null,
            table: 'table',
            default: 'default',
            currentTime: 1732502704,
        );
        $deferredQueue = new TestDatabaseQueue(
            resolver: $deferredResolver = Double::for(ConnectionResolverInterface::class)->strict(),
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

        $immediateConnection = Double::for(Connection::class)->strict();
        $immediateConnection->allows('table')->with('table')->returns($immediateQuery = Double::for(Builder::class)->strict());
        $immediateResolver->expects('connection')->with(null)->returns($immediateConnection);
        $immediateRecords = [];
        $immediateQuery->expects('insert')->resolves(static function (array $records) use (&$immediateRecords): bool {
            $immediateRecords = $records;

            return true;
        });

        $deferredConnection = Double::for(Connection::class)->strict();
        $deferredConnection->allows('table')->with('table')->returns($deferredQuery = Double::for(Builder::class)->strict());
        $deferredResolver->expects('connection')->with(null)->returns($deferredConnection);
        $deferredRecords = [];
        $deferredQuery->expects('insert')->resolves(static function (array $records) use (&$deferredRecords): bool {
            $deferredRecords = $records;

            return true;
        });

        $reacquired = false;
        $queue->setAfterCommitDispatcher(static function (Closure $callback) use ($deferredQueue, &$reacquired): mixed {
            $reacquired = true;

            return $callback($deferredQueue);
        });

        $this->assertTrue($queue->bulk(['immediate', new DatabaseBulkAfterCommitDelayJob], queue: IntegerQueueName::Zero));
        $this->assertFalse($reacquired);
        $this->assertCount(1, $immediateRecords);
        $this->assertSame(1732502704, $immediateRecords[0]['available_at']);
        $this->assertSame('0', $immediateRecords[0]['queue']);
        $this->assertSame([], $deferredRecords);

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1732502804));
        $transactions->commit('default', 1, 0);

        $this->assertTrue($reacquired);
        $this->assertCount(1, $deferredRecords);
        $this->assertSame(1732502813, $deferredRecords[0]['available_at']);
        $this->assertSame('0', $deferredRecords[0]['queue']);
        $this->assertSame(9, json_decode($deferredRecords[0]['payload'], true)['delay']);
        $this->assertSame(1732502704, json_decode($deferredRecords[0]['payload'], true)['createdAt']);
    }

    public function testBulkRollbackDoesNotBeginAnEnqueueAttempt(): void
    {
        $queue = new TestDatabaseQueue(
            resolver: $resolver = Double::for(ConnectionResolverInterface::class)->strict(),
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

        $resolver->expects('connection')->never();
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

    public function testBuildDatabaseRecordWithPayloadAtTheEnd(): void
    {
        $queue = new DatabaseQueue(Double::for(ConnectionResolverInterface::class)->strict(), null, 'table', 'default');
        $class = new ReflectionClass(DatabaseQueue::class);
        $record = $class->getMethod('buildDatabaseRecord')->invoke($queue, 'queue', 'any_payload', 0);
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

    public function testGetLockForPoppingIsCached(): void
    {
        $pdo = Double::for(PDO::class)->strict();
        // Initial resolution reads the version and identifies MariaDB; later calls reuse the lock.
        $pdo->expects('getAttribute')->times(2)->with(PDO::ATTR_SERVER_VERSION)->returns('8.0.36');
        $database = new MySqlConnection($pdo, 'test');
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->times(2)->with(null)->returns($database);
        $queue = new TestDatabaseQueue($resolver, null, 'table', 'default', 1732502704);

        $result1 = $queue->lockForPopping();
        $result2 = $queue->lockForPopping();

        $this->assertSame('FOR UPDATE SKIP LOCKED', $result1);
        $this->assertSame($result1, $result2);
    }

    public function testInvalidInspectedPayloadIdentifiesItsQueueAndRecord(): void
    {
        [$queue, $query] = $this->createInspectionQueue();
        $query->allows('where')->with('queue', 'emails')->returns($query);
        $query->allows('whereNull')->with('reserved_at')->returns($query);
        $query->allows('where')->with('available_at', '<=', 1732502704)->returns($query);
        $query->allows('get')->returns(collect([
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

    /**
     * Create a queue whose selected job cannot be reserved.
     */
    private function createFailingReservationQueue(Throwable $failure): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $resolver->allows('connection')->with(null)->returns($connection);
        $connection->allows('transactionLevel')->returns(0);
        $connection->expects('transaction')->resolves(static fn (Closure $callback): mixed => $callback());

        $container = new Container;
        $events = Double::for(DispatcherContract::class)->strict();
        $container->instance(DispatcherContract::class, $events);
        $queue = Double::for(DatabaseQueue::class)->passthru(new DatabaseQueue($resolver, null, 'jobs'));
        $queue->setContainer($container);
        $queue->setConnectionName('database');
        $record = new DatabaseJobRecord((object) [
            'id' => 1,
            'payload' => json_encode(['job' => stdClass::class, 'data' => []]),
            'attempts' => 255,
        ]);
        $queue->expects('getNextAvailableJob')->with('default')->returns($record);
        $queue->expects('marshalJob')->with('default', Argument::same($record))->throws($failure);

        return [$queue, $events];
    }

    /**
     * Create a queue with an expected inspection query.
     */
    private function createInspectionQueue(): array
    {
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $connection = Double::for(Connection::class)->strict();
        $query = Double::for(Builder::class)->strict();
        $resolver->expects('connection')->with(null)->returns($connection);
        $connection->expects('table')->with('table')->returns($query);

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
    /**
     * Create a queue with fixed timestamps.
     */
    public function __construct(
        ConnectionResolverInterface $resolver,
        ?string $connection,
        string $table,
        string $default,
        private readonly int $currentTime,
    ) {
        parent::__construct($resolver, $connection, $table, $default);
    }

    /**
     * Get the current timestamp.
     */
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
}
