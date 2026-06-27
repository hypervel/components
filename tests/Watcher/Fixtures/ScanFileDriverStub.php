<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Watcher\Driver\ScanFileDriver;

class ScanFileDriverStub extends ScanFileDriver
{
    protected function getWatchFileHashes(): array
    {
        return ['.env' => hash('xxh128', strval(microtime()))];
    }
}
