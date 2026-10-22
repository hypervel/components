<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use RuntimeException;

class DatabaseOutboxRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Load only the shipped outbox schema.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php',
        ];
    }

    public function testClaimPreservesPayloadAndOriginalEligibilityWithThreeStatements(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->repository();
        $connection = DB::connection();
        $first = $this->row($now - 10);
        $second = $this->row($now - 30);
        $future = $this->row($now + 60);
        $repository->insert($connection, [$first, $second, $future]);

        $connection->maxBindings();
        $connection->lockForPopping();
        $transactionsStarted = 0;
        $this->app->make('events')->listen(TransactionBeginning::class, static function () use (&$transactionsStarted): void {
            ++$transactionsStarted;
        });
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $messages = $repository->claim($connection->getName(), 2);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertCount(3, $queries);
        $this->assertSame(0, $transactionsStarted);
        $this->assertSame([$second['uuid'], $first['uuid']], array_column($messages, 'uuid'));
        $this->assertSame([$now - 30, $now - 10], array_column($messages, 'eligibleAt'));
        $this->assertSame($second['payload'], $messages[0]->payload);
        $this->assertSame(['transport' => ['custom' => ['group' => 'orders']]], $messages[0]->options);
        $this->assertSame(1, $messages[0]->attempts);
        $this->assertSame($now, $messages[0]->createdAt);
        $this->assertSame($messages[0]->leaseToken, $messages[1]->leaseToken);
        $this->assertSame($now + 300, (int) DB::table('job_outbox')->where('uuid', $first['uuid'])->value('available_at'));
        $this->assertSame([], $repository->claim($connection->getName(), 2));
    }

    public function testExpiredClaimCanBeRecoveredWithoutChangingDeliveryOrPayload(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $repository = $this->repository();
        $row = $this->row(Date::now()->getTimestamp());
        $row['deliver_at'] = Date::now()->getTimestamp() + 900;
        $repository->insert(DB::connection(), [$row]);
        $first = $repository->claim(DB::connection()->getName(), 10)[0];

        Date::setTestNow(Date::now()->addSeconds(300));
        $second = $repository->claim(DB::connection()->getName(), 10)[0];

        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->leaseToken, $second->leaseToken);
        $this->assertSame(2, $second->attempts);
        $this->assertSame($first->deliverAt, $second->deliverAt);
        $this->assertSame($first->payload, $second->payload);
    }

    public function testSettlementDeletesAcceptedDefersFutureAndBacksOffFailures(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->repository();
        $database = DB::connection()->getName();
        $accepted = $this->row($now);
        $deferred = $this->row($now);
        $deferred['deliver_at'] = $now + 2000;
        $deferred['last_error'] = 'Previous failure';
        $failed = $this->row($now);
        $failed['attempts'] = 6;
        $repository->insert(DB::connection(), [$accepted, $deferred, $failed]);
        $messages = $repository->claim($database, 10);

        $affected = $repository->settle($database, $messages, new OutboxBatchResult(
            [$messages[0]->id],
            [$messages[1]->id],
            new RuntimeException(str_repeat('雪', 5000)),
        ));

        $this->assertSame(3, $affected);
        $this->assertNull($repository->find($database, $accepted['uuid']));
        $storedDeferred = $repository->find($database, $deferred['uuid']);
        $this->assertNull($storedDeferred->lease_token);
        $this->assertSame($now + 2000, (int) $storedDeferred->available_at);
        $this->assertSame(0, (int) $storedDeferred->attempts);
        $this->assertSame('Previous failure', $storedDeferred->last_error);
        $storedFailed = $repository->find($database, $failed['uuid']);
        $this->assertNull($storedFailed->lease_token);
        $this->assertSame(7, (int) $storedFailed->attempts);
        $this->assertGreaterThanOrEqual($now + 300, (int) $storedFailed->available_at);
        $this->assertLessThanOrEqual($now + 600, (int) $storedFailed->available_at);
        $this->assertSame(4096, mb_strlen($storedFailed->last_error));
    }

    public function testStaleSettlementCannotDeleteOrReleaseANewerClaim(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $repository = $this->repository();
        $database = DB::connection()->getName();
        $repository->insert(DB::connection(), [$this->row(Date::now()->getTimestamp()), $this->row(Date::now()->getTimestamp())]);
        $old = $repository->claim($database, 10);
        Date::setTestNow(Date::now()->addSeconds(300));
        $current = $repository->claim($database, 10);

        $this->assertSame(0, $repository->settle($database, $old, new OutboxBatchResult(
            [$old[0]->id],
            failure: new RuntimeException('Old attempt failed'),
        )));
        $this->assertSame($current[0]->leaseToken, $repository->find($database, $current[0]->uuid)->lease_token);
        $this->assertSame($current[1]->leaseToken, $repository->find($database, $current[1]->uuid)->lease_token);
        $this->assertSame(2, $repository->settle($database, $current, new OutboxBatchResult(array_column($current, 'id'))));
    }

    public function testOperatorQueriesDistinguishScheduledFailedAndLeasedRows(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $repository = $this->repository();
        $connection = DB::connection();
        $database = $connection->getName();
        $this->assertSame([
            'total' => 0, 'eligible' => 0, 'delayed' => 0, 'leased' => 0,
            'retrying' => 0, 'oldest_eligible_age' => null,
        ], $repository->statistics($database));
        $eligible = $this->row($now - 20);
        $delayed = $this->row($now + 1000);
        $leased = $this->row($now + 300);
        $leased['lease_token'] = (string) Str::uuid();
        $leased['attempts'] = 1;
        $failed = $this->row($now + 600);
        $failed['attempts'] = 7;
        $failed['last_error'] = 'Queue unavailable';
        $repository->insert(DB::connection(), [$eligible, $delayed, $leased, $failed]);

        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $statistics = $repository->statistics($database);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame([
            'total' => 4, 'eligible' => 1, 'delayed' => 1, 'leased' => 1,
            'retrying' => 1, 'oldest_eligible_age' => 20,
        ], $statistics);
        $this->assertCount(2, $repository->pending($database, 2));
        $this->assertSame(0, $repository->retry($database, $delayed['uuid']));
        $this->assertSame(0, $repository->retry($database, $leased['uuid']));
        $this->assertSame(1, $repository->retryAll($database));
        $retried = $repository->find($database, $failed['uuid']);
        $this->assertSame($now, (int) $retried->available_at);
        $this->assertNull($retried->last_error);
        $this->assertSame(7, (int) $retried->attempts);
        $leasedRow = $repository->find($database, $leased['uuid']);
        $this->assertFalse($repository->forget($database, $leasedRow->id, $leasedRow->lease_token));
        $this->assertFalse($repository->forget($database, $retried->id, (string) Str::uuid()));
        $this->assertTrue($repository->forget($database, $retried->id, null));
        $this->assertNull($repository->find($database, $failed['uuid']));
    }

    public function testRepositoryRejectsUnconfiguredSourceBeforeQuerying(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not configured in queue.outbox.databases');

        $this->repository()->claim('not-configured', 100);
    }

    /**
     * Create a repository using the test's configured database.
     */
    protected function repository(): DatabaseOutboxRepository
    {
        return new DatabaseOutboxRepository($this->app->make('db'), [DB::connection()->getName()], 'job_outbox', 300);
    }

    /**
     * Build a row with opaque payload bytes and transport metadata.
     */
    protected function row(int $availableAt): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'orders',
            'payload' => '{ "uuid": "opaque-payload", "data": {"text": "雪"} }',
            'options' => '{"transport":{"custom":{"group":"orders"}}}',
            'deliver_at' => null,
            'attempts' => 0,
            'available_at' => $availableAt,
            'lease_token' => null,
            'last_error' => null,
            'created_at' => Date::now()->getTimestamp(),
        ];
    }
}
