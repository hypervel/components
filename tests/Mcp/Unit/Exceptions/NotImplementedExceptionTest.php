<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Exceptions;

use Exception;
use Hypervel\Mcp\Exceptions\NotImplementedException;
use Hypervel\Tests\TestCase;

class NotImplementedExceptionTest extends TestCase
{
    public function testCreatesExceptionForUnimplementedMethod(): void
    {
        $exception = NotImplementedException::forMethod('TestClass', 'testMethod');

        $this->assertInstanceOf(NotImplementedException::class, $exception);
        $this->assertSame('The method [TestClass@testMethod] is not implemented yet.', $exception->getMessage());
    }

    public function testCanBeThrownLikeAnyException(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->expectExceptionMessage('The method [MyClass@myMethod] is not implemented yet.');
        throw NotImplementedException::forMethod('MyClass', 'myMethod');
    }

    public function testExtendsBaseExceptionClass(): void
    {
        $exception = NotImplementedException::forMethod('SomeClass', 'someMethod');

        $this->assertInstanceOf(Exception::class, $exception);
    }
}
