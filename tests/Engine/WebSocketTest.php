<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Contracts\Engine\WebSocket\WebSocketInterface;
use Hypervel\Engine\Exceptions\RuntimeException as EngineRuntimeException;
use Hypervel\Engine\WebSocket\Frame;
use Hypervel\Engine\WebSocket\Opcode;
use Hypervel\Engine\WebSocket\Response;
use Hypervel\Engine\WebSocket\WebSocket;
use Hypervel\Tests\TestCase;
use LogicException;
use stdClass;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server;

/**
 * Unit tests for WebSocket components.
 */
class WebSocketTest extends TestCase
{
    public function testFrameToString(): void
    {
        $frame = new Frame(payloadData: 'Hello World.');

        $this->assertIsString($string = (string) $frame);

        $sf = new SwooleFrame;
        $sf->data = 'Hello World.';
        $frame = Frame::from($sf);
        $this->assertSame($string, (string) $frame);
    }

    public function testFrameReportsComputedPayloadLength(): void
    {
        $frame = new Frame(payloadData: 'Hello');

        $this->assertSame(5, $frame->getPayloadLength());

        $frame->setPayloadData('Hello World');

        $this->assertSame(11, $frame->getPayloadLength());
    }

    public function testFrameModelsTheNativeMaskFlag(): void
    {
        $frame = new Frame(payloadData: 'Hello');
        $masked = $frame->withMask(true);

        $this->assertFalse($frame->getMask());
        $this->assertTrue($masked->getMask());

        $unpacked = SwooleFrame::unpack((string) $masked);
        $this->assertSame('Hello', $unpacked->data);
        $this->assertNotSame(0, $unpacked->flags & SWOOLE_WEBSOCKET_FLAG_MASK);

        $swooleFrame = new SwooleFrame;
        $swooleFrame->data = 'Hello';
        $swooleFrame->flags = SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_MASK;

        $this->assertTrue(Frame::from($swooleFrame)->getMask());
    }

    public function testNativeFramesDecodeMaskedCompressedPayloadsWithoutMutatingTheInput(): void
    {
        if (! defined('SWOOLE_HAVE_ZLIB')) {
            $this->markTestSkipped('Swoole was not compiled with zlib support.');
        }

        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires input-preserving WebSocket decoding from https://github.com/swoole/swoole-src/pull/6158.');
        }

        $payload = str_repeat('hypervel-websocket-frame:', 20);
        $packed = SwooleFrame::pack(
            $payload,
            Opcode::TEXT,
            SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_MASK | SWOOLE_WEBSOCKET_FLAG_COMPRESS,
        );
        $original = bin2hex($packed);

        $this->assertNotSame(0, ord($packed[0]) & 0x40);
        $this->assertNotSame(0, ord($packed[1]) & 0x80);

        $frame = SwooleFrame::unpack($packed);

        $this->assertInstanceOf(SwooleFrame::class, $frame);
        $this->assertSame($payload, $frame->data);
        $this->assertSame($original, bin2hex($packed));
    }

    public function testMaskedFramesUseFreshKeys(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires fresh WebSocket masking keys from https://github.com/swoole/swoole-src/pull/6165.');
        }

        $keys = [];

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $packed = (string) new Frame(mask: true, payloadData: 'hello');
            $keys[] = substr($packed, 2, 4);

            $this->assertSame('hello', SwooleFrame::unpack($packed)->data);
        }

        $this->assertGreaterThan(1, count(array_unique($keys)));
    }

    public function testCompressedControlFramesAreRejected(): void
    {
        if (! defined('SWOOLE_HAVE_ZLIB')) {
            $this->markTestSkipped('Swoole was not compiled with zlib support.');
        }

        $payload = "\x03\xe8bye";
        $packed = SwooleFrame::pack(
            $payload,
            Opcode::TEXT,
            SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS,
        );
        $textFrame = SwooleFrame::unpack($packed);

        $this->assertNotSame(0, ord($packed[0]) & 0x40);
        $this->assertSame(Opcode::TEXT, ord($packed[0]) & 0x0F);
        $this->assertInstanceOf(SwooleFrame::class, $textFrame);
        $this->assertSame($payload, $textFrame->data);

        foreach ([Opcode::CLOSE, Opcode::PING, Opcode::PONG] as $opcode) {
            $invalid = $packed;
            $invalid[0] = chr((ord($invalid[0]) & 0xF0) | $opcode);

            $this->assertNotSame(0, ord($invalid[0]) & 0x40);
            $this->assertSame($opcode, ord($invalid[0]) & 0x0F);
            $this->assertFalse(@SwooleFrame::unpack($invalid));
        }
    }

    public function testResponseReturnsTheNativeResponsePushResult(): void
    {
        $connection = new EngineTestSwooleResponse;
        $connection->pushResult = false;

        $this->assertFalse((new Response($connection))->push(new Frame(payloadData: 'Hello')));
    }

    public function testResponseReturnsTheNativeServerPushResult(): void
    {
        $connection = new EngineTestSwooleServer;
        $connection->pushResult = false;
        $response = new Response($connection);
        $response->init(42);

        $this->assertFalse($response->push(new Frame(payloadData: 'Hello')));
        $this->assertSame(42, $connection->pushedFd);
    }

    public function testWebSocketRejectsAFailedUpgrade(): void
    {
        $connection = new EngineTestSwooleResponse;
        $connection->upgradeResult = false;

        $this->expectException(EngineRuntimeException::class);

        new WebSocket($connection, new Request);
    }

    public function testWebSocketCleansUpAfterACallbackFailure(): void
    {
        $connection = new EngineTestSwooleResponse;
        $frame = new SwooleFrame;
        $frame->opcode = Opcode::TEXT;
        $frame->data = 'Hello';
        $connection->receivedFrames[] = $frame;
        $webSocket = new EngineInspectableWebSocket($connection, new Request);
        $webSocket->on(WebSocketInterface::ON_MESSAGE, static function (): never {
            throw new LogicException('Callback failed.');
        });

        try {
            $webSocket->start();
            $this->fail('Expected the message callback to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Callback failed.', $exception->getMessage());
        }

        $this->assertNull($webSocket->connection());
        $this->assertSame([], $webSocket->events());
    }

    public function testResponseGetFd(): void
    {
        $response = new Response(new stdClass);

        $response->init(123);
        $this->assertSame(123, $response->getFd());

        $sf = new SwooleFrame;
        $sf->fd = 1234;
        $response->init($sf);
        $this->assertSame(1234, $response->getFd());
    }
}

class EngineTestSwooleResponse extends SwooleResponse
{
    public bool $pushResult = true;

    public bool $upgradeResult = true;

    /**
     * @var array<int, false|string|SwooleFrame>
     */
    public array $receivedFrames = [];

    public function __construct()
    {
    }

    public function push(
        SwooleFrame|string $data,
        int $opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT,
        int $flags = SWOOLE_WEBSOCKET_FLAG_FIN
    ): bool {
        return $this->pushResult;
    }

    public function upgrade(): bool
    {
        return $this->upgradeResult;
    }

    public function recv(float $timeout = 0): SwooleFrame|string|false
    {
        return array_shift($this->receivedFrames) ?? false;
    }
}

class EngineTestSwooleServer extends Server
{
    public bool $pushResult = true;

    public int $pushedFd = 0;

    public function __construct()
    {
    }

    public function push(
        int $fd,
        SwooleFrame|string $data,
        int $opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT,
        int $flags = SWOOLE_WEBSOCKET_FLAG_FIN
    ): bool {
        $this->pushedFd = $fd;

        return $this->pushResult;
    }
}

class EngineInspectableWebSocket extends WebSocket
{
    public function connection(): ?SwooleResponse
    {
        return $this->connection;
    }

    /**
     * @return array<string, callable>
     */
    public function events(): array
    {
        return $this->events;
    }
}
