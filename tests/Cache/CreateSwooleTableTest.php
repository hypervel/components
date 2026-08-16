<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Tests\TestCase;
use LogicException;

class CreateSwooleTableTest extends TestCase
{
    public function testInitializesAndSealsTablesAcrossRepeatedServerStartEvents(): void
    {
        $config = new Repository([
            'cache' => [
                'stores' => [
                    'swoole' => [
                        'driver' => 'swoole',
                        'table' => 'shared',
                    ],
                ],
                'swoole_tables' => [
                    'shared' => [
                        'rows' => 64,
                        'bytes' => 1024,
                        'conflict_proportion' => 0.2,
                    ],
                ],
            ],
        ]);
        $container = new Container;
        $tables = new SwooleTableManager($container);
        $container->instance('config', $config);
        $container->instance(SwooleTableManager::class, $tables);
        $listener = new CreateSwooleTable($container, $config);

        $listener->handle(new BeforeServerStart('http'));
        $state = $tables->get('shared');
        $listener->handle(new BeforeServerStart('https'));

        $this->assertSame($state, $tables->get('shared'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Swoole cache table [late] was not initialized before the server fork.');

        $tables->get('late');
    }

    public function testSealsTheManagerWhenNoSwooleStoresAreConfigured(): void
    {
        $config = new Repository([
            'cache' => [
                'stores' => [],
            ],
        ]);
        $container = new Container;
        $tables = new SwooleTableManager($container);
        $container->instance('config', $config);
        $container->instance(SwooleTableManager::class, $tables);

        (new CreateSwooleTable($container, $config))->handle(new BeforeServerStart('http'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Swoole cache table [late] was not initialized before the server fork.');

        $tables->get('late');
    }
}
