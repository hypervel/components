<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Translation\Loader;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerLoader();

        $this->app->singleton('translator', function ($app) {
            $config = $app->make(ConfigRepository::class);
            $loader = $app->make(Loader::class);

            $trans = new Translator(
                $loader,
                $config->string('app.locale')
            );

            $trans->setFallback($config->string('app.fallback_locale'));

            return $trans;
        });
    }

    /**
     * Register the translation line loader.
     */
    protected function registerLoader(): void
    {
        $this->app->singleton('translation.loader', function ($app) {
            return new FileLoader(
                $app->make(Filesystem::class),
                [
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                    $app->langPath(),
                ]
            );
        });
    }
}
