<?php

declare(strict_types=1);

namespace Hypervel\Translation;

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
            $config = $app->make('config');

            $trans = new Translator(
                $app->make('translation.loader'),
                $config->get('app.locale', 'en')
            );

            $trans->setFallback($config->get('app.fallback_locale', 'en'));

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
                $app->make('files'),
                [
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                    $app->langPath(),
                ]
            );
        });
    }
}
