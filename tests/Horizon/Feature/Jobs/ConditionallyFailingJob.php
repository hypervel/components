<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Jobs;

use Exception;
use Hypervel\Queue\InteractsWithQueue;

class ConditionallyFailingJob
{
    use InteractsWithQueue;

    public function handle()
    {
        if (isset($_SERVER['horizon.fail'])) {
            return $this->fail(new Exception());
        }
    }

    public function tags()
    {
        return ['first'];
    }
}
