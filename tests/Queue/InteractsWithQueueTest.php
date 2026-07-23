<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;

class InteractsWithQueueTest extends TestCase
{
    public function testCreatesAnExceptionFromString(): void
    {
        $queueJob = m::mock(Job::class);
        $queueJob->shouldReceive('fail')->withArgs(function (Exception $exception): bool {
            $this->assertSame('Whoops!', $exception->getMessage());

            return true;
        });

        $job = new class {
            use InteractsWithQueue;

            public ?Job $job = null;
        };

        $job->job = $queueJob;
        $job->fail('Whoops!');
    }
}
