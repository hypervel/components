<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Stubs;

use Hypervel\Watcher\Driver\FindNewerDriver;

class FindNewerDriverStub extends FindNewerDriver
{
    protected function scan(): array
    {
        return ['.env'];
    }
}
