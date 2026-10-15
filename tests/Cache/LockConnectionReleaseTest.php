<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\Limiters\ConcurrencyLimiter;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

#[WithConfig('database.connections.wait', [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'pool' => ['testing_enabled' => true, 'max_connections' => 1],
])]
class LockConnectionReleaseTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testWaitingReturnsIdleSessionsButPreservesTransactionsAndPins(bool $funnel): void
    {
        $observed = [];
        $connection = DB::connection('wait');
        $pool = $this->app->make(PoolManager::class)->pool('wait');
        $connection->statement('create table cache_locks (key varchar(255) primary key, owner varchar(255), expiration integer)');
        $store = new DatabaseStore($this->app->make(ConnectionResolverInterface::class), 'wait', 'cache', lockLottery: []);
        $wait = function () use ($funnel, $connection, $pool, $store, &$observed): void {
            $limiter = new ConcurrencyLimiter($store, 'waiting', 1, 10);
            $holder = $funnel ? $limiter->acquire(0) : $store->lock('waiting', 10);

            if (! $funnel) {
                $this->assertTrue($holder->acquire());
            }

            Sleep::fake();
            $sleeps = 0;
            Sleep::whenFakingSleep(static function () use ($holder, $pool, &$observed, &$sleeps): void {
                $observed[] = $pool->getBorrowedCount();

                if (++$sleeps === 2) {
                    $holder->release();
                }
            });
            $connection->selectOne('select 1');

            if ($funnel) {
                $limiter->acquire(1, 1)->release();
            } else {
                $store->lock('waiting', 10)->betweenBlockedAttemptsSleepFor(1)->block(1, static fn () => null);
            }
        };

        $wait();
        $connection->transaction($wait);
        $connection->withPinnedSession($wait);

        $this->assertSame([0, 0, 1, 1, 1, 1], $observed);
    }
}
