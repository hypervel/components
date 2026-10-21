<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs;

use Exception;
use Hypervel\Queue\InteractsWithQueue;

class ConditionallyFailingJob
{
    use InteractsWithQueue;

    /**
     * Fail the job when the failure flag is set.
     */
    public function handle(): void
    {
        if (isset($_SERVER['horizon.fail'])) {
            $this->fail(new Exception);
        }
    }

    /**
     * Get the tags assigned to the job.
     */
    public function tags(): array
    {
        return ['first'];
    }
}
