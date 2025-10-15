<?php

namespace Hypervel\View\Compilers;

use ErrorException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use InvalidArgumentException;

abstract class Compiler
{
    /**
     * The filesystem instance.
     *
     * @var \Hypervel\Filesystem\Filesystem
     */
    protected Filesystem $files;

    /**
     * The cache path for the compiled views.
     *
     * @var string
     */
    protected string $cachePath;

    /**
     * The base path that should be removed from paths before hashing.
     *
     * @var string
     */
    protected string $basePath;

    /**
     * Determines if compiled views should be cached.
     *
     * @var bool
     */
    protected bool $shouldCache;

    /**
     * The compiled view file extension.
     *
     * @var string
     */
    protected string $compiledExtension = 'php';

    /**
     * Create a new compiler instance.
     *
     * @param  \Hypervel\Filesystem\Filesystem  $files
     * @param  string  $cachePath
     * @param  string  $basePath
     * @param  bool  $shouldCache
     * @param  string  $compiledExtension
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        Filesystem $files,
        string $cachePath,
        string $basePath = '',
        bool $shouldCache = true,
        string $compiledExtension = 'php',
    ): void {
        if (! $cachePath) {
            throw new InvalidArgumentException('Please provide a valid cache path.');
        }

        $this->files = $files;
        $this->cachePath = $cachePath;
        $this->basePath = $basePath;
        $this->shouldCache = $shouldCache;
        $this->compiledExtension = $compiledExtension;
    }

    /**
     * Get the path to the compiled version of a view.
     *
     * @param  string  $path
     * @return string
     */
    public function getCompiledPath(string $path): string
    {
        return $this->cachePath.'/'.hash('xxh128', 'v2'.Str::after($path, $this->basePath)).'.'.$this->compiledExtension;
    }

    /**
     * Determine if the view at the given path is expired.
     *
     * @param  string  $path
     * @return bool
     *
     * @throws \ErrorException
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
            return $this->files->lastModified($path) >=
                $this->files->lastModified($compiled);
        } catch (ErrorException $exception) {
            if (! $this->files->exists($compiled)) {
                return true;
            }

            throw $exception;
        }
    }

    /**
     * Create the compiled file directory if necessary.
     *
     * @param  string  $path
     * @return void
     */
    protected function ensureCompiledDirectoryExists(string $path): void
    {
        if (! $this->files->exists(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        }
    }
}
