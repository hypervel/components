<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\FailureReporter;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class FailureReporterTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $tempDir;

    protected string $errorLog;

    protected string|false|null $previousErrorLog = null;

    protected string|false|null $previousLogErrors = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('FailureReporterTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
        $this->errorLog = $this->tempDir . '/php-error.log';
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== null && $this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }

        if ($this->previousLogErrors !== null && $this->previousLogErrors !== false) {
            ini_set('log_errors', $this->previousLogErrors);
        }

        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testReportsThroughTheBoundExceptionHandler(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $exception = new RuntimeException('reported through handler');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($exception);
        $container->instance(ExceptionHandler::class, $handler);

        FailureReporter::report($exception);

        $this->assertFileDoesNotExist($this->errorLog);
    }

    public function testFallsBackToThePhpErrorLogWithoutAHandler(): void
    {
        Container::setInstance(new Container);
        $this->routeErrorsToTempFile();

        FailureReporter::report(new RuntimeException('fallback without handler'));

        $this->assertStringContainsString('fallback without handler', $this->errorLogContents());
    }

    public function testHandlerFailureFallsBackWithoutPropagating(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $exception = new RuntimeException('original cleanup failure');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($exception)->andThrow(new RuntimeException('handler failed'));
        $container->instance(ExceptionHandler::class, $handler);
        $this->routeErrorsToTempFile();

        FailureReporter::report($exception);

        $contents = $this->errorLogContents();
        $this->assertStringContainsString('original cleanup failure', $contents);
        $this->assertStringNotContainsString('handler failed', $contents);
    }

    public function testCancellationIsNotReportedOrLogged(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');
        $container->instance(ExceptionHandler::class, $handler);
        $this->routeErrorsToTempFile();

        FailureReporter::report(new CanceledException('cleanup canceled'));

        $this->assertFileDoesNotExist($this->errorLog);
    }

    public function testCancellationFromTheExceptionHandlerIsNotLogged(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $exception = new RuntimeException('original cleanup failure');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($exception)->andThrow(
            new CanceledException('reporting canceled')
        );
        $container->instance(ExceptionHandler::class, $handler);
        $this->routeErrorsToTempFile();

        FailureReporter::report($exception);

        $this->assertFileDoesNotExist($this->errorLog);
    }

    public function testPreferCancellationReportsTheFailureDisplacedByCancellation(): void
    {
        $failure = new RuntimeException('displaced failure');
        $cancellation = new CanceledException('canceled');
        $this->bindReportingHandler()->shouldReceive('report')->once()->with($failure);

        $this->assertSame($cancellation, FailureReporter::preferCancellation($failure, $cancellation));
    }

    public function testPreferCancellationKeepsTheFirstFailureAndReportsTheLaterOne(): void
    {
        $first = new RuntimeException('first failure');
        $later = new RuntimeException('later failure');
        $this->bindReportingHandler()->shouldReceive('report')->once()->with($later);

        $this->assertSame($first, FailureReporter::preferCancellation($first, $later));
    }

    public function testPreferCancellationReportsNothingWithoutADistinctLoser(): void
    {
        $failure = new RuntimeException('failure');
        $this->bindReportingHandler()->shouldNotReceive('report');

        $this->assertSame($failure, FailureReporter::preferCancellation(null, $failure));
        $this->assertSame($failure, FailureReporter::preferCancellation($failure, $failure));
    }

    private function bindReportingHandler(): MockInterface
    {
        $container = new Container;
        Container::setInstance($container);
        $handler = m::mock(ExceptionHandler::class);
        $container->instance(ExceptionHandler::class, $handler);

        return $handler;
    }

    private function errorLogContents(): string
    {
        $contents = file_get_contents($this->errorLog);
        $this->assertIsString($contents);

        return $contents;
    }

    private function routeErrorsToTempFile(): void
    {
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
        $this->previousLogErrors = ini_set('log_errors', '1');
    }
}
