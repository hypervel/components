<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

class EloquentTransactionWithAfterCommitUsingDatabaseTransactionsTest extends TestCase
{
    use DatabaseTransactions;
    use EloquentTransactionWithAfterCommitTests;

    /**
     * Indicates whether the schema has been initialized in this process.
     *
     * The tables intentionally outlive this class within a worker and remain
     * until a later migrate:fresh or the worker database is discarded.
     */
    protected static bool $transactionTestSchemaInitialized = false;

    /**
     * The current database driver.
     */
    protected string $driver;

    protected function setUpTraits(): array
    {
        // Skip BEFORE DatabaseTransactions starts its wrapping transaction.
        // In-memory SQLite has no persistent schema, so tables created in setUp
        // would be rolled back when the test ends, breaking subsequent tests.
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Test cannot be used with in-memory SQLite connection.');
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::$transactionTestSchemaInitialized) {
            // Establish the schema inside a coroutine but before DatabaseTransactions
            // starts the wrapping transaction for the test method.
            $this->runInCoroutine(function (): void {
                Schema::dropIfExists('password_reset_tokens');
                Schema::dropIfExists('users');

                $this->createTransactionTestTables();
            });

            static::$transactionTestSchemaInitialized = true;
        }
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->get('database.default');

        $this->driver = $config->get("database.connections.{$connection}.driver");
    }
}
