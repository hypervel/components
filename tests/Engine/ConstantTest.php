<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Constant;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Coroutine\Server;

/**
 * @internal
 * @coversNothing
 */
class ConstantTest extends TestCase
{
    use RunTestsInCoroutine;

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
