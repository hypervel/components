<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Engine\WebSocket\Opcode;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame as SwooleFrame;

/**
 * Integration tests for WebSocket.
 *
 * These tests require a WebSocket server running on the configured host/port.
 *
 * @internal
 * @coversNothing
 */
class WebSocketTest extends EngineIntegrationTestCase
{
    /**
     * The WebSocket server port for these tests.
     */
    protected int $httpServerPort = 9503;

    public function testWebSocket(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort(), false);
        $client->set(['open_websocket_pong_frame' => true]);
        $client->upgrade('/');

        $client->push('Hello World!', Opcode::TEXT);
        $ret = $client->recv(1);
        $this->assertInstanceOf(SwooleFrame::class, $ret);
        $this->assertSame('received: Hello World!', $ret->data);
        $this->assertSame(Opcode::TEXT, $ret->opcode);

        if (SWOOLE_VERSION_ID > 60102) {
            $client->push('', Opcode::PING);
            $ret = $client->recv(1);
            $this->assertInstanceOf(SwooleFrame::class, $ret);
            $this->assertSame(Opcode::PONG, $ret->opcode);
        }
    }
}
