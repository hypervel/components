<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Contracts\Translation\Loader;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider implements ReloadsConfiguration
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
     * Reload the worker configuration owned by the provider.
     *
     * Boot-only. Calling this while requests are running mutates shared worker
     * state while concurrent coroutines may still use the previous configuration.
     */
    public function reloadConfiguration(): void
    {
        if (! $this->app->resolved('translator')) {
            return;
        }

        $translator = $this->app->make('translator');

        if (! $translator instanceof Translator) {
            return;
        }

        $config = $this->app->make(ConfigRepository::class);

        $translator->setBaseLocale($config->string('app.locale'));
        $translator->setFallback($config->string('app.fallback_locale'));
        $translator->forgetLoadedGroups();
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
