<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class PoolErrorReporterTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    public function testReportsThroughTheSupportFailureReporter(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $exception = new RuntimeException('reported through handler');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($exception);
        $container->instance(ExceptionHandler::class, $handler);

        PoolErrorReporter::report($exception);
    }
}
