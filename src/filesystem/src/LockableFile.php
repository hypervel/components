<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use Exception;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Locker;
use Hypervel\Contracts\Filesystem\LockTimeoutException;

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
        if (! file_exists(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
    }

    /**
     * Create the file resource.
     *
     * @throws Exception
     */
    protected function createResource(string $path, string $mode): void
    {
        $this->handle = fopen($path, $mode);
    }

    /**
     * Read the file contents.
     */
    public function read(?int $length = null): string
    {
        clearstatcache(true, $this->path);

        return fread($this->handle, $length ?? ($this->size() ?: 1));
    }

    /**
     * Get the file size.
     */
    public function size(): int
    {
        return filesize($this->path);
    }

    /**
     * Write to the file.
     *
     * @return $this
     */
    public function write(string $contents): static
    {
        fwrite($this->handle, $contents);

        fflush($this->handle);

        return $this;
    }

    /**
     * Truncate the file.
     *
     * @return $this
     */
    public function truncate(): static
    {
        rewind($this->handle);

        ftruncate($this->handle, 0);

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
        $this->atomic(function () use ($block) {
            if (! flock($this->handle, LOCK_SH | ($block ? 0 : LOCK_NB))) {
                throw new LockTimeoutException("Unable to acquire file lock at path [{$this->path}].");
            }
        });

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
        $this->atomic(function () use ($block) {
            if (! flock($this->handle, LOCK_EX | ($block ? 0 : LOCK_NB))) {
                throw new LockTimeoutException("Unable to acquire file lock at path [{$this->path}].");
            }
        });

        $this->isLocked = true;

        return $this;
    }

    /**
     * Release the lock on the file.
     *
     * @return $this
     */
    public function releaseLock(): static
    {
        $this->atomic(fn () => flock($this->handle, LOCK_UN));

        $this->isLocked = false;

        return $this;
    }

    /**
     * Close the file.
     */
    public function close(): bool
    {
        if ($this->isLocked) {
            $this->releaseLock();
        }

        return fclose($this->handle);
    }

    protected function atomic(Closure $callback): mixed
    {
        if (! Coroutine::inCoroutine()) {
            return $callback();
        }

        try {
            while (! Locker::lock($this->path)) {
                usleep(1000);
            }

            return $callback();
        } finally {
            Locker::unlock($this->path);
        }
    }
}
