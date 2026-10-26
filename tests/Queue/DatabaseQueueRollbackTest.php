<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class DatabaseQueueRollbackTest extends TestCase
{
    #[DataProvider('dispatchMethods')]
    public function testRollbackReleasesAcceptedLocksOnTheStorageOwner(string $method, string $kind): void
    {
        $queue = $this->queue();
        $database = $queue->getDatabase();
        $cache = new Repository(new WorkerArrayStore);
        $lock = $kind === 'unique' ? new UniqueLock($cache) : new DebounceLock($cache);
        $job = (object) ['debounceFor' => 10];
        $lock->acquireForDispatch($job);
        $database->beginTransaction();

        match ($method) {
            'push' => $queue->push($job),
            'later' => $queue->later(30, $job),
            'bulk' => $queue->bulk([$job]),
        };

        $this->assertFalse(DispatchLockContext::has($job));
        $this->assertSame(1, $database->table('jobs')->count());
        $this->assertTrue($kind === 'unique' ? $cache->lock($lock::getKey($job))->isLocked() : $cache->has($lock::getKey($job)));
        $database->rollBack();
        $this->assertSame(0, $database->table('jobs')->count());
        $this->assertFalse($kind === 'unique' ? $cache->lock($lock::getKey($job))->isLocked() : $cache->has($lock::getKey($job)));
    }

    /**
     * Provide the storage entry points for unique and debounce locks.
     */
    public static function dispatchMethods(): array
    {
        return [
            'push unique' => ['push', 'unique'],
            'later unique' => ['later', 'unique'],
            'bulk unique' => ['bulk', 'unique'],
            'push debounce' => ['push', 'debounce'],
            'later debounce' => ['later', 'debounce'],
            'bulk debounce' => ['bulk', 'debounce'],
        ];
    }

    public function testChunkedBulkRegistersCleanupOnTheEnclosingSavepoint(): void
    {
        $queue = $this->queue();
        $database = $queue->getDatabase();
        $cache = new Repository(new WorkerArrayStore);
        $jobs = array_map(static fn (int $id): object => (object) ['uniqueId' => $id], range(1, 3));
        $lock = new UniqueLock($cache);

        foreach ($jobs as $job) {
            $this->assertTrue($lock->acquireForDispatch($job));
        }

        $database->beginTransaction();
        $database->beginTransaction();
        $queue->bulk($jobs);
        $this->assertSame(2, $database->transactionLevel());
        $database->rollBack();

        foreach ($jobs as $job) {
            $this->assertFalse($cache->lock(UniqueLock::getKey($job))->isLocked());
        }

        $this->assertSame(0, $database->table('jobs')->count());
        $database->commit();
    }

    public function testDeadlockRetryCanReacquireTheRolledBackDispatchLock(): void
    {
        $queue = $this->queue();
        $cache = new Repository(new WorkerArrayStore);
        $lock = new UniqueLock($cache);
        $attempts = 0;
        $job = new stdClass;

        $queue->getDatabase()->transaction(function () use ($queue, $lock, $job, &$attempts): void {
            ++$attempts;
            $this->assertTrue($lock->acquireForDispatch($job));
            $queue->push($job);

            if ($attempts === 1) {
                throw new PDOException('deadlock detected', 40001);
            }
        }, 2);

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $queue->getDatabase()->table('jobs')->count());
        $this->assertTrue($cache->lock(UniqueLock::getKey($job))->isLocked());
    }

    public function testDurableInsertDoesNotRegisterCleanupOnAnotherTransaction(): void
    {
        $queue = $this->queue();
        $manager = $queue->getDatabase()->getTransactionManager();
        $other = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => 'other']);
        $other->setTransactionManager($manager);
        $other->beginTransaction();
        $cache = new Repository(new WorkerArrayStore);
        $job = new stdClass;
        $this->assertTrue((new UniqueLock($cache))->acquireForDispatch($job));

        $queue->push($job);
        $other->rollBack();

        $this->assertSame(1, $queue->getDatabase()->table('jobs')->count());
        $this->assertTrue($cache->lock(UniqueLock::getKey($job))->isLocked());
    }

    /**
     * Create a real queue with a small binding limit to exercise chunk savepoints.
     */
    protected function queue(): DatabaseQueue
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance(EventDispatcher::class, new Dispatcher($container));
        $database = new RollbackQueueConnection(new PDO('sqlite::memory:'), config: ['name' => 'testing']);
        $database->setReadWriteType('write');
        $database->setTransactionManager($manager = new DatabaseTransactionsManager);
        $container->instance('db.transactions', $manager);
        $database->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->text('payload');
            $table->unsignedInteger('attempts');
            $table->unsignedBigInteger('reserved_at')->nullable();
            $table->unsignedBigInteger('available_at');
            $table->unsignedBigInteger('created_at');
        });
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('testing::write')->andReturn($database);
        $queue = new DatabaseQueue($resolver, 'testing::write', 'jobs');
        $queue->setContainer($container);
        $queue->setConnectionName('database');

        return $queue;
    }
}

class RollbackQueueConnection extends SQLiteConnection
{
    /**
     * Force a batch of three jobs through the binding-safe chunk path.
     */
    protected function resolveMaxBindings(): int
    {
        return 12;
    }
}
