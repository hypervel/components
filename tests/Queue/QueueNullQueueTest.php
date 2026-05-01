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
}
