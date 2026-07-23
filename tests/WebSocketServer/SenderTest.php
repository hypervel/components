<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Engine\WebSocket\Frame;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Sender;
use Hypervel\WebSocketServer\SenderPipeMessage;
use Mockery as m;
use Mockery\MockInterface;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;

class SenderTest extends TestCase
{
    public function testCheckUsesCachedServerAndRecognizesOnlyActiveConnections(): void
    {
        $server = $this->server();
        $server->shouldReceive('connection_info')->once()->andReturn(false);
        $server->shouldReceive('connection_info')->once()->andReturn([]);
        $server->shouldReceive('connection_info')->once()->andReturn(['websocket_status' => WEBSOCKET_STATUS_CLOSING]);
        $server->shouldReceive('connection_info')->once()->andReturn(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $sender = $this->sender($server);

        $this->assertFalse($sender->check(1));
        $this->assertFalse($sender->check(1));
        $this->assertFalse($sender->check(1));
        $this->assertTrue($sender->check(1));
    }

    // REMOVED: testSenderResult — Tests coroutine-server path (CoroutineServer::class config, setResponse(), direct push via $responses property). All of this code was removed in the Swoole-only simplification of Sender.php.

    public function testReturnsVerbatimNativeResultsForLocalConnections(): void
    {
        $server = $this->server();
        $server->shouldReceive('connection_info')->twice()->andReturn(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $server->shouldReceive('push')->once()->with(42, 'first')->andReturnTrue();
        $server->shouldReceive('push')->once()->with(42, 'second')->andReturnFalse();
        $server->shouldNotReceive('sendMessage');
        $sender = $this->sender($server);

        $this->assertTrue($sender->push(42, 'first'));
        $this->assertFalse($sender->push(42, 'second'));
    }

    public function testDoesNotFanOutMissingProcessModeConnections(): void
    {
        $server = $this->server();
        $server->shouldReceive('connection_info')->once()->with(42)->andReturnFalse();
        $server->shouldNotReceive('sendMessage');

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testDoesNotFanOutAfterLocalNativeFailure(): void
    {
        $server = $this->server(SWOOLE_BASE, workerCount: 2);
        $server->shouldReceive('connection_info')->once()->with(42)->andReturn(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $server->shouldReceive('disconnect')->once()->with(42)->andReturnFalse();
        $server->shouldNotReceive('sendMessage');

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testBaseModeFanOutReturnsTrueWhenEveryOtherWorkerAcceptsTheMessage(): void
    {
        $server = $this->server(SWOOLE_BASE, workerId: 1, workerCount: 3);
        $server->shouldReceive('connection_info')->once()->with(42)->andReturnFalse();
        $server->shouldReceive('sendMessage')->once()
            ->with(m::on(fn (SenderPipeMessage $message): bool => $message->name === 'disconnect'
                && $message->arguments === [42]), 0)
            ->andReturnTrue();
        $server->shouldReceive('sendMessage')->once()
            ->with(m::type(SenderPipeMessage::class), 2)
            ->andReturnTrue();

        $this->assertTrue($this->sender($server)->disconnect(42));
    }

    public function testBaseModeFanOutAttemptsEveryWorkerAndReturnsFalseWhenOneRejectsTheMessage(): void
    {
        $server = $this->server(SWOOLE_BASE, workerId: 1, workerCount: 4);
        $server->shouldReceive('connection_info')->once()->with(42)->andReturnFalse();
        $server->shouldReceive('sendMessage')->once()->with(m::type(SenderPipeMessage::class), 0)->andReturnTrue();
        $server->shouldReceive('sendMessage')->once()->with(m::type(SenderPipeMessage::class), 2)->andReturnFalse();
        $server->shouldReceive('sendMessage')->once()->with(m::type(SenderPipeMessage::class), 3)->andReturnTrue();

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testBaseModeFanOutReturnsFalseWithoutAnotherWorker(): void
    {
        $server = $this->server(SWOOLE_BASE);
        $server->shouldReceive('connection_info')->once()->with(42)->andReturnFalse();
        $server->shouldNotReceive('sendMessage');

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testPushFrameReturnsLocalNativeFailureWithoutFanOut(): void
    {
        $server = $this->server(SWOOLE_BASE, workerCount: 2);
        $server->shouldReceive('connection_info')->once()->with(42)->andReturn(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $server->shouldReceive('push')->once()
            ->with(42, 'payload', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN)
            ->andReturnFalse();
        $server->shouldNotReceive('sendMessage');

        $this->assertFalse($this->sender($server)->pushFrame(42, new Frame(payloadData: 'payload')));
    }

    public function testPushFrameUsesBaseModeFanOutForANonLocalConnection(): void
    {
        $server = $this->server(SWOOLE_BASE, workerCount: 2);
        $server->shouldReceive('connection_info')->once()->with(42)->andReturnFalse();
        $server->shouldReceive('sendMessage')->once()
            ->with(m::on(fn (SenderPipeMessage $message): bool => $message->name === 'push'
                && $message->arguments === [42, 'payload', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN]), 1)
            ->andReturnTrue();

        $this->assertTrue($this->sender($server)->pushFrame(42, new Frame(payloadData: 'payload')));
    }

    /**
     * Create a Sender backed by the given native server.
     */
    protected function sender(WebSocketServer $server): Sender
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with(Server::class)->andReturn($server);

        return new Sender($container);
    }

    /**
     * Create a native WebSocket server mock with worker state.
     */
    protected function server(
        int $mode = SWOOLE_PROCESS,
        int $workerId = 0,
        int $workerCount = 1,
    ): WebSocketServer&MockInterface {
        $server = m::mock(WebSocketServer::class);
        $server->mode = $mode;
        $server->worker_id = $workerId;
        $server->setting = ['worker_num' => $workerCount];

        return $server;
    }
}
