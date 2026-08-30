<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Foundation\Http\HealthCheckController;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Coroutine\CanceledException;

class HealthCheckControllerTest extends TestCase
{
    public function testDiagnosisCancellationEscapesWithoutReportingOrRendering(): void
    {
        $cancellation = new CanceledException('cancelled');
        $application = m::mock(Application::class);
        $application->shouldNotReceive('hasDebugModeEnabled');
        $events = m::mock(Dispatcher::class);
        $events->expects('dispatch')
            ->with(m::type(DiagnosingHealth::class))
            ->andThrow($cancellation);
        $exceptions = m::mock(ExceptionHandler::class);
        $exceptions->shouldNotReceive('report');
        $responses = m::mock(ResponseFactory::class);
        $responses->shouldNotReceive('make', 'json');
        $views = m::mock(ViewFactory::class);
        $views->shouldNotReceive('file');
        $controller = new HealthCheckController(
            $application,
            $events,
            $exceptions,
            $responses,
            $views,
        );

        try {
            $controller(Request::create('/up'));
            $this->fail('Expected health diagnosis to preserve cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }
}
