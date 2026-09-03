<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Bootstrap\CloseCallback;
use Hypervel\Core\Bootstrap\ConnectCallback;
use Hypervel\Core\Bootstrap\FinishCallback;
use Hypervel\Core\Bootstrap\ManagerStartCallback;
use Hypervel\Core\Bootstrap\ManagerStopCallback;
use Hypervel\Core\Bootstrap\PacketCallback;
use Hypervel\Core\Bootstrap\PipeMessageCallback;
use Hypervel\Core\Bootstrap\ReceiveCallback;
use Hypervel\Core\Bootstrap\ShutdownCallback;
use Hypervel\Core\Bootstrap\StartCallback;
use Hypervel\Core\Bootstrap\WorkerErrorCallback;
use Hypervel\Core\Bootstrap\WorkerStopCallback;
use Hypervel\Core\Events\OnClose;
use Hypervel\Core\Events\OnConnect;
use Hypervel\Core\Events\OnFinish;
use Hypervel\Core\Events\OnManagerStart;
use Hypervel\Core\Events\OnManagerStop;
use Hypervel\Core\Events\OnPacket;
use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Core\Events\OnReceive;
use Hypervel\Core\Events\OnShutdown;
use Hypervel\Core\Events\OnStart;
use Hypervel\Core\Events\OnWorkerError;
use Hypervel\Core\Events\OnWorkerStop;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Server;

class LifecycleCallbackTest extends TestCase
{
    #[DataProvider('callbacks')]
    public function testCallbackSkipsEventWithoutListeners(
        string $callbackClass,
        string $method,
        string $eventClass,
        array $arguments,
    ): void {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with($eventClass)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $callback = new $callbackClass($dispatcher);

        $this->assertNull($callback->{$method}(m::mock(Server::class), ...$arguments));
    }

    #[DataProvider('callbacks')]
    public function testCallbackDispatchesEventWhenListenedFor(
        string $callbackClass,
        string $method,
        string $eventClass,
        array $arguments,
    ): void {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with($eventClass)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type($eventClass));

        $callback = new $callbackClass($dispatcher);

        $this->assertNull($callback->{$method}(m::mock(Server::class), ...$arguments));
    }

    /**
     * Provide simple server lifecycle callbacks and their event arguments.
     */
    public static function callbacks(): array
    {
        return [
            'close' => [CloseCallback::class, 'onClose', OnClose::class, [12, 3]],
            'connect' => [ConnectCallback::class, 'onConnect', OnConnect::class, [12, 3]],
            'finish' => [FinishCallback::class, 'onFinish', OnFinish::class, [12, 'result']],
            'manager start' => [ManagerStartCallback::class, 'onManagerStart', OnManagerStart::class, []],
            'manager stop' => [ManagerStopCallback::class, 'onManagerStop', OnManagerStop::class, []],
            'packet' => [PacketCallback::class, 'onPacket', OnPacket::class, ['payload', ['address' => '127.0.0.1']]],
            'pipe message' => [PipeMessageCallback::class, 'onPipeMessage', OnPipeMessage::class, [3, 'payload']],
            'receive' => [ReceiveCallback::class, 'onReceive', OnReceive::class, [12, 3, 'payload']],
            'shutdown' => [ShutdownCallback::class, 'onShutdown', OnShutdown::class, []],
            'start' => [StartCallback::class, 'onStart', OnStart::class, []],
            'worker error' => [WorkerErrorCallback::class, 'onWorkerError', OnWorkerError::class, [2, 123, 1, 9]],
            'worker stop' => [WorkerStopCallback::class, 'onWorkerStop', OnWorkerStop::class, [3]],
        ];
    }
}
