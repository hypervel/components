<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentTransactionWithAfterCommitUsingDatabaseTransactionsTest extends TestCase
{
    use DatabaseTransactions;
    use EloquentTransactionWithAfterCommitTests;

    /**
     * The current database driver.
     */
    protected string $driver;

    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            foreach (array_keys($this->app['db']->getConnections()) as $name) {
                $this->app['db']->purge($name);
            }
        });

        parent::setUp();

        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Test cannot be used with in-memory SQLite connection.');
        }

        $this->createTransactionTestTables();
    }

    protected function defineEnvironment($app): void
    {
        $connection = $app->make('config')->get('database.default');

        $this->driver = $app['config']->get("database.connections.{$connection}.driver");
    }
}
