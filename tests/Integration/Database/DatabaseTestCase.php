<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\TestCase;

/**
 * Base test case for database integration tests.
 *
 * Tests extending this class run against the database configured via
 * standard DB_* environment variables (DB_CONNECTION, DB_HOST, etc.).
 *
 * Each test should define its schema in afterRefreshingDatabase().
 *
 * @internal
 * @coversNothing
 */
abstract class DatabaseTestCase extends TestCase
{
    use DatabaseMigrations;

    /**
     * The current database driver.
     */
    protected string $driver;

    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            $db = $this->app->get(DatabaseManager::class);
            foreach (array_keys($db->getConnections()) as $name) {
                $db->purge($name);
            }
        });

        parent::setUp();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->get('config');
        $connection = $config->get('database.default');

        $this->driver = $config->get("database.connections.{$connection}.driver", 'sqlite');
    }

    /**
     * Skip this test if not running on the specified driver.
     */
    protected function skipUnlessDriver(string $driver): void
    {
        if ($this->driver !== $driver) {
            $this->markTestSkipped("This test requires the {$driver} database driver.");
        }
    }

    /**
     * Skip this test if running on the specified driver.
     */
    protected function skipIfDriver(string $driver): void
    {
        if ($this->driver === $driver) {
            $this->markTestSkipped("This test cannot run on the {$driver} database driver.");
        }
    }
}
