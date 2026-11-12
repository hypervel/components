<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\BindingCycles;

use Hypervel\Auth\AuthManager;
use Hypervel\Cache\CacheManager;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\App;
use Hypervel\Support\ServiceProvider;

class TenantApp extends App
{
    public static function singleton(string $abstract, ?string $concrete = null): void
    {
    }
}

class CycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('auth', AuthManager::class);
        app()->bind('cache', CacheManager::class);
        App::scoped('auth', AuthManager::class);
        Container::getInstance()->singleton('cache', CacheManager::class);
        $this->app->singletonIf('auth', AuthManager::class);
        $this->app->singleton('auth', fn ($app) => new AuthManager($app));
        $this->app->singleton(AuthManager::class);
        $this->app->singleton(CacheManager::class, AuthManager::class);
        (new Container)->singleton('auth', AuthManager::class);
        TenantApp::singleton('auth', AuthManager::class);
    }

    public function configure(Application $application): void
    {
        $application->bind('auth', AuthManager::class);
    }
}
