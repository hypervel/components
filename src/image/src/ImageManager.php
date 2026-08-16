<?php

declare(strict_types=1);

namespace Hypervel\Image;

use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Image\Driver;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Drivers\GdDriver;
use Hypervel\Image\Drivers\ImagickDriver;
use Hypervel\Support\Manager;
use InvalidArgumentException;
use UnitEnum;

class ImageManager extends Manager
{
    /**
     * The registered transformation handlers.
     *
     * @var array<string, array<class-string<Transformation>, callable>>
     */
    protected array $transformationHandlers = [];

    /**
     * Create an image instance from raw bytes.
     */
    public function fromBytes(string $contents): Image
    {
        return new Image($contents);
    }

    /**
     * Create an image instance from a stream.
     *
     * @param resource $stream
     */
    public function fromStream(mixed $stream): Image
    {
        return new Image(function () use ($stream): string {
            $contents = stream_get_contents($stream);

            if ($contents === false || $contents === '') {
                throw new ImageException('Invalid stream image data.');
            }

            return $contents;
        });
    }

    /**
     * Create an image instance from a base64 encoded string.
     */
    public function fromBase64(string $base64): Image
    {
        return new Image(function () use ($base64): string {
            $contents = base64_decode($base64, true);

            if ($contents === false || $contents === '') {
                throw new ImageException('Invalid base64 image data.');
            }

            return $contents;
        });
    }

    /**
     * Create an image instance from a file path.
     */
    public function fromPath(string $path): Image
    {
        return new Image(
            fn (): string => $this->container->make(Filesystem::class)->get($path),
        );
    }

    /**
     * Create an image instance from a storage disk path.
     */
    public function fromStorage(string $path, UnitEnum|string|null $disk = null): Image
    {
        return new Image(
            fn (): string => $this->container->make(FilesystemFactory::class)->disk($disk)->get($path)
                ?? throw new ImageException("Unable to read image from path [{$path}]."),
        );
    }

    /**
     * Create an image instance from an uploaded file.
     */
    public function fromUpload(UploadedFile $file): Image
    {
        return new Image(fn (): string => $file->getContent(), $file);
    }

    /**
     * Create an image instance from a URL.
     */
    public function fromUrl(string $url): Image
    {
        return new Image(
            fn (): string => $this->container->make(HttpFactory::class)->get($url)->throw()->body(),
        );
    }

    /**
     * Create a new driver instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createDriver(string $driver): Driver
    {
        try {
            /** @var Driver $instance */
            $instance = parent::createDriver($driver);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException("Image driver [{$driver}] is not supported.", 0, $exception);
        }

        $this->applyTransformationHandlers($driver, $instance);

        return $instance;
    }

    /**
     * Create the GD image driver.
     */
    protected function createGdDriver(): GdDriver
    {
        return new GdDriver;
    }

    /**
     * Create the Imagick image driver.
     */
    protected function createImagickDriver(): ImagickDriver
    {
        return new ImagickDriver;
    }

    /**
     * Register a transformation handler for the given driver.
     *
     * Boot-only. The handler persists on a cached driver for the worker lifetime and affects every subsequent image processed by that driver.
     *
     * @param class-string<Transformation> $transformation
     */
    public function transformUsing(string $driver, string $transformation, callable $callback): static
    {
        $this->transformationHandlers[$driver][$transformation] = $callback;

        if (isset($this->drivers[$driver])) {
            /** @var Driver $instance */
            $instance = $this->drivers[$driver];

            $this->applyTransformationHandlers($driver, $instance);
        }

        return $this;
    }

    /**
     * Apply registered transformation handlers to the given driver instance.
     */
    protected function applyTransformationHandlers(string $driver, Driver $instance): void
    {
        foreach ($this->transformationHandlers[$driver] ?? [] as $transformation => $callback) {
            $instance->transformUsing($transformation, $callback);
        }
    }

    /**
     * Get the default image driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->string('images.default');
    }
}
