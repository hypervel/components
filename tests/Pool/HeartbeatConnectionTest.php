<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\Pool\Fixtures\HeartbeatPoolStub;
use Hypervel\Tests\Pool\Fixtures\KeepaliveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

class HeartbeatConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::set('test.pool.heartbeat_connection', []);
        parent::tearDown();
    }

    public function testConnectionConstruct()
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        $connection = $pool->get();

        $this->assertInstanceOf(KeepaliveConnectionStub::class, $connection);
        $this->assertSame(1, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getConnectionsInChannel());

        $connection = $pool->get();
        $this->assertSame(2, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getConnectionsInChannel());

        $connection->release();
        $this->assertSame(1, $pool->getConnectionsInChannel());

        $connection = $pool->get();
        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertSame(2, $pool->getCurrentConnections());
    }

    public function testConnectionCall()
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->setActiveConnection($conn = new class {
            public function send(string $data)
            {
                return str_repeat($data, 2);
            }
        });
        $str = uniqid();
        $result = $connection->call(function ($connection) use ($str) {
            return $connection->send($str);
        });

        $this->assertSame($result, str_repeat($str, 2));
    }

    public function testConnectionHeartbeat()
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();
        $timer = $connection->timer;
        $this->assertSame(1, count((new ClassInvoker($timer))->closures));
        $this->assertTrue($connection->check());
        $connection->close();
        $this->assertSame(0, count((new ClassInvoker($timer))->closures));
        $this->assertFalse($connection->check());
        $this->assertSame('close protocol', CoroutineContext::get('test.pool.heartbeat_connection')['close']);
    }

    public function testConnectionDestruct()
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();
        $connection->release();

        $connection = $pool->get();
        $connection->reconnect();
        $connection->release();

        $pool->flush();

        $this->assertSame('close protocol', CoroutineContext::get('test.pool.heartbeat_connection')['close']);
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerContract::class);
        Container::setInstance($container);

        $container->shouldReceive('make')->with(HeartbeatPoolStub::class)->andReturnUsing(function () use ($container) {
            return new HeartbeatPoolStub($container, 'test', []);
        });

        return $container;
    }
}
