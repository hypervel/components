<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Stubs;

use Hypervel\Watcher\Driver\FindDriver;

class FindDriverStub extends FindDriver
{
    protected function scan(array $fileModifyTimes, string $minutes): array
    {
        return [[], ['.env']];
    }
}
