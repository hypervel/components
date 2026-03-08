<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Watcher\Driver\FindNewerDriver;

class FindNewerDriverStub extends FindNewerDriver
{
    protected function scan(): array
    {
        return ['.env'];
    }
}
