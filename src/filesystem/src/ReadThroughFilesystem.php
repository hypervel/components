<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use DateTimeInterface;
use Hypervel\Contracts\Filesystem\Cloud;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToReadFile;

class ReadThroughFilesystem extends FilesystemAdapter
{
    protected PathPrefixer $readThroughPrefixer;

    /**
     * Create a new read-through filesystem instance.
     *
     * Pooled disk decorators forward adapter methods that are not on the Cloud contract.
     */
    public function __construct(
        FilesystemOperator $driver,
        FlysystemAdapter $adapter,
        array $config,
        protected Cloud $primary,
        protected Cloud $fallback,
        string $prefix = '',
        protected ?ReadThroughFilesystemAdapter $readThroughAdapter = null,
    ) {
        parent::__construct($driver, $adapter, $config);

        $this->readThroughPrefixer = new PathPrefixer($prefix);
    }

    /**
     * Get the primary filesystem path, including both disks' prefixes.
     */
    public function path(string $path): string
    {
        return $this->primary->path($this->readThroughPrefixer->prefixPath($path));
    }

    /**
     * Get the URL for the file at the given path.
     */
    public function url(string $path): string
    {
        return $this->readerFor($path)->url($this->readThroughPrefixer->prefixPath($path));
    }

    /**
     * Get a temporary URL for the file at the given path.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        return isset($this->temporaryUrlCallback)
            ? ($this->temporaryUrlCallback)($path, $expiration, $options)
            : $this->readerFor($path)->temporaryUrl($this->readThroughPrefixer->prefixPath($path), $expiration, $options); // @phpstan-ignore method.notFound
    }

    /**
     * Determine if temporary upload URLs can be generated.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return isset($this->temporaryUploadUrlCallback) || $this->primary->providesTemporaryUploadUrls(); // @phpstan-ignore method.notFound
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiration, array $options = []): array|string
    {
        return isset($this->temporaryUploadUrlCallback)
            ? ($this->temporaryUploadUrlCallback)($path, $expiration, $options)
            : $this->primary->temporaryUploadUrl($this->readThroughPrefixer->prefixPath($path), $expiration, $options); // @phpstan-ignore method.notFound
    }

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return isset($this->temporaryUrlCallback)
            || $this->primary->providesTemporaryUrls() // @phpstan-ignore method.notFound
            || $this->fallback->providesTemporaryUrls(); // @phpstan-ignore method.notFound
    }

    /**
     * Read a byte range using native cloud requests when promotion is unnecessary.
     *
     * @return null|resource
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        [$start, $end] = $this->normalizeStreamRange($start, $end);

        if ($start === null && $end === null) {
            return $this->readStream($path);
        }

        try {
            $stream = $this->readThroughAdapter?->readStreamRange(
                $this->readThroughPrefixer->prefixPath($path),
                $start,
                $end,
            );
        } catch (UnableToReadFile $exception) {
            throw_if($this->throwsExceptions(), $exception);
            $this->report($exception);

            return null;
        }

        return $stream ?? parent::readStreamRange($path, $start, $end);
    }

    /**
     * Get the filesystem that contains the given path.
     */
    protected function readerFor(string $path): Cloud
    {
        return $this->primary->fileExists($this->readThroughPrefixer->prefixPath($path))
            ? $this->primary
            : $this->fallback;
    }
}
