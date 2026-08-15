<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Support\ServiceProvider;

class CookieServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('cookie', function ($app) {
            $config = $app->make('config')->array('session');

            return (new CookieJar)->setDefaultPathAndDomain(
                $config['path'],
                $config['domain'],
                $config['secure'],
                $config['same_site']
            );
        });
    }

    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use changes shared cookie defaults while
     * concurrent coroutines may still be creating cookies from the old values.
     */
    public function reloadConfiguration(): void
    {
        if (! $this->app->resolved('cookie')) {
            return;
        }

        $config = $this->app->make('config')->array('session');

        /** @var CookieJar $cookie */
        $cookie = $this->app->make('cookie');
        $cookie->setDefaultPathAndDomain(
            $config['path'],
            $config['domain'],
            $config['secure'],
            $config['same_site'],
        );
    }
}
