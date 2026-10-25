<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

#[WithConfig('database.default', 'testing')]
class RefreshDatabaseWithRetainedMigrationStateTest extends TestCase
{
    use RefreshDatabase;
    use WithHypervelMigrations;

    /**
     * Retain migration state from a persistent database class.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Persistent database classes retain this flag without an in-memory PDO.
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    /**
     * Load package migrations alongside the framework migrations.
     */
    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    #[Test]
    public function itMigratesDefaultAndPackageTablesDespiteRetainedPersistentState(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('testbench_users'));
    }
}
