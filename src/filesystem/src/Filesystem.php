<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hyperf\Support\Filesystem\Filesystem as HyperfFilesystem;

class Filesystem extends HyperfFilesystem
{
    /**
     * Determine if a file or directory is missing.
     */
    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    /**
     * Ensure a directory exists.
     */
    public function ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): void
    {
        if (! $this->isDirectory($path)) {
            $this->makeDirectory($path, $mode, $recursive);
        }
    }
}
