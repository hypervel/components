<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Support\ServiceProvider;

class MailServiceProvider extends ServiceProvider
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
     * Register the Illuminate mailer instance.
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
            $config = $app->make('config');

            return new Markdown($app->make('view'), [
                'theme' => $config->get('mail.markdown.theme', 'default'),
                'paths' => $config->get('mail.markdown.paths', []),
            ]);
        });
    }
}
