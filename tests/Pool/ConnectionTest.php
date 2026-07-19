<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Pool\Events\ReleaseConnection;
use Hypervel\Pool\Pool;
use Hypervel\Pool\PoolOption;
use Hypervel\Tests\Pool\Fixtures\ActiveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ConnectionTest extends TestCase
{
    public function testGetActiveConnectionAgain(): void
    {
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')->withAnyArgs()->once()->andReturnTrue();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();

        $connection = new ActiveConnectionStub($container, m::mock(Pool::class));
        $this->assertEquals($connection, $connection->getConnection());
    }

    public function testReleaseConnectionEvent(): void
    {
        $assert = 0;
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('dispatch')->once()->with(ReleaseConnection::class)->andReturnUsing(function (ReleaseConnection $event) use (&$assert) {
            $assert = $event->connection->getLastReleaseTime();
        });

        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: [ReleaseConnection::class]));

        $before = hrtime(true) / 1e9;
        $connection->release();
        $after = hrtime(true) / 1e9;

        $this->assertGreaterThanOrEqual($before, $assert);
        $this->assertLessThanOrEqual($after, $assert);
    }

    public function testDontHaveEvents(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('dispatch')->never()->with(ReleaseConnection::class)->andReturnNull();

        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: []));

        $connection->release();

        $this->assertTrue(true);
    }

    public function testDiscardDelegatesToOwningPool(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $pool = m::mock(Pool::class);
        $connection = new ActiveConnectionStub($container, $pool);
        $pool->shouldReceive('discard')->once()->with($connection);

        $connection->discard();

        $this->addToAssertionCount(1);
    }

    public function testCheckDoesNotResetActivityTimestamp(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('release')->once();
        $pool->shouldReceive('getOption')->twice()->andReturn(new PoolOption(maxIdleTime: 60.0));
        $connection = new ActiveConnectionStub($container, $pool);

        $connection->release();
        $lastUseTime = $connection->getLastUseTime();

        $this->assertTrue($connection->check());
        $this->assertSame($lastUseTime, $connection->getLastUseTime());
    }
}
