<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\ServerProcess\Exceptions\ServerInvalidException;
use Hypervel\ServerProcess\Exceptions\SocketAcceptException;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class ExceptionTest extends TestCase
{
    public function testServerInvalidExceptionExtendsRuntimeException()
    {
        $exception = new ServerInvalidException('test');
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('test', $exception->getMessage());
    }

    public function testSocketAcceptExceptionExtendsRuntimeException()
    {
        $exception = new SocketAcceptException('test');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testSocketAcceptExceptionIsTimeoutWithTimeoutCode()
    {
        $exception = new SocketAcceptException('Socket timed out', SOCKET_ETIMEDOUT);
        $this->assertTrue($exception->isTimeout());
    }

    public function testSocketAcceptExceptionIsNotTimeoutWithOtherCode()
    {
        $exception = new SocketAcceptException('Socket closed', SOCKET_ECONNRESET);
        $this->assertFalse($exception->isTimeout());
    }

    public function testSocketAcceptExceptionIsNotTimeoutWithZeroCode()
    {
        $exception = new SocketAcceptException('Socket closed', 0);
        $this->assertFalse($exception->isTimeout());
    }
}
