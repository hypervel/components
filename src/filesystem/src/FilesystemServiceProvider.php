<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Support\ServiceProvider;

class FilesystemServiceProvider extends ServiceProvider
{
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
        $this->app->singleton('files', fn () => new Filesystem());
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
