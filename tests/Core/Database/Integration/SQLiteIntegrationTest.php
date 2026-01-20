<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Integration;

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Tests\Support\DatabaseIntegrationTestCase;

/**
 * SQLite integration tests for database query builder features.
 *
 * Uses in-memory SQLite database, so no external service is required.
 * These tests can run as part of the regular test suite.
 *
 * @group integration
 * @group sqlite-integration
 *
 * @internal
 * @coversNothing
 */
class SQLiteIntegrationTest extends DatabaseIntegrationTestCase
{
    protected function getDatabaseDriver(): string
    {
        return 'sqlite';
    }

    public function testCanConnectToSqliteDatabase(): void
    {
        // Create a simple test table
        $this->createTestTable('smoke_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        // Insert a row
        $this->db()->table('smoke_test')->insert(['name' => 'test']);

        // Query it back
        $result = $this->db()->table('smoke_test')->where('name', 'test')->first();

        $this->assertNotNull($result);
        $this->assertEquals('test', $result->name);
    }

    public function testSqliteVersionSupportsWindowFunctions(): void
    {
        $version = $this->db()->selectOne('SELECT sqlite_version() as version')->version;

        // SQLite 3.25.0+ supports window functions (ROW_NUMBER, etc.)
        $this->assertTrue(
            version_compare($version, '3.25.0', '>='),
            "SQLite version {$version} is older than 3.25.0 and doesn't support window functions"
        );
    }
}
