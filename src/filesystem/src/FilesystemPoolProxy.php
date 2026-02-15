<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\HttpMessage\Upload\UploadedFile;
use Hypervel\Contracts\Filesystem\Cloud;
use Hypervel\ObjectPool\PoolProxy;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class FilesystemPoolProxy extends PoolProxy implements Cloud
{
    /**
     * Get the full path to the file that exists at the given relative path.
     */
    public function path(string $path): string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Determine if a file exists.
     */
    public function exists(string $path): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get the contents of a file.
     */
    public function get(string $path): ?string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get a resource to read the file.
     *
     * @return null|resource the path resource or null on failure
     */
    public function readStream(string $path): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get a resource to read the partial file.
     *
     * @return null|resource the path resource or null on failure
     * @throws RuntimeException
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Write the contents of a file.
     *
     * @param resource|StreamInterface|string|UploadedFile $contents
     */
    public function put(string $path, mixed $contents, mixed $options = []): bool|string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Store the uploaded file on the disk.
     */
    public function putFile(string|UploadedFile $path, array|string|UploadedFile|null $file = null, mixed $options = []): false|string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Store the uploaded file on the disk with a given name.
     */
    public function putFileAs(string|UploadedFile $path, array|string|UploadedFile|null $file, array|string|null $name = null, mixed $options = []): false|string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Write a new file using a stream.
     *
     * @param resource $resource
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get the visibility for the given path.
     */
    public function getVisibility(string $path): string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Set the visibility for the given path.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Prepend to a file.
     */
    public function prepend(string $path, string $data): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Append to a file.
     */
    public function append(string $path, string $data): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Copy a file to a new location.
     */
    public function copy(string $from, string $to): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Move a file to a new location.
     */
    public function move(string $from, string $to): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get the file size of a given file.
     */
    public function size(string $path): int
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get the file's last modification time.
     */
    public function lastModified(string $path): int
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get an array of all files in a directory.
     */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get all of the files from the given directory (recursive).
     */
    public function allFiles(?string $directory = null): array
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get all of the directories within a given directory.
     */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get all (recursive) of the directories within a given directory.
     */
    public function allDirectories(?string $directory = null): array
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Create a directory.
     */
    public function makeDirectory(string $path): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory(string $directory): bool
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get the URL for the file at the given path.
     */
    public function url(string $path): string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }
}
