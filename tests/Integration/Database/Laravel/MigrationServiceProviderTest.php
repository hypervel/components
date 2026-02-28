<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Migrations\Migrator;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class MigrationServiceProviderTest extends DatabaseTestCase
{
    /**
     * Test that 'migrator' alias and Migrator::class resolve to the same singleton.
     *
     * Note: Hypervel uses get() for singleton resolution. Laravel's make() creates
     * new instances, but in Laravel's test the singleton binding ensures same instance.
     * In Hypervel, get() returns the cached singleton.
     */
    public function testContainerCanBuildMigrator(): void
    {
        $fromString = $this->app->make('migrator');
        $fromClass = $this->app->make(Migrator::class);

        $this->assertSame($fromString, $fromClass);
    }
}
