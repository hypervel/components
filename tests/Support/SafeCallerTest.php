<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\SafeCaller;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SafeCallerTest extends TestCase
{
    use VerifiesDoubles;

    public function testCallReturnsClosureResult(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $caller = new SafeCaller($container->instance());

        $result = $caller->call(fn () => 'hello');

        $this->assertSame('hello', $result);
    }

    public function testCallReportsExceptionAndReturnsNull(): void
    {
        $exception = new RuntimeException('test error');

        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with($exception);

        $container = new Container;
        $container->instance(ExceptionHandlerContract::class, $handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(fn () => throw $exception);

        $this->assertNull($result);
    }

    public function testCallReturnsDefaultClosureOnException(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report');

        $container = new Container;
        $container->instance(ExceptionHandlerContract::class, $handler);

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
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with($exception)->throws($reportingFailure);
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(ExceptionHandlerContract::class)->returns(true);
        $container->expects('make')->with(ExceptionHandlerContract::class)->returns($handler);

        try {
            $result = (new SafeCaller($container->instance()))->call(
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

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'cleanup wraps cancellation')]
    public function testCallPreservesCancellationFromCallback(bool $wrapped): void
    {
        $cancellation = new CanceledException('canceled');

        if ($wrapped) {
            $cancellation = new RuntimeException('Callback cleanup failed.', previous: $cancellation);
        }
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->never();
        $caller = new SafeCaller($container->instance());

        try {
            $caller->call(fn () => throw $cancellation);

            $this->fail('The cancellation was not preserved.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'cleanup wraps cancellation')]
    public function testCallPreservesCancellationFromExceptionReporter(bool $wrapped): void
    {
        $failure = new RuntimeException('failed');
        $cancellation = new CanceledException('canceled');

        if ($wrapped) {
            $cancellation = new RuntimeException('Reporter cleanup failed.', previous: $cancellation);
        }

        $defaultCalled = false;
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with($failure)->throws($cancellation);
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(ExceptionHandlerContract::class)->returns(true);
        $container->expects('make')->with(ExceptionHandlerContract::class)->returns($handler);
        $caller = new SafeCaller($container->instance());

        try {
            $caller->call(
                fn () => throw $failure,
                function () use (&$defaultCalled): void {
                    $defaultCalled = true;
                },
            );

            $this->fail('The cancellation was not preserved.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertFalse($defaultCalled);
    }

    public function testCallWithoutExceptionHandlerInContainer(): void
    {
        $container = new Container;

        $caller = new SafeCaller($container);
        $result = $caller->call(fn () => throw new RuntimeException('fail'));

        $this->assertNull($result);
    }

    public function testCallWithNullDefaultReturnsNull(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report');

        $container = new Container;
        $container->instance(ExceptionHandlerContract::class, $handler);

        $caller = new SafeCaller($container);
        $result = $caller->call(
            fn () => throw new RuntimeException('fail'),
            null
        );

        $this->assertNull($result);
    }
}
