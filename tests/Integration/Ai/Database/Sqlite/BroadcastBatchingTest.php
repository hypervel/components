<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\Sqlite;

use Generator;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Broadcasting\AnonymousEvent;
use Hypervel\Broadcasting\Channel as BroadcastChannel;
use Hypervel\Contracts\Events\ShouldHandleEventsAfterCommit;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[RequiresDatabase('sqlite')]
class BroadcastBatchingTest extends TestCase
{
    protected ?string $databaseFile = null;

    /**
     * Close the owned database before removing its file.
     */
    protected function tearDown(): void
    {
        try {
            if ($this->databaseFile !== null) {
                DB::purge('broadcasts');
                unlink($this->databaseFile);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testQueuedSenderReleasesItsOnlyDatabaseSlotBeforeParentPersistence(): void
    {
        $this->prepareDatabaseQueue();
        config(['ai.broadcasting.batching.milliseconds' => 5, 'queue.connections.broadcasts.connection' => null]);
        Context::add('request_id', 'queued-request');
        $queued = new Channel(1);
        Event::listen(JobQueued::class, static fn () => $queued->push(true));
        $response = new StreamableAgentResponse('invocation', function () use ($queued): Generator {
            yield new TextDelta('text', 'message', 'Hello', 1);
            $this->assertTrue($queued->pop(5));
        });
        $response->then(static fn () => DB::connection('broadcasts')->table('turns')->insert(['content' => 'Hello']));

        try {
            DB::usingConnection('broadcasts', static fn () => $response->broadcast(new BroadcastChannel('conversation'), now: false));
            $this->assertSame('Hello', DB::connection('broadcasts')->table('turns')->value('content'));
            $payload = json_decode(DB::connection('broadcasts')->table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(serialize('queued-request'), $payload['illuminate:log:context']['data']['request_id']);
        } finally {
            $queued->close();
        }
    }

    #[DataProvider('transactionModes')]
    public function testTransactionsKeepQueueAndListenerCommitBehavior(bool $now, bool $commit): void
    {
        $this->prepareDatabaseQueue();
        config(['broadcasting.default' => 'null']);
        $listener = new BroadcastAfterCommitListener;
        $this->app->instance(BroadcastAfterCommitListener::class, $listener);
        Event::listen(AnonymousEvent::class, BroadcastAfterCommitListener::class);
        $connection = DB::connection('broadcasts');
        $connection->beginTransaction();

        try {
            (new StreamableAgentResponse('invocation', static fn (): array => [
                new TextDelta('1', 'message', 'Hello', 1),
                new TextDelta('2', 'message', ' world', 2),
            ]))->broadcast(new BroadcastChannel('conversation'), now: $now);

            $this->assertSame(0, $connection->table('jobs')->count());
            $this->assertSame([], $listener->deltas);
            $commit ? $connection->commit() : $connection->rollBack();
            $this->assertSame($commit ? ['Hello', ' world'] : [], $listener->deltas);
            $this->assertSame($commit && ! $now ? 2 : 0, $connection->table('jobs')->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Cover queued writes and immediate broadcasts with after-commit listeners.
     */
    public static function transactionModes(): array
    {
        return ['queued commit' => [false, true], 'queued rollback' => [false, false], 'immediate rollback' => [true, false]];
    }

    /**
     * Create a real single-slot queue connection without a test transaction wrapper.
     */
    protected function prepareDatabaseQueue(): void
    {
        $this->databaseFile = tempnam(database_path(), 'broadcast-batching-');
        config([
            'database.connections.broadcasts' => [
                'driver' => 'sqlite',
                'database' => $this->databaseFile,
                // Durability is not under test; avoid disk flush stalls during parallel runs.
                'synchronous' => 'off',
                'pool' => ['testing_enabled' => true, 'max_connections' => 1, 'wait_timeout' => 0.2],
            ],
            'queue.default' => 'broadcasts',
            'queue.connections.broadcasts' => ['driver' => 'database', 'connection' => 'broadcasts', 'table' => 'jobs', 'queue' => 'default', 'after_commit' => true],
        ]);
        Schema::connection('broadcasts')->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->text('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('broadcasts')->create('turns', static function (Blueprint $table): void {
            $table->text('content');
        });
        ConnectionResolver::releaseIdleConnections();
    }
}

class BroadcastAfterCommitListener implements ShouldHandleEventsAfterCommit
{
    public array $deltas = [];

    /**
     * Record broadcasts only when their enclosing transaction commits.
     */
    public function handle(AnonymousEvent $event): void
    {
        $this->deltas[] = $event->broadcastWith()['delta'];
    }
}
