<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Jobs;

use Exception;

class FailingJob
{
    public function handle()
    {
        throw new Exception('Job Failed');
    }

    public function tags()
    {
        return ['first'];
    }
}
