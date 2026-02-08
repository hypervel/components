<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentTransactionWithAfterCommitUsingRefreshDatabaseTest extends TestCase
{
    use EloquentTransactionWithAfterCommitTests;
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected string $driver;

    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            $database = $this->app->get(DatabaseManager::class);
            foreach (array_keys($database->getConnections()) as $name) {
                $database->purge($name);
            }
        });

        parent::setUp();
    }

    protected function afterRefreshingDatabase(): void
    {
        $this->createTransactionTestTables();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->get('config');
        $connection = $config->get('database.default');

        $this->driver = $config->get("database.connections.{$connection}.driver");
    }
}
