<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\Pool\Stub\HeartbeatPoolStub;
use Hypervel\Tests\Pool\Stub\KeepaliveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class HeartbeatConnectionTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        // RunTestsInCoroutine resumes the WORKER_EXIT coordinator after each test,
        // closing its channel. Clear it so each test gets a fresh coordinator.
        CoordinatorManager::clear(Constants::WORKER_EXIT);
    }

    protected function tearDown(): void
    {
        Context::set('test.pool.heartbeat_connection', []);
        parent::tearDown();
    }

    public function testConnectionConstruct()
    {
        $container = $this->getContainer();
        $pool = $container->get(HeartbeatPoolStub::class);
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
        $pool = $container->get(HeartbeatPoolStub::class);
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
        $pool = $container->get(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();
        $timer = $connection->timer;
        $this->assertSame(1, count((new ClassInvoker($timer))->closures));
        $this->assertTrue($connection->check());
        $connection->close();
        $this->assertSame(0, count((new ClassInvoker($timer))->closures));
        $this->assertFalse($connection->check());
        $this->assertSame('close protocol', Context::get('test.pool.heartbeat_connection')['close']);
    }

    public function testConnectionDestruct()
    {
        $container = $this->getContainer();
        $pool = $container->get(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();
        $connection->release();

        $connection = $pool->get();
        $connection->reconnect();
        $connection->release();

        $pool->flush();

        $this->assertSame('close protocol', Context::get('test.pool.heartbeat_connection')['close']);
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerContract::class);
        Container::setInstance($container);

        $container->shouldReceive('get')->with(HeartbeatPoolStub::class)->andReturnUsing(function () use ($container) {
            return new HeartbeatPoolStub($container, []);
        });

        return $container;
    }
}
