<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Filesystem;

use Hypervel\Filesystem\FilesystemManager;

/**
 * A filesystem manager that forwards dynamic calls somewhere other than the default disk.
 */
class ForwardingFilesystemManager extends FilesystemManager
{
    /**
     * Handle a dynamic call.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return null;
    }
}
