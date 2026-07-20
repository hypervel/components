<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\ServerProcess\Exceptions\SocketAcceptException;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ExceptionTest extends TestCase
{
    public function testSocketAcceptExceptionExtendsRuntimeException(): void
    {
        $exception = new SocketAcceptException('test');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testSocketAcceptExceptionIsTransientByDefault(): void
    {
        $exception = new SocketAcceptException('Socket temporarily unavailable');

        $this->assertFalse($exception->isPermanent());
    }

    public function testSocketAcceptExceptionCanBePermanent(): void
    {
        $exception = new SocketAcceptException('Socket closed', permanent: true);

        $this->assertTrue($exception->isPermanent());
    }
}
