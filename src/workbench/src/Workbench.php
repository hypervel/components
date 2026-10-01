<?php

declare(strict_types=1);

namespace Hypervel\Workbench;

use Hypervel\Container\Container;
use Hypervel\Foundation\Vite;
use Hypervel\Support\Arr;
use Hypervel\Support\HtmlString;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Workbench\Workbench as BaseWorkbench;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\workbench;
use function Hypervel\Testbench\workbench_path;

/**
 * @phpstan-import-type TWorkbenchConfig from Config
 */
class Workbench extends BaseWorkbench
{
    /**
     * The public directory, relative to the public path, that holds the page assets.
     */
    public const string BUILD_DIRECTORY = 'vendor/workbench/build';

    /**
     * Get the path to the application (Hypervel) folder.
     *
     * @no-named-arguments
     *
     * @param array<int, null|string>|string ...$path
     */
    public static function applicationPath(array|string $path = ''): string
    {
        /** @var array<int, null|string> $paths */
        $paths = Arr::wrap(\func_num_args() > 1 ? \func_get_args() : $path);

        return base_path(join_paths(...$paths));
    }

    /**
     * Get the path to the Hypervel application skeleton.
     *
     * @no-named-arguments
     *
     * @param array<int, mixed>|string ...$path
     *
     * @see Workbench::applicationPath()
     */
    public static function hypervelPath(array|string $path = ''): string
    {
        return static::applicationPath(...\func_get_args());
    }

    /**
     * Get the path to the package folder.
     *
     * @no-named-arguments
     *
     * @param array<int, null|string>|string ...$path
     */
    public static function packagePath(array|string $path = ''): string
    {
        /** @var array<int, null|string> $paths */
        $paths = Arr::wrap(\func_num_args() > 1 ? \func_get_args() : $path);

        return package_path(...$paths);
    }

    /**
     * Get the path to the workbench folder.
     *
     * @no-named-arguments
     *
     * @param array<int, null|string>|string ...$path
     */
    public static function path(array|string $path = ''): string
    {
        /** @var array<int, null|string> $paths */
        $paths = Arr::wrap(\func_num_args() > 1 ? \func_get_args() : $path);

        return workbench_path(...$paths);
    }

    /**
     * Get the available configuration.
     *
     * @return array<string, mixed>|mixed
     *
     * @phpstan-return ($key is null ? TWorkbenchConfig : mixed)
     */
    public static function config(?string $key = null): mixed
    {
        return ! \is_null($key)
            ? Arr::get(workbench(), $key)
            : workbench();
    }

    // REMOVED: stub(), swapFile() and stubFile() belong to the excluded Canvas generator presets.

    /**
     * Render the Vite tags for the Workbench page assets.
     *
     * The application's Vite instance is cloned, keeping its tag and URL
     * customizations while ignoring the application's own hot file and manifest.
     *
     * @param array<int, string>|string $entrypoints
     */
    public static function vite(array|string $entrypoints): HtmlString
    {
        $vite = clone Container::getInstance()->make(Vite::class);

        return $vite
            ->useHotFile(public_path('vendor/workbench/hot'))
            ->useManifestFilename('manifest.json')
            ->__invoke($entrypoints, static::BUILD_DIRECTORY);
    }
}
