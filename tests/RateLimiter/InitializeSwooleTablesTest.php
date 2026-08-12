<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Config\Repository;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\RateLimiter\Listeners\InitializeSwooleTables;
use Hypervel\RateLimiter\Swoole\TableManager;
use Hypervel\RateLimiter\Swoole\TableState;
use Hypervel\Tests\TestCase;
use Mockery as m;

class InitializeSwooleTablesTest extends TestCase
{
    public function testInitializesEverySwooleStoreAndSealsTheTableManager(): void
    {
        $config = new Repository([
            'rate-limiter' => [
                'stores' => [
                    'first' => ['driver' => 'swoole'],
                    'redis' => ['driver' => 'redis'],
                    'second' => ['driver' => 'swoole'],
                ],
            ],
        ]);
        $tables = m::mock(TableManager::class);
        $tables->shouldReceive('get')->once()->with('first')->andReturn(m::mock(TableState::class));
        $tables->shouldReceive('get')->once()->with('second')->andReturn(m::mock(TableState::class));
        $tables->shouldReceive('seal')->once();

        (new InitializeSwooleTables($config, $tables))->handle(new BeforeServerStart('server'));
    }
}
