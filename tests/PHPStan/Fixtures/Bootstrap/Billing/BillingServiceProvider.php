<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap\Billing;

use Hypervel\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register billing's schema macros and migrations.
     */
    public function boot(): void
    {
        BillingSchema::register();
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
