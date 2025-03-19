<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\Queueable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Foundation\Testing\ApplicationTestCase;

/**
 * @internal
 * @coversNothing
 */
class QueueSizeTest extends ApplicationTestCase
{
    public function testQueueSize()
    {
        Queue::fake();

        $this->assertEquals(0, Queue::size());
        $this->assertEquals(0, Queue::size('Q2'));

        $job = new TestJob1();

        dispatch($job);
        dispatch(new TestJob2());
        dispatch($job)->onQueue('Q2');

        $this->assertEquals(2, Queue::size());
        $this->assertEquals(1, Queue::size('Q2'));
    }
}

class TestJob1 implements ShouldQueue
{
    use Queueable;
}

class TestJob2 implements ShouldQueue
{
    use Queueable;
}
