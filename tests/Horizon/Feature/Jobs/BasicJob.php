<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Jobs;

class BasicJob
{
    public function handle()
    {
    }

    public function tags()
    {
        return ['first', 'second'];
    }
}
