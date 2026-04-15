<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Listeners;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Tests\Server\Fixtures\DemoProcess;
use Hypervel\Tests\Server\Fixtures\InitProcessTitleListenerStub;
use Hypervel\Tests\Server\Fixtures\InitProcessTitleListenerStub2;
use Hypervel\Tests\TestCase;
use Mockery as m;

class InitProcessTitleListenerTest extends TestCase
{
    public function testProcessDefaultName()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(m::any())->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $listener = new InitProcessTitleListenerStub($container);
        $process = new DemoProcess($container);

        $listener->handle(new BeforeProcessHandle($process, 1));

        if (! $listener->isSupportedOS()) {
            $this->assertSame(null, CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame('test.demo.1', CoroutineContext::get('test.server.process.title'));
        }
    }

    public function testProcessName()
    {
        $name = 'hyperf-skeleton.' . uniqid();
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with('config')->andReturn(true);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('make')->with('config')->andReturn(new ConfigRepository([
            'app' => ['name' => $name],
        ]));

        $listener = new InitProcessTitleListenerStub($container);
        $process = new DemoProcess($container);

        $listener->handle(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertSame(null, CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame($name . '.test.demo.0', CoroutineContext::get('test.server.process.title'));
        }
    }

    public function testUserDefinedDot()
    {
        $name = 'hyperf-skeleton.' . uniqid();
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with('config')->andReturn(true);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('make')->with('config')->andReturn(new ConfigRepository([
            'app' => ['name' => $name],
        ]));

        $listener = new InitProcessTitleListenerStub2($container);
        $process = new DemoProcess($container);

        $listener->handle(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertSame(null, CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame($name . '#test.demo#0', CoroutineContext::get('test.server.process.title'));
        }
    }
}
