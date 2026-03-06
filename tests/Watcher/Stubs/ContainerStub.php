<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Stubs;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Mockery as m;
use Mockery\MockInterface;

class ContainerStub
{
    public static function getContainer(string $driver): ContainerContract|MockInterface
    {
        $container = m::mock(ContainerContract::class);

        $container->shouldReceive('make')->with('config')->andReturnUsing(function () use ($driver) {
            return new Repository([
                'watcher' => [
                    'driver' => $driver,
                    'bin' => 'php',
                    'watch' => [
                        'dir' => ['/tmp'],
                        'file' => ['.env'],
                        'scan_interval' => 1,
                    ],
                ],
            ]);
        });
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturnUsing(function () {
            $logger = m::mock(StdoutLoggerInterface::class);
            $logger->shouldReceive('debug')->andReturn(null);
            $logger->shouldReceive('log')->andReturn(null);
            return $logger;
        });
        return $container;
    }
}
