<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @method static \Hypervel\Contracts\Filesystem\Filesystem drive(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Contracts\Filesystem\Filesystem disk(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Contracts\Filesystem\Cloud cloud()
 * @method static \Hypervel\Contracts\Filesystem\Filesystem build(array|string $config)
 * @method static \Hypervel\Contracts\Filesystem\Filesystem createLocalDriver(array $config, string $name = 'local')
 * @method static \Hypervel\Contracts\Filesystem\Filesystem createFtpDriver(array $config)
 * @method static \Hypervel\Contracts\Filesystem\Filesystem createSftpDriver(array $config)
 * @method static \Hypervel\Contracts\Filesystem\Cloud createS3Driver(array $config)
 * @method static \Hypervel\Contracts\Filesystem\Cloud createGcsDriver(array $config)
 * @method static \Hypervel\Contracts\Filesystem\Filesystem createScopedDriver(array $config)
 * @method static \Hypervel\Filesystem\FilesystemManager set(string $name, mixed $disk)
 * @method static string getDefaultDriver()
 * @method static string getDefaultCloudDriver()
 * @method static \Hypervel\Filesystem\FilesystemManager forgetDisk(array|string $disk)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\Filesystem\FilesystemManager extend(string $driver, \Closure $callback, bool $poolable = false)
 * @method static \Hypervel\Filesystem\FilesystemManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static \Hypervel\Filesystem\FilesystemManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\Filesystem\FilesystemManager addPoolable(string $driver)
 * @method static \Hypervel\Filesystem\FilesystemManager removePoolable(string $driver)
 * @method static array getPoolables()
 * @method static \Hypervel\Filesystem\FilesystemManager setPoolables(array $poolables)
 * @method static string path(string $path)
 * @method static bool exists(string $path)
 * @method static string|null get(string $path)
 * @method static null|resource readStream(string $path)
 * @method static null|resource readStreamRange(string $path, int|null $start, int|null $end)
 * @method static bool|string put(string $path, \Psr\Http\Message\StreamInterface|\Hypervel\Http\File|\Hypervel\Http\UploadedFile|string|resource $contents, mixed $options = [])
 * @method static string|false putFile(\Hypervel\Http\File|\Hypervel\Http\UploadedFile|string $path, \Hypervel\Http\File|\Hypervel\Http\UploadedFile|string|array|null $file = null, mixed $options = [])
 * @method static string|false putFileAs(\Hypervel\Http\File|\Hypervel\Http\UploadedFile|string $path, \Hypervel\Http\File|\Hypervel\Http\UploadedFile|string|array|null $file, string|array|null $name = null, mixed $options = [])
 * @method static bool writeStream(string $path, resource $resource, array $options = [])
 * @method static string getVisibility(string $path)
 * @method static bool setVisibility(string $path, string $visibility)
 * @method static bool prepend(string $path, string $data, string $separator = '\n')
 * @method static bool append(string $path, string $data, string $separator = '\n')
 * @method static bool delete(string|array $paths)
 * @method static bool copy(string $from, string $to)
 * @method static bool move(string $from, string $to)
 * @method static int size(string $path)
 * @method static string|false checksum(string $path, array $options = [])
 * @method static string|false mimeType(string $path)
 * @method static int lastModified(string $path)
 * @method static array files(string|null $directory = null, bool $recursive = false)
 * @method static array allFiles(string|null $directory = null)
 * @method static array directories(string|null $directory = null, bool $recursive = false)
 * @method static array allDirectories(string|null $directory = null)
 * @method static bool makeDirectory(string $path)
 * @method static bool deleteDirectory(string $directory)
 * @method static \Hypervel\Filesystem\FilesystemAdapter assertExists(array|string $path, string|null $content = null)
 * @method static \Hypervel\Filesystem\FilesystemAdapter assertCount(string $path, int $count, bool $recursive = false)
 * @method static \Hypervel\Filesystem\FilesystemAdapter assertMissing(array|string $path)
 * @method static \Hypervel\Filesystem\FilesystemAdapter assertDirectoryEmpty(string $path)
 * @method static bool missing(string $path)
 * @method static bool fileExists(string $path)
 * @method static bool fileMissing(string $path)
 * @method static bool directoryExists(string $path)
 * @method static bool directoryMissing(string $path)
 * @method static array|null json(string $path, int $flags = 0)
 * @method static \Hypervel\Http\Response response(string $path, string|null $name = null, array $headers = [], string|null $disposition = 'inline')
 * @method static \Hypervel\Http\Response serve(\Hypervel\Http\Request $request, string $path, string|null $name = null, array $headers = [])
 * @method static \Hypervel\Http\Response download(string $path, string|null $name = null, array $headers = [])
 * @method static string url(string $path)
 * @method static bool providesTemporaryUrls()
 * @method static bool providesTemporaryUploadUrls()
 * @method static string temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = [])
 * @method static array|string temporaryUploadUrl(string $path, \DateTimeInterface $expiration, array $options = [])
 * @method static \League\Flysystem\FilesystemOperator getDriver()
 * @method static \League\Flysystem\FilesystemAdapter getAdapter()
 * @method static array getConfig()
 * @method static void serveUsing(\Closure $callback)
 * @method static void buildTemporaryUrlsUsing(\Closure $callback)
 * @method static void buildTemporaryUploadUrlsUsing(\Closure $callback)
 * @method static \Hypervel\Filesystem\FilesystemAdapter|mixed when(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
 * @method static \Hypervel\Filesystem\FilesystemAdapter|mixed unless(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static bool has(string $location)
 * @method static string read(string $location)
 * @method static \League\Flysystem\DirectoryListing listContents(string $location, bool $deep = false)
 * @method static int fileSize(string $path)
 * @method static string visibility(string $path)
 * @method static void write(string $location, string $contents, array $config = [])
 * @method static void createDirectory(string $location, array $config = [])
 *
 * @see \Hypervel\Filesystem\FilesystemManager
 */
class Storage extends Facade
{
    /**
     * Replace the given disk with a local testing disk.
     *
     * @return \Hypervel\Contracts\Filesystem\Filesystem
     */
    public static function fake(UnitEnum|string|null $disk = null, array $config = [])
    {
        $root = self::getRootPath($disk = enum_value($disk) ?: static::$app['config']->get('filesystems.default'));

        if ($token = ParallelTesting::token()) {
            $root = "{$root}_test_{$token}";
        }

        (new Filesystem())->cleanDirectory($root);

        static::set($disk, $fake = static::createLocalDriver(
            self::buildDiskConfiguration($disk, $config, root: $root)
        ));

        /** @var FilesystemAdapter $fake */
        return tap($fake, function ($fake) {
            $fake->buildTemporaryUrlsUsing(function ($path, $expiration) {
                return URL::to($path . '?expiration=' . $expiration->getTimestamp());
            });

            $fake->buildTemporaryUploadUrlsUsing(function ($path, $expiration) {
                return ['url' => URL::to($path . '?expiration=' . $expiration->getTimestamp()), 'headers' => []];
            });
        });
    }

    /**
     * Replace the given disk with a persistent local testing disk.
     *
     * @return \Hypervel\Contracts\Filesystem\Filesystem
     */
    public static function persistentFake(UnitEnum|string|null $disk = null, array $config = [])
    {
        $disk = enum_value($disk) ?: static::$app['config']->get('filesystems.default');

        static::set($disk, $fake = static::createLocalDriver(
            self::buildDiskConfiguration($disk, $config, root: self::getRootPath($disk))
        ));

        return $fake;
    }

    /**
     * Get the root path of the given disk.
     */
    protected static function getRootPath(string $disk): string
    {
        return storage_path('framework/testing/disks/' . $disk);
    }

    /**
     * Assemble the configuration of the given disk.
     */
    protected static function buildDiskConfiguration(string $disk, array $config, string $root): array
    {
        $originalConfig = static::$app['config']["filesystems.disks.{$disk}"] ?? [];

        return array_merge(
            ['throw' => $originalConfig['throw'] ?? false],
            $config,
            ['root' => $root]
        );
    }

    protected static function getFacadeAccessor(): string
    {
        return 'filesystem';
    }
}
