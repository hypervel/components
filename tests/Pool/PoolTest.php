<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\FrequencyInterface;
use Hypervel\Pool\Pool;
use Hypervel\Tests\Pool\Stub\FooPool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class PoolTest extends TestCase
{
    public function testPoolFlush()
    {
        $container = $this->getContainer();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn((function () {
            $logger = m::mock(StdoutLoggerInterface::class);
            $logger->shouldReceive('error')->withAnyArgs()->times(4)->andReturn(true);
            return $logger;
        })());
        $pool = new FooPool($container, []);

        $conns = [];
        for ($i = 0; $i < 5; ++$i) {
            $conns[] = $pool->get();
        }

        foreach ($conns as $conn) {
            $pool->release($conn);
        }

        $pool->flush();
        $this->assertSame(1, $pool->getConnectionsInChannel());
        $this->assertSame(1, $pool->getCurrentConnections());
    }

    public function testPoolFlushOne()
    {
        $container = $this->getContainer();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn((function () {
            $logger = m::mock(StdoutLoggerInterface::class);
            $logger->shouldReceive('error')->withAnyArgs()->times(3)->andReturn(true);
            return $logger;
        })());
        $pool = new FooPool($container, []);

        $conns = [];
        $checks = [false, false, true, true, true];
        for ($i = 0; $i < 5; ++$i) {
            $conn = $pool->get();
            $conn->shouldReceive('check')->andReturn(array_shift($checks));
            $conns[] = $conn;
        }

        foreach ($conns as $conn) {
            $pool->release($conn);
        }

        $pool->flushOne();
        $this->assertSame(4, $pool->getConnectionsInChannel());
        $this->assertSame(4, $pool->getCurrentConnections());
        $pool->flushOne(true);
        $this->assertSame(3, $pool->getConnectionsInChannel());
        $this->assertSame(3, $pool->getCurrentConnections());
        $pool->flushOne(true);
        $this->assertSame(2, $pool->getConnectionsInChannel());
        $this->assertSame(2, $pool->getCurrentConnections());
        $pool->flushOne();
        $this->assertSame(2, $pool->getConnectionsInChannel());
        $this->assertSame(2, $pool->getCurrentConnections());
    }

    public function testPoolFlushAll()
    {
        $container = $this->getContainer();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn((function () {
            $logger = m::mock(StdoutLoggerInterface::class);
            $logger->shouldReceive('error')->withAnyArgs()->times(5)->andReturn(true);
            return $logger;
        })());
        $pool = new FooPool($container, []);

        $conns = [];
        for ($i = 0; $i < 5; ++$i) {
            $conns[] = $pool->get();
        }

        foreach ($conns as $conn) {
            $pool->release($conn);
        }

        $this->assertSame(5, $pool->getConnectionsInChannel());
        $this->assertSame(5, $pool->getCurrentConnections());

        $pool->flushAll();

        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertSame(0, $pool->getCurrentConnections());
    }

    public function testFrequenctHitFailed()
    {
        $container = $this->getContainer();
        $container->shouldReceive('has')->andReturnTrue();
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')->with(m::any())->once()->andReturnUsing(function ($args) {
            $this->assertStringContainsString('Hit Failed', $args);
        });
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn($logger);

        $pool = new class($container, []) extends Pool {
            public function __construct(ContainerContract $container, array $config = [])
            {
                parent::__construct($container, $config);

                $this->frequency = m::mock(FrequencyInterface::class);
                $this->frequency->shouldReceive('hit')->andThrow(new RuntimeException('Hit Failed'));
            }

            protected function createConnection(): ConnectionInterface
            {
                return m::mock(ConnectionInterface::class);
            }
        };

        $this->assertInstanceOf(ConnectionInterface::class, $pool->get());
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerContract::class);
        ApplicationContext::setContainer($container);

        return $container;
    }
}
