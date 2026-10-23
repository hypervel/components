<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Redis;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\TestCase;

#[ResetRefreshDatabaseState]
class OutboxDeliveryTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithRedis;

    /**
     * Exercise the normal Redis connector, dispatch APIs and worker commands.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('queue.default', 'redis');
        $config->set('queue.connections.redis.queue', 'outbox');
        $config->set('queue.outbox.databases', [$config->string('database.default')]);
        $config->set('cache.default', 'worker-array');
        $config->set('app.key', str_repeat('a', 32));
    }

    /**
     * Load only tables used by these dispatch and processing paths.
     */
    protected function migrateFreshUsing(): array
    {
        $migrations = dirname(__DIR__, 4) . '/src/testbench/hypervel/migrations/';

        return [
            '--realpath' => true,
            '--path' => [
                $migrations . '0001_01_01_000000_testbench_create_users_table.php',
                $migrations . '0001_01_01_000005_testbench_create_job_batches_table.php',
                $migrations . '0001_01_01_000009_testbench_create_job_outbox_table.php',
            ],
        ];
    }

    public function testCommitRelayAndProcessingPreserveEncryptedModelsAndUniqueOwnership(): void
    {
        $user = UserFactory::new()->create(['name' => 'Before dispatch']);
        $finalizations = 0;
        $queued = 0;
        $this->app->make('events')->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event) use (&$finalizations): void {
            ++$finalizations;
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
            $event->payload = json_encode([...$payload, 'custom' => 'preserved'], JSON_THROW_ON_ERROR);
        });
        $this->app->make('events')->listen(JobQueued::class, static function () use (&$queued): void { ++$queued; });
        DB::transaction(static function () use ($user): void {
            OutboxEncryptedDeliveryJob::dispatch('private-result-key', $user)->viaOutbox();
            OutboxUniqueDeliveryJob::dispatch('unique')->viaOutbox();
            OutboxUniqueDeliveryJob::dispatch('unique')->viaOutbox();
        });
        $rows = DB::table('job_outbox')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(0, Queue::size());
        $this->assertStringNotContainsString('private-result-key', $rows[0]->payload);
        $unique = new OutboxUniqueDeliveryJob('unique');
        $this->assertTrue(Cache::lock(UniqueLock::getKey($unique))->isLocked());
        $user->name = 'After dispatch';
        $user->save();

        $this->assertSame(0, Artisan::call('queue:relay', ['--once' => true, '--memory' => 1024]));
        $this->assertSame(0, DB::table('job_outbox')->count());
        $this->assertSame(2, Queue::size());
        $this->assertSame(2, $finalizations);
        $this->assertSame(2, $queued);
        $payloads = Queue::connection()->pendingJobs()->all();
        $this->assertSame($rows->pluck('uuid')->all(), array_map(static fn (InspectedJob $job): string => $job->uuid, $payloads));
        $this->assertSame(
            $rows->map(static fn (object $row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))->all(),
            array_map(static fn (InspectedJob $job): array => $job->payload, $payloads),
        );
        $this->assertTrue(Cache::lock(UniqueLock::getKey($unique))->isLocked());

        $this->work();
        $this->assertSame('After dispatch', Cache::get('private-result-key'));
        $this->assertSame(1, Cache::get('unique'));
        $this->assertFalse(Cache::lock(UniqueLock::getKey($unique))->isLocked());
    }

    public function testBatchesNestedChainsAndClosuresRunThroughTheExistingWorker(): void
    {
        $batch = Bus::batch([
            [new OutboxDeliveryJob('first'), new OutboxDeliveryJob('second')],
            static function (): void { Cache::put('closure', true); },
        ])->viaOutbox()->dispatch();
        $batch->add([new OutboxDeliveryJob('added')]);

        $this->assertSame(3, DB::table('job_outbox')->count());
        $this->assertSame(4, $batch->fresh()->pendingJobs);
        $this->assertSame(0, Queue::size());
        $this->assertSame(0, Artisan::call('queue:relay', ['--once' => true, '--memory' => 1024]));
        $this->work();

        $this->assertSame(1, Cache::get('first'));
        $this->assertSame(1, Cache::get('second'));
        $this->assertSame(1, Cache::get('added'));
        $this->assertTrue(Cache::get('closure'));
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame(0, DB::table('job_outbox')->count());
        $this->assertSame(0, Queue::size());
    }

    public function testDebouncedAndDelayedPayloadsRetainTheirOriginalDeliveryRules(): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        OutboxDebouncedDeliveryJob::dispatch('debounced')->viaOutbox();
        OutboxDebouncedDeliveryJob::dispatch('debounced')->viaOutbox();
        OutboxDeliveryJob::dispatch('delayed')->viaOutbox()->delay(60);
        $this->assertSame(0, Artisan::call('queue:relay', ['--once' => true, '--memory' => 1024]));
        $this->assertSame(0, DB::table('job_outbox')->count());
        $this->assertNull(Queue::pop());

        Date::setTestNow(Date::now()->addSeconds(61));
        $this->work();

        $this->assertSame(1, Cache::get('debounced'));
        $this->assertSame(1, Cache::get('delayed'));
        $this->assertSame(0, Queue::size());
    }

    /**
     * Process the ready queue using the public worker command.
     */
    protected function work(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024]));
    }
}

class OutboxDeliveryJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Store a result key and optional model reference.
     */
    public function __construct(public string $key, public ?User $user = null)
    {
    }

    /**
     * Record execution in a store shared with the worker's child coroutine.
     */
    public function handle(): void
    {
        Cache::put($this->key, $this->user?->name ?? ((int) Cache::get($this->key, 0) + 1));
    }
}

class OutboxEncryptedDeliveryJob extends OutboxDeliveryJob implements ShouldBeEncrypted
{
}

class OutboxUniqueDeliveryJob extends OutboxDeliveryJob implements ShouldBeUnique
{
}

#[DebounceFor(30)]
class OutboxDebouncedDeliveryJob extends OutboxDeliveryJob
{
}
