<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs;

class BasicJob
{
    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }

    /**
     * Get the tags assigned to the job.
     */
    public function tags(): array
    {
        return ['first', 'second'];
    }
}
