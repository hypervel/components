<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\QueryException;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class DatabaseOutboxPublicationTest extends TestCase
{
    public function testRawPublicationUsesOneInsertAcrossQueuesWithAbsoluteAvailability(): void
    {
        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        [$queue, $database, $routes] = $this->queue();
        $routes->forward(['orders' => 'processing', 'processing' => 'archive']);
        $now = CarbonImmutable::now()->getTimestamp();
        $messages = [$this->message(1, 'orders', $now + 30), $this->message(2, 'reports', $now - 30)];
        $database->enableQueryLog();

        $result = $queue->publishOutboxMessages($messages);
        $inserts = array_filter($database->getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'insert into'));
        $database->disableQueryLog();

        $this->assertSame([1, 2], $result->accepted);
        $this->assertNull($result->failure);
        $this->assertCount(1, $inserts);
        $rows = $database->table('jobs')->orderBy('id')->get()->all();
        $this->assertSame(['processing', 'reports'], array_column($rows, 'queue'));
        $this->assertSame([$now + 30, $now], array_column($rows, 'available_at'));
        $this->assertSame(array_column($messages, 'payload'), array_column($rows, 'payload'));
        $this->assertSame(0, $database->transactionLevel());
    }

    public function testARejectedLaterChunkRollsBackEveryInsertedMessage(): void
    {
        [$queue, $database] = $this->queue();
        $database->unprepared("CREATE TRIGGER reject_job BEFORE INSERT ON jobs WHEN NEW.queue = 'rejected' BEGIN SELECT RAISE(ABORT, 'Queue rejected'); END");

        $result = $queue->publishOutboxMessages([
            $this->message(1, 'orders'), $this->message(2, 'orders'), $this->message(3, 'rejected'),
        ]);

        $this->assertSame([], $result->accepted);
        $this->assertInstanceOf(QueryException::class, $result->failure);
        $this->assertSame(0, $database->table('jobs')->count());
        $this->assertSame(0, $database->transactionLevel());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCancellationIsNotReturnedAsAPublicationFailure(bool $cleanupThrows): void
    {
        [$queue, $database] = $this->queue();
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Insert cleanup failed.', previous: $cancellation);
        }
        $database->beforeExecuting(static fn () => throw $cancellation);

        try {
            $queue->publishOutboxMessages([$this->message(1, 'orders')]);
            $this->fail('Expected publication cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        } finally {
            $database->clearBeforeExecutingCallbacks();
        }

        $this->assertSame(0, $database->table('jobs')->count());
        $this->assertSame(0, $database->transactionLevel());
    }

    /**
     * Create a real target jobs table with a two-row binding limit.
     */
    protected function queue(): array
    {
        $container = new Container;
        Container::setInstance($container);
        $database = new OutboxPublicationConnection(new PDO('sqlite::memory:'), config: ['name' => 'target']);
        $container->instance('db.schema', $database->getSchemaBuilder());
        $container->instance('queue.routes', $routes = new QueueRoutes);
        Facade::setFacadeApplication($container);
        (require dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000006_testbench_create_jobs_table.php')->up();
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('target')->andReturn($database);
        $queue = new DatabaseQueue($resolver, 'target', 'jobs');
        $queue->setContainer($container)->setConnectionName('database');

        return [$queue, $database, $routes];
    }

    /**
     * Build a finalized payload without recreating its serialized command.
     */
    protected function message(int $id, string $queue, ?int $deliverAt = null): OutboxMessage
    {
        $uuid = (string) Str::uuid();

        return new OutboxMessage($id, $uuid, (string) Str::uuid(), 1, 'database', $queue, '{ "uuid": "' . $uuid . '" }', [], $deliverAt, 1, 1);
    }
}

class OutboxPublicationConnection extends SQLiteConnection
{
    /**
     * Exercise binding-safe chunking with a small batch.
     */
    protected function resolveMaxBindings(): int
    {
        return 12;
    }
}
