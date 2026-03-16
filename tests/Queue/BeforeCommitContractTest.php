<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BeforeCommitContractTest extends TestCase
{
    public function testJobWithoutContractRespectsBeforeCommit()
    {
        $job = new class {
            use Dispatchable;
            use InteractsWithQueue;
            use Queueable;

            public function beforeCommit()
            {
                $this->afterCommit = false;

                return $this;
            }
        };

        $this->assertFalse($this->shouldDispatchAfterCommit($job));
    }

    public function testJobWithoutContractRespectsAfterCommit()
    {
        $job = new class {
            use Dispatchable;
            use InteractsWithQueue;
            use Queueable;

            public function afterCommit()
            {
                $this->afterCommit = true;

                return $this;
            }
        };

        $job->afterCommit();

        $this->assertTrue($this->shouldDispatchAfterCommit($job));
    }

    public function testJobWithContractDefaultsToAfterCommit()
    {
        $job = new class implements ShouldQueueAfterCommit {
            use Dispatchable;
            use InteractsWithQueue;
            use Queueable;
        };

        $this->assertTrue($this->shouldDispatchAfterCommit($job));
    }

    public function testJobWithContractAndAfterCommitFalseRespectsBeforeCommit()
    {
        $job = new class implements ShouldQueueAfterCommit {
            use Dispatchable;
            use InteractsWithQueue;
            use Queueable;

            public function beforeCommit()
            {
                $this->afterCommit = false;

                return $this;
            }
        };

        $job->beforeCommit();

        $this->assertFalse($this->shouldDispatchAfterCommit($job));
    }

    public function testJobWithContractAndExplicitAfterCommitTrueStillSchedulesAfterCommit()
    {
        $job = new class implements ShouldQueueAfterCommit {
            use Dispatchable;
            use InteractsWithQueue;
            use Queueable;

            public function afterCommit()
            {
                $this->afterCommit = true;

                return $this;
            }
        };

        $job->afterCommit();

        $this->assertTrue($this->shouldDispatchAfterCommit($job));
    }

    protected function shouldDispatchAfterCommit($job)
    {
        if ($job instanceof ShouldQueueAfterCommit) {
            return ! (isset($job->afterCommit) && $job->afterCommit === false);
        }

        return isset($job->afterCommit) && $job->afterCommit;
    }
}
