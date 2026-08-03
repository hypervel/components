<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Pool\Fixtures\HeartbeatPoolStub;
use Hypervel\Tests\Pool\Fixtures\KeepaliveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class HeartbeatConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::set('test.pool.heartbeat_connection', []);
        parent::tearDown();
    }

    public function testConnectionConstruct(): void
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

    public function testConnectionCall(): void
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->setActiveConnection(new class {
            public function send(string $data): string
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

    public function testDiscardDelegatesToOwningPool(): void
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        $connection = $pool->get();

        $connection->discard();

        $this->assertSame(0, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getConnectionsInChannel());
    }

    public function testConnectionHeartbeat(): void
    {
        $container = $this->getContainer(['heartbeat' => 0.001]);
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();
        $timer = $connection->timer;
        $this->assertSame(1, count((new ClassInvoker($timer))->coroutines));
        $this->assertTrue($connection->check());
        $connection->close();
        $this->assertSame(0, count((new ClassInvoker($timer))->coroutines));
        $this->assertFalse($connection->check());
        $this->assertSame('close protocol', CoroutineContext::get('test.pool.heartbeat_connection')['close']);
    }

    public function testDisabledHeartbeatDoesNotStartTimer(): void
    {
        $container = $this->getContainer([
            'heartbeat' => -1,
            'max_idle_time' => 0.001,
        ]);
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();
        $timer = $connection->timer;

        $this->assertTrue($connection->check());
        $this->assertSame(0, count((new ClassInvoker($timer))->coroutines));

        Coroutine::sleep(0.01);

        $this->assertTrue($connection->check());
        $this->assertSame(0, $connection->closeCount);

        $connection->close();
    }

    public function testEnabledHeartbeatClosesIdleConnection(): void
    {
        $container = $this->getContainer([
            'heartbeat' => 0.001,
            'max_idle_time' => 0.001,
        ]);
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->get();
        $connection->reconnect();

        Coroutine::sleep(0.01);

        $this->assertFalse($connection->check());
        $this->assertSame(1, $connection->closeCount);
    }

    public function testHeartbeatFailureFallsBackToThePhpErrorLogWithoutALogger(): void
    {
        $directory = ParallelTesting::tempDir('HeartbeatConnectionTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $previousLogErrors = ini_set('log_errors', '1');

        try {
            $container = $this->getContainer(['heartbeat' => 0.001]);
            $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
            $pool = $container->make(HeartbeatPoolStub::class);
            /** @var KeepaliveConnectionStub $connection */
            $connection = $pool->get();
            $connection->heartbeatFailure = new RuntimeException('heartbeat fallback failed');
            $connection->reconnect();

            Coroutine::sleep(0.01);

            $this->assertFalse($connection->check());
            $this->assertSame(1, $connection->closeCount);
            $contents = file_get_contents($errorLog);
            $this->assertIsString($contents);
            $this->assertStringContainsString('heartbeat fallback failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            if ($previousLogErrors !== false) {
                ini_set('log_errors', $previousLogErrors);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testConnectionCloseProtocolRunsOnPoolFlush(): void
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

    protected function getContainer(array $poolConfig = []): ContainerContract
    {
        $container = m::mock(ContainerContract::class);
        Container::setInstance($container);

        $container->shouldReceive('make')->with(HeartbeatPoolStub::class)->andReturnUsing(function () use ($container, $poolConfig) {
            return new HeartbeatPoolStub($container, 'test', $poolConfig);
        });

        return $container;
    }
}
