<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Throwable;

trait LazilyRefreshDatabase
{
    use RefreshDatabase {
        refreshDatabase as baseRefreshDatabase;
        setUpRefreshDatabaseInCoroutine as baseSetUpRefreshDatabaseInCoroutine;
        tearDownRefreshDatabaseInCoroutine as baseTearDownRefreshDatabaseInCoroutine;
    }

    /**
     * Define hooks to migrate the database before and after each test.
     */
    public function refreshDatabase(): void
    {
        if (! $this->runsTestsInCoroutine()) {
            $this->setUpLazilyRefreshDatabaseInCoroutine();
        }

        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$lazilyRefreshed = false;
        });
    }

    /**
     * Register lazy database refresh hooks in the test coroutine.
     */
    protected function setUpLazilyRefreshDatabaseInCoroutine(): void
    {
        $database = $this->app->make('db');

        $callback = function (): void {
            if (RefreshDatabaseState::$lazilyRefreshed) {
                return;
            }

            RefreshDatabaseState::$lazilyRefreshed = true;
            $hasMockConsoleOutput = property_exists($this, 'mockConsoleOutput');
            $shouldMockOutput = $hasMockConsoleOutput ? $this->mockConsoleOutput : null;

            try {
                if ($hasMockConsoleOutput) {
                    $this->mockConsoleOutput = false;
                }

                $this->baseRefreshDatabase();

                if ($this->runsTestsInCoroutine()) {
                    $this->baseSetUpRefreshDatabaseInCoroutine();
                }
            } catch (Throwable $throwable) {
                RefreshDatabaseState::$lazilyRefreshed = false;

                throw $throwable;
            } finally {
                if ($hasMockConsoleOutput) {
                    $this->mockConsoleOutput = $shouldMockOutput;
                }
            }
        };

        foreach ($this->connectionsToTransact() as $connection) {
            $database->connection($connection)->beforeStartingTransaction($callback);
            $database->connection($connection)->beforeExecuting($callback);
        }
    }

    /**
     * Roll back a lazily started transaction in the test coroutine.
     */
    protected function tearDownLazilyRefreshDatabaseInCoroutine(): void
    {
        if (RefreshDatabaseState::$lazilyRefreshed) {
            $this->baseTearDownRefreshDatabaseInCoroutine();
        }
    }

    /**
     * Defer eager refresh setup to the lazy trait hook.
     */
    protected function setUpRefreshDatabaseInCoroutine(): void
    {
    }

    /**
     * Defer eager refresh teardown to the lazy trait hook.
     */
    protected function tearDownRefreshDatabaseInCoroutine(): void
    {
    }
}
