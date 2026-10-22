<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Fixtures;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\RedisQueue;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

#[ResetRefreshDatabaseState]
abstract class OutboxClaimTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * Enable independent database sessions for competing relay coroutines.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $database = $config->string('database.default');
        $config->set('queue.outbox.databases', [$database]);
        $config->set("database.connections.{$database}.pool.testing_enabled", true);
        $config->set("database.connections.{$database}.pool.max_connections", 4);
        $config->set("database.connections.{$database}.pool.heartbeat_interval", null);
    }

    /**
     * Migrate only the shipped operational outbox table.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => dirname(__DIR__, 4) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php',
        ];
    }

    public function testClaimBoundsAndStatementCountOnTheLiveEngine(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $connection = DB::connection();
        $database = $connection->getName();
        $rows = [$this->row($now - 10), $this->row($now - 30), $this->row($now - 20), $this->row($now + 60)];
        $repository->insert($connection, $rows);
        $skipLocked = $connection->lockForPopping() === 'FOR UPDATE SKIP LOCKED';
        $transactions = 0;
        $this->app->make('events')->listen(TransactionBeginning::class, static function () use (&$transactions): void { ++$transactions; });
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            $messages = $repository->claim($database, 2);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $postgres = $connection->getDriverName() === 'pgsql';
        $this->assertCount($postgres ? 1 : ($skipLocked ? 2 : 3), $queries);
        $this->assertSame($skipLocked && ! $postgres ? 1 : 0, $transactions);
        $this->assertSame([$rows[1]['uuid'], $rows[2]['uuid']], array_column($messages, 'uuid'));
        $this->assertSame([$now - 30, $now - 20], array_column($messages, 'eligibleAt'));
        $this->assertSame($rows[1]['payload'], $messages[0]->payload);
        $this->assertSame(['transport' => ['custom' => ['group' => 'orders']]], $messages[0]->options);
        $this->assertSame(2, DB::table('job_outbox')->whereNotNull('lease_token')->count());
        $this->assertSame([$rows[0]['uuid']], array_column($repository->claim($database, 10), 'uuid'));
        $this->assertSame(2, $repository->settle($database, $messages, new OutboxBatchResult(array_column($messages, 'id'))));
    }

    public function testStagingUsesTheExactWriteRoleTransactionAndDrainsThroughItsBaseSource(): void
    {
        $base = DB::connection();
        $database = $base->getName();
        $writer = DB::connection($database . '::write');
        $queue = new RedisQueue(m::mock(Factory::class), 'orders');
        $queue->setContainer($this->app)->setConnectionName('redis');
        $base->beginTransaction();
        $writer->beginTransaction();

        try {
            $queue->push((new OutboxClaimJob)->onOutboxDatabase($database . '::write'));
            $this->assertSame(1, $writer->table('job_outbox')->count());
            $this->assertSame(0, $base->table('job_outbox')->count());
        } finally {
            $writer->rollBack();
            $base->rollBack();
        }

        $this->assertSame(0, $base->table('job_outbox')->count());
        $uuid = $writer->transaction(fn (): mixed => $queue->push((new OutboxClaimJob)->viaOutbox()));
        $messages = $this->app->make(DatabaseOutboxRepository::class)->claim($database, 1);

        $this->assertSame([$uuid], array_column($messages, 'uuid'));
    }

    public function testCompetingClaimsAndExpiredOwnershipOnIndependentSessions(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $connection = DB::connection();
        $database = $connection->getName();
        $rows = array_map(fn (): array => $this->row($now), range(1, 4));
        $repository->insert($connection, $rows);
        $fallback = $connection->lockForPopping() !== 'FOR UPDATE SKIP LOCKED';

        // Force both fallback claimants to see the same candidates before either updates them.
        if ($fallback) {
            $selected = new Channel(1);
            $selections = 0;
            $this->app->make('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use ($selected, &$selections): void {
                if (str_starts_with($event->sql, 'select ')
                    && str_contains($event->sql, 'available_at')
                    && ! str_contains($event->sql, 'lease_token')) {
                    if (++$selections === 1) {
                        $this->assertTrue($selected->pop(5));
                    } else {
                        $selected->push(true);
                    }
                }
            });
        }

        try {
            [$first, $second] = parallel([
                fn (): array => $repository->claim($database, 2),
                fn (): array => $repository->claim($database, 2),
            ]);
        } finally {
            if ($fallback) {
                $this->app->make('events')->forget(QueryExecuted::class);
                $selected->close();
            }
        }

        $firstIds = array_column($first, 'id');
        $secondIds = array_column($second, 'id');
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertCount($fallback ? 2 : 4, array_merge($firstIds, $secondIds));
        $old = array_merge($first, $second, $repository->claim($database, 10));
        $this->assertCount(4, $old);
        $this->assertSame([], $repository->claim($database, 10));

        Date::setTestNow(Date::now()->addSeconds(300));
        $current = $repository->claim($database, 10);
        $this->assertCount(4, $current);
        $this->assertSame([2, 2, 2, 2], array_column($current, 'attempts'));
        $this->assertNotSame($old[0]->leaseToken, $current[0]->leaseToken);

        foreach ([$first, $second] as $stale) {
            if ($stale !== []) {
                $this->assertSame(0, $repository->settle($database, $stale, new OutboxBatchResult(array_column($stale, 'id'))));
            }
        }

        $this->assertSame(4, $repository->settle($database, $current, new OutboxBatchResult(array_column($current, 'id'))));
        $this->assertSame(0, DB::table('job_outbox')->count());
    }

    public function testSkipLockedClaimsAdvancePastAnUncommittedOwner(): void
    {
        $connection = DB::connection();

        if ($connection->lockForPopping() !== 'FOR UPDATE SKIP LOCKED') {
            $this->markTestSkipped('This engine uses the conditional-update claim path.');
        }

        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $database = $connection->getName();
        $now = Date::now()->getTimestamp();
        $locked = $this->row($now - 20);
        $available = $this->row($now - 10);
        $repository->insert($connection, [$locked, $available]);
        $ready = new Channel(1);
        $release = new Channel(1);

        try {
            [, $messages] = parallel([
                function () use ($locked, $ready, $release): void {
                    DB::transaction(function () use ($locked, $ready, $release): void {
                        DB::table('job_outbox')->where('uuid', $locked['uuid'])->lockForUpdate()->first();
                        $ready->push(true);
                        $this->assertTrue($release->pop(5));
                    });
                },
                function () use ($repository, $database, $ready, $release): array {
                    try {
                        $this->assertTrue($ready->pop(5));

                        return $repository->claim($database, 1);
                    } finally {
                        $release->push(true);
                    }
                },
            ]);
        } finally {
            $ready->close();
            $release->close();
        }

        $this->assertSame([$available['uuid']], array_column($messages, 'uuid'));
        $this->assertSame([$locked['uuid']], array_column($repository->claim($database, 1), 'uuid'));
    }

    /**
     * Create opaque stored bytes and exact epoch deadlines for claim tests.
     */
    protected function row(int $availableAt): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'orders',
            'payload' => '{ "displayName": "SendInvoice", "data": {"text": "雪"} }',
            'options' => '{"transport":{"custom":{"group":"orders"}}}',
            'deliver_at' => null, 'attempts' => 0, 'available_at' => $availableAt,
            'lease_token' => null, 'last_error' => null, 'created_at' => Date::now()->getTimestamp(),
        ];
    }
}

class OutboxClaimJob
{
    use Queueable;
}
