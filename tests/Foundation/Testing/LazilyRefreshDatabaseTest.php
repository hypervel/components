<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Throwable;

#[ResetRefreshDatabaseState]
#[WithConfig('database.connections.testing2', ['driver' => 'sqlite', 'database' => ':memory:'])]
class LazilyRefreshDatabaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected array $connectionsToTransact = ['testing', 'testing2'];

    protected int $refreshCount = 0;

    protected int $transactionCount = 0;

    protected int $rollbackCount = 0;

    protected int $afterRefreshCount = 0;

    protected ?Throwable $refreshFailure = null;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.default', 'testing');
    }

    public function testDatabaseIsRefreshedOnceOnFirstInteraction(): void
    {
        $database = $this->app->make('db');

        $database->select('select 1');
        $database->select('select 1');

        $this->assertSame(1, $this->refreshCount);
        $this->assertSame(1, $this->transactionCount);
        $this->assertSame(1, $this->afterRefreshCount);
        $this->assertTrue(RefreshDatabaseState::$lazilyRefreshed);
    }

    public function testDatabaseIsNotRefreshedWithoutInteraction(): void
    {
        $this->app->make('db')->getPdo();

        $this->assertSame(0, $this->refreshCount);
        $this->assertSame(0, $this->transactionCount);
        $this->assertSame(0, $this->afterRefreshCount);
        $this->assertFalse(RefreshDatabaseState::$lazilyRefreshed);
    }

    public function testNonDefaultConnectionTriggersRefresh(): void
    {
        $this->app->make('db')->connection('testing2')->select('select 1');

        $this->assertSame(1, $this->refreshCount);
        $this->assertSame(1, $this->transactionCount);
        $this->assertSame(1, $this->afterRefreshCount);
    }

    public function testRuntimeCoroutineOptOutRegistersLazyHooksImmediately(): void
    {
        $this->app->make('config')->set('database.connections.optout', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->connectionsToTransact = ['optout'];
        $this->runTestsInCoroutine = false;

        $this->refreshDatabase();
        $this->app->make('db')->connection('optout')->select('select 1');

        $this->assertSame(1, $this->refreshCount);
        $this->assertSame(1, $this->afterRefreshCount);
        $this->assertTrue(RefreshDatabaseState::$lazilyRefreshed);
    }

    public function testRefreshFailureRestoresStateAndMockConsoleOutput(): void
    {
        $this->refreshFailure = new RuntimeException('Migration failed.');
        $this->mockConsoleOutput = true;

        try {
            $this->app->make('db')->select('select 1');
            $this->fail('Expected the refresh failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->refreshFailure, $exception);
            $this->assertFalse(RefreshDatabaseState::$lazilyRefreshed);
            $this->assertTrue($this->mockConsoleOutput);
            $this->assertSame(0, $this->transactionCount);
            $this->assertSame(0, $this->rollbackCount);
        }
    }

    public function testLazyTeardownRunsOnlyAfterSuccessfulRefresh(): void
    {
        $this->tearDownLazilyRefreshDatabaseInCoroutine();

        $this->assertSame(0, $this->rollbackCount);

        $this->app->make('db')->select('select 1');
        $this->tearDownLazilyRefreshDatabaseInCoroutine();

        $this->assertSame(1, $this->rollbackCount);

        RefreshDatabaseState::$lazilyRefreshed = false;
    }

    protected function refreshTestDatabase(): void
    {
        ++$this->refreshCount;

        if ($this->refreshFailure !== null) {
            throw $this->refreshFailure;
        }
    }

    protected function beginDatabaseTransactionWork(): void
    {
        ++$this->transactionCount;
    }

    protected function rollbackDatabaseTransactionWork(): void
    {
        ++$this->rollbackCount;
    }

    protected function afterRefreshingDatabase(): void
    {
        ++$this->afterRefreshCount;
    }
}
