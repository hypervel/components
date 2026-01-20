<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Integration;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\SQLite\Connectors\SQLiteConnector;
use Hyperf\DbConnection\Db;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

/**
 * SQLite tests for database features.
 *
 * Unlike MySQL/PostgreSQL tests, this does NOT extend DatabaseIntegrationTestCase
 * or use @group annotations because:
 * - SQLite in-memory requires no external services
 * - Each test gets an isolated :memory: database (destroyed when test ends)
 * - No env vars, groups, or table prefixes needed for parallel safety
 *
 * @internal
 * @coversNothing
 */
class SQLiteIntegrationTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->set('db.connector.sqlite', new SQLiteConnector());

        $config = $this->app->get(ConfigInterface::class);
        $config->set('databases.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('databases.default', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function db(): ConnectionInterface
    {
        return Db::connection('sqlite');
    }

    protected function createTestTable(string $name, callable $callback): void
    {
        Schema::connection('sqlite')->create($name, $callback);
    }

    public function testCanConnectToSqliteDatabase(): void
    {
        $this->createTestTable('smoke_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $this->db()->table('smoke_test')->insert(['name' => 'test']);

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
