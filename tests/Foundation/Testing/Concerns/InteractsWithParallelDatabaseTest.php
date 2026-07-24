<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Closure;
use Hypervel\Foundation\Testing\Concerns\InteractsWithParallelDatabase;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

class InteractsWithParallelDatabaseTest extends TestCase
{
    use InteractsWithParallelDatabase;

    protected function setUp(): void
    {
        // Reset static state between tests
        static::$originalDatabaseName = null;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        static::$originalDatabaseName = null;

        parent::tearDown();
    }

    public function testParallelTestDatabaseAppendsTokenToName()
    {
        $result = $this->parallelTestDatabase('testing', '3');

        $this->assertSame('testing_test_3', $result);
    }

    public function testParallelTestDatabasePreservesOriginalNameOnSubsequentCalls()
    {
        $first = $this->parallelTestDatabase('testing', '1');
        $second = $this->parallelTestDatabase('testing_test_1', '2');

        $this->assertSame('testing_test_1', $first);
        $this->assertSame('testing_test_2', $second);
    }

    public function testConfigureParallelDatabaseNameIsNoOpWithoutTestToken()
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $original = $config->get("database.connections.{$connection}.database");

        $this->configureParallelDatabaseName($this->app);

        $this->assertSame($original, $config->get("database.connections.{$connection}.database"));
    }

    public function testConfigureParallelDatabaseNameSkipsInMemorySqlite()
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", ':memory:');

        $this->configureParallelDatabaseName($this->app);

        $this->assertSame(':memory:', $config->get("database.connections.{$connection}.database"));
    }

    public function testConfigureParallelDatabaseNameSkipsSqliteMemoryUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file::memory:');

        $this->withParallelEnvironment('7', false, function () use ($config, $connection): void {
            $this->configureParallelDatabaseName($this->app);

            $this->assertSame(
                'file::memory:',
                $config->get("database.connections.{$connection}.database")
            );
        });
    }

    public function testConfigureParallelDatabaseNameRejectsSqliteFileUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file:/tmp/database.sqlite?mode=rwc');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SQLite URI databases cannot be automatically managed during parallel testing. '
            . 'Configure a plain filesystem path or run with --without-databases.'
        );

        $this->withParallelEnvironment(
            '7',
            false,
            fn () => $this->configureParallelDatabaseName($this->app)
        );
    }

    public function testConfigureParallelDatabaseNameHonorsWithoutDatabasesOption(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $database = $config->get("database.connections.{$connection}.database");

        $this->withParallelEnvironment('7', true, function () use ($config, $connection, $database): void {
            $this->configureParallelDatabaseName($this->app);

            $this->assertSame(
                $database,
                $config->get("database.connections.{$connection}.database")
            );
        });
    }

    public function testConfigureParallelDatabaseNameSkipsEmptyDatabase()
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", '');

        $this->configureParallelDatabaseName($this->app);

        $this->assertSame('', $config->get("database.connections.{$connection}.database"));
    }

    public function testConfigureParallelDatabaseNameSkipsUnconfiguredConnection()
    {
        $config = $this->app->make('config');
        $config->set('database.default', 'nonexistent');

        // Should not throw — just skip
        $this->configureParallelDatabaseName($this->app);
    }

    public function testEnsureParallelDatabaseExistsIsNoOpWithoutTestToken()
    {
        // Without TEST_TOKEN, should be a no-op (no exceptions)
        $this->ensureParallelDatabaseExists();

        $this->assertTrue(true);
    }

    public function testEnsureParallelDatabaseExistsSkipsLateConfiguredSqliteMemoryUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file::memory:');

        $this->withParallelEnvironment('7', false, function (): void {
            $this->ensureParallelDatabaseExists();

            $this->assertTrue(true);
        });
    }

    public function testEnsureParallelDatabaseExistsRejectsLateConfiguredSqliteFileUri(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file:/tmp/database.sqlite?mode=rwc');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'SQLite URI databases cannot be automatically managed during parallel testing. '
            . 'Configure a plain filesystem path or run with --without-databases.'
        );

        $this->withParallelEnvironment(
            '7',
            false,
            fn () => $this->ensureParallelDatabaseExists()
        );
    }

    public function testEnsureParallelDatabaseExistsHonorsWithoutDatabasesOption(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('database.default');
        $config->set("database.connections.{$connection}.database", 'file:/tmp/database.sqlite?mode=rwc');

        $this->withParallelEnvironment('7', true, function (): void {
            $this->ensureParallelDatabaseExists();

            $this->assertTrue(true);
        });
    }

    /**
     * Run a callback with an isolated parallel-testing environment.
     */
    private function withParallelEnvironment(
        string $token,
        bool $withoutDatabases,
        Closure $callback
    ): void {
        $previousProcessToken = getenv('TEST_TOKEN');
        $previousServerTokenExists = array_key_exists('TEST_TOKEN', $_SERVER);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentTokenExists = array_key_exists('TEST_TOKEN', $_ENV);
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousWithoutDatabasesExists = array_key_exists(
            'HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES',
            $_SERVER
        );
        $previousWithoutDatabases = $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] ?? null;

        putenv("TEST_TOKEN={$token}");
        $_SERVER['TEST_TOKEN'] = $token;
        $_ENV['TEST_TOKEN'] = $token;

        if ($withoutDatabases) {
            $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = 1;
        } else {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
        }

        try {
            $callback();
        } finally {
            $previousProcessToken === false
                ? putenv('TEST_TOKEN')
                : putenv("TEST_TOKEN={$previousProcessToken}");

            if ($previousServerTokenExists) {
                $_SERVER['TEST_TOKEN'] = $previousServerToken;
            } else {
                unset($_SERVER['TEST_TOKEN']);
            }

            if ($previousEnvironmentTokenExists) {
                $_ENV['TEST_TOKEN'] = $previousEnvironmentToken;
            } else {
                unset($_ENV['TEST_TOKEN']);
            }

            if ($previousWithoutDatabasesExists) {
                $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = $previousWithoutDatabases;
            } else {
                unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
            }
        }
    }
}
