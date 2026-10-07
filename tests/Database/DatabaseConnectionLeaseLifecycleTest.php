<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseConnectionLeaseLifecycleTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    /**
     * Configure the production resolver for non-coroutine execution.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('app.stdout_log.level', []);
        $app->make('config')->set('database.connections.leases', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'pool' => [
                'testing_enabled' => true,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'events' => [ConnectionReleasing::class],
            ],
        ]);
    }

    #[DataProvider('terminalReleases')]
    public function testTerminalCallbacksResolveTheirOwningConnection(bool $coroutine, bool $listenerFails): void
    {
        $resolver = $this->app->make('db.resolver');
        $pool = $this->app->make(PoolManager::class)->pool('leases');
        $connection = null;
        $callbacks = [];
        $registeredAfterCleanup = null;
        Event::listen(ConnectionReleasing::class, static function (ConnectionReleasing $event) use (&$callbacks, $listenerFails): void {
            $resolved = DB::connection();
            $callbacks[] = ['release', $resolved, $resolved->selectOne('select 2 as value')->value];

            if ($listenerFails) {
                throw new RuntimeException('Release listener failed after querying its owner.');
            }
        });
        $execute = static function () use (&$connection, &$callbacks): void {
            DB::setDefaultConnection('leases');
            $connection = DB::connection();
            $connection->beginTransaction();
            $connection->afterRollBack(static function () use (&$callbacks): void {
                $resolved = DB::connection();
                $callbacks[] = ['rollback', $resolved, $resolved->selectOne('select 1 as value')->value];
            });
        };

        try {
            if ($coroutine) {
                $this->runInCoroutine(static function () use ($execute, &$registeredAfterCleanup): void {
                    Coroutine::defer(static function () use (&$registeredAfterCleanup): void {
                        $registeredAfterCleanup = array_key_exists('leases', DB::getConnections());
                    });
                    $execute();
                });
            } else {
                $execute();
                $resolver->releaseConnections();
                $registeredAfterCleanup = array_key_exists('leases', DB::getConnections());
                $this->assertSame(config('database.default'), DB::getDefaultConnection());
            }

            $this->assertSame([['rollback', $connection, 1], ['release', $connection, 2]], $callbacks);
            $this->assertFalse($registeredAfterCleanup);
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(0, $pool->getBorrowedCount());
            $this->assertSame(1, $pool->getIdleCount());
        } finally {
            $resolver->discardConnections();
            $pool->close();
        }
    }

    /**
     * Provide terminal cleanup triggers and a failed release callback.
     */
    public static function terminalReleases(): array
    {
        return [
            'coroutine end' => [true, false],
            'task end' => [false, false],
            'failed task release listener' => [false, true],
        ];
    }

    public function testTaskCleanupSettlesTheCurrentLeaseAfterRepeatedEarlyReleases(): void
    {
        $resolver = $this->app->make('db.resolver');
        $connection = DB::connection('leases');
        $pool = $this->app->make(PoolManager::class)->pool('leases');

        try {
            foreach ([1, 2] as $value) {
                $this->assertSame($value, $connection->selectOne('select ? as value', [$value])->value);
                DB::releaseIdleConnections();
                $this->assertSame(1, $pool->getIdleCount());
            }

            $connection->selectOne('select 1');
            $resolver->releaseConnections();
            $resolver->releaseConnections();

            $this->assertSame(1, $pool->getIdleCount());
            $this->assertNotSame($connection, DB::connection('leases'));
            $resolver->discardConnections();
            $this->assertSame(0, $pool->getManagedCount());
        } finally {
            $resolver->discardConnections();
            $pool->close();
        }
    }

    public function testPurgingAndReplacingAConnectionDoesNotLoseItsPreviousOwner(): void
    {
        $resolver = $this->app->make('db.resolver');
        $pools = $this->app->make(PoolManager::class);
        $connection = DB::connection('leases');
        $connection->selectOne('select 1');
        $previousPool = $pools->pool('leases');

        try {
            DB::purge('leases');
            $replacement = DB::connection('leases');
            $replacement->selectOne('select 2');
            $resolver->releaseConnections();

            $this->assertNotSame($connection, $replacement);
            $this->assertTrue($previousPool->isClosed());
            $this->assertSame(0, $previousPool->getManagedCount());
            $this->assertSame(1, $pools->pool('leases')->getIdleCount());
        } finally {
            $resolver->discardConnections();
            $pools->purgeAll();
        }
    }
}
