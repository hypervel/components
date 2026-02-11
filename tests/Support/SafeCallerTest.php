<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Hypervel\Contracts\Container\Container;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class SafeCallerTest extends TestCase
{
    public function testCallReturnsClosureResult()
    {
        $container = m::mock(Container::class);
        $caller = new SafeCaller($container);

        $result = $caller->call(fn () => 'hello');

        $this->assertSame('hello', $result);
    }

    public function testCallReportsExceptionAndReturnsNull()
    {
        $exception = new RuntimeException('test error');

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($exception);

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('get')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(fn () => throw $exception);

        $this->assertNull($result);
    }

    public function testCallReturnsDefaultClosureOnException()
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('get')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(
            fn () => throw new RuntimeException('fail'),
            fn () => 'fallback'
        );

        $this->assertSame('fallback', $result);
    }

    public function testCallWithoutExceptionHandlerInContainer()
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnFalse();

        $caller = new SafeCaller($container);
        $result = $caller->call(fn () => throw new RuntimeException('fail'));

        $this->assertNull($result);
    }

    public function testCallWithNullDefaultReturnsNull()
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('get')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(
            fn () => throw new RuntimeException('fail'),
            null
        );

        $this->assertNull($result);
    }
}
