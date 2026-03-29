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
            $loader = $app['translation.loader'];

            $trans = new Translator(
                $loader,
                $app['config']->get('app.locale', 'en')
            );

            $trans->setFallback($app['config']->get('app.fallback_locale', 'en'));

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
                $app['files'],
                [
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                    $app->langPath(),
                ]
            );
        });
    }
}
