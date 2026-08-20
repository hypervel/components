<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class MailServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerIlluminateMailer();
        $this->registerMarkdownRenderer();
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use clears shared mailers and Markdown settings
     * while concurrent coroutines may still be using the previous objects.
     */
    public function reloadConfiguration(): void
    {
        if ($this->app->resolved('mail.manager')) {
            $this->app->make('mail.manager')->forgetMailers();
        }

        $this->app->forgetInstance(Markdown::class);
    }

    /**
     * Register the mailer instance.
     *
     * The method name is retained for compatibility with Laravel's protected extension point.
     */
    protected function registerIlluminateMailer(): void
    {
        $this->app->singleton('mail.manager', fn ($app) => new MailManager($app));

        // bind() instead of singleton() because MailManager exposes purge(),
        // forgetMailers(), and setDefaultDriver() which mutate internal state.
        // Each resolution re-asks the manager, which returns its own cached
        // mailer instance — one hash lookup, no performance penalty.
        $this->app->bind('mailer', fn ($app) => $app->make('mail.manager')->mailer());
    }

    /**
     * Register the Markdown renderer instance.
     */
    protected function registerMarkdownRenderer(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/mail'),
            ], 'hypervel-mail');
        }

        $this->app->singleton(Markdown::class, function ($app) {
            return new Markdown(
                $app->make('view'),
                $app->make('config')->array('mail.markdown', []),
            );
        });
    }
}
