<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Pennant\PennantServiceProvider;
use Hypervel\Testbench\TestCase as TestbenchTestCase;

abstract class TestCase extends TestbenchTestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PennantServiceProvider::class];
    }

    /**
     * Load the Pennant migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/pennant/database/migrations');
    }
}
