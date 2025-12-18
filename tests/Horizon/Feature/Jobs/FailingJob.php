<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Jobs;

use Hypervel\Tests\Horizon\Feature\Exceptions\DontReportException;

class FailingJob
{
    public function handle()
    {
        throw new DontReportException('Job Failed');
    }

    public function tags()
    {
        return ['first'];
    }
}
