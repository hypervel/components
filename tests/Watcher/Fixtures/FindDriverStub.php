<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Fixtures;

use Hypervel\Watcher\Driver\FindDriver;

class FindDriverStub extends FindDriver
{
    protected function scan(): array
    {
        return [
            'files' => ['.env'],
            'changedComplete' => true,
            'inventoryComplete' => true,
            'failureCode' => null,
        ];
    }

    public function referenceFilesForTest(): array
    {
        return $this->referenceFiles;
    }
}
