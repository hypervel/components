<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Exception;
use Hypervel\Bus\Batchable;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\BatchWatcher;
use Hypervel\Telescope\Watchers\JobWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Telescope\FeatureTestCase;

#[WithMigration('queue')]
#[WithConfig('logging.default', 'null')]
#[WithConfig('queue.failed.database', 'testing')]
#[WithConfig('telescope.watchers', [
    JobWatcher::class => true,
    BatchWatcher::class => true,
])]
class BatchWatcherTest extends FeatureTestCase
{
    public function testJobDispatchRegistersEntries(): void
    {
        $batch = $this->app->make(QueueingDispatcher::class)->batch([
            new BananaJob('First Banana'),
            new FailedBananaJob('Second Banana'),
        ])->onQueue('on-demand')->onConnection('database')->dispatch();

        // The worker runs each job in its own coroutine, so store the dispatch-time
        // entries first, as the dispatching request would, for its updates to apply.
        $this->terminateTelescope();

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--max-jobs' => 2,
            '--queue' => 'on-demand',
        ])->run();

        $entries = $this->loadTelescopeEntries()->all();

        $this->assertSame(3, count($entries));

        $this->assertSame(EntryType::JOB, $entries[0]->type);
        $this->assertSame('processed', $entries[0]->content['status']);
        $this->assertSame('database', $entries[0]->content['connection']);
        $this->assertSame($batch->id, $entries[0]->family_hash);
        $this->assertSame(BananaJob::class, $entries[0]->content['name']);
        $this->assertSame('on-demand', $entries[0]->content['queue']);
        $this->assertSame('First Banana', $entries[0]->content['data']['payload']);

        $this->assertSame(EntryType::JOB, $entries[1]->type);
        $this->assertSame('failed', $entries[1]->content['status']);
        $this->assertSame('database', $entries[1]->content['connection']);
        $this->assertSame($batch->id, $entries[1]->family_hash);
        $this->assertSame(FailedBananaJob::class, $entries[1]->content['name']);
        $this->assertSame('on-demand', $entries[1]->content['queue']);
        $this->assertSame('Second Banana', $entries[1]->content['data']['payload']);

        $this->assertSame(EntryType::BATCH, $entries[2]->type);
        $this->assertSame($batch->id, $entries[2]->uuid);
        $this->assertSame(2, $entries[2]->content['totalJobs']);
        $this->assertSame(1, $entries[2]->content['failedJobs']);
        $this->assertSame($batch->id, $entries[2]->content['id']);
        $this->assertSame('on-demand', $entries[2]->content['queue']);
        $this->assertSame('database', $entries[2]->content['connection']);
        $this->assertFalse($entries[2]->content['allowsFailures']);
    }
}

class BananaJob implements ShouldQueue
{
    use Batchable;

    private string $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(string $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
    }
}

class FailedBananaJob implements ShouldQueue
{
    use Batchable;

    public int $tries = 1;

    private string $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(string $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        throw new Exception($this->payload);
    }
}
