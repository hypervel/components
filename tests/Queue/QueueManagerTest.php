<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Container\Container;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Queue\Connectors\ConnectorInterface;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueuePoolProxy;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class QueueManagerTest extends TestCase
{
    public function testDefaultConnectionCanBeResolved()
    {
        $container = $this->getContainer();
        $config = $container->get(ConfigInterface::class);
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.sync', ['driver' => 'sync']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('sync')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'sync'])->andReturn($queue);
        $manager->addConnector('sync', function () use ($connector) {
            return $connector;
        });

        $this->assertSame($queue, $manager->connection('sync'));
    }

    public function testOtherConnectionCanBeResolved()
    {
        $container = $this->getContainer();
        $config = $container->get(ConfigInterface::class);
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.foo', ['driver' => 'bar']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('foo')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'bar'])->andReturn($queue);
        $manager->addConnector('bar', function () use ($connector) {
            return $connector;
        });
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();

        $this->assertSame($queue, $manager->connection('foo'));
    }

    public function testNullConnectionCanBeResolved()
    {
        $container = $this->getContainer();
        $config = $container->get(ConfigInterface::class);
        $config->set('queue.default', 'null');

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('null')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'null'])->andReturn($queue);
        $manager->addConnector('null', function () use ($connector) {
            return $connector;
        });
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();

        $this->assertSame($queue, $manager->connection('null'));
    }

    public function testAddPoolableConnector()
    {
        $container = $this->getContainer();
        $config = $container->get(ConfigInterface::class);
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.foo', ['driver' => 'bar']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $manager->addConnector('bar', function () use ($connector) {
            return $connector;
        });
        $manager->addPoolable('bar');

        $this->assertInstanceOf(QueuePoolProxy::class, $manager->connection('foo'));
    }

    protected function getContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config([]),
                Encrypter::class => fn () => m::mock(Encrypter::class),
                PoolFactory::class => PoolManager::class,
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
