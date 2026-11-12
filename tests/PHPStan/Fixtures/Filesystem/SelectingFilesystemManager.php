<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Filesystem;

use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemManager;
use UnitEnum;

/**
 * A filesystem manager that selects its disks in its own way.
 */
class SelectingFilesystemManager extends FilesystemManager
{
    /**
     * Get a filesystem instance.
     */
    public function disk(UnitEnum|string|null $name = null): Filesystem
    {
        return parent::disk('local');
    }
}
