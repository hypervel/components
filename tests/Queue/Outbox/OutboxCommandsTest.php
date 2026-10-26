<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Events\OutboxBusy;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class OutboxCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Configure an explicitly named in-process lock store.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('cache.default', 'worker-array');
        $config->set('queue.outbox.databases', [$config->string('database.default')]);
    }

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

    public function testListingIsBoundedAndOmitsPayloadOptionsAndOwnershipTokens(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $error = str_repeat('Broker unavailable. ', 12) . "\nSecond error line.";
        $first = $this->insert(['last_error' => $error]);
        $this->insert();

        $this->assertSame(0, Artisan::call('queue:outbox', ['--json' => true, '--limit' => 1]));
        $output = Artisan::output();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $rows);
        $this->assertSame($first, $rows[0]['uuid']);
        $this->assertSame('SendInvoice', $rows[0]['job']);
        $this->assertSame('eligible', $rows[0]['state']);
        $this->assertSame(Date::now()->getTimestamp(), $rows[0]['available_at']);
        $this->assertSame($error, $rows[0]['last_error']);
        $this->assertArrayNotHasKey('payload', $rows[0]);
        $this->assertArrayNotHasKey('options', $rows[0]);
        $this->assertArrayNotHasKey('lease_token', $rows[0]);
        $this->assertStringNotContainsString('private-content', $output);

        Artisan::call('queue:outbox', ['--limit' => 1]);
        $output = Artisan::output();
        $this->assertStringContainsString('2040-01-01 12:00:00', $output);
        $this->assertStringContainsString(Str::limit(Str::before($error, "\n"), 80), $output);
        $this->assertStringNotContainsString('Second error line.', $output);
        $this->assertStringNotContainsString($error, $output);
    }

    public function testListingRejectsAnUnboundedLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Artisan::call('queue:outbox', ['--limit' => 1001]);
    }

    public function testMonitorReportsDistinctStatesAndAlertsOnlyForEligibleAge(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $this->insert(['available_at' => $now + 60]);
        $this->insert(['available_at' => $now + 60, 'attempts' => 1, 'lease_token' => (string) Str::uuid()]);
        $this->insert(['available_at' => $now + 60, 'attempts' => 1]);
        $seen = [];
        $this->app->make('events')->listen(OutboxBusy::class, static function (OutboxBusy $event) use (&$seen): void { $seen[] = $event; });

        Artisan::call('queue:outbox-monitor', ['--json' => true, '--age' => 10]);
        $statistics = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame(3, $statistics['total']);
        $this->assertSame(0, $statistics['eligible']);
        $this->assertSame(1, $statistics['delayed']);
        $this->assertSame(1, $statistics['leased']);
        $this->assertSame(1, $statistics['retrying']);
        $this->assertSame('OK', $statistics['status']);
        $this->assertSame([], $seen);

        $this->insert(['available_at' => $now - 20]);
        $this->insert(['available_at' => $now - 1, 'attempts' => 2]);
        Artisan::call('queue:outbox-monitor', ['--json' => true, '--age' => 10]);
        $statistics = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame(2, $statistics['eligible']);
        $this->assertSame(1, $statistics['retrying']);
        $this->assertSame($statistics['total'], $statistics['eligible'] + $statistics['delayed'] + $statistics['leased'] + $statistics['retrying']);
        $this->assertCount(1, $seen);
        $this->assertSame(5, $seen[0]->size);
        $this->assertSame(20, $seen[0]->oldestEligibleAge);
    }

    public function testRetryAllPreservesAttemptHistoryAndExcludesLiveAndNeverAttemptedRows(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $now = Date::now()->getTimestamp();
        $retry = $this->insert(['attempts' => 5, 'available_at' => $now + 500, 'last_error' => 'Broker offline']);
        $live = $this->insert(['attempts' => 1, 'available_at' => $now + 500, 'lease_token' => (string) Str::uuid()]);
        $new = $this->insert(['available_at' => $now + 500]);

        $this->assertSame(0, Artisan::call('queue:outbox-retry', ['uuid' => ['all']]));
        $row = DB::table('job_outbox')->where('uuid', $retry)->first();
        $this->assertSame($now, (int) $row->available_at);
        $this->assertSame(5, (int) $row->attempts);
        $this->assertNull($row->last_error);
        $this->assertSame($now + 500, (int) DB::table('job_outbox')->where('uuid', $live)->value('available_at'));
        $this->assertSame($now + 500, (int) DB::table('job_outbox')->where('uuid', $new)->value('available_at'));
        $this->assertSame(1, Artisan::call('queue:outbox-retry', ['uuid' => [$live, $new, 'missing']]));
        $this->assertSame(0, Artisan::call('queue:outbox-retry', ['uuid' => [$retry], '--database' => DB::connection()->getName()]));
    }

    public function testRetryRefusesCrossSourceUuidAmbiguityBeforeMutation(): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $repository->expects('getDatabases')->andReturn(['first', 'second']);
        $repository->expects('find')->with('first', 'same')->andReturn((object) ['id' => 1]);
        $repository->expects('find')->with('second', 'same')->andReturn((object) ['id' => 2]);
        $this->app->instance(DatabaseOutboxRepository::class, $repository);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Select one using --database');

        Artisan::call('queue:outbox-retry', ['uuid' => ['same']]);
    }

    public function testForgetRequiresConfirmationAndRefusesLiveLeases(): void
    {
        $uuid = $this->insert(['attempts' => 1, 'lease_token' => (string) Str::uuid(), 'available_at' => Date::now()->getTimestamp() + 60]);
        $prompt = 'Are you sure you want to run this command?';
        $this->artisan('queue:outbox-forget', ['uuid' => $uuid])->expectsConfirmation($prompt, 'no')->expectsOutputToContain('Command cancelled.')->assertExitCode(1);
        $this->assertDatabaseHas('job_outbox', ['uuid' => $uuid]);
        $this->artisan('queue:outbox-forget', ['uuid' => $uuid])->expectsConfirmation($prompt, 'yes')->assertExitCode(1);
        $this->assertDatabaseHas('job_outbox', ['uuid' => $uuid]);

        $this->assertSame(1, Artisan::call('queue:outbox-forget', ['uuid' => $uuid, '--no-interaction' => true]));
        $this->assertStringContainsString('Command cancelled.', Artisan::output());
        $this->assertSame(1, Artisan::call('queue:outbox-forget', ['uuid' => $uuid, '--no-interaction' => true, '--force' => true]));
        $this->assertDatabaseHas('job_outbox', ['uuid' => $uuid]);

        DB::table('job_outbox')->where('uuid', $uuid)->update(['available_at' => Date::now()->getTimestamp() - 1]);
        $this->assertSame(0, Artisan::call('queue:outbox-forget', ['uuid' => $uuid, '--no-interaction' => true, '--force' => true]));
        $this->assertDatabaseMissing('job_outbox', ['uuid' => $uuid]);
    }

    #[DataProvider('lockReplacement')]
    public function testForgetReleasesOnlyItsExactUniqueOwnerWithoutHydratingOtherData(bool $replaced): void
    {
        $cache = Cache::store('worker-array');
        $lock = $cache->lock('unique-key');
        $this->assertTrue($lock->get());
        $uuid = $this->insert(['payload' => json_encode([
            'data' => ['command' => 'Do not deserialize this job.'],
            'illuminate:log:context' => ['hidden' => [
                'laravel_unique_job_cache_store' => serialize('worker-array'),
                'laravel_unique_job_key' => serialize('unique-key'),
                'laravel_unique_job_lock_owner' => serialize($lock->owner()),
                'unrelated' => 'Do not hydrate this context.',
            ]],
        ], JSON_THROW_ON_ERROR)]);

        if ($replaced) {
            $lock->release();
            $replacement = $cache->lock('unique-key');
            $this->assertTrue($replacement->get());
        }

        $this->artisan('queue:outbox-forget', ['uuid' => $uuid])
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->assertExitCode(0);
        $this->assertDatabaseMissing('job_outbox', ['uuid' => $uuid]);
        $this->assertSame(! $replaced, $cache->lock('unique-key')->get());
    }

    /**
     * Cover current ownership and a later dispatch's replacement lock.
     */
    public static function lockReplacement(): array
    {
        return [[false], [true]];
    }

    public function testForgetReportsUnresolvableOwnershipAfterDeletingWithoutGuessingTheDefaultStore(): void
    {
        $cache = Cache::store('worker-array');
        $lock = $cache->lock('unique-key');
        $this->assertTrue($lock->get());
        $uuid = $this->insert(['payload' => json_encode([
            'illuminate:log:context' => ['hidden' => [
                'laravel_unique_job_cache_store' => serialize(null),
                'laravel_unique_job_key' => serialize('unique-key'),
                'laravel_unique_job_lock_owner' => serialize($lock->owner()),
            ]],
        ], JSON_THROW_ON_ERROR)]);
        $exceptions = m::mock(ExceptionHandler::class);
        $exceptions->expects('report')->with(m::type(LogicException::class), m::on(fn (array $context): bool => $context['job_uuid'] === $uuid));
        $this->app->instance(ExceptionHandler::class, $exceptions);

        $this->artisan('queue:outbox-forget', ['uuid' => $uuid])
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->expectsOutputToContain('was deleted, but unique-lock cleanup failed')
            ->assertExitCode(1);
        $this->assertDatabaseMissing('job_outbox', ['uuid' => $uuid]);
        $this->assertFalse($cache->lock('unique-key')->get());
    }

    /**
     * Insert an operational row using the shipped repository and schema.
     */
    protected function insert(array $attributes = []): string
    {
        $uuid = (string) Str::uuid();
        $now = Date::now()->getTimestamp();
        $this->app->make(DatabaseOutboxRepository::class)->insert(DB::connection(), [array_replace([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'invoices',
            'payload' => '{"displayName":"SendInvoice","data":{"command":"private-content"}}',
            'options' => '{"private-content":true}',
            'deliver_at' => null,
            'attempts' => 0,
            'available_at' => $now,
            'lease_token' => null,
            'last_error' => null,
            'created_at' => $now,
        ], $attributes)]);

        return $uuid;
    }
}
