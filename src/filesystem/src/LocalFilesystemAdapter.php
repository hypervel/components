<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use DateTimeInterface;
use Hyperf\Conditionable\Conditionable;
use RuntimeException;

class LocalFilesystemAdapter extends FilesystemAdapter
{
    use Conditionable;

    /**
     * The name of the filesystem disk.
     */
    protected string $disk;

    /**
     * Indicates if signed URLs should serve corresponding files.
     */
    protected bool $shouldServeSignedUrls = false;

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
            $this->shouldServeSignedUrls && $this->urlGeneratorResolver instanceof Closure
        );
    }

    /**
     * Get a temporary URL for the file at the given path.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        if ($this->temporaryUrlCallback) {
            return $this->temporaryUrlCallback->bindTo($this, static::class)(
                $path,
                $expiration,
                $options
            );
        }

        if (! $this->providesTemporaryUrls()) {
            throw new RuntimeException('This driver does not support creating temporary URLs.');
        }

        $url = call_user_func($this->urlGeneratorResolver);

        return $url->to($url->temporarySignedRoute(
            'storage.' . $this->disk,
            $expiration,
            ['path' => $path],
            absolute: false
        ));
    }

    /**
     * Specify the name of the disk the adapter is managing.
     *
     * @return $this
     */
    public function diskName(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Indicate that signed URLs should serve the corresponding files.
     *
     * @return $this
     */
    public function shouldServeSignedUrls(bool $serve = true, ?Closure $urlGeneratorResolver = null): static
    {
        $this->shouldServeSignedUrls = $serve;
        $this->urlGeneratorResolver = $urlGeneratorResolver;

        return $this;
    }
}
