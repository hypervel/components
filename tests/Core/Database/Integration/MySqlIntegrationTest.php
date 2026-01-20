<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Integration;

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Tests\Support\DatabaseIntegrationTestCase;

/**
 * MySQL integration tests for database query builder features.
 *
 * Requires a MySQL server. Configure via environment variables:
 * - MYSQL_HOST (default: 127.0.0.1)
 * - MYSQL_PORT (default: 3306)
 * - MYSQL_DATABASE (default: testing)
 * - MYSQL_USERNAME (default: root)
 * - MYSQL_PASSWORD (default: empty)
 *
 * @group integration
 * @group mysql-integration
 *
 * @internal
 * @coversNothing
 */
class MySqlIntegrationTest extends DatabaseIntegrationTestCase
{
    protected function getDatabaseDriver(): string
    {
        return 'mysql';
    }

    public function testCanConnectToMySqlDatabase(): void
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

    public function testMySqlVersionIsDetectable(): void
    {
        $result = $this->db()->selectOne('SELECT VERSION() as version');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result->version);

        // Just verify we can parse a version number
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $result->version);
    }
}
