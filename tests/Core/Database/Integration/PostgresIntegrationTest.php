<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Integration;

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Tests\Support\DatabaseIntegrationTestCase;

/**
 * PostgreSQL integration tests for database query builder features.
 *
 * Requires a PostgreSQL server. Configure via environment variables:
 * - PGSQL_HOST (default: 127.0.0.1)
 * - PGSQL_PORT (default: 5432)
 * - PGSQL_DATABASE (default: testing)
 * - PGSQL_USERNAME (default: postgres)
 * - PGSQL_PASSWORD (default: empty)
 *
 * @group integration
 * @group pgsql-integration
 *
 * @internal
 * @coversNothing
 */
class PostgresIntegrationTest extends DatabaseIntegrationTestCase
{
    protected function getDatabaseDriver(): string
    {
        return 'pgsql';
    }

    public function testCanConnectToPostgresDatabase(): void
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

    public function testPostgresVersionIsDetectable(): void
    {
        $result = $this->db()->selectOne('SELECT version() as version');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result->version);

        // PostgreSQL version string contains "PostgreSQL X.Y"
        $this->assertStringContainsString('PostgreSQL', $result->version);
    }
}
