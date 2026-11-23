<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package;

use Hypervel\Support\ServiceProvider;

class FrenchGreeterServiceProvider extends ServiceProvider
{
    /**
     * Bind the French greeter after every provider has registered.
     */
    public function boot(): void
    {
        $this->app->bind(Greeter::class, FrenchGreeter::class);
    }
}
