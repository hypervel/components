<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Support\Facades\DB;
use ReflectionClass;

/**
 * Verifies that the test lifecycle flushes the database connection pool
 * before $this->app->flush() runs.
 *
 * Captures the PoolFactory and a live DbPool during the test body, then
 * asserts post-teardown state in a custom tearDown() that runs AFTER
 * parent::tearDown(). Without the lifecycle pool flush, the captured pool
 * would still hold its PDO socket and the factory would still cache the
 * pool, which is the FD/memory leak path that affects long ParaTest runs.
 *
 * Opts into pool.testing_enabled because the default DatabaseConnectionResolver
 * caches bare Connections and bypasses the pool's checkout/release cycle,
 * which would leave nothing in the channel for flushAll() to drain. With
 * testing_enabled = true, the resolver falls through to the parent
 * ConnectionResolver and the real pool lifecycle is exercised.
 *
 * Mirrors RedisPoolTeardownLifecycleTest.
 */
class DbPoolTeardownLifecycleTest extends DatabaseTestCase
{
    private static ?PoolFactory $capturedFactory = null;

    private static ?DbPool $capturedPool = null;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $default = $config->get('database.default');
        $config->set("database.connections.{$default}.pool.testing_enabled", true);
    }

    public function testTearDownLifecyclePurgesDbPools(): void
    {
        // Real query forces the manager/resolver path to instantiate a live
        // pool with a real PDO connection. Going through
        // DB::statement (rather than poking the factory directly) proves the
        // normal application path creates the pool the trait must clean up.
        DB::statement('SELECT 1');

        $factory = $this->app->make(PoolFactory::class);
        $defaultName = $this->app->make('config')->get('database.default');
        $pool = $factory->getPool($defaultName);

        // Sanity: the pool actually has a real connection
        $this->assertGreaterThan(0, $pool->getCurrentConnections());

        self::$capturedFactory = $factory;
        self::$capturedPool = $pool;
    }

    protected function tearDown(): void
    {
        // parent::tearDown() runs tearDownTheTestEnvironment, where the
        // pool-purge lifecycle hook lives. After it returns, the captured
        // references should reflect a fully torn-down pool layer.
        parent::tearDown();

        if (self::$capturedFactory === null || self::$capturedPool === null) {
            return;
        }

        try {
            $this->assertSame(
                0,
                self::$capturedPool->getConnectionsInChannel(),
                'Pool channel should be empty after lifecycle teardown'
            );
            $this->assertSame(
                0,
                self::$capturedPool->getCurrentConnections(),
                'Pool currentConnections should be 0 after lifecycle teardown'
            );

            // The factory's $pools cache should be cleared so the previous
            // pool object can be refcount-collected (no public accessor for
            // this, so reflection is the only way to verify it directly).
            $reflection = new ReflectionClass(self::$capturedFactory);
            $poolsProperty = $reflection->getProperty('pools');
            $this->assertSame(
                [],
                $poolsProperty->getValue(self::$capturedFactory),
                'PoolFactory $pools should be empty after lifecycle teardown'
            );
        } finally {
            self::$capturedFactory = null;
            self::$capturedPool = null;
        }
    }
}
