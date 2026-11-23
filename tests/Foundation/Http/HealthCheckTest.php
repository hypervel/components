<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Foundation\Http\HealthCheck;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class HealthCheckTest extends TestCase
{
    public function testHealthyResponseDoesNotDispatchWithoutListeners(): void
    {
        Event::shouldReceive('hasListeners')->once()->with(DiagnosingHealth::class)->andReturnFalse();
        Event::shouldReceive('dispatch')->never();
        Exceptions::fake();

        $response = (new HealthCheck)(Request::create('/up', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'up'], $response->getData(true));
        Exceptions::assertNothingReported();
    }

    public function testDiagnosisFailureEscapesWithoutReportingOrRenderingInDebugMode(): void
    {
        $failure = new RuntimeException('Database connection refused.');
        config(['app.debug' => true]);
        Event::listen(DiagnosingHealth::class, static fn () => throw $failure);
        Exceptions::fake();
        $this->mock(ResponseFactory::class)->shouldNotReceive('make', 'json');
        $caught = null;

        try {
            (new HealthCheck)(Request::create('/up'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        Exceptions::assertNothingReported();
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'cancellation through cleanup')]
    public function testDiagnosisCancellationEscapesWithoutReportingOrRendering(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('cancelled');
        $failure = $cleanupThrows
            ? new RuntimeException('Health probe cleanup failed.', previous: $cancellation)
            : $cancellation;
        config(['app.debug' => false]);
        Event::listen(DiagnosingHealth::class, static fn () => throw $failure);
        Exceptions::fake();
        $this->mock(ResponseFactory::class)->shouldNotReceive('make', 'json');
        $caught = null;

        try {
            (new HealthCheck)(Request::create('/up'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        Exceptions::assertNothingReported();
    }
}
