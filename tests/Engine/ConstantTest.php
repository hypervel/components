<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Constant;
use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Coroutine\Server;

/**
 * @internal
 * @coversNothing
 */
class ConstantTest extends EngineTestCase
{
    public function testEngine()
    {
        $this->assertSame('Swoole', Constant::ENGINE);
    }

    public function testIsCoroutineServer()
    {
        $this->assertTrue(Constant::isCoroutineServer(new HttpServer('127.0.0.1')));
        $this->assertTrue(Constant::isCoroutineServer(new Server('127.0.0.1')));
    }
}
