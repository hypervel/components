<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Config\Repository;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\RateLimiter\Listeners\InitializeSwooleTables;
use Hypervel\RateLimiter\Swoole\TableManager;
use Hypervel\RateLimiter\Swoole\TableState;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class InitializeSwooleTablesTest extends TestCase
{
    use VerifiesDoubles;

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
        $tables = Double::for(TableManager::class)->strict();
        $tables->expects('get')->with('first')->returns(Double::for(TableState::class)->strict());
        $tables->expects('get')->with('second')->returns(Double::for(TableState::class)->strict());
        $tables->expects('seal');

        (new InitializeSwooleTables($config, $tables))->handle(new BeforeServerStart('server'));
    }
}
