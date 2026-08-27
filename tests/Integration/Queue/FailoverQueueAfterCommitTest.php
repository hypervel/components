<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\FailoverQueueAfterCommitTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Throwable;

class FailoverQueueAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');
        $config->set('queue.default', 'failover');
        $config->set('queue.connections.failover', [
            'driver' => 'failover',
            'connections' => ['sync'],
            'after_commit' => true,
        ]);
    }

    protected function tearDown(): void
    {
        FailoverQueueAfterCommitJob::$handled = false;

        parent::tearDown();
    }

    public function testFailoverDispatchesWhenAnApplicationTransactionCommits(): void
    {
        DB::transaction(function (): void {
            Bus::dispatch(new FailoverQueueAfterCommitJob);

            $this->assertFalse(FailoverQueueAfterCommitJob::$handled);
        });

        $this->assertTrue(FailoverQueueAfterCommitJob::$handled);
    }

    public function testFailoverDoesNotDispatchWhenAnApplicationTransactionRollsBack(): void
    {
        $failure = new RuntimeException('Rollback.');
        $caught = null;

        try {
            DB::transaction(function () use ($failure): void {
                Bus::dispatch(new FailoverQueueAfterCommitJob);

                throw $failure;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($failure, $caught);
        $this->assertFalse(FailoverQueueAfterCommitJob::$handled);
    }
}

class FailoverQueueAfterCommitJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }
}
