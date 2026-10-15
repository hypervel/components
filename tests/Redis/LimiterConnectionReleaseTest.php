<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Database\Pool\PoolManager;
use Hypervel\Redis\Limiters\ConcurrencyLimiter;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

#[WithConfig('database.connections.wait', [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'pool' => ['testing_enabled' => true, 'max_connections' => 1],
])]
class LimiterConnectionReleaseTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testWaitingReturnsIdleSessionsButPreservesTransactionsAndPins(bool $funnel): void
    {
        $observed = [];
        $connection = DB::connection('wait');
        $pool = $this->app->make(PoolManager::class)->pool('wait');
        Sleep::fake();
        Sleep::whenFakingSleep(static function () use ($pool, &$observed): void {
            $observed[] = $pool->getBorrowedCount();
        });
        $wait = static function () use ($funnel, $connection): void {
            $redis = m::mock(RedisProxy::class);

            if ($funnel) {
                $redis->shouldReceive('isCluster')->once()->andReturnFalse();
                $redis->shouldReceive('withConnection')->twice()->andReturn(false, 'waiting0');
                $limiter = new ConcurrencyLimiter($redis, 'waiting', 1, 10);
            } else {
                $redis->shouldReceive('withConnection')->twice()->andReturn([0, time() + 10, 0], [1, time() + 10, 0]);
                $limiter = new DurationLimiter($redis, 'waiting', 1, 10);
            }

            $connection->selectOne('select 1');

            if ($funnel) {
                $limiter->acquire(1, 1);
            } else {
                $limiter->block(1, sleep: 1);
            }
        };

        $wait();
        $connection->transaction($wait);
        $connection->withPinnedSession($wait);

        $this->assertSame([0, 1, 1], $observed);
    }
}
