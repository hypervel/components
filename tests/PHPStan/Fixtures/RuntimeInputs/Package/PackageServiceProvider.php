<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package;

use Hypervel\Support\ServiceProvider;

class PackageServiceProvider extends ServiceProvider
{
    /**
     * Bind the greeter the booted configuration selects.
     */
    public function register(): void
    {
        $config = $this->app->make('config');
        $french = $config->string('package.greeter') === 'french'
            || $config->string('database.connections.mysql.database') === 'french'
            || $config->string('database.default') === 'sqlite';

        $this->app->bind(Greeter::class, $french ? FrenchGreeter::class : EnglishGreeter::class);
    }
}
