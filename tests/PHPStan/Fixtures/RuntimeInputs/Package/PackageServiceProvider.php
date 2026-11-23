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
        $french = $config->get('package.greeter') === 'french'
            || $config->get('database.connections.mysql.database') === 'french'
            || $config->get('database.default') === 'sqlite';

        $this->app->bind(Greeter::class, $french ? FrenchGreeter::class : EnglishGreeter::class);
    }
}
