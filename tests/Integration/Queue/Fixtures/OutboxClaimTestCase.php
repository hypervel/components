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
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

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
        if ($config->string("database.connections.{$database}.driver") !== 'sqlite') {
            $config->set("database.connections.{$database}.lock_timeout", 1);
        }
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
        $skipLocked = $connection->supportsSkipLocked();
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
        $this->assertCount($postgres ? 1 : 3, $queries);
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
        $fallback = ! $connection->supportsSkipLocked();

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

        if (! $connection->supportsSkipLocked()) {
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

    public function testConcurrentClaimsLeaveRowsForEachRelay(): void
    {
        $connection = DB::connection();
        if (! $connection->supportsSkipLocked() || $connection->getDriverName() === 'pgsql') {
            $this->markTestSkipped('This regression needs the InnoDB locking-read claim path.');
        }

        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $database = $connection->getName();
        $repository->insert($connection, array_map(
            fn (int $index): array => $this->row($now - ($index % 2 === 0 ? 20 : 10)),
            range(0, 127),
        ));
        $ready = new Channel(2);
        $release = new Channel(2);

        // Interleave the actual SELECT and UPDATE, after both relays
        // have acquired their own candidate locks.
        $this->app->make('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use ($ready, $release): void {
            if (str_contains($event->sql, 'job_outbox') && str_contains($event->sql, 'for update')) {
                $this->assertTrue($ready->push(true, 5));
                $this->assertTrue($release->pop(5));
            }
        });

        try {
            [$first, $second] = parallel([
                fn (): array => $repository->claim($database, 64),
                fn (): array => $repository->claim($database, 64),
                function () use ($ready, $release): void {
                    $this->assertTrue($ready->pop(5));
                    $this->assertTrue($ready->pop(5));
                    $release->push(true);
                    $release->push(true);
                },
            ]);
            $this->assertNotEmpty($first);
            $this->assertNotEmpty($second);
            $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        } finally {
            $ready->close();
            $release->close();
        }
    }

    public function testConcurrentClaimsRetryWhenMovingDueIndexEntries(): void
    {
        $connection = DB::connection();
        if (! $connection->supportsSkipLocked() || $connection->getDriverName() === 'pgsql') {
            $this->markTestSkipped('This regression needs InnoDB next-key locking.');
        }

        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $database = $connection->getName();
        $repository->insert($connection, array_map(fn (): array => $this->row($now - 10), range(1, 4)));
        $held = new Channel(1);
        $unlock = new Channel(1);
        $startSecond = new Channel(1);
        $selected = new Channel(2);
        $update = new Channel(2);
        $reads = 0;
        $this->app->make('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use ($selected, $update, &$reads): void {
            if (str_starts_with($event->sql, 'select ') && str_contains($event->sql, 'skip locked') && ++$reads <= 2) {
                $this->assertTrue($selected->push(true, 5));
                $this->assertTrue($update->pop(5));
            }
        });

        try {
            [, $first, $second] = parallel([
                function () use ($held, $unlock, $startSecond, $now): void {
                    DB::transaction(function () use ($held, $unlock, $now): void {
                        $index = DB::connection()->getSchemaBuilder()->qualifyIndexName('job_outbox_available_at_id_index');
                        // Hold the secondary entries, so the first relay skips them
                        // before taking any locks on their primary records.
                        DB::table('job_outbox')->forceIndex($index)->where('available_at', $now - 10)
                            ->whereIn('id', [2, 4])->lockForUpdate()->get();
                        $held->push(true);
                        $this->assertTrue($unlock->pop(5));
                    });
                    $startSecond->push(true);
                },
                function () use ($held, $repository, $database): array {
                    $this->assertTrue($held->pop(5));

                    return $repository->claim($database, 100);
                },
                function () use ($startSecond, $repository, $database): array {
                    $this->assertTrue($startSecond->pop(5));

                    return $repository->claim($database, 100);
                },
                function () use ($selected, $unlock, $update): void {
                    $this->assertTrue($selected->pop(5));
                    $unlock->push(true);
                    $this->assertTrue($selected->pop(5));
                    $update->push(true);
                    $update->push(true);
                },
            ]);
            $messages = [...$first, ...$second];
            $ids = array_column($messages, 'id');
            sort($ids);
            $this->assertSame([1, 2, 3, 4], $ids);
            $this->assertSame([1, 1, 1, 1], array_column($messages, 'attempts'));
        } finally {
            $held->close();
            $unlock->close();
            $startSecond->close();
            $selected->close();
            $update->close();
        }
    }

    #[TestWith(['accepted'])]
    #[TestWith(['deferred'])]
    #[TestWith(['failed'])]
    #[TestWith(['retry'])]
    public function testBatchMutationsDoNotWaitForAnotherRelaysRows(string $operation): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $this->markTestSkipped('These mutation regressions require row locks.');
        }

        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->app->make(DatabaseOutboxRepository::class);
        $database = $connection->getName();
        $rows = [];
        for ($index = 0; $index < 128; ++$index) {
            $rows[] = [...$this->row($now - ($index % 2 === 0 ? 20 : 10)), 'deliver_at' => $now + 600];
        }
        $repository->insert($connection, $rows);
        $ready = new Channel(2);
        $release = new Channel(2);

        try {
            $messages = $repository->claim($database, 64);
            $other = $repository->claim($database, 64);
            $this->assertCount(64, $messages);
            $this->assertCount(64, $other);
            $ids = array_column($messages, 'id');
            if ($operation === 'retry') {
                $connection->table('job_outbox')->whereIn('id', $ids)->update(['available_at' => $now - 1]);
            }

            [, $affected] = parallel([
                function () use ($other, $ready, $release): void {
                    DB::transaction(function () use ($other, $ready, $release): void {
                        $query = DB::table('job_outbox')->whereIn('id', array_column($other, 'id'));
                        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                            $query->forceIndex('PRIMARY');
                        }
                        $query->lockForUpdate()->get();
                        $ready->push(true);
                        $this->assertTrue($release->pop(5));
                    });
                },
                function () use ($repository, $database, $messages, $ids, $operation, $ready, $release): int {
                    try {
                        $this->assertTrue($ready->pop(5));

                        return match ($operation) {
                            'accepted' => $repository->settle($database, $messages, new OutboxBatchResult($ids)),
                            'deferred' => $repository->settle($database, $messages, new OutboxBatchResult(deferred: $ids)),
                            'failed' => $repository->settle($database, $messages, new OutboxBatchResult(failure: new RuntimeException('Transport failed.'))),
                            'retry' => $repository->retryAll($database),
                        };
                    } finally {
                        $release->push(true);
                    }
                },
            ]);
            $this->assertSame(64, $affected);
            $this->assertSame(64, DB::table('job_outbox')->where('lease_token', $other[0]->leaseToken)->count());
        } finally {
            $ready->close();
            $release->close();
        }
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
