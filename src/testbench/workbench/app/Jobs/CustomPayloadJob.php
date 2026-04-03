<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Hypervel\Contracts\Queue\ShouldQueue;

class CustomPayloadJob implements ShouldQueue
{
    public string $connection = 'sync';

    public function handle(): void
    {
    }
}
