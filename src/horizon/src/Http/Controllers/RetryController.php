<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Jobs\RetryFailedJob;

class RetryController
{
    /**
     * Retry a failed job.
     */
    public function store(string $id): void
    {
        dispatch(new RetryFailedJob($id));
    }
}
