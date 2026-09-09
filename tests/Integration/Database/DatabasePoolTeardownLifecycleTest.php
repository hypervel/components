<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Support\Facades\DB;

/**
 * Retains a live pool across application teardown to verify resource cleanup.
 * Enables the normal resolver so coroutine release returns connections to the
 * idle queue before teardown, instead of keeping them in the testing cache.
 */
class DatabasePoolTeardownLifecycleTest extends DatabaseTestCase
{
    private static ?PoolManager $capturedManager = null;

    private static ?DatabasePool $capturedPool = null;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $default = $config->string('database.default');
        $config->set("database.connections.{$default}.pool.testing_enabled", true);
    }

    public function testTearDownLifecyclePurgesDatabasePools(): void
    {
        // Exercise the application path that creates the pool owned by teardown.
        DB::statement('SELECT 1');

        $poolManager = $this->app->make(PoolManager::class);
        $defaultName = $this->app->make('config')->string('database.default');
        $pool = $poolManager->pool($defaultName);

        $this->assertGreaterThan(0, $pool->getManagedCount());

        self::$capturedManager = $poolManager;
        self::$capturedPool = $pool;
    }

    protected function tearDown(): void
    {
        // Assert after the framework's pool cleanup has run.
        parent::tearDown();

        if (self::$capturedManager === null || self::$capturedPool === null) {
            return;
        }

        try {
            $this->assertSame(
                0,
                self::$capturedPool->getIdleCount(),
                'Pool channel should be empty after lifecycle teardown'
            );
            $this->assertSame(
                0,
                self::$capturedPool->getManagedCount(),
                'Pool managed count should be 0 after lifecycle teardown'
            );

            $this->assertSame(
                [],
                self::$capturedManager->getPools(),
                'The pool registry should be empty after lifecycle teardown'
            );
        } finally {
            self::$capturedManager = null;
            self::$capturedPool = null;
        }
    }
}
