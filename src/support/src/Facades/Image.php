<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static mixed driver(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Image\ImageManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Image\ImageManager forgetDrivers()
 * @method static \Hypervel\Image\Image fromBase64(string $base64)
 * @method static \Hypervel\Image\Image fromBytes(string $contents)
 * @method static \Hypervel\Image\Image fromPath(string $path)
 * @method static \Hypervel\Image\Image fromStorage(string $path, \UnitEnum|string|null $disk = null)
 * @method static \Hypervel\Image\Image fromStream(resource $stream)
 * @method static \Hypervel\Image\Image fromUpload(\Hypervel\Http\UploadedFile $file)
 * @method static \Hypervel\Image\Image fromUrl(string $url)
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static string getDefaultDriver()
 * @method static array getDrivers()
 * @method static \Hypervel\Image\ImageManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Image\ImageManager transformUsing(string $driver, string $transformation, callable $callback)
 *
 * @see \Hypervel\Image\ImageManager
 */
class Image extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'image';
    }
}
