<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\NullQueue;
use Hypervel\Tests\TestCase;

class QueueNullQueueTest extends TestCase
{
    public function testCreationTimeOfOldestPendingJobReturnsNull()
    {
        $queue = new NullQueue;

        $this->assertNull($queue->creationTimeOfOldestPendingJob());
        $this->assertNull($queue->creationTimeOfOldestPendingJob('custom'));
    }

    public function testInspectionReturnsEmptyCollections(): void
    {
        $queue = new NullQueue;

        $this->assertTrue($queue->pendingJobs()->isEmpty());
        $this->assertTrue($queue->delayedJobs()->isEmpty());
        $this->assertTrue($queue->reservedJobs()->isEmpty());
        $this->assertTrue($queue->allPendingJobs()->isEmpty());
        $this->assertTrue($queue->allDelayedJobs()->isEmpty());
        $this->assertTrue($queue->allReservedJobs()->isEmpty());
    }
}
