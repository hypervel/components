<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\QueryException;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxDispatcher;
use Hypervel\Queue\RedisQueue;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class OutboxTransactionTest extends TestCase
{
    #[DataProvider('transactionDepths')]
    public function testFailedLaterInsertChunkLeavesNoPartialGroup(int $depth): void
    {
        [$queue, $database, $events] = $this->queue();
        $firstUuid = null;
        $finalized = 0;
        $events->listen(JobPayloadFinalizing::class, function (JobPayloadFinalizing $event) use (&$firstUuid, &$finalized): void {
            $payload = json_decode($event->payload, true);
            $firstUuid ??= $payload['uuid'];

            if (++$finalized === 3) {
                $payload['uuid'] = $firstUuid;
                $event->payload = json_encode($payload);
            }
        });

        for ($level = 0; $level < $depth; ++$level) {
            $database->beginTransaction();
        }

        try {
            $queue->bulk([
                (new OutboxTransactionJob)->viaOutbox(),
                (new OutboxTransactionJob)->viaOutbox(),
                (new OutboxTransactionJob)->viaOutbox(),
            ]);
            $this->fail('The duplicate UUID must reject the complete owner group.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
            $this->assertSame($depth, $database->transactionLevel());
            $this->assertSame(0, $database->table('job_outbox')->count());
        }

        while ($database->transactionLevel() > 0) {
            $database->commit();
        }

        $this->assertSame(0, $database->table('job_outbox')->count());
    }

    /**
     * Exercise both a standalone insert transaction and nested application savepoints.
     */
    public static function transactionDepths(): array
    {
        return [[0], [1], [2]];
    }

    public function testNestedRollbackReleasesAcceptedLocksButPreservesTheOuterGroup(): void
    {
        [$queue, $database] = $this->queue();
        $cache = new Repository(new WorkerArrayStore);
        $lock = new UniqueLock($cache);
        $outer = (new OutboxTransactionJob('outer'))->viaOutbox();
        $inner = (new OutboxTransactionJob('inner'))->viaOutbox();

        $database->beginTransaction();
        $this->assertTrue($lock->acquireForDispatch($outer));
        $queue->push($outer);
        $database->beginTransaction();
        $this->assertTrue($lock->acquireForDispatch($inner));
        $queue->bulk([$inner]);

        $this->assertFalse(DispatchLockContext::has($outer));
        $this->assertFalse(DispatchLockContext::has($inner));
        $database->rollBack();
        $this->assertSame(1, $database->table('job_outbox')->count());
        $this->assertFalse($cache->lock(UniqueLock::getKey($inner))->isLocked());
        $this->assertTrue($cache->lock(UniqueLock::getKey($outer))->isLocked());

        $database->commit();
        $this->assertSame(1, $database->table('job_outbox')->count());
        $this->assertTrue($cache->lock(UniqueLock::getKey($outer))->isLocked());
    }

    public function testOuterRollbackReleasesEveryAcceptedChunkSnapshot(): void
    {
        [$queue, $database] = $this->queue();
        $cache = new Repository(new WorkerArrayStore);
        $lock = new UniqueLock($cache);
        $jobs = array_map(static fn (int $id): OutboxTransactionJob => (new OutboxTransactionJob((string) $id))->viaOutbox(), range(1, 3));

        $database->beginTransaction();

        foreach ($jobs as $job) {
            $this->assertTrue($lock->acquireForDispatch($job));
        }

        $queue->bulk($jobs);
        $this->assertSame(3, $database->table('job_outbox')->count());
        $database->rollBack();
        $this->assertSame(0, $database->table('job_outbox')->count());

        foreach ($jobs as $job) {
            $this->assertFalse($cache->lock(UniqueLock::getKey($job))->isLocked());
        }
    }

    /**
     * Create an independent SQLite owner using the shipped outbox schema.
     *
     * @return array{RedisQueue, OutboxTransactionConnection, Dispatcher}
     */
    protected function queue(): array
    {
        $container = new Container;
        Container::setInstance($container);
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $database = new OutboxTransactionConnection(new PDO('sqlite::memory:'), config: ['name' => 'business']);
        $database->setTransactionManager($transactions = new DatabaseTransactionsManager);
        $container->instance('db.schema', $database->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        (require dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php')->up();

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('business')->andReturn($database);
        $repository = new DatabaseOutboxRepository($resolver, ['business'], 'job_outbox', 300);
        $container->instance(OutboxDispatcher::class, new OutboxDispatcher($repository, $resolver, $transactions));
        $queue = new RedisQueue(m::mock(Factory::class), 'orders');
        $queue->setContainer($container)->setConnectionName('redis');

        return [$queue, $database, $events];
    }
}

class OutboxTransactionJob
{
    use Queueable;

    /**
     * Identify a distinct dispatch-owned lock.
     */
    public function __construct(public string $uniqueId = '')
    {
    }
}

class OutboxTransactionConnection extends SQLiteConnection
{
    /**
     * Force three outbox rows through two binding-safe insert chunks.
     */
    protected function resolveMaxBindings(): int
    {
        return 26;
    }
}
