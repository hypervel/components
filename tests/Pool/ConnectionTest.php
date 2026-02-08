<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Pool\Event\ReleaseConnection;
use Hypervel\Pool\Pool;
use Hypervel\Pool\PoolOption;
use Hypervel\Tests\Pool\Stub\ActiveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class ConnectionTest extends TestCase
{
    public function testGetActiveConnectionAgain()
    {
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')->withAnyArgs()->once()->andReturnTrue();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturnFalse();

        $connection = new ActiveConnectionStub($container, m::mock(Pool::class));
        $this->assertEquals($connection, $connection->getConnection());
    }

    public function testReleaseConnectionEvent()
    {
        $assert = 0;
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturnTrue();
        $container->shouldReceive('get')->with(EventDispatcherInterface::class)->andReturn($dispatcher = m::mock(EventDispatcherInterface::class));
        $dispatcher->shouldReceive('dispatch')->once()->with(ReleaseConnection::class)->andReturnUsing(function (ReleaseConnection $event) use (&$assert) {
            $assert = $event->connection->getLastReleaseTime();
        });

        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: [ReleaseConnection::class]));

        $connection->release();
        $this->assertTrue($assert > 0);
    }

    public function testDontHaveEvents()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturnTrue();
        $container->shouldReceive('get')->with(EventDispatcherInterface::class)->andReturn($dispatcher = m::mock(EventDispatcherInterface::class));
        $dispatcher->shouldReceive('dispatch')->never()->with(ReleaseConnection::class)->andReturnNull();

        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: []));

        $connection->release();

        $this->assertTrue(true);
    }
}
