<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

use ErrorException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;

abstract class Compiler
{
    /**
     * Create a new compiler instance.
     */
    public function __construct(
        protected Filesystem $files,
        protected string $cachePath,
        protected string $basePath = '',
        protected bool $shouldCache = true,
        protected string $compiledExtension = 'php',
    ) {
    }

    /**
     * Get the path to the compiled version of a view.
     */
    public function getCompiledPath(string $path): string
    {
        return $this->cachePath.'/'.hash('xxh128', 'v2'.Str::after($path, $this->basePath)).'.'.$this->compiledExtension;
    }

    /**
     * Determine if the view at the given path is expired.
     *
     * @throws ErrorException
     */
    public function isExpired(string $path): bool
    {
        if (! $this->shouldCache) {
            return true;
        }

        $compiled = $this->getCompiledPath($path);

        // If the compiled file doesn't exist we will indicate that the view is expired
        // so that it can be re-compiled. Else, we will verify the last modification
        // of the views is less than the modification times of the compiled views.
        if (! $this->files->exists($compiled)) {
            return true;
        }

        try {
            return $this->files->lastModified($path) >= $this->files->lastModified($compiled);
        } catch (ErrorException $exception) {
            if (! $this->files->exists($compiled)) {
                return true;
            }

            throw $exception;
        }
    }

    /**
     * Create the compiled file directory if necessary.
     */
    protected function ensureCompiledDirectoryExists(string $path): void
    {
        if (! $this->files->exists(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        }
    }
}
