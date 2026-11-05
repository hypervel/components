<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class DynamicCallbackWriteProvider extends ServiceProvider
{
    /**
     * Write a key with no constant segment from a callback.
     */
    public function register(): void
    {
        $this->configureUsing(static function (Repository $config): void {
            $config->set($config->string('package.target'), true);
        });
    }
}
