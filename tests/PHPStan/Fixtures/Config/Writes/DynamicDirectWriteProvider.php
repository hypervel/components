<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Support\ServiceProvider;

class DynamicDirectWriteProvider extends ServiceProvider
{
    /**
     * Write a key with no constant segment directly.
     */
    public function register(): void
    {
        config([$this->app->environment() => true]);
    }
}
