<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\QueueDelayTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;

class QueueDelayTest extends TestCase
{
    public function testQueueDelay(): void
    {
        Queue::fake();

        $job = new TestJob;

        dispatch($job);

        $this->assertEquals(60, $job->delay);
    }

    public function testQueueWithoutDelay(): void
    {
        Queue::fake();

        $job = new TestJob;

        dispatch($job->withoutDelay());

        $this->assertEquals(0, $job->delay);
    }

    public function testPendingDispatchWithoutDelay(): void
    {
        Queue::fake();

        $job = new TestJob;

        dispatch($job)->withoutDelay();

        $this->assertEquals(0, $job->delay);
    }
}

class TestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->delay(60);
    }
}
