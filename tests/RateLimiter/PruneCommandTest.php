<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\Contracts\PrunableStore;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Testbench\TestCase;

class PruneCommandTest extends TestCase
{
    public function testPrunesTheSelectedStoreWithTheConfiguredChunkSize(): void
    {
        config([
            'rate-limiter.stores.prunable' => [
                'driver' => 'prunable',
            ],
        ]);
        $store = new PrunableWorkerArrayStore;
        $this->app->make(RateLimiter::class)->extend(
            'prunable',
            static fn (): PrunableWorkerArrayStore => $store,
        );

        $this->artisan('rate-limiter:prune', [
            'store' => 'prunable',
            '--chunk' => 17,
        ])->expectsOutputToContain('Pruned 7 expired rate limiter entries.')
            ->assertSuccessful();

        $this->assertSame(17, $store->chunkSize);
    }

    public function testRejectsAStoreThatDoesNotSupportPruning(): void
    {
        $this->artisan('rate-limiter:prune', ['store' => 'worker-array'])
            ->expectsOutputToContain('Rate limiter store [worker-array] does not support pruning.')
            ->assertExitCode(1);
    }
}

class PrunableWorkerArrayStore extends WorkerArrayStore implements PrunableStore
{
    public int $chunkSize = 0;

    public function pruneExpired(int $chunkSize = 1000): int
    {
        $this->chunkSize = $chunkSize;

        return 7;
    }
}
