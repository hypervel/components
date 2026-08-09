<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Closure;
use DateTimeInterface;
use Hypervel\Filesystem\FilesystemAdapter;

trait FilesystemAdapterDecorator
{
    use CloudFilesystemDecorator;

    /**
     * Assert that the given file or directory exists.
     */
    public function assertExists(array|string $path, ?string $content = null): static
    {
        [$description, $data] = $this->getDescriptionAndDataForPathOrPaths($path);

        // Wrapped fluent assertions return the inner adapter; return this decorator
        // instead so a following chained operation remains instrumented.
        $this->withSentry(__FUNCTION__, func_get_args(), $description, $data);

        return $this;
    }

    /**
     * Assert that the given file or directory does not exist.
     */
    public function assertMissing(array|string $path): static
    {
        [$description, $data] = $this->getDescriptionAndDataForPathOrPaths($path);

        $this->withSentry(__FUNCTION__, func_get_args(), $description, $data);

        return $this;
    }

    /**
     * Assert that the given directory is empty.
     */
    public function assertDirectoryEmpty(string $path): static
    {
        $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));

        return $this;
    }

    /**
     * Determine if a file exists.
     */
    public function fileExists(string $path): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Determine if a directory exists.
     */
    public function directoryExists(string $path): bool
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Get the checksum for a file.
     */
    public function checksum(string $path, array $options = []): false|string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path', 'options'));
    }

    /**
     * Get the mime-type of a given file.
     */
    public function mimeType(string $path): false|string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return $this->wrappedAdapter()->providesTemporaryUrls();
    }

    /**
     * Determine if temporary upload URLs can be generated.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return $this->wrappedAdapter()->providesTemporaryUploadUrls();
    }

    /**
     * Get a temporary URL for the file at the given path.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path', 'expiration', 'options'));
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiration, array $options = []): array|string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path', 'expiration', 'options'));
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
        $this->wrappedAdapter()->buildTemporaryUrlsUsing($callback);
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
        $this->wrappedAdapter()->buildTemporaryUploadUrlsUsing($callback);
    }

    /**
     * Get the wrapped filesystem adapter.
     */
    private function wrappedAdapter(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $filesystem */
        $filesystem = $this->filesystem;

        return $filesystem;
    }
}
