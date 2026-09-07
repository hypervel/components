<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Deferred;

use Generator;
use Hypervel\OpenTelemetry\Deferred\Trace\DeferredTracerProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

class DeferredTraceTest extends TestCase
{
    public function testPreBindTracerReplaysItsScopeAcrossBindUnbindAndRebind(): void
    {
        $provider = new DeferredTracerProvider;
        $attributes = $this->scopeAttributes();
        $tracer = $provider->getTracer('billing', '1.0', 'https://schema.test', $attributes);

        $this->assertFalse($tracer->isEnabled());
        $this->assertInstanceOf(SpanBuilderInterface::class, $tracer->spanBuilder('before-bind'));
        $this->assertFalse($attributes->valid());

        $firstBuilder = m::mock(SpanBuilderInterface::class);
        $firstTracer = m::mock(TracerInterface::class);
        $firstTracer->shouldReceive('isEnabled')->once()->andReturnTrue();
        $firstTracer->shouldReceive('spanBuilder')->once()->with('first')->andReturn($firstBuilder);
        $firstProvider = m::mock(TracerProviderInterface::class);
        $firstProvider->shouldReceive('getTracer')
            ->once()
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->andReturn($firstTracer);

        $provider->bind($firstProvider);

        $this->assertTrue($tracer->isEnabled());
        $this->assertSame($firstBuilder, $tracer->spanBuilder('first'));

        $provider->unbind();

        $this->assertFalse($tracer->isEnabled());

        $secondTracer = m::mock(TracerInterface::class);
        $secondTracer->shouldReceive('isEnabled')->once()->andReturnTrue();
        $secondProvider = m::mock(TracerProviderInterface::class);
        $secondProvider->shouldReceive('getTracer')
            ->once()
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->andReturn($secondTracer);

        $provider->bind($secondProvider);

        $this->assertTrue($tracer->isEnabled());
    }

    public function testTracerRequestedAfterBindComesDirectlyFromTheWorkerProvider(): void
    {
        $provider = new DeferredTracerProvider;
        $workerTracer = m::mock(TracerInterface::class);
        $workerProvider = m::mock(TracerProviderInterface::class);
        $workerProvider->shouldReceive('getTracer')
            ->once()
            ->with('worker', null, null, [])
            ->andReturn($workerTracer);

        $provider->bind($workerProvider);

        $this->assertSame($workerTracer, $provider->getTracer('worker'));
    }

    /**
     * Yield one instrumentation-scope attribute.
     *
     * @return Generator<non-empty-string, string>
     */
    private function scopeAttributes(): Generator
    {
        yield 'scope.kind' => 'test';
    }
}
