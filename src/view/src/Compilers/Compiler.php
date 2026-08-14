<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

use ErrorException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use InvalidArgumentException;

abstract class Compiler
{
    /**
     * The directory where compiled views are stored.
     */
    protected string $cachePath;

    /**
     * The application base path removed from compiled view hashes.
     */
    protected string $basePath;

    /**
     * Determine whether compiled views should be cached.
     */
    protected bool $shouldCache;

    /**
     * The compiled view file extension.
     */
    protected string $compiledExtension;

    /**
     * Determine whether compiled view timestamps should be checked.
     */
    protected bool $shouldCheckTimestamps;

    /**
     * Create a new compiler instance.
     */
    public function __construct(
        protected Filesystem $files,
        string $cachePath,
        string $basePath = '',
        bool $shouldCache = true,
        string $compiledExtension = 'php',
        bool $shouldCheckTimestamps = true,
    ) {
        $this->reloadConfiguration(
            $cachePath,
            $basePath,
            $shouldCache,
            $compiledExtension,
            $shouldCheckTimestamps,
        );
    }

    /**
     * Reload configuration-derived compiler state.
     *
     * Boot-only. Mutates the worker-shared compiler while concurrent
     * coroutines may still compile views using its previous configuration.
     *
     * @throws InvalidArgumentException
     */
    public function reloadConfiguration(
        string $cachePath,
        string $basePath,
        bool $shouldCache,
        string $compiledExtension,
        bool $shouldCheckTimestamps,
    ): void {
        if ($cachePath === '') {
            throw new InvalidArgumentException('Please provide a valid cache path.');
        }

        $this->cachePath = $cachePath;
        $this->basePath = $basePath;
        $this->shouldCache = $shouldCache;
        $this->compiledExtension = $compiledExtension;
        $this->shouldCheckTimestamps = $shouldCheckTimestamps;
    }

    /**
     * Get the path to the compiled version of a view.
     */
    public function getCompiledPath(string $path): string
    {
        return $this->cachePath . '/' . hash('xxh128', 'v3' . Str::after($path, $this->basePath)) . '.' . $this->compiledExtension;
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

        if (! $this->shouldCheckTimestamps) {
            return false;
        }

        try {
            return $this->files->lastModified($path) >= $this->files->lastModified($compiled);
        } catch (ErrorException $exception) {
            // The compiled file might have been deleted between the initial check and lastModified() call
            // @phpstan-ignore booleanNot.alwaysFalse
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
