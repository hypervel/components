<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Contracts\Mail\Factory as FactoryContract;
use Hypervel\Contracts\Mail\Mailer as MailerContract;
use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Support\ServiceProvider;

class MailServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(FactoryContract::class, fn ($app) => $app->build(MailManager::class));

        $this->app->singleton(MailerContract::class, fn ($app) => $app->make(FactoryContract::class)->mailer());

        $this->app->bind(Markdown::class, fn ($app) => new Markdown(
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
