<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\WebSocket\Frame;
use Hypervel\Engine\WebSocket\Response;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use stdClass;
use Swoole\WebSocket\Frame as SwooleFrame;

/**
 * Unit tests for WebSocket components.
 *
 * @internal
 * @coversNothing
 */
class WebSocketTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testFrameToString()
    {
        $frame = new Frame(payloadData: 'Hello World.');

        $this->assertIsString($string = (string) $frame);

        $sf = new SwooleFrame();
        $sf->data = 'Hello World.';
        $frame = Frame::from($sf);
        $this->assertSame($string, (string) $frame);
    }

    public function testResponseGetFd()
    {
        $response = new Response(new stdClass());

        $response->init(123);
        $this->assertSame(123, $response->getFd());

        $sf = new SwooleFrame();
        $sf->fd = 1234;
        $response->init($sf);
        $this->assertSame(1234, $response->getFd());
    }
}
