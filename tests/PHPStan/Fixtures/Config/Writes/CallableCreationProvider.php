<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class CallableCreationProvider extends ServiceProvider
{
    /**
     * Create callables outside a callback; creating them writes nothing.
     */
    protected function createCallables(Repository $config): void
    {
        $write = config(...);
        $set = $config->set(...);
        $make = $this->app->make(...);
    }
}
