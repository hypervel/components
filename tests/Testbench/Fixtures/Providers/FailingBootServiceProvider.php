<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class FailingBootServiceProvider extends ServiceProvider
{
    /**
     * The exception the next boot throws, when a test supplies one.
     */
    public static ?Throwable $exception = null;

    /**
     * Fail while the application boots.
     */
    public function boot(): void
    {
        throw static::$exception ?? new RuntimeException('The failing boot fixture failed.');
    }
}
