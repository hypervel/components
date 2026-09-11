<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs;

use Hypervel\Contracts\Queue\Job;

class LegacyJob
{
    /**
     * Delete the legacy job after handling it.
     */
    public function fire(Job $job, mixed $data): void
    {
        $job->delete();
    }
}
