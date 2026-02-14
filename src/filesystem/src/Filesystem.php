<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hyperf\Support\Filesystem\Filesystem as HyperfFilesystem;
use Hypervel\Http\Exceptions\FileNotFoundException;

class Filesystem extends HyperfFilesystem
{
    /**
     * Ensure a directory exists.
     */
    public function ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): void
    {
        if (! $this->isDirectory($path)) {
            $this->makeDirectory($path, $mode, $recursive);
        }
    }

    /**
     * Get the returned value of a file.
     *
     * @throws FileNotFoundException
     */
    public function getRequire(string $path, array $data = [[]])
    {
        if ($this->isFile($path)) {
            $__path = $path;
            $__data = $data;

            return (static function () use ($__path, $__data) {
                extract($__data, EXTR_SKIP);

                return require $__path;
            })();
        }

        throw new FileNotFoundException("File does not exist at path {$path}.");
    }
}
