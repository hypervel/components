<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\SafeCaller;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class SafeCallerTest extends TestCase
{
    public function testCallReturnsClosureResult(): void
    {
        $container = m::mock(Container::class);
        $caller = new SafeCaller($container);

        $result = $caller->call(fn () => 'hello');

        $this->assertSame('hello', $result);
    }

    public function testCallReportsExceptionAndReturnsNull(): void
    {
        $exception = new RuntimeException('test error');

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($exception);

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(fn () => throw $exception);

        $this->assertNull($result);
    }

    public function testCallReturnsDefaultClosureOnException(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(
            fn () => throw new RuntimeException('fail'),
            fn () => 'fallback'
        );

        $this->assertSame('fallback', $result);
    }

    public function testCallReturnsDefaultWhenExceptionReportingFails(): void
    {
        $directory = ParallelTesting::tempDir('SafeCallerTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $exception = new RuntimeException('protected operation failed');
        $reportingFailure = new RuntimeException('exception reporting failed');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($exception)->andThrow($reportingFailure);
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->once()->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('make')->once()->with(ExceptionHandlerContract::class)->andReturn($handler);

        try {
            $result = (new SafeCaller($container))->call(
                static fn () => throw $exception,
                static fn () => 'fallback',
            );
            $contents = file_get_contents($errorLog);

            $this->assertSame('fallback', $result);
            $this->assertIsString($contents);
            $this->assertStringContainsString('protected operation failed', $contents);
            $this->assertStringContainsString('exception reporting failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testCallPreservesCancellationFromCallback(): void
    {
        $cancellation = new CanceledException('canceled');
        $container = m::mock(Container::class);
        $container->shouldNotReceive('has');
        $caller = new SafeCaller($container);

        try {
            $caller->call(fn () => throw $cancellation);

            $this->fail('The cancellation was not preserved.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCallPreservesCancellationFromExceptionReporter(): void
    {
        $failure = new RuntimeException('failed');
        $cancellation = new CanceledException('canceled');
        $defaultCalled = false;
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($failure)->andThrow($cancellation);
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->once()->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('make')->once()->with(ExceptionHandlerContract::class)->andReturn($handler);
        $caller = new SafeCaller($container);

        try {
            $caller->call(
                fn () => throw $failure,
                function () use (&$defaultCalled): void {
                    $defaultCalled = true;
                },
            );

            $this->fail('The cancellation was not preserved.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertFalse($defaultCalled);
    }

    public function testCallWithoutExceptionHandlerInContainer(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnFalse();

        $caller = new SafeCaller($container);
        $result = $caller->call(fn () => throw new RuntimeException('fail'));

        $this->assertNull($result);
    }

    public function testCallWithNullDefaultReturnsNull(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
        $container->shouldReceive('make')->with(ExceptionHandlerContract::class)->andReturn($handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(
            fn () => throw new RuntimeException('fail'),
            null
        );

        $this->assertNull($result);
    }
}
