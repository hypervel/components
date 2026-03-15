<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
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
                $app->make('translator.loader'),
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
        $this->app->singleton('translator.loader', function ($app) {
            $langPath = $app instanceof ApplicationContract
                ? $app->langPath()
                : BASE_PATH . DIRECTORY_SEPARATOR . 'lang';

            return new FileLoader(
                $app->make('files'),
                [
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                    $langPath,
                ]
            );
        });
    }
}
