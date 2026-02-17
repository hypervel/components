<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Listener;

use Hyperf\Process\Event\BeforeProcessHandle;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Context\Context;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Framework\Events\OnManagerStart;
use Hypervel\Framework\Events\OnStart;
use Hypervel\Server\Listener\InitProcessTitleListener;
use Hypervel\Tests\Server\Stub\DemoProcess;
use Hypervel\Tests\Server\Stub\InitProcessTitleListenerStub;
use Hypervel\Tests\Server\Stub\InitProcessTitleListenerStub2;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class InitProcessTitleListenerTest extends TestCase
{
    public function testInitProcessTitleListenerListen()
    {
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(m::any())->andReturn(false);

        $listener = new InitProcessTitleListener($container);

        $this->assertSame([
            OnStart::class,
            OnManagerStart::class,
            AfterWorkerStart::class,
            BeforeProcessHandle::class,
        ], $listener->listen());
    }

    public function testProcessDefaultName()
    {
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(m::any())->andReturn(false);

        $listener = new InitProcessTitleListenerStub($container);
        $process = new DemoProcess($container);

        $listener->process(new BeforeProcessHandle($process, 1));

        if (! $listener->isSupportedOS()) {
            $this->assertSame(null, Context::get('test.server.process.title'));
        } else {
            $this->assertSame('test.demo.1', Context::get('test.server.process.title'));
        }
    }

    public function testProcessName()
    {
        $name = 'hyperf-skeleton.' . uniqid();
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(Repository::class)->andReturn(true);
        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturn(false);
        $container->shouldReceive('make')->with(Repository::class)->andReturn(new ConfigRepository([
            'app_name' => $name,
        ]));

        $listener = new InitProcessTitleListenerStub($container);
        $process = new DemoProcess($container);

        $listener->process(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertSame(null, Context::get('test.server.process.title'));
        } else {
            $this->assertSame($name . '.test.demo.0', Context::get('test.server.process.title'));
        }
    }

    public function testUserDefinedDot()
    {
        $name = 'hyperf-skeleton.' . uniqid();
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(Repository::class)->andReturn(true);
        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturn(false);
        $container->shouldReceive('make')->with(Repository::class)->andReturn(new ConfigRepository([
            'app_name' => $name,
        ]));

        $listener = new InitProcessTitleListenerStub2($container);
        $process = new DemoProcess($container);

        $listener->process(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertSame(null, Context::get('test.server.process.title'));
        } else {
            $this->assertSame($name . '#test.demo#0', Context::get('test.server.process.title'));
        }
    }
}
