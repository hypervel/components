<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use DateTimeInterface;
use Hypervel\Support\Traits\Conditionable;
use RuntimeException;

class LocalFilesystemAdapter extends FilesystemAdapter
{
    use Conditionable;

    /**
     * The name of the filesystem disk.
     */
    protected ?string $disk = null;

    /**
     * Indicates if signed URLs should serve corresponding files.
     */
    protected bool $shouldServeSignedUrls = false;

    /**
     * Indicate if serving-route ownership was configured explicitly.
     */
    protected bool $servingRouteConfigured = false;

    /**
     * The configured disk which owns the serving route.
     */
    protected ?string $servingRouteDisk = null;

    /**
     * The path prefix relative to the serving-route owner.
     */
    protected string $servingRoutePrefix = '';

    /**
     * The Closure that should be used to resolve the URL generator.
     */
    protected ?Closure $urlGeneratorResolver = null;

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return $this->temporaryUrlCallback || (
            $this->shouldServeSignedUrls
            && $this->urlGeneratorResolver instanceof Closure
            && $this->servingRouteDisk() !== null
        );
    }

    /**
     * Determine if temporary upload URLs can be generated.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return $this->temporaryUploadUrlCallback || (
            $this->shouldServeSignedUrls
            && $this->urlGeneratorResolver instanceof Closure
            && $this->servingRouteDisk() !== null
        );
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @throws RuntimeException
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        if ($this->temporaryUrlCallback) {
            return ($this->temporaryUrlCallback)(
                $path,
                $expiration,
                $options
            );
        }

        if (! $this->providesTemporaryUrls()) {
            throw $this->unsupportedTemporaryUrlException();
        }

        $url = call_user_func($this->urlGeneratorResolver);

        return $url->to($url->temporarySignedRoute(
            'storage.' . $this->servingRouteDisk(),
            $expiration,
            ['path' => $this->servingRoutePath($path)],
            absolute: false
        ));
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     *
     * @throws RuntimeException
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiration, array $options = []): array|string
    {
        if ($this->temporaryUploadUrlCallback) {
            return ($this->temporaryUploadUrlCallback)(
                $path,
                $expiration,
                $options
            );
        }

        if (! $this->providesTemporaryUploadUrls()) {
            throw $this->unsupportedTemporaryUrlException(upload: true);
        }

        $url = call_user_func($this->urlGeneratorResolver);

        return [
            'url' => $url->to($url->temporarySignedRoute(
                'storage.' . $this->servingRouteDisk() . '.upload',
                $expiration,
                ['path' => $this->servingRoutePath($path), 'upload' => true],
                absolute: false
            )),
            'headers' => [],
        ];
    }

    /**
     * Create the exception for an unsupported temporary URL operation.
     */
    private function unsupportedTemporaryUrlException(bool $upload = false): RuntimeException
    {
        if ($this->shouldServeSignedUrls && $this->servingRouteConfigured && $this->servingRouteDisk === null) {
            return new RuntimeException('This disk does not have a registered file-serving route.');
        }

        return new RuntimeException(
            $upload
                ? 'This driver does not support creating temporary upload URLs.'
                : 'This driver does not support creating temporary URLs.',
        );
    }

    /**
     * Specify the name of the disk the adapter is managing.
     */
    public function diskName(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Specify the registered route which serves this disk.
     *
     * Boot-only. The route metadata persists on the cached adapter for the
     * worker lifetime and affects every subsequent signed URL request.
     */
    public function servingRoute(?string $disk, string $prefix = ''): static
    {
        $this->servingRouteConfigured = true;
        $this->servingRouteDisk = $disk;
        $this->servingRoutePrefix = trim(str_replace('\\', '/', $prefix), '/');

        return $this;
    }

    /**
     * Indicate that signed URLs should serve the corresponding files.
     *
     * Boot-only. The flag and resolver persist on the cached local disk adapter
     * for the worker lifetime and apply to every subsequent signed URL request
     * for that disk.
     */
    public function shouldServeSignedUrls(bool $serve = true, ?Closure $urlGeneratorResolver = null): static
    {
        $this->shouldServeSignedUrls = $serve;
        $this->urlGeneratorResolver = $urlGeneratorResolver;

        return $this;
    }

    /**
     * Get the registered serving-route disk.
     */
    protected function servingRouteDisk(): ?string
    {
        return $this->servingRouteConfigured ? $this->servingRouteDisk : $this->disk;
    }

    /**
     * Prefix a path for the registered serving route.
     */
    protected function servingRoutePath(string $path): string
    {
        return $this->servingRoutePrefix === ''
            ? $path
            : $this->servingRoutePrefix . '/' . ltrim($path, '/');
    }
}
