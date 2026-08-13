<?php

declare(strict_types=1);

namespace Hypervel\Image;

use Hypervel\Contracts\Container\Container;
use Hypervel\Support\ServiceProvider;

class ImageServiceProvider extends ServiceProvider
{
    /**
     * Register Image services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/images.php',
            'images',
        );

        $this->app->singleton(
            'image',
            fn (Container $container): ImageManager => new ImageManager($container),
        );
    }

    /**
     * Bootstrap Image services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__) . '/config/images.php' => config_path('images.php'),
            ], 'image-config');
        }
    }
}
