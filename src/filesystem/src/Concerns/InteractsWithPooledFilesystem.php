<?php

declare(strict_types=1);

namespace Hypervel\Filesystem\Concerns;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Filesystem\FileResponseBuilder;
use Hypervel\Http\File;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Traits\Conditionable;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

trait InteractsWithPooledFilesystem
{
    use Conditionable;

    protected ?Closure $serveCallback = null;

    protected ?Closure $temporaryUrlCallback = null;

    protected ?Closure $temporaryUploadUrlCallback = null;

    /**
     * Assert that the given file or directory exists.
     */
    public function assertExists(array|string $path, ?string $content = null): static
    {
        $this->invoke(__FUNCTION__, [$path, $content]);

        return $this;
    }

    /**
     * Assert that the number of files in path equals the expected count.
     */
    public function assertCount(string $path, int $count, bool $recursive = false): static
    {
        $this->invoke(__FUNCTION__, [$path, $count, $recursive]);

        return $this;
    }

    /**
     * Assert that the given file or directory does not exist.
     */
    public function assertMissing(array|string $path): static
    {
        $this->invoke(__FUNCTION__, [$path]);

        return $this;
    }

    /**
     * Assert that the given directory is empty.
     */
    public function assertDirectoryEmpty(string $path): static
    {
        $this->invoke(__FUNCTION__, [$path]);

        return $this;
    }

    /**
     * Determine if a file or directory exists.
     */
    public function exists(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if a file or directory is missing.
     */
    public function missing(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if a file exists.
     */
    public function fileExists(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if a file is missing.
     */
    public function fileMissing(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if a directory exists.
     */
    public function directoryExists(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if a directory is missing.
     */
    public function directoryMissing(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Get the full path to the file that exists at the given relative path.
     */
    public function path(string $path): string
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if a file or directory exists using the Flysystem operator.
     */
    public function has(string $location): bool
    {
        return $this->invoke(__FUNCTION__, [$location]);
    }

    /**
     * Read a file using the Flysystem operator.
     */
    public function read(string $location): string
    {
        return $this->invoke(__FUNCTION__, [$location]);
    }

    /**
     * Get a file size using the Flysystem operator.
     */
    public function fileSize(string $path): int
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Get raw Flysystem visibility for a path.
     */
    public function visibility(string $path): string
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Write a file using the Flysystem operator.
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->invoke(__FUNCTION__, [$location, $contents, $config]);
    }

    /**
     * Create a directory using the Flysystem operator.
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->invoke(__FUNCTION__, [$location, $config]);
    }

    /**
     * Get the contents of a file.
     */
    public function get(string $path): ?string
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Get the contents of a file as decoded JSON.
     */
    public function json(string $path, int $flags = 0): ?array
    {
        return $this->invoke(__FUNCTION__, [$path, $flags]);
    }

    /**
     * Create a streamed response for a given file.
     */
    public function response(
        string $path,
        ?string $name = null,
        array $headers = [],
        string $disposition = 'inline',
    ): Response {
        return $this->buildFileResponse(
            Container::getInstance()->make(Request::class),
            $path,
            $name,
            $headers,
            $disposition,
        );
    }

    /**
     * Create a streamed response for serving a given file.
     */
    public function serve(Request $request, string $path, ?string $name = null, array $headers = []): Response
    {
        return $this->serveCallback !== null
            ? ($this->serveCallback)($request, $path, $headers)
            : $this->buildFileResponse($request, $path, $name, $headers, 'inline');
    }

    /**
     * Create a streamed download response for a given file.
     */
    public function download(string $path, ?string $name = null, array $headers = []): Response
    {
        return $this->response($path, $name, $headers, 'attachment');
    }

    /**
     * Write the contents of a file.
     *
     * @param File|resource|StreamInterface|string|UploadedFile $contents
     */
    public function put(string $path, mixed $contents, mixed $options = []): bool|string
    {
        return $this->invoke(__FUNCTION__, [$path, $contents, $options]);
    }

    /**
     * Store the uploaded file on the disk.
     */
    public function putFile(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file = null,
        mixed $options = [],
    ): false|string {
        return $this->invoke(__FUNCTION__, [$path, $file, $options]);
    }

    /**
     * Store the uploaded file on the disk with a given name.
     */
    public function putFileAs(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file,
        array|string|null $name = null,
        mixed $options = [],
    ): false|string {
        return $this->invoke(__FUNCTION__, [$path, $file, $name, $options]);
    }

    /**
     * Get the visibility for the given path.
     */
    public function getVisibility(string $path): string
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Set the visibility for the given path.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        return $this->invoke(__FUNCTION__, [$path, $visibility]);
    }

    /**
     * Prepend to a file.
     */
    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        return $this->invoke(__FUNCTION__, [$path, $data, $separator]);
    }

    /**
     * Append to a file.
     */
    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        return $this->invoke(__FUNCTION__, [$path, $data, $separator]);
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        return $this->invoke(__FUNCTION__, [$paths]);
    }

    /**
     * Copy a file to a new location.
     */
    public function copy(string $from, string $to): bool
    {
        return $this->invoke(__FUNCTION__, [$from, $to]);
    }

    /**
     * Move a file to a new location.
     */
    public function move(string $from, string $to): bool
    {
        return $this->invoke(__FUNCTION__, [$from, $to]);
    }

    /**
     * Get the file size of a given file.
     */
    public function size(string $path): int
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Get the checksum for a file.
     */
    public function checksum(string $path, array $options = []): false|string
    {
        return $this->invoke(__FUNCTION__, [$path, $options]);
    }

    /**
     * Get the mime-type of a given file.
     */
    public function mimeType(string $path): false|string
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Get the file's last modification time.
     */
    public function lastModified(string $path): int
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Get a resource to read the file.
     *
     * @return null|resource the leased resource or null on failure
     */
    public function readStream(string $path): mixed
    {
        return $this->leasedStream(
            fn (FilesystemContract $filesystem): mixed => $filesystem->readStream($path),
        );
    }

    /**
     * Get a resource to read the partial file.
     *
     * @return null|resource the leased resource or null on failure
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        return $this->leasedStream(
            fn (FilesystemContract $filesystem): mixed => $filesystem->readStreamRange($path, $start, $end),
        );
    }

    /**
     * Write a new file using a stream.
     *
     * @param resource $resource
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        return $this->invoke(__FUNCTION__, [$path, $resource, $options]);
    }

    /**
     * Get the URL for the file at the given path.
     */
    public function url(string $path): string
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return $this->invoke(__FUNCTION__, []);
    }

    /**
     * Determine if temporary upload URLs can be generated.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return $this->invoke(__FUNCTION__, []);
    }

    /**
     * Get a temporary URL for the file at the given path.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        return $this->invoke(__FUNCTION__, [$path, $expiration, $options]);
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     */
    public function temporaryUploadUrl(
        string $path,
        DateTimeInterface $expiration,
        array $options = [],
    ): array|string {
        return $this->invoke(__FUNCTION__, [$path, $expiration, $options]);
    }

    /**
     * Get an array of all files in a directory.
     */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->invoke(__FUNCTION__, [$directory, $recursive]);
    }

    /**
     * Get all of the files from the given directory recursively.
     */
    public function allFiles(?string $directory = null): array
    {
        return $this->invoke(__FUNCTION__, [$directory]);
    }

    /**
     * Get all of the directories within a given directory.
     */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->invoke(__FUNCTION__, [$directory, $recursive]);
    }

    /**
     * Get all of the directories within a given directory recursively.
     */
    public function allDirectories(?string $directory = null): array
    {
        return $this->invoke(__FUNCTION__, [$directory]);
    }

    /**
     * Create a directory.
     */
    public function makeDirectory(string $path): bool
    {
        return $this->invoke(__FUNCTION__, [$path]);
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory(string $directory): bool
    {
        return $this->invoke(__FUNCTION__, [$directory]);
    }

    /**
     * Reject access to the borrowed Flysystem driver.
     */
    public function getDriver(): never
    {
        throw $this->borrowedInternalsException();
    }

    /**
     * Reject access to the borrowed Flysystem adapter.
     */
    public function getAdapter(): never
    {
        throw $this->borrowedInternalsException();
    }

    /**
     * Reject access to a borrowed client.
     */
    public function getClient(): never
    {
        throw $this->borrowedInternalsException();
    }

    /**
     * Run a callback with borrow-scoped access to the Flysystem driver.
     */
    public function withDriver(Closure $callback): mixed
    {
        return $this->withBorrowedAccessor('getDriver', $callback);
    }

    /**
     * Run a callback with borrow-scoped access to the Flysystem adapter.
     */
    public function withAdapter(Closure $callback): mixed
    {
        return $this->withBorrowedAccessor('getAdapter', $callback);
    }

    /**
     * Run a callback with borrow-scoped access to the driver client.
     */
    public function withClient(Closure $callback): mixed
    {
        return $this->withBorrowedAccessor('getClient', $callback);
    }

    /**
     * Get the disk configuration.
     *
     * Nested objects remain shared references; the configuration is not deep-copied.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Define a custom callback that generates file download responses.
     *
     * Boot-only. The callback persists on the manager-cached disk for the
     * worker lifetime and is written to every borrowed adapter.
     */
    public function serveUsing(?Closure $callback): void
    {
        $this->serveCallback = $callback;
    }

    /**
     * Define a custom temporary URL builder callback.
     *
     * Boot-only. The callback persists on the manager-cached disk for the
     * worker lifetime and is written to every borrowed adapter.
     */
    public function buildTemporaryUrlsUsing(?Closure $callback): void
    {
        $this->temporaryUrlCallback = $callback;
    }

    /**
     * Define a custom temporary upload URL builder callback.
     *
     * Boot-only. The callback persists on the manager-cached disk for the
     * worker lifetime and is written to every borrowed adapter.
     */
    public function buildTemporaryUploadUrlsUsing(?Closure $callback): void
    {
        $this->temporaryUploadUrlCallback = $callback;
    }

    /**
     * Invoke a synchronous method within one borrow.
     */
    abstract protected function invoke(string $method, array $parameters): mixed;

    /**
     * Open a stream that retains its lease until closure.
     *
     * @return null|resource
     */
    abstract protected function leasedStream(Closure $operation): mixed;

    /**
     * Run a callback with an accessor result from a borrowed filesystem.
     */
    abstract protected function withBorrowedAccessor(string $accessor, Closure $callback): mixed;

    /**
     * Build a file response using short borrows and a leased stream.
     */
    protected function buildFileResponse(
        Request $request,
        string $path,
        ?string $name,
        array $headers,
        string $disposition,
    ): Response {
        $container = Container::getInstance();

        return $container->make(FileResponseBuilder::class)->build(
            $request,
            $container->make(Response::class),
            $path,
            $name,
            $headers,
            $disposition,
            fn (): false|string => $this->mimeType($path),
            fn (): int => $this->size($path),
            fn (?int $start, ?int $end): mixed => $start === null && $end === null
                ? $this->readStream($path)
                : $this->readStreamRange($path, $start, $end),
        );
    }

    /**
     * Create the exception used for every rejected borrowed-internal accessor.
     */
    protected function borrowedInternalsException(): RuntimeException
    {
        return new RuntimeException(
            'Pooled disks do not expose borrowed internals. '
            . 'Use withDriver(), withAdapter(), or withClient() for borrow-scoped access.',
        );
    }

    /**
     * Reject methods that have not been audited for deferred results.
     */
    public function __call(string $method, array $parameters): never
    {
        throw new BadMethodCallException(
            "Method [{$method}] is not supported on pooled disks: an unmapped call could return "
            . 'a lazy result that outlives its borrow. Use withDriver(), withAdapter(), or withClient() '
            . 'for borrow-scoped raw access.',
        );
    }
}
