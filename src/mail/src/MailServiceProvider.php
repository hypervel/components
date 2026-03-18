<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Contracts\View\Factory as ViewFactoryContract;
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
     * Register the mailer instance.
     */
    protected function registerIlluminateMailer(): void
    {
        $this->app->singleton('mail.manager', fn ($app) => new MailManager($app));

        $this->app->singleton('mailer', fn ($app) => $app->make('mail.manager')->mailer());
    }

    /**
     * Register the Markdown renderer instance.
     */
    protected function registerMarkdownRenderer(): void
    {
        $this->app->singleton(Markdown::class, fn ($app) => new Markdown(
            $app->make(ViewFactoryContract::class),
            $app->make('config')->get('mail.markdown', []),
        ));
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/mail'),
        ], 'hypervel-mail');
    }
}
