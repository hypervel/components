<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\PreparesForDispatch;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;

class PreparesForDispatchTest extends TestCase
{
    public function testDoesNotDispatchWhenPrepareReturnsFalse(): void
    {
        Queue::fake();

        PreparesForDispatchFalseJob::dispatch();

        Queue::assertNotPushed(PreparesForDispatchFalseJob::class);
    }

    public function testDispatchesWhenPrepareReturnsVoid(): void
    {
        Queue::fake();

        PreparesForDispatchVoidJob::$ran = false;

        PreparesForDispatchVoidJob::dispatch();

        $this->assertTrue(PreparesForDispatchVoidJob::$ran);
        Queue::assertPushed(PreparesForDispatchVoidJob::class);
    }
}

class PreparesForDispatchFalseJob implements PreparesForDispatch, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function prepareForDispatch(): bool
    {
        return false;
    }

    public function handle(): void
    {
    }
}

class PreparesForDispatchVoidJob implements PreparesForDispatch, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function prepareForDispatch(): void
    {
        static::$ran = true;
    }

    public function handle(): void
    {
    }
}
