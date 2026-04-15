<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\InteractsWithParallelDatabase;
use Hypervel\Testbench\TestCase;

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
}
