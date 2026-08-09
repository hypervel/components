<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Http\File;
use Hypervel\Http\UploadedFile;
use Hypervel\ObjectPool\Contracts\InvalidatesPool;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\Util\Filesize;
use Psr\Http\Message\StreamInterface;
use Sentry\Breadcrumb;
use Sentry\Tracing\SpanContext;

use function Sentry\trace;

/**
 * Decorates the underlying filesystem by wrapping all calls to it with tracing.
 *
 * Parameters such as paths, directories or options are attached to the span as data,
 * parameters that contain file contents are omitted due to potential problems with
 * payload size or sensitive data.
 */
trait FilesystemDecorator
{
    protected Filesystem $filesystem;

    protected array $defaultData;

    protected bool $recordSpans;

    protected bool $recordBreadcrumbs;

    /**
     * Execute the method on the underlying filesystem and wrap it with tracing and log a breadcrumb.
     *
     * @param list<mixed> $args
     * @param array<string, mixed> $data
     */
    protected function withSentry(string $method, array $args, ?string $description, array $data): mixed
    {
        $op = "file.{$method}"; // See https://develop.sentry.dev/sdk/performance/span-operations/#web-server
        $data = array_merge($data, $this->defaultData);

        if ($this->recordBreadcrumbs) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                $op,
                $description,
                $data
            ));
        }

        if ($this->recordSpans) {
            return trace(
                function () use ($method, $args) {
                    return $this->filesystem->{$method}(...$args);
                },
                SpanContext::make()
                    ->setOp($op)
                    ->setData($data)
                    ->setOrigin('auto.filesystem')
                    ->setDescription($description)
            );
        }

        return $this->filesystem->{$method}(...$args);
    }

    /**
     * Get the full path to the file that exists at the given relative path.
     */
    public function path(string $path): string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Determine if a file exists.
     */
    public function exists(string $path): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Get the contents of a file.
     */
    public function get(string $path): ?string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Get a resource to read the file.
     *
     * @return null|resource
     */
    public function readStream(string $path): mixed
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Get a resource to read part of the file.
     *
     * @return null|resource
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path', 'start', 'end'));
    }

    /**
     * Write the contents of a file.
     *
     * @param File|resource|StreamInterface|string|UploadedFile $contents
     */
    public function put(string $path, mixed $contents, mixed $options = []): bool|string
    {
        $description = is_string($contents) ? sprintf('%s (%s)', $path, Filesize::toHuman(strlen($contents))) : $path;

        return $this->withSentry(__FUNCTION__, func_get_args(), $description, compact('path', 'options'));
    }

    /**
     * Store the uploaded file on the disk.
     */
    public function putFile(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file = null,
        mixed $options = []
    ): false|string {
        $description = is_string($path) ? $path : $path->getPathname();

        return $this->withSentry(__FUNCTION__, func_get_args(), $description, compact('path', 'file', 'options'));
    }

    /**
     * Store the uploaded file on the disk with a given name.
     */
    public function putFileAs(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file,
        array|string|null $name = null,
        mixed $options = []
    ): false|string {
        $description = is_string($path) ? $path : $path->getPathname();

        return $this->withSentry(__FUNCTION__, func_get_args(), $description, compact('path', 'file', 'name', 'options'));
    }

    /**
     * Write a new file using a stream.
     *
     * @param resource $resource
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path', 'options'));
    }

    /**
     * Get the visibility for the given path.
     */
    public function getVisibility(string $path): string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Set the visibility for the given path.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path', 'visibility'));
    }

    /**
     * Prepend to a file.
     */
    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        $description = sprintf('%s (%s)', $path, Filesize::toHuman(strlen($data)));

        return $this->withSentry(__FUNCTION__, func_get_args(), $description, compact('path'));
    }

    /**
     * Append to a file.
     */
    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        $description = sprintf('%s (%s)', $path, Filesize::toHuman(strlen($data)));

        return $this->withSentry(__FUNCTION__, func_get_args(), $description, compact('path'));
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        [$description, $data] = $this->getDescriptionAndDataForPathOrPaths($paths);

        return $this->withSentry(__FUNCTION__, func_get_args(), $description, $data);
    }

    /**
     * Copy a file to a new location.
     */
    public function copy(string $from, string $to): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), sprintf('from "%s" to "%s"', $from, $to), compact('from', 'to'));
    }

    /**
     * Move a file to a new location.
     */
    public function move(string $from, string $to): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), sprintf('from "%s" to "%s"', $from, $to), compact('from', 'to'));
    }

    /**
     * Get the file size of a given file.
     */
    public function size(string $path): int
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Get the file's last modification time.
     */
    public function lastModified(string $path): int
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Get an array of all files in a directory.
     */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $directory, compact('directory', 'recursive'));
    }

    /**
     * Get all of the files from the given directory recursively.
     */
    public function allFiles(?string $directory = null): array
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $directory, compact('directory'));
    }

    /**
     * Get all of the directories within a given directory.
     */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $directory, compact('directory', 'recursive'));
    }

    /**
     * Get all of the directories recursively.
     */
    public function allDirectories(?string $directory = null): array
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $directory, compact('directory'));
    }

    /**
     * Create a directory.
     */
    public function makeDirectory(string $path): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory(string $directory): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $directory, compact('directory'));
    }

    /**
     * Get the wrapped filesystem.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /**
     * Invalidate the wrapped filesystem's pool when supported.
     */
    public function invalidatePool(): bool
    {
        return $this->filesystem instanceof InvalidatesPool
            && $this->filesystem->invalidatePool();
    }

    /**
     * Get the description and data for one or more paths.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function getDescriptionAndDataForPathOrPaths(array|string $pathOrPaths): array
    {
        if (is_array($pathOrPaths)) {
            $description = sprintf('%s paths', count($pathOrPaths));
            $data = ['paths' => $pathOrPaths];
        } else {
            $description = $pathOrPaths;
            $data = ['path' => $pathOrPaths];
        }

        return [$description, $data];
    }

    /**
     * Dynamically proxy calls to the wrapped filesystem.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->filesystem->{$name}(...$arguments);
    }
}
