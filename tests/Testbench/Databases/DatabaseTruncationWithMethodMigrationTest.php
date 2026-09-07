<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

class DatabaseTruncationWithMethodMigrationTest extends TestCase
{
    use DatabaseTruncation;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        RefreshDatabaseState::$migrated = true;
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    #[Test]
    #[WithMigration('notifications')]
    public function itIsolatesMethodSpecificMigrationSets(): void
    {
        $this->assertTrue(Schema::hasTable('notifications'));
    }

    #[Test]
    #[Depends('itIsolatesMethodSpecificMigrationSets')]
    public function itRestoresTheClassSchemaAfterAMethodSpecificMigrationSet(): void
    {
        $this->assertTrue(Schema::hasTable('testbench_users'));
        $this->assertFalse(Schema::hasTable('notifications'));
    }
}
