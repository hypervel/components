<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Watcher\Driver\ScanFileDriver;

class ScanFileDriverStub extends ScanFileDriver
{
    protected function getWatchMD5(&$files): array
    {
        $files[] = '.env';
        return ['.env' => md5(strval(microtime()))];
    }
}
