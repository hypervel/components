<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DeadlockException;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Database\QueryException;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;

class DatabaseQueueReservationTest extends TestCase
{
    #[TestWith([null, 'reports'])]
    #[TestWith(['failover', 'processing'])]
    public function testFailoverForwardsOnceBeforeStoringOnTheFallbackConnection(?string $connection, string $delegatedQueue): void
    {
        [$database, $events] = $this->createQueue();
        $routes = new QueueRoutes;
        $routes->forward(['reports' => 'processing', 'processing' => 'archive'], connection: $connection);
        $database->getContainer()->instance('queue.routes', $routes);
        $payload = json_encode(['job' => stdClass::class, 'data' => []]);
        $primary = m::mock(QueueContract::class);
        $primary->shouldReceive('pushRaw')->once()->with($payload, $delegatedQueue)->andThrow(new RuntimeException('Primary unavailable.'));
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with('primary')->andReturn($primary);
        $manager->shouldReceive('connection')->once()->with('database')->andReturn($database);
        $queue = new FailoverQueue($manager, $events, ['primary', 'database']);
        $queue->setContainer($database->getContainer());
        $queue->setConnectionName('failover');

        $id = $queue->pushRaw($payload, 'reports');

        $this->assertSame('processing', $database->getDatabase()->table('jobs')->find($id)->queue);
        $this->assertSame(1, $database->getDatabase()->table('jobs')->count());
    }

    public function testForwardedQueueReservesAndReleasesUsingTheLogicalName(): void
    {
        [$queue] = $this->createQueue();
        $routes = new QueueRoutes;
        $routes->forward(['reports' => 'processing', 'processing' => 'archive']);
        $queue->getContainer()->instance('queue.routes', $routes);
        $id = $queue->pushRaw(json_encode(['job' => stdClass::class, 'data' => []]), 'reports');

        $this->assertSame('processing', $queue->getDatabase()->table('jobs')->find($id)->queue);

        $job = $queue->pop('reports');

        $this->assertSame((string) $id, $job?->getJobId());
        $this->assertSame('reports', $job->getQueue());
        $this->assertSame(1, $job->attempts());

        $job->release();

        $record = $queue->getDatabase()->table('jobs')->sole();
        $this->assertSame('processing', $record->queue);
        $this->assertSame(1, $record->attempts);
        $this->assertNull($record->reserved_at);
        $this->assertSame(2, $queue->pop('reports')?->attempts());
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function testFailedReservationDoesNotBlockTheNextJob(int $transactionLevel): void
    {
        [$queue, $events] = $this->createQueue();
        $database = $queue->getDatabase();
        $payload = json_encode(['job' => stdClass::class, 'data' => []]);
        $failedId = $queue->pushRaw($payload);
        $nextId = $queue->pushRaw($payload);
        $database->table('jobs')->where('id', $failedId)->update(['attempts' => 65535]);
        $failed = null;
        $events->listen(JobFailed::class, static function (JobFailed $event) use (&$failed): void {
            $failed = $event;
        });

        if ($transactionLevel === 1) {
            $database->beginTransaction();
        }

        try {
            try {
                $queue->pop();
                $this->fail('Expected the attempts constraint to reject the reservation.');
            } catch (QueryException $exception) {
                $this->assertSame($exception, $failed?->exception);
            }

            $this->assertSame((string) $failedId, $failed->job->getJobId());
            $this->assertSame('database', $failed->connectionName);
            $this->assertFalse($database->table('jobs')->where('id', $failedId)->exists());
            $this->assertSame((string) $nextId, $queue->pop()?->getJobId());
            $this->assertSame($transactionLevel, $database->transactionLevel());
        } finally {
            if ($transactionLevel === 1) {
                $database->rollBack();
            }
        }
    }

    public function testNestedConcurrencyFailureDoesNotFailTheJob(): void
    {
        $pdo = new PDO('sqlite::memory:');
        [$queue, $events] = $this->createQueue($pdo);
        $database = $queue->getDatabase();
        $queue->pushRaw(json_encode(['job' => stdClass::class, 'data' => []]));
        $previous = new class extends PDOException {
            /**
             * Create a driver exception identified only by its SQLSTATE.
             */
            public function __construct()
            {
                parent::__construct('Could not serialize access due to concurrent update.');

                $this->code = '40001';
            }
        };
        $failure = new QueryException('database', 'update jobs', [], $previous);
        $database->beforeExecuting(static function (string $query) use ($failure): void {
            if (str_starts_with($query, 'update ')) {
                throw $failure;
            }
        });
        $failed = false;
        $events->listen(JobFailed::class, static function () use (&$failed): void {
            $failed = true;
        });
        $database->beginTransaction();

        try {
            try {
                $queue->pop();
                $this->fail('Expected the nested concurrency failure.');
            } catch (DeadlockException $exception) {
                $this->assertSame($failure, $exception->getPrevious());
            }

            $this->assertFalse($failed);
            $this->assertSame(1, $database->transactionLevel());
            $this->assertSame(1, (int) $pdo->query('select count(*) from jobs')->fetchColumn());
        } finally {
            $database->rollBack();
        }
    }

    #[TestWith(['before'])]
    #[TestWith(['executed'])]
    #[TestWith(['duration'])]
    public function testQueryObserverFailureKeepsTheJobAvailable(string $observer): void
    {
        [$queue, $events] = $this->createQueue();
        $database = $queue->getDatabase();
        $id = $queue->pushRaw(json_encode(['job' => stdClass::class, 'data' => []]));
        $failure = new RuntimeException('Query observer failed.');
        $callback = static function (string $query) use ($failure): void {
            if (str_starts_with($query, 'update ')) {
                throw $failure;
            }
        };
        $failed = false;
        $events->listen(JobFailed::class, static function () use (&$failed): void {
            $failed = true;
        });

        if ($observer === 'before') {
            $database->beforeExecuting($callback);
        } elseif ($observer === 'executed') {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($callback): void {
                $callback($event->sql);
            });
        } else {
            // A negative threshold fires even at zero measured duration. Selection runs
            // first, so re-arm the one-shot handler for the reservation update.
            $database->whenQueryingForLongerThan(-1, static function (PdoConnection $connection, QueryExecuted $event) use ($callback): void {
                $callback($event->sql);
            });

            $database->beforeExecuting(static function (string $query) use ($database): void {
                if (str_starts_with($query, 'update ')) {
                    $database->allowQueryDurationHandlersToRunAgain();
                }
            });
        }

        try {
            $queue->pop();
            $this->fail('Expected the query observer failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $record = $database->table('jobs')->find($id);
        $this->assertNotNull($record);
        $this->assertSame(0, $record->attempts);
        $this->assertNull($record->reserved_at);
        $this->assertFalse($failed);
    }

    public function testCommittedListenerFailureKeepsTheReservedJob(): void
    {
        [$queue, $events] = $this->createQueue();
        $database = $queue->getDatabase();
        $id = $queue->pushRaw(json_encode(['job' => stdClass::class, 'data' => []]));
        $failure = new RuntimeException('Committed listener failed.');
        $failed = false;
        $events->listen(JobFailed::class, static function () use (&$failed): void {
            $failed = true;
        });
        $events->listen(TransactionCommitted::class, static function () use ($failure): never {
            throw $failure;
        });

        try {
            $queue->pop();
            $this->fail('Expected the committed listener failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $record = $database->table('jobs')->find($id);
        $this->assertNotNull($record);
        $this->assertNotNull($record->reserved_at);
        $this->assertSame(1, $record->attempts);
        $this->assertFalse($failed);
    }

    public function testFailedRollbackDoesNotFailTheJobInTheOpenTransaction(): void
    {
        $pdo = new ReservationRollbackFailingPdo('sqlite::memory:');
        [$queue, $events] = $this->createQueue($pdo);
        $database = $queue->getDatabase();
        $id = $queue->pushRaw(json_encode(['job' => stdClass::class, 'data' => []]));
        $database->table('jobs')->where('id', $id)->update(['attempts' => 65535]);
        $failed = false;
        $events->listen(JobFailed::class, static function () use (&$failed): void {
            $failed = true;
        });
        $pdo->failRollback = true;

        try {
            try {
                $queue->pop();
                $this->fail('Expected the original reservation failure.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('CHECK constraint failed', $exception->getMessage());
            }

            $this->assertSame(1, $database->transactionLevel());
            $this->assertFalse($failed);
            $this->assertSame(1, (int) $pdo->query('select count(*) from jobs')->fetchColumn());
        } finally {
            $pdo->failRollback = false;
            $database->rollBack();
        }
    }

    /**
     * Create a queue backed by an isolated SQLite database with an enforced attempts limit.
     *
     * @return array{DatabaseQueue, Dispatcher}
     */
    private function createQueue(?PDO $pdo = null): array
    {
        $database = new PdoConnection($pdo ?? new PDO('sqlite::memory:'));
        $database->setQueryGrammar(new SQLiteGrammar($database));

        // SQLite ignores integer widths, so enforce the migration's unsignedSmallInteger ceiling explicitly.
        $database->statement('create table jobs (
            id integer primary key autoincrement,
            queue text not null,
            payload text not null,
            attempts integer not null check (attempts <= 65535),
            reserved_at integer,
            available_at integer not null,
            created_at integer not null
        )');

        $container = new Container;
        $events = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $events);
        $database->setEventDispatcher($events);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($database);
        $queue = new DatabaseQueue($resolver, null, 'jobs');
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        return [$queue, $events];
    }
}

class ReservationRollbackFailingPdo extends PDO
{
    public bool $failRollback = false;

    /**
     * Fail the physical rollback when testing reservation cleanup.
     */
    public function rollBack(): bool
    {
        if ($this->failRollback) {
            throw new RuntimeException('Physical rollback failed.');
        }

        return parent::rollBack();
    }
}
