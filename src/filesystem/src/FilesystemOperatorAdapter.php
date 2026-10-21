<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Adapt operation-scoped access to a Flysystem operator.
 *
 * @internal
 */
class FilesystemOperatorAdapter implements FilesystemOperator
{
    /**
     * Create an operator with separately owned stream lifetimes.
     *
     * @param Closure(Closure): mixed $operation
     * @param Closure(string): resource $stream
     * @param null|Closure(string, ?int, ?int): (null|resource) $range
     */
    public function __construct(
        protected Closure $operation,
        protected Closure $stream,
        protected ?Closure $range = null,
    ) {
    }

    /**
     * Determine if a file exists.
     */
    public function fileExists(string $location): bool
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): bool => $driver->fileExists($location));
    }

    /**
     * Determine if a directory exists.
     */
    public function directoryExists(string $location): bool
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): bool => $driver->directoryExists($location));
    }

    /**
     * Determine if a file or directory exists.
     */
    public function has(string $location): bool
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): bool => $driver->has($location));
    }

    /**
     * Read a file.
     */
    public function read(string $location): string
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): string => $driver->read($location));
    }

    /**
     * Open a stream that retains any required lease until closure.
     *
     * @return resource
     */
    public function readStream(string $location): mixed
    {
        return ($this->stream)($location);
    }

    /**
     * Open a native ranged stream, or return null when ranges are unsupported.
     *
     * @return null|resource
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        return $this->range === null ? null : ($this->range)($path, $start, $end);
    }

    /**
     * List contents without holding a lease while the caller processes entries.
     *
     * @return DirectoryListing<StorageAttributes>
     */
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return new DirectoryListing(($this->operation)(
            static fn (FilesystemOperator $driver): array => $driver->listContents($location, $deep)->toArray(),
        ));
    }

    /**
     * Get a file's last modification time.
     */
    public function lastModified(string $path): int
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): int => $driver->lastModified($path));
    }

    /**
     * Get a file's size.
     */
    public function fileSize(string $path): int
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): int => $driver->fileSize($path));
    }

    /**
     * Get a file's MIME type.
     */
    public function mimeType(string $path): string
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): string => $driver->mimeType($path));
    }

    /**
     * Get a file's visibility.
     */
    public function visibility(string $path): string
    {
        return ($this->operation)(static fn (FilesystemOperator $driver): string => $driver->visibility($path));
    }

    /**
     * Write a file.
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($location, $contents, $config): void {
            $driver->write($location, $contents, $config);
        });
    }

    /**
     * Write a file stream.
     *
     * @param resource $contents
     */
    public function writeStream(string $location, mixed $contents, array $config = []): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($location, $contents, $config): void {
            $driver->writeStream($location, $contents, $config);
        });
    }

    /**
     * Set a file's visibility.
     */
    public function setVisibility(string $path, string $visibility): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($path, $visibility): void {
            $driver->setVisibility($path, $visibility);
        });
    }

    /**
     * Delete a file.
     */
    public function delete(string $location): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($location): void {
            $driver->delete($location);
        });
    }

    /**
     * Delete a directory.
     */
    public function deleteDirectory(string $location): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($location): void {
            $driver->deleteDirectory($location);
        });
    }

    /**
     * Create a directory.
     */
    public function createDirectory(string $location, array $config = []): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($location, $config): void {
            $driver->createDirectory($location, $config);
        });
    }

    /**
     * Move a file.
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($source, $destination, $config): void {
            $driver->move($source, $destination, $config);
        });
    }

    /**
     * Copy a file.
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        ($this->operation)(static function (FilesystemOperator $driver) use ($source, $destination, $config): void {
            $driver->copy($source, $destination, $config);
        });
    }
}
