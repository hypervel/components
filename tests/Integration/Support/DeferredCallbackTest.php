<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

class DeferredCallbackTest extends TestCase
{
    #[WithConfig('queue.default', 'sync')]
    public function testDeferredCallbackIsNotDiscardedBySyncJob(): void
    {
        $executed = false;

        Route::get('/test', function () use (&$executed): void {
            defer(function () use (&$executed): void {
                $executed = true;
            });

            dispatch(new DeferredCallbackTestSyncJob);
        })->middleware(InvokeDeferredCallbacks::class);

        $this->get('/test');

        $this->assertTrue($executed);
    }
}

class DeferredCallbackTestSyncJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}
