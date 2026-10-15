<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector as RateLimitedConnector;
use PHPUnit\Framework\Attributes\TestWith;

#[WithConfig('database.connections.wait', [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'pool' => ['testing_enabled' => true, 'max_connections' => 1],
])]
#[WithConfig('saloon.rate_limiter.store', 'worker-array')]
class WaitConnectionReleaseTest extends TestCase
{
    /**
     * Register Saloon's application services.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [SaloonServiceProvider::class];
    }

    #[TestWith(['delay'])]
    #[TestWith(['retry'])]
    #[TestWith(['rate-limit'])]
    public function testWaitingReturnsIdleSessionsButPreservesTransactionsAndPins(string $mode): void
    {
        $observed = [];
        $connection = DB::connection('wait');
        $pool = $this->app->make(PoolManager::class)->pool('wait');
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(static function () use ($pool, &$observed): void {
            $observed[] = $pool->getBorrowedCount();
        });
        $sequence = Http::sequence();

        for ($scope = 0; $scope < 3; ++$scope) {
            if ($mode === 'retry') {
                $sequence->pushStatus(500);
            }

            $sequence->pushStatus(200);
        }

        Http::fake(['*' => $sequence]);
        $wait = function () use ($mode, $connection): void {
            $request = new UserRequest;
            $connector = new TestConnector;
            $request->middleware()->onRequest(static function () use ($connection): void {
                $connection->selectOne('select 1');
            });

            if ($mode === 'rate-limit') {
                $policy = Limit::perMinute(1);
                $connector = new RateLimitedConnector([$policy], [$policy]);
                $this->app->make(RateLimiter::class)->store('worker-array')->consume($policy, 'saloon:' . $connector::class);
            } elseif ($mode === 'retry') {
                $request->retry(2, 1);
            } else {
                $request->delay(1);
            }

            $this->assertTrue($connector->send($request)->successful());
        };

        $wait();
        $connection->transaction($wait);
        $connection->withPinnedSession($wait);

        $this->assertSame([0, 1, 1], $observed);
    }
}
