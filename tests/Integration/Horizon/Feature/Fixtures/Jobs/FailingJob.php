<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs;

use Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Exceptions\DontReportException;

class FailingJob
{
    /**
     * Fail the job with an unreported exception.
     *
     * @throws DontReportException
     */
    public function handle(): void
    {
        throw new DontReportException('Job Failed');
    }

    /**
     * Get the tags assigned to the job.
     */
    public function tags(): array
    {
        return ['first'];
    }
}
