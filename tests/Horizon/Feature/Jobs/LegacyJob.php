<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Jobs;

class LegacyJob
{
    public function fire($job, $data)
    {
        $job->delete();
    }
}
