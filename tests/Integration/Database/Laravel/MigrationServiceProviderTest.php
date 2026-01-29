<?php

declare(strict_types=1);

namespace Illuminate\Tests\Integration\Database;

use Illuminate\Database\Migrations\Migrator;

/**
 * @internal
 * @coversNothing
 */
class MigrationServiceProviderTest extends DatabaseTestCase
{
    public function testContainerCanBuildMigrator()
    {
        $fromString = $this->app->make('migrator');
        $fromClass = $this->app->make(Migrator::class);

        $this->assertSame($fromString, $fromClass);
    }
}
