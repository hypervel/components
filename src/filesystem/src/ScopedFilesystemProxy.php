<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Http\File;
use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Traits\Conditionable;
use League\Flysystem\PathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Isolate every filesystem path behind a dynamically resolved prefix.
 *
 * This class deliberately maps the complete path-bearing adapter surface and
 * rejects every unknown call. Percent-encoded segments remain literal names;
 * URL decoding belongs at the HTTP boundary and must happen exactly once.
 */
class ScopedFilesystemProxy implements Filesystem
{
    use Conditionable;

    protected PathNormalizer $normalizer;

    /**
     * Create a dynamically scoped filesystem.
     *
     * @param Closure(): string $prefixResolver
     */
    public function __construct(
        protected Filesystem $disk,
        protected Closure $prefixResolver,
        protected bool $allowRootPassthrough = false,
    ) {
        $this->normalizer = new WhitespacePathNormalizer;
    }

    /**
     * Assert that the given file or directory exists.
     */
    public function assertExists(array|string $path, ?string $content = null): static
    {
        $prefix = $this->prefix();
        $paths = is_array($path)
            ? array_map(fn (string $item): string => $this->applyPrefix($prefix, $item), $path)
            : $this->applyPrefix($prefix, $path);
        $this->call(__FUNCTION__, [$paths, $content]);

        return $this;
    }

    /**
     * Assert that the number of files in path equals the expected count.
     */
    public function assertCount(string $path, int $count, bool $recursive = false): static
    {
        $prefix = $this->prefix();
        $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $count, $recursive]);

        return $this;
    }

    /**
     * Assert that the given file or directory does not exist.
     */
    public function assertMissing(array|string $path): static
    {
        $prefix = $this->prefix();
        $paths = is_array($path)
            ? array_map(fn (string $item): string => $this->applyPrefix($prefix, $item), $path)
            : $this->applyPrefix($prefix, $path);
        $this->call(__FUNCTION__, [$paths]);

        return $this;
    }

    /**
     * Assert that the given directory is empty.
     */
    public function assertDirectoryEmpty(string $path): static
    {
        $prefix = $this->prefix();
        $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);

        return $this;
    }

    /**
     * Assert that the scoped disk contains no files.
     */
    public function assertEmpty(): static
    {
        $prefix = $this->prefix();
        $this->call('assertDirectoryEmpty', [$prefix]);

        return $this;
    }

    /**
     * Get the full path to a scoped file.
     */
    public function path(string $path): string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Determine if a scoped path exists using the Flysystem operator.
     */
    public function has(string $location): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $location)]);
    }

    /**
     * Read a scoped file using the Flysystem operator.
     */
    public function read(string $location): string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $location)]);
    }

    /**
     * Get a scoped file size using the Flysystem operator.
     */
    public function fileSize(string $path): int
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get raw Flysystem visibility for a scoped path.
     */
    public function visibility(string $path): string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Write a scoped file using the Flysystem operator.
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $prefix = $this->prefix();
        $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $location), $contents, $config]);
    }

    /**
     * Create a scoped directory using the Flysystem operator.
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $prefix = $this->prefix();
        $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $location), $config]);
    }

    /**
     * Determine if a scoped file or directory exists.
     */
    public function exists(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Determine if a scoped file or directory is missing.
     */
    public function missing(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Determine if a scoped file exists.
     */
    public function fileExists(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Determine if a scoped file is missing.
     */
    public function fileMissing(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Determine if a scoped directory exists.
     */
    public function directoryExists(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Determine if a scoped directory is missing.
     */
    public function directoryMissing(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get the contents of a scoped file.
     */
    public function get(string $path): ?string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get the contents of a scoped file as decoded JSON.
     */
    public function json(string $path, int $flags = 0): array|bool|float|int|string|null
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $flags]);
    }

    /**
     * Create a streamed response for a scoped file.
     */
    public function response(
        string $path,
        ?string $name = null,
        array $headers = [],
        string $disposition = 'inline',
    ): StreamedResponse {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [
            $this->applyPrefix($prefix, $path),
            $name,
            $headers,
            $disposition,
        ]);
    }

    /**
     * Create a streamed response for serving a scoped file.
     */
    public function serve(Request $request, string $path, ?string $name = null, array $headers = []): Response
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [
            $request,
            $this->applyPrefix($prefix, $path),
            $name,
            $headers,
        ]);
    }

    /**
     * Create a streamed download response for a scoped file.
     */
    public function download(string $path, ?string $name = null, array $headers = []): StreamedResponse
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $name, $headers]);
    }

    /**
     * Write the contents of a scoped file.
     */
    public function put(string $path, mixed $contents, mixed $options = []): bool|string
    {
        $prefix = $this->prefix();
        $result = $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $contents, $options]);

        return is_string($result) ? $this->stripPrefix($prefix, $result) : $result;
    }

    /**
     * Store an uploaded file on the scoped disk.
     */
    public function putFile(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file = null,
        mixed $options = [],
    ): false|string {
        $prefix = $this->prefix();

        if ($file === null || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        $result = $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $file, $options]);

        return $result === false ? false : $this->stripPrefix($prefix, $result);
    }

    /**
     * Store an uploaded file with a given name on the scoped disk.
     */
    public function putFileAs(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file,
        array|string|null $name = null,
        mixed $options = [],
    ): false|string {
        $prefix = $this->prefix();

        if ($name === null || is_array($name)) {
            [$path, $file, $name, $options] = ['', $path, $file, $name ?? []];
        }

        $target = trim($path . '/' . $name, '/');
        $result = $this->call(__FUNCTION__, [
            $this->applyPrefix($prefix, $target),
            $file,
            '',
            $options,
        ]);

        return $result === false ? false : $this->stripPrefix($prefix, $result);
    }

    /**
     * Write a scoped file using a stream.
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $resource, $options]);
    }

    /**
     * Get the visibility for a scoped path.
     */
    public function getVisibility(string $path): string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Set the visibility for a scoped path.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $visibility]);
    }

    /**
     * Prepend to a scoped file.
     */
    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $data, $separator]);
    }

    /**
     * Append to a scoped file.
     */
    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $data, $separator]);
    }

    /**
     * Delete scoped files.
     */
    public function delete(array|string $paths): bool
    {
        $prefix = $this->prefix();
        $paths = is_array($paths)
            ? array_map(fn (string $path): string => $this->applyPrefix($prefix, $path), $paths)
            : $this->applyPrefix($prefix, $paths);

        return $this->call(__FUNCTION__, [$paths]);
    }

    /**
     * Copy a scoped file to another scoped path.
     */
    public function copy(string $from, string $to): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [
            $this->applyPrefix($prefix, $from),
            $this->applyPrefix($prefix, $to),
        ]);
    }

    /**
     * Move a scoped file to another scoped path.
     */
    public function move(string $from, string $to): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [
            $this->applyPrefix($prefix, $from),
            $this->applyPrefix($prefix, $to),
        ]);
    }

    /**
     * Get the size of a scoped file.
     */
    public function size(string $path): int
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get the checksum of a scoped file.
     */
    public function checksum(string $path, array $options = []): false|string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $options]);
    }

    /**
     * Get the MIME type of a scoped file.
     */
    public function mimeType(string $path): false|string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get the last modification time of a scoped file.
     */
    public function lastModified(string $path): int
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get a stream for a scoped file.
     *
     * @return null|resource
     */
    public function readStream(string $path): mixed
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Get a partial stream for a scoped file.
     *
     * @return null|resource
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $start, $end]);
    }

    /**
     * Get a temporary URL for a scoped file.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $expiration, $options]);
    }

    /**
     * Get a temporary upload URL for a scoped file.
     */
    public function temporaryUploadUrl(
        string $path,
        DateTimeInterface $expiration,
        array $options = [],
    ): array|string {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path), $expiration, $options]);
    }

    /**
     * Determine if the inner disk provides temporary URLs.
     */
    public function providesTemporaryUrls(): bool
    {
        return $this->call(__FUNCTION__, []);
    }

    /**
     * Determine if the inner disk provides temporary upload URLs.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return $this->call(__FUNCTION__, []);
    }

    /**
     * Get the inner disk configuration.
     */
    public function getConfig(): array
    {
        return $this->call(__FUNCTION__, []);
    }

    /**
     * Get scoped files in a directory.
     */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        $prefix = $this->prefix();
        $paths = $this->call(__FUNCTION__, [
            $this->applyPrefix($prefix, $directory ?? ''),
            $recursive,
        ]);

        return array_map(fn (string $path): string => $this->stripPrefix($prefix, $path), $paths);
    }

    /**
     * Get all scoped files in a directory recursively.
     */
    public function allFiles(?string $directory = null): array
    {
        $prefix = $this->prefix();
        $paths = $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $directory ?? '')]);

        return array_map(fn (string $path): string => $this->stripPrefix($prefix, $path), $paths);
    }

    /**
     * Get scoped directories in a directory.
     */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        $prefix = $this->prefix();
        $paths = $this->call(__FUNCTION__, [
            $this->applyPrefix($prefix, $directory ?? ''),
            $recursive,
        ]);

        return array_map(fn (string $path): string => $this->stripPrefix($prefix, $path), $paths);
    }

    /**
     * Get all scoped directories in a directory recursively.
     */
    public function allDirectories(?string $directory = null): array
    {
        $prefix = $this->prefix();
        $paths = $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $directory ?? '')]);

        return array_map(fn (string $path): string => $this->stripPrefix($prefix, $path), $paths);
    }

    /**
     * Create a scoped directory.
     */
    public function makeDirectory(string $path): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }

    /**
     * Delete a scoped directory recursively.
     */
    public function deleteDirectory(string $directory): bool
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $directory)]);
    }

    /**
     * Reject access to the inner Flysystem driver.
     */
    public function getDriver(): never
    {
        throw $this->unsupportedMutationOrInternal(__FUNCTION__);
    }

    /**
     * Reject access to the inner Flysystem adapter.
     */
    public function getAdapter(): never
    {
        throw $this->unsupportedMutationOrInternal(__FUNCTION__);
    }

    /**
     * Reject access to the inner driver client.
     */
    public function getClient(): never
    {
        throw $this->unsupportedMutationOrInternal(__FUNCTION__);
    }

    /**
     * Reject mutation of the shared inner disk's serve callback.
     */
    public function serveUsing(?Closure $callback): never
    {
        throw $this->unsupportedMutationOrInternal(__FUNCTION__);
    }

    /**
     * Reject mutation of the shared inner disk's temporary URL callback.
     */
    public function buildTemporaryUrlsUsing(?Closure $callback): never
    {
        throw $this->unsupportedMutationOrInternal(__FUNCTION__);
    }

    /**
     * Reject mutation of the shared inner disk's temporary upload URL callback.
     */
    public function buildTemporaryUploadUrlsUsing(?Closure $callback): never
    {
        throw $this->unsupportedMutationOrInternal(__FUNCTION__);
    }

    /**
     * Reject calls that have not been explicitly mapped through the prefix boundary.
     */
    public function __call(string $method, array $parameters): never
    {
        throw new BadMethodCallException(
            "Method [{$method}] is not supported on scoped filesystems: unmapped calls could bypass the path prefix.",
        );
    }

    /**
     * Resolve and validate the prefix for one public operation.
     */
    protected function prefix(): string
    {
        $prefix = $this->normalizer->normalizePath(($this->prefixResolver)());

        if ($prefix === '' && ! $this->allowRootPassthrough) {
            throw new RuntimeException(
                'The scoped filesystem prefix resolver returned an empty prefix. '
                . 'Enable root passthrough explicitly if operating on the disk root is intended.',
            );
        }

        return $prefix;
    }

    /**
     * Apply a validated prefix to a normalized user path.
     */
    protected function applyPrefix(string $prefix, string $path): string
    {
        $path = $this->normalizer->normalizePath($path);

        return $prefix === '' ? $path : trim($prefix . '/' . $path, '/');
    }

    /**
     * Strip a validated prefix from an inner returned path.
     */
    protected function stripPrefix(string $prefix, string $path): string
    {
        $path = $this->normalizer->normalizePath($path);

        if ($prefix === '') {
            return $path;
        }

        if ($path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
            throw new RuntimeException(
                "Path [{$path}] returned by the scoped disk is outside the resolved prefix [{$prefix}].",
            );
        }

        return ltrim(substr($path, strlen($prefix)), '/');
    }

    /**
     * Forward one audited method to the inner disk.
     */
    protected function call(string $method, array $arguments): mixed
    {
        $declared = method_exists($this->disk, $method);

        if (! $declared && ! is_callable([$this->disk, $method])) {
            throw new BadMethodCallException("The scoped disk's inner filesystem does not support [{$method}].");
        }

        try {
            return $this->disk->{$method}(...$arguments);
        } catch (BadMethodCallException $exception) {
            if (! $declared) {
                throw new BadMethodCallException(
                    "The scoped disk's inner filesystem does not support [{$method}].",
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * Create the exception for every rejected internal or shared-state mutator.
     */
    protected function unsupportedMutationOrInternal(string $method): RuntimeException
    {
        return new RuntimeException(
            "Method [{$method}] is not available on scoped filesystems because it could expose "
            . 'unscoped internals or mutate shared base-disk behavior.',
        );
    }
}
