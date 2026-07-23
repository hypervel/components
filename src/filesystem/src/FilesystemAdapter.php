<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Filesystem\Cloud as CloudFilesystemContract;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Http\File;
use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathPrefixer;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;
use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use ReflectionFunction;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @mixin \League\Flysystem\FilesystemOperator
 */
class FilesystemAdapter implements CloudFilesystemContract
{
    use Conditionable;
    use Macroable {
        __call as macroCall;
    }

    /**
     * The filesystem configuration.
     */
    protected array $config;

    /**
     * The Flysystem PathPrefixer instance.
     */
    protected PathPrefixer $prefixer;

    /**
     * The Flysystem path normalizer instance.
     */
    protected PathNormalizer $pathNormalizer;

    /**
     * The file server callback.
     */
    protected ?Closure $serveCallback = null;

    /**
     * The temporary URL builder callback.
     */
    protected ?Closure $temporaryUrlCallback = null;

    /**
     * The temporary upload URL builder callback.
     */
    protected ?Closure $temporaryUploadUrlCallback = null;

    /**
     * Create a new filesystem adapter instance.
     */
    public function __construct(
        protected FilesystemOperator $driver,
        protected FlysystemAdapter $adapter,
        array $config = []
    ) {
        $this->config = $config;
        $this->pathNormalizer = new WhitespacePathNormalizer;
        $separator = $config['directory_separator'] ?? DIRECTORY_SEPARATOR;

        $this->prefixer = new PathPrefixer($config['root'] ?? '', $separator);

        if (isset($config['prefix'])) {
            $this->prefixer = new PathPrefixer($this->prefixer->prefixPath($config['prefix']), $separator);
        }
    }

    /**
     * Assert that the given file or directory exists.
     */
    public function assertExists(array|string $path, ?string $content = null): static
    {
        clearstatcache();

        $paths = Arr::wrap($path);

        foreach ($paths as $path) {
            PHPUnit::assertTrue(
                $this->exists($path),
                "Unable to find a file or directory at path [{$path}]."
            );

            if (! is_null($content)) {
                $actual = $this->get($path);

                PHPUnit::assertSame(
                    $content,
                    $actual,
                    "File or directory [{$path}] was found, but content [{$actual}] does not match [{$content}]."
                );
            }
        }

        return $this;
    }

    /**
     * Assert that the number of files in path equals the expected count.
     */
    public function assertCount(string $path, int $count, bool $recursive = false): static
    {
        clearstatcache();

        $actual = count($this->files($path, $recursive));

        PHPUnit::assertEquals(
            $count,
            $actual,
            "Expected [{$count}] files at [{$path}], but found [{$actual}]."
        );

        return $this;
    }

    /**
     * Assert that the given file or directory does not exist.
     */
    public function assertMissing(array|string $path): static
    {
        clearstatcache();

        $paths = Arr::wrap($path);

        foreach ($paths as $path) {
            PHPUnit::assertFalse(
                $this->exists($path),
                "Found unexpected file or directory at path [{$path}]."
            );
        }

        return $this;
    }

    /**
     * Assert that the given directory is empty.
     */
    public function assertDirectoryEmpty(string $path): static
    {
        PHPUnit::assertEmpty(
            $this->allFiles($path),
            "Directory [{$path}] is not empty."
        );

        return $this;
    }

    /**
     * Assert that the disk contains no files.
     */
    public function assertEmpty(): static
    {
        PHPUnit::assertEmpty($this->allFiles(), 'Disk is not empty.');

        return $this;
    }

    /**
     * Determine if a file or directory exists.
     */
    public function exists(string $path): bool
    {
        return $this->driver->has($path);
    }

    /**
     * Determine if a file or directory is missing.
     */
    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    /**
     * Determine if a file exists.
     */
    public function fileExists(string $path): bool
    {
        return $this->driver->fileExists($path);
    }

    /**
     * Determine if a file is missing.
     */
    public function fileMissing(string $path): bool
    {
        return ! $this->fileExists($path);
    }

    /**
     * Determine if a directory exists.
     */
    public function directoryExists(string $path): bool
    {
        return $this->driver->directoryExists($path);
    }

    /**
     * Determine if a directory is missing.
     */
    public function directoryMissing(string $path): bool
    {
        return ! $this->directoryExists($path);
    }

    /**
     * Get the full path to the file that exists at the given relative path.
     */
    public function path(string $path): string
    {
        return $this->prefixer->prefixPath($this->pathNormalizer->normalizePath($path));
    }

    /**
     * Get the contents of a file.
     */
    public function get(string $path): ?string
    {
        try {
            return $this->driver->read($path);
        } catch (UnableToReadFile $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);
        }

        return null;
    }

    /**
     * Get the contents of a file as decoded JSON.
     */
    public function json(string $path, int $flags = 0): array|bool|float|int|string|null
    {
        $content = $this->get($path);

        return is_null($content) ? null : json_decode($content, true, 512, $flags);
    }

    /**
     * Create a streamed response for a given file.
     */
    public function response(
        string $path,
        ?string $name = null,
        array $headers = [],
        string $disposition = 'inline',
    ): StreamedResponse {
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
        return isset($this->serveCallback)
            ? call_user_func($this->serveCallback, $request, $path, $headers)
            : $this->buildFileResponse($request, $path, $name, $headers, 'inline');
    }

    /**
     * Create a streamed download response for a given file.
     */
    public function download(string $path, ?string $name = null, array $headers = []): StreamedResponse
    {
        return $this->response($path, $name, $headers, 'attachment');
    }

    /**
     * Build a file response using the supplied request context.
     */
    protected function buildFileResponse(
        Request $request,
        string $path,
        ?string $name,
        array $headers,
        string $disposition,
    ): StreamedResponse {
        $container = Container::getInstance();

        return $container->make(FileResponseBuilder::class)->build(
            $request,
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
     * Write the contents of a file.
     *
     * @param File|resource|StreamInterface|string|UploadedFile $contents
     */
    public function put(string $path, mixed $contents, mixed $options = []): bool|string
    {
        $options = is_string($options)
            ? ['visibility' => $options]
            : (array) $options;

        // If the given contents is actually a file or uploaded file instance than we will
        // automatically store the file using a stream. This provides a convenient path
        // for the developer to store streams without managing them manually in code.
        if ($contents instanceof File
            || $contents instanceof UploadedFile) {
            return $this->putFile($path, $contents, $options);
        }

        try {
            if ($contents instanceof StreamInterface) {
                $this->driver->writeStream($path, $contents->detach(), $options);

                return true;
            }

            is_resource($contents)
                ? $this->driver->writeStream($path, $contents, $options)
                : $this->driver->write($path, $contents, $options);
        } catch (UnableToWriteFile|UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Store the uploaded file on the disk.
     */
    public function putFile(string|File|UploadedFile $path, array|string|File|UploadedFile|null $file = null, mixed $options = []): false|string
    {
        if (is_null($file) || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        $file = is_string($file) ? new File($file) : $file;

        return $this->putFileAs($path, $file, $file->hashName(), $options);
    }

    /**
     * Store the uploaded file on the disk with a given name.
     */
    public function putFileAs(string|File|UploadedFile $path, array|string|File|UploadedFile|null $file, array|string|null $name = null, mixed $options = []): false|string
    {
        if (is_null($name) || is_array($name)) {
            [$path, $file, $name, $options] = ['', $path, $file, $name ?? []];
        }

        $path = trim($path . '/' . $name, '/');
        $source = is_string($file) ? $file : $file->getRealPath();
        $stream = $source === false ? false : @fopen($source, 'r');

        if ($stream === false) {
            $exception = UnableToWriteFile::atLocation($path, 'Unable to open the source file.');

            throw_if($this->throwsExceptions(), $exception);

            $this->report($exception);

            return false;
        }

        // Next, we will format the path of the file and store the file using a stream since
        // they provide better performance than alternatives. Once we write the file this
        // stream will get closed automatically by us so the developer doesn't have to.
        try {
            $result = $this->put($path, $stream, $options);
        } finally {
            @fclose($stream);
        }

        return $result ? $path : false;
    }

    /**
     * Get the visibility for the given path.
     */
    public function getVisibility(string $path): string
    {
        if ($this->driver->visibility($path) === Visibility::PUBLIC) {
            return FilesystemContract::VISIBILITY_PUBLIC;
        }

        return FilesystemContract::VISIBILITY_PRIVATE;
    }

    /**
     * Set the visibility for the given path.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        try {
            $this->driver->setVisibility($path, $this->parseVisibility($visibility));
        } catch (UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Prepend to a file.
     */
    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        if ($this->fileExists($path)) {
            $contents = $this->get($path);

            if ($contents === null) {
                return false;
            }

            return $this->put($path, $data . $separator . $contents);
        }

        return $this->put($path, $data);
    }

    /**
     * Append to a file.
     */
    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        if ($this->fileExists($path)) {
            $contents = $this->get($path);

            if ($contents === null) {
                return false;
            }

            return $this->put($path, $contents . $separator . $data);
        }

        return $this->put($path, $data);
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        $success = true;

        foreach ($paths as $path) {
            try {
                $this->driver->delete($path);
            } catch (UnableToDeleteFile $e) {
                throw_if($this->throwsExceptions(), $e);

                $this->report($e);

                $success = false;
            }
        }

        return $success;
    }

    /**
     * Copy a file to a new location.
     */
    public function copy(string $from, string $to): bool
    {
        try {
            $this->driver->copy($from, $to);
        } catch (UnableToCopyFile $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Move a file to a new location.
     */
    public function move(string $from, string $to): bool
    {
        try {
            $this->driver->move($from, $to);
        } catch (UnableToMoveFile $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Get the file size of a given file.
     */
    public function size(string $path): int
    {
        return $this->driver->fileSize($path);
    }

    /**
     * Get the checksum for a file.
     *
     * @throws UnableToProvideChecksum
     */
    public function checksum(string $path, array $options = []): false|string
    {
        try {
            return $this->driver->checksum($path, $options);
        } catch (UnableToProvideChecksum $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }
    }

    /**
     * Get the mime-type of a given file.
     */
    public function mimeType(string $path): false|string
    {
        try {
            return $this->driver->mimeType($path);
        } catch (UnableToRetrieveMetadata $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);
        }

        return false;
    }

    /**
     * Get the file's last modification time.
     */
    public function lastModified(string $path): int
    {
        return $this->driver->lastModified($path);
    }

    /**
     * Get a resource to read the file.
     *
     * @return null|resource the path resource or null on failure
     */
    public function readStream(string $path): mixed
    {
        try {
            return $this->driver->readStream($path);
        } catch (UnableToReadFile $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);
        }

        return null;
    }

    /**
     * Get a resource to read the partial file.
     *
     * @return null|resource the path resource or null on failure
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        [$start, $end] = $this->normalizeStreamRange($start, $end);

        if ($start === null && $end === null) {
            return $this->readStream($path);
        }

        if ($start === null) {
            $start = max(0, $this->size($path) - $end);
        }

        $stream = $this->readStream($path);

        if (! is_resource($stream) || $start === 0) {
            return $stream;
        }

        $metadata = stream_get_meta_data($stream);

        if ($metadata['seekable']) {
            if (fseek($stream, $start) === 0) {
                return $stream;
            }

            if (! rewind($stream)) {
                fclose($stream);

                throw UnableToReadFile::fromLocation($path, 'Unable to position the stream at the requested range.');
            }
        }

        $remaining = $start;

        while ($remaining > 0) {
            $content = fread($stream, min(8192, $remaining));

            if ($content === false || ($content === '' && feof($stream))) {
                fclose($stream);

                throw UnableToReadFile::fromLocation($path, 'Unable to position the stream at the requested range.');
            }

            if ($content === '') {
                fclose($stream);

                throw UnableToReadFile::fromLocation($path, 'The stream returned no data while positioning at the requested range.');
            }

            $remaining -= strlen($content);
        }

        return $stream;
    }

    /**
     * Normalize and validate byte-range stream arguments.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function normalizeStreamRange(?int $start, ?int $end): array
    {
        if (($start !== null && $start < 0)
            || ($end !== null && $end < 0)
            || ($start === null && $end === 0)
            || ($start !== null && $end !== null && $start > $end)
        ) {
            throw new InvalidArgumentException(
                'A stream range must be a non-negative bounded range (start, end), '
                . 'an open-ended range (start, null), a positive suffix length (null, end), '
                . 'or no range (null, null).',
            );
        }

        return [$start, $end];
    }

    /**
     * Write a new file using a stream.
     *
     * @param resource $resource
     */
    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        try {
            $this->driver->writeStream($path, $resource, $options);
        } catch (UnableToWriteFile|UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Get the URL for the file at the given path.
     *
     * @throws RuntimeException
     */
    public function url(string $path): string
    {
        if (isset($this->config['prefix'])) {
            $path = $this->concatPathToUrl($this->config['prefix'], $path);
        }

        $adapter = $this->adapter;

        if (method_exists($adapter, 'getUrl')) {
            return $adapter->getUrl($path);
        }
        if (method_exists($this->driver, 'getUrl')) {
            return $this->driver->getUrl($path);
        }
        if ($adapter instanceof FtpAdapter || $adapter instanceof SftpAdapter) { // @phpstan-ignore class.notFound, class.notFound
            return $this->getFtpUrl($path);
        }
        if ($adapter instanceof LocalAdapter) {
            return $this->getLocalUrl($path);
        }
        throw new RuntimeException('This driver does not support retrieving URLs.');
    }

    /**
     * Get the URL for the file at the given path.
     */
    protected function getFtpUrl(string $path): string
    {
        return isset($this->config['url'])
            ? $this->concatPathToUrl($this->config['url'], $path)
            : $path;
    }

    /**
     * Get the URL for the file at the given path.
     */
    protected function getLocalUrl(string $path): string
    {
        // If an explicit base URL has been set on the disk configuration then we will use
        // it as the base URL instead of the default path. This allows the developer to
        // have full control over the base path for this filesystem's generated URLs.
        if (isset($this->config['url'])) {
            return $this->concatPathToUrl($this->config['url'], $path);
        }

        $path = '/storage/' . $path;

        // If the path contains "storage/public", it probably means the developer is using
        // the default disk to generate the path instead of the "public" disk like they
        // are really supposed to use. We will remove the public from this path here.
        if (str_contains($path, '/storage/public/')) {
            return Str::replaceFirst('/public/', '/', $path);
        }

        return $path;
    }

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return method_exists($this->adapter, 'getTemporaryUrl') || isset($this->temporaryUrlCallback);
    }

    /**
     * Determine if temporary upload URLs can be generated.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return method_exists($this->adapter, 'temporaryUploadUrl') || isset($this->temporaryUploadUrlCallback);
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @throws RuntimeException
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        if (method_exists($this->adapter, 'getTemporaryUrl')) {
            return $this->adapter->getTemporaryUrl($path, $expiration, $options);
        }

        if ($this->temporaryUrlCallback) {
            return ($this->temporaryUrlCallback)(
                $path,
                $expiration,
                $options
            );
        }

        throw new RuntimeException('This driver does not support creating temporary URLs.');
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     *
     * @throws RuntimeException
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiration, array $options = []): array|string
    {
        if (method_exists($this->adapter, 'temporaryUploadUrl')) {
            return $this->adapter->temporaryUploadUrl($path, $expiration, $options);
        }

        if ($this->temporaryUploadUrlCallback) {
            return ($this->temporaryUploadUrlCallback)(
                $path,
                $expiration,
                $options
            );
        }

        throw new RuntimeException('This driver does not support creating temporary upload URLs.');
    }

    /**
     * Concatenate a path to a URL.
     */
    protected function concatPathToUrl(string $url, string $path): string
    {
        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Replace the scheme, host and port of the given UriInterface with values from the given URL.
     */
    protected function replaceBaseUrl(UriInterface $uri, string $url): UriInterface
    {
        $parsed = parse_url($url);

        return $uri
            ->withScheme($parsed['scheme'])
            ->withHost($parsed['host'])
            ->withPort($parsed['port'] ?? null);
    }

    /**
     * Get an array of all files in a directory.
     */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->driver->listContents($directory ?? '', $recursive)
            ->filter(function (StorageAttributes $attributes) {
                return $attributes->isFile();
            })
            ->sortByPath()
            ->map(function (StorageAttributes $attributes) {
                return $attributes->path();
            })
            ->toArray();
    }

    /**
     * Get all of the files from the given directory (recursive).
     */
    public function allFiles(?string $directory = null): array
    {
        return $this->files($directory, true);
    }

    /**
     * Get all of the directories within a given directory.
     */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->driver->listContents($directory ?? '', $recursive)
            ->filter(function (StorageAttributes $attributes) {
                return $attributes->isDir();
            })
            ->map(function (StorageAttributes $attributes) {
                return $attributes->path();
            })
            ->toArray();
    }

    /**
     * Get all the directories within a given directory (recursive).
     */
    public function allDirectories(?string $directory = null): array
    {
        return $this->directories($directory, true);
    }

    /**
     * Create a directory.
     */
    public function makeDirectory(string $path): bool
    {
        try {
            $this->driver->createDirectory($path);
        } catch (UnableToCreateDirectory|UnableToSetVisibility $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory(string $directory): bool
    {
        try {
            $this->driver->deleteDirectory($directory);
        } catch (UnableToDeleteDirectory $e) {
            throw_if($this->throwsExceptions(), $e);

            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Get the Flysystem driver.
     */
    public function getDriver(): FilesystemOperator
    {
        return $this->driver;
    }

    /**
     * Get the Flysystem adapter.
     */
    public function getAdapter(): FlysystemAdapter
    {
        return $this->adapter;
    }

    /**
     * Get the configuration values.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Parse the given visibility value.
     *
     * @throws InvalidArgumentException
     */
    protected function parseVisibility(?string $visibility): ?string
    {
        if (is_null($visibility)) {
            return null;
        }

        return match ($visibility) {
            FilesystemContract::VISIBILITY_PUBLIC => Visibility::PUBLIC,
            FilesystemContract::VISIBILITY_PRIVATE => Visibility::PRIVATE,
            default => throw new InvalidArgumentException("Unknown visibility: {$visibility}."),
        };
    }

    /**
     * Define a custom callback that generates file download responses.
     *
     * Boot-only. The callback persists on the cached disk adapter for the
     * worker lifetime and runs on every subsequent serve() call for that disk.
     */
    public function serveUsing(?Closure $callback): void
    {
        $this->serveCallback = $callback;
    }

    /**
     * Define a custom temporary URL builder callback.
     *
     * Boot-only. The callback persists on the cached disk adapter for the
     * worker lifetime and runs on every subsequent temporary URL generation for
     * that disk.
     */
    public function buildTemporaryUrlsUsing(?Closure $callback): void
    {
        $this->temporaryUrlCallback = $this->prepareTemporaryUrlCallback($callback);
    }

    /**
     * Define a custom temporary upload URL builder callback.
     *
     * Boot-only. The callback persists on the cached disk adapter for the
     * worker lifetime and runs on every subsequent temporary upload URL
     * generation for that disk.
     */
    public function buildTemporaryUploadUrlsUsing(?Closure $callback): void
    {
        $this->temporaryUploadUrlCallback = $this->prepareTemporaryUrlCallback($callback);
    }

    /**
     * Prepare a temporary URL callback for direct invocation.
     */
    protected function prepareTemporaryUrlCallback(?Closure $callback): ?Closure
    {
        if ($callback === null) {
            return null;
        }

        $reflection = new ReflectionFunction($callback);

        if ($reflection->isStatic() || ! $reflection->isAnonymous()) {
            return $callback;
        }

        return $callback->bindTo($this, static::class)
            ?? throw new RuntimeException('Unable to bind the temporary URL callback to the filesystem adapter.');
    }

    /**
     * Determine if Flysystem exceptions should be thrown.
     */
    protected function throwsExceptions(): bool
    {
        return (bool) ($this->config['throw'] ?? false);
    }

    /**
     * Report the exception.
     *
     * @throws Throwable
     */
    protected function report(Throwable $exception): void
    {
        if ($this->shouldReport() && Container::getInstance()->bound(ExceptionHandler::class)) {
            Container::getInstance()->make(ExceptionHandler::class)->report($exception);
        }
    }

    /**
     * Determine if Flysystem exceptions should be reported.
     */
    protected function shouldReport(): bool
    {
        return (bool) ($this->config['report'] ?? false);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }

    /**
     * Pass dynamic methods call onto Flysystem.
     *
     * @return mixed
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->driver->{$method}(...$parameters);
    }
}
