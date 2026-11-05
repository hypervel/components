<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class EscapingCallbackProvider extends ServiceProvider
{
    /**
     * Pass the callback's repository to code the analysis does not follow.
     */
    public function register(): void
    {
        $this->configureUsing(static function (Repository $config): void {
            static::configure($config);
        });
    }

    /**
     * Change the configuration.
     */
    protected static function configure(object $config): void
    {
    }
}
