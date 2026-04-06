<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;

class FilesystemServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the filesystem.
     */
    public function boot(): void
    {
        $this->serveFiles();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerNativeFilesystem();
        $this->registerFlysystem();
    }

    /**
     * Register the native filesystem implementation.
     */
    protected function registerNativeFilesystem(): void
    {
        $this->app->singleton('files', fn () => new Filesystem);
    }

    /**
     * Register the driver based filesystem.
     */
    protected function registerFlysystem(): void
    {
        $this->registerManager();

        $this->app->singleton('filesystem.disk', fn ($app) => $app['filesystem']->disk($this->getDefaultDriver()));

        $this->app->singleton('filesystem.cloud', fn ($app) => $app['filesystem']->disk($this->getCloudDriver()));
    }

    /**
     * Register the filesystem manager.
     */
    protected function registerManager(): void
    {
        $this->app->singleton('filesystem', fn ($app) => new FilesystemManager($app));
    }

    /**
     * Register protected file serving.
     *
     * @throws InvalidArgumentException
     */
    protected function serveFiles(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $served = [];

        foreach ($this->app['config']['filesystems.disks'] ?? [] as $disk => $config) {
            if (! $this->shouldServeFiles($config)) {
                continue;
            }

            $this->app->booted(function ($app) use ($disk, $config, &$served) {
                $uri = isset($config['url'])
                    ? rtrim(parse_url($config['url'], PHP_URL_PATH) ?? '', '/')
                    : '/storage';

                if (isset($served[$uri])) {
                    throw new InvalidArgumentException(
                        "The [{$disk}] disk conflicts with the [{$served[$uri]}] disk at [{$uri}]. Each served disk must have a unique URL."
                    );
                }

                $served[$uri] = $disk;

                $isProduction = $app->isProduction();

                Route::get($uri . '/{path}', function (Request $request, string $path) use ($disk, $config, $isProduction) {
                    return (new ServeFile(
                        $disk,
                        $config,
                        $isProduction
                    ))($request, $path);
                })->where('path', '.*')->name('storage.' . $disk);

                Route::put($uri . '/{path}', function (Request $request, string $path) use ($disk, $config, $isProduction) {
                    return (new ReceiveFile(
                        $disk,
                        $config,
                        $isProduction
                    ))($request, $path);
                })->where('path', '.*')->name('storage.' . $disk . '.upload');
            });
        }
    }

    /**
     * Determine if the disk is serveable.
     */
    protected function shouldServeFiles(array $config): bool
    {
        return $config['driver'] === 'local' && ($config['serve'] ?? false);
    }

    /**
     * Get the default file driver.
     */
    protected function getDefaultDriver(): string
    {
        return $this->app['config']['filesystems.default'];
    }

    /**
     * Get the default cloud based file driver.
     */
    protected function getCloudDriver(): string
    {
        return $this->app['config']['filesystems.cloud'];
    }
}
