<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Contracts\Filesystem\LockTimeoutException;
use RuntimeException;
use Throwable;

class LockableFile
{
    /**
     * The file resource.
     *
     * @var resource
     */
    protected $handle;

    /**
     * The file path.
     */
    protected string $path;

    /**
     * Indicates if the file is locked.
     */
    protected bool $isLocked = false;

    /**
     * Create a new File instance.
     */
    public function __construct(string $path, string $mode)
    {
        $this->path = $path;

        $this->ensureDirectoryExists($path);
        $this->createResource($path, $mode);
    }

    /**
     * Create the file's directory if necessary.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory at path [{$directory}].");
        }
    }

    /**
     * Create the file resource.
     *
     * @throws RuntimeException
     */
    protected function createResource(string $path, string $mode): void
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Unable to open file at path [{$path}].");
        }

        $this->handle = $handle;
    }

    /**
     * Read the file contents.
     */
    public function read(?int $length = null): string
    {
        $contents = @fread($this->handle, $length ?? ($this->size() ?: 1));

        if ($contents === false) {
            throw new RuntimeException("Unable to read from file at path [{$this->path}].");
        }

        return $contents;
    }

    /**
     * Get the file size.
     */
    public function size(): int
    {
        $stat = @fstat($this->handle);

        if ($stat === false) {
            throw new RuntimeException("Unable to determine the size of file at path [{$this->path}].");
        }

        return $stat['size'];
    }

    /**
     * Write to the file.
     */
    public function write(string $contents): static
    {
        while ($contents !== '') {
            $written = @fwrite($this->handle, $contents);

            if ($written === false || $written === 0) {
                throw new RuntimeException("Unable to write to file at path [{$this->path}].");
            }

            $contents = substr($contents, $written);
        }

        if (! @fflush($this->handle)) {
            throw new RuntimeException("Unable to flush file at path [{$this->path}].");
        }

        return $this;
    }

    /**
     * Truncate the file.
     */
    public function truncate(): static
    {
        if (! @rewind($this->handle)) {
            throw new RuntimeException("Unable to rewind file at path [{$this->path}].");
        }

        if (! @ftruncate($this->handle, 0)) {
            throw new RuntimeException("Unable to truncate file at path [{$this->path}].");
        }

        return $this;
    }

    /**
     * Get a shared lock on the file.
     *
     * @return $this
     *
     * @throws LockTimeoutException
     */
    public function getSharedLock(bool $block = false): static
    {
        if (! @flock($this->handle, LOCK_SH | ($block ? 0 : LOCK_NB))) {
            throw new LockTimeoutException("Unable to acquire file lock at path [{$this->path}].");
        }

        $this->isLocked = true;

        return $this;
    }

    /**
     * Get an exclusive lock on the file.
     *
     * @return $this
     *
     * @throws LockTimeoutException
     */
    public function getExclusiveLock(bool $block = false): static
    {
        if (! @flock($this->handle, LOCK_EX | ($block ? 0 : LOCK_NB))) {
            throw new LockTimeoutException("Unable to acquire file lock at path [{$this->path}].");
        }

        $this->isLocked = true;

        return $this;
    }

    /**
     * Release the lock on the file.
     */
    public function releaseLock(): static
    {
        if (! @flock($this->handle, LOCK_UN)) {
            throw new RuntimeException("Unable to release file lock at path [{$this->path}].");
        }

        $this->isLocked = false;

        return $this;
    }

    /**
     * Close the file.
     */
    public function close(): bool
    {
        $exception = null;

        if ($this->isLocked) {
            try {
                $this->releaseLock();
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        try {
            $closed = @fclose($this->handle);

            if (! $closed) {
                throw new RuntimeException("Unable to close file at path [{$this->path}].");
            }
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }

        return true;
    }
}
