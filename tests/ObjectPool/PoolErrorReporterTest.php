<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class PoolErrorReporterTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testReportsThroughTheSupportFailureReporter(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $exception = new RuntimeException('reported through handler');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($exception);
        $container->instance(ExceptionHandler::class, $handler);

        PoolErrorReporter::report($exception);
    }
}
