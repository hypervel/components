<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Engine\Channel;
use Hypervel\ServerProcess\Events\PipeMessage;
use Hypervel\ServerProcess\Exceptions\SocketAcceptException;
use Hypervel\Tests\ServerProcess\Fixtures\FakeSocket;
use Hypervel\Tests\ServerProcess\Fixtures\ListenableProcess;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ListenMethodTest extends TestCase
{
    public function testListenerContinuesAfterSignalInterruption()
    {
        $dispatched = [];
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->with(m::type(SocketAcceptException::class))->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $process = new ListenableProcess($container);
        $process->fakeSocket = new FakeSocket([
            [false, SOCKET_EINTR],                     // 1st: signal interruption (transient)
            [serialize(['hello' => 'world']), 0],      // 2nd: valid data
        ]);

        $quit = new Channel(1);
        $process->callListen($quit);

        // Give the coroutine time to process both recv() calls.
        usleep(50_000);

        $quit->push(true);
        usleep(10_000);

        // The listener should have survived the transient error and dispatched the message.
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(PipeMessage::class, $dispatched[0]);
        $this->assertSame(['hello' => 'world'], $dispatched[0]->data);
        $this->assertGreaterThanOrEqual(2, $process->fakeSocket->getCallCount());
    }

    public function testListenerContinuesAfterEagain()
    {
        $dispatched = [];
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->with(m::type(SocketAcceptException::class))->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $process = new ListenableProcess($container);
        $process->fakeSocket = new FakeSocket([
            [false, SOCKET_EAGAIN],                    // 1st: temporarily unavailable (transient)
            [serialize(['data' => 'value']), 0],       // 2nd: valid data
        ]);

        $quit = new Channel(1);
        $process->callListen($quit);

        usleep(50_000);

        $quit->push(true);
        usleep(10_000);

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(PipeMessage::class, $dispatched[0]);
        $this->assertSame(['data' => 'value'], $dispatched[0]->data);
    }

    public function testListenerStopsOnPermanentSocketClosure()
    {
        $dispatched = [];
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->with(m::type(SocketAcceptException::class))->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $process = new ListenableProcess($container);
        $process->fakeSocket = new FakeSocket([
            ['', 0],                                   // Permanent closure (empty string)
            [serialize(['should' => 'not reach']), 0], // Should never be called
        ]);

        $quit = new Channel(1);
        $process->callListen($quit);

        usleep(50_000);

        // The listener should have stopped — no PipeMessage dispatched, only 1 recv() call.
        $this->assertCount(0, $dispatched);
        $this->assertSame(1, $process->fakeSocket->getCallCount());

        $quit->push(true);
        usleep(10_000);
    }

    public function testListenerStopsOnConnectionReset()
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->with(m::type(SocketAcceptException::class))->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $process = new ListenableProcess($container);
        $process->fakeSocket = new FakeSocket([
            [false, SOCKET_ECONNRESET],                // Permanent error
            [serialize(['should' => 'not reach']), 0], // Should never be called
        ]);

        $quit = new Channel(1);
        $process->callListen($quit);

        usleep(50_000);

        $this->assertSame(1, $process->fakeSocket->getCallCount());

        $quit->push(true);
        usleep(10_000);
    }

    public function testListenerStopsOnBadFileDescriptor()
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->with(m::type(SocketAcceptException::class))->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $process = new ListenableProcess($container);
        $process->fakeSocket = new FakeSocket([
            [false, SOCKET_EBADF],                     // Permanent error
            [serialize(['should' => 'not reach']), 0], // Should never be called
        ]);

        $quit = new Channel(1);
        $process->callListen($quit);

        usleep(50_000);

        $this->assertSame(1, $process->fakeSocket->getCallCount());

        $quit->push(true);
        usleep(10_000);
    }
}
