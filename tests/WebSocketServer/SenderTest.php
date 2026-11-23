<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Engine\WebSocket\Frame;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Sender;
use Hypervel\WebSocketServer\SenderPipeMessage;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;

class SenderTest extends TestCase
{
    use VerifiesDoubles;

    public function testCheckUsesCachedServerAndRecognizesOnlyActiveConnections(): void
    {
        $server = $this->server();
        $server->expects('connection_info')->times(4)->returns(
            false,
            [],
            ['websocket_status' => WEBSOCKET_STATUS_CLOSING],
            ['websocket_status' => WEBSOCKET_STATUS_ACTIVE],
        );
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
        $server->expects('connection_info')->times(2)->returns(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $server->expects('push')->with(42, 'first')->returns(true);
        $server->expects('push')->with(42, 'second')->returns(false);
        $server->expects('sendMessage')->never();
        $sender = $this->sender($server);

        $this->assertTrue($sender->push(42, 'first'));
        $this->assertFalse($sender->push(42, 'second'));
    }

    public function testDoesNotFanOutMissingProcessModeConnections(): void
    {
        $server = $this->server();
        $server->expects('connection_info')->with(42)->returns(false);
        $server->expects('sendMessage')->never();

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testDoesNotFanOutAfterLocalNativeFailure(): void
    {
        $server = $this->server(SWOOLE_BASE, workerCount: 2);
        $server->expects('connection_info')->with(42)->returns(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $server->expects('disconnect')->with(42)->returns(false);
        $server->expects('sendMessage')->never();

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testBaseModeFanOutReturnsTrueWhenEveryOtherWorkerAcceptsTheMessage(): void
    {
        $server = $this->server(SWOOLE_BASE, workerId: 1, workerCount: 3);
        $server->expects('connection_info')->with(42)->returns(false);
        $server->expects('sendMessage')
            ->with(Argument::satisfies(fn (SenderPipeMessage $message): bool => $message->name === 'disconnect'
                && $message->arguments === [42]), 0)
            ->returns(true);
        $server->expects('sendMessage')
            ->with(Argument::type(SenderPipeMessage::class), 2)
            ->returns(true);

        $this->assertTrue($this->sender($server)->disconnect(42));
    }

    public function testBaseModeFanOutAttemptsEveryWorkerAndReturnsFalseWhenOneRejectsTheMessage(): void
    {
        $server = $this->server(SWOOLE_BASE, workerId: 1, workerCount: 4);
        $server->expects('connection_info')->with(42)->returns(false);
        $server->expects('sendMessage')->with(Argument::type(SenderPipeMessage::class), 0)->returns(true);
        $server->expects('sendMessage')->with(Argument::type(SenderPipeMessage::class), 2)->returns(false);
        $server->expects('sendMessage')->with(Argument::type(SenderPipeMessage::class), 3)->returns(true);

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testBaseModeFanOutReturnsFalseWithoutAnotherWorker(): void
    {
        $server = $this->server(SWOOLE_BASE);
        $server->expects('connection_info')->with(42)->returns(false);
        $server->expects('sendMessage')->never();

        $this->assertFalse($this->sender($server)->disconnect(42));
    }

    public function testPushFrameReturnsLocalNativeFailureWithoutFanOut(): void
    {
        $server = $this->server(SWOOLE_BASE, workerCount: 2);
        $server->expects('connection_info')->with(42)->returns(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $server->expects('push')
            ->with(42, 'payload', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN)
            ->returns(false);
        $server->expects('sendMessage')->never();

        $this->assertFalse($this->sender($server)->pushFrame(42, new Frame(payloadData: 'payload')));
    }

    public function testPushFrameUsesBaseModeFanOutForANonLocalConnection(): void
    {
        $server = $this->server(SWOOLE_BASE, workerCount: 2);
        $server->expects('connection_info')->with(42)->returns(false);
        $server->expects('sendMessage')
            ->with(Argument::satisfies(fn (SenderPipeMessage $message): bool => $message->name === 'push'
                && $message->arguments === [42, 'payload', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN]), 1)
            ->returns(true);

        $this->assertTrue($this->sender($server)->pushFrame(42, new Frame(payloadData: 'payload')));
    }

    /**
     * Create a Sender backed by the given native server.
     */
    protected function sender(WebSocketServer $server): Sender
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(Server::class)->returns($server);

        return new Sender($container->instance());
    }

    /**
     * Create a native WebSocket server mock with worker state.
     */
    protected function server(
        int $mode = SWOOLE_PROCESS,
        int $workerId = 0,
        int $workerCount = 1,
    ): WebSocketServer&DoubleInterface {
        $server = Double::for(WebSocketServer::class)->strict();
        $server->mode = $mode;
        $server->worker_id = $workerId;
        $server->setting = ['worker_num' => $workerCount];

        return $server;
    }
}
