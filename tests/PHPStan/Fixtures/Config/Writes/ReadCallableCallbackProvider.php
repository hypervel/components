<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class ReadCallableCallbackProvider extends ServiceProvider
{
    /**
     * Copy a value from a callback through a callable created from a read method.
     */
    public function register(): void
    {
        $this->configureUsing(static function (Repository $config): void {
            $get = $config->get(...);
            $config->set('package.copy', $get('package.original'));
        });
    }
}
