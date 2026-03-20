<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Database\QueryException;
use Hypervel\Foundation\Testing;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\Facades\Schema;

trait TestDatabases
{
    /**
     * Indicates if the test database schema is up to date.
     */
    protected static bool $schemaIsUpToDate = false;

    /**
     * The root database name prior to concatenating the token.
     */
    protected static ?string $originalDatabaseName = null;

    /**
     * Boot a test database.
     */
    protected function bootTestDatabase(): void
    {
        ParallelTesting::setUpProcess(function () {
            $this->whenNotUsingInMemoryDatabase(function (string $database) {
                if (ParallelTesting::option('recreate_databases')) {
                    Schema::dropDatabaseIfExists(
                        $this->testDatabase($database)
                    );
                }
            });
        });

        ParallelTesting::setUpTestCase(function ($testCase) {
            $uses = array_flip(class_uses_recursive(get_class($testCase)));

            $databaseTraits = [
                Testing\DatabaseMigrations::class,
                Testing\DatabaseTransactions::class,
                Testing\DatabaseTruncation::class,
                Testing\RefreshDatabase::class,
            ];

            if (Arr::hasAny($uses, $databaseTraits) && ! ParallelTesting::option('without_databases')) {
                $this->whenNotUsingInMemoryDatabase(function (string $database) use ($uses) {
                    [$testDatabase, $created] = $this->ensureTestDatabaseExists($database);

                    $this->switchToDatabase($testDatabase);

                    if ($created) {
                        ParallelTesting::callSetUpTestDatabaseBeforeMigratingCallbacks($testDatabase);
                    }

                    if (isset($uses[Testing\DatabaseTransactions::class])) {
                        $this->ensureSchemaIsUpToDate();
                    }

                    if ($created) {
                        ParallelTesting::callSetUpTestDatabaseCallbacks($testDatabase);
                    }
                });
            }
        });

        ParallelTesting::tearDownProcess(function () {
            $this->whenNotUsingInMemoryDatabase(function (string $database) {
                if (ParallelTesting::option('drop_databases')) {
                    Schema::dropDatabaseIfExists(
                        $this->testDatabase($database)
                    );
                }
            });
        });
    }

    /**
     * Ensure a test database exists and return its name.
     *
     * @return array{string, bool}
     */
    protected function ensureTestDatabaseExists(string $database): array
    {
        $testDatabase = $this->testDatabase($database);

        try {
            $this->usingDatabase($testDatabase, function () {
                Schema::hasTable('dummy');
            });
        } catch (QueryException) {
            $this->usingDatabase($database, function () use ($testDatabase) {
                Schema::dropDatabaseIfExists($testDatabase);
                Schema::createDatabase($testDatabase);
            });

            return [$testDatabase, true];
        }

        return [$testDatabase, false];
    }

    /**
     * Ensure the current database test schema is up to date.
     */
    protected function ensureSchemaIsUpToDate(): void
    {
        if (! static::$schemaIsUpToDate) {
            Artisan::call('migrate');

            static::$schemaIsUpToDate = true;
        }
    }

    /**
     * Run the given callable using the given database.
     */
    protected function usingDatabase(string $database, callable $callable): void
    {
        $original = DB::getConfig('database');

        try {
            $this->switchToDatabase($database);
            $callable();
        } finally {
            $this->switchToDatabase($original);
        }
    }

    /**
     * Apply the given callback when tests are not using in-memory database.
     */
    protected function whenNotUsingInMemoryDatabase(callable $callback): void
    {
        if (ParallelTesting::option('without_databases')) {
            return;
        }

        $database = DB::getConfig('database');

        if ($database !== ':memory:') {
            $callback($database);
        }
    }

    /**
     * Switch to the given database.
     */
    protected function switchToDatabase(string $database): void
    {
        DB::purge();

        $default = config('database.default');

        $url = config("database.connections.{$default}.url");

        if ($url) {
            config()->set(
                "database.connections.{$default}.url",
                preg_replace('/^(.*)(\/[\w-]*)(\??.*)$/', "$1/{$database}$3", $url),
            );
        } else {
            config()->set(
                "database.connections.{$default}.database",
                $database,
            );
        }
    }

    /**
     * Get the test database name.
     */
    protected function testDatabase(string $database): string
    {
        if (! isset(self::$originalDatabaseName)) {
            self::$originalDatabaseName = $database;
        } else {
            $database = self::$originalDatabaseName;
        }

        $token = ParallelTesting::token();

        return "{$database}_test_{$token}";
    }
}
