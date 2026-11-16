<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Deferred;

use Generator;
use Hypervel\OpenTelemetry\Deferred\Trace\DeferredTracerProvider;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

class DeferredTraceTest extends TestCase
{
    use VerifiesDoubles;

    public function testPreBindTracerReplaysItsScopeAcrossBindUnbindAndRebind(): void
    {
        $provider = new DeferredTracerProvider;
        $attributes = $this->scopeAttributes();
        $tracer = $provider->getTracer('billing', '1.0', 'https://schema.test', $attributes);

        $this->assertFalse($tracer->isEnabled());
        $this->assertInstanceOf(SpanBuilderInterface::class, $tracer->spanBuilder('before-bind'));
        $this->assertFalse($attributes->valid());

        $firstBuilder = Double::for(SpanBuilderInterface::class)->strict();
        $firstTracer = Double::for(TracerInterface::class)->strict();
        $firstTracer->expects('isEnabled')->returns(true);
        $firstTracer->expects('spanBuilder')->with('first')->returns($firstBuilder);
        $firstProvider = Double::for(TracerProviderInterface::class)->strict();
        $firstProvider->expects('getTracer')
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->returns($firstTracer);

        $provider->bind($firstProvider);

        $this->assertTrue($tracer->isEnabled());
        $this->assertSame($firstBuilder, $tracer->spanBuilder('first'));

        $provider->unbind();

        $this->assertFalse($tracer->isEnabled());

        $secondTracer = Double::for(TracerInterface::class)->strict();
        $secondTracer->expects('isEnabled')->returns(true);
        $secondProvider = Double::for(TracerProviderInterface::class)->strict();
        $secondProvider->expects('getTracer')
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->returns($secondTracer);

        $provider->bind($secondProvider);

        $this->assertTrue($tracer->isEnabled());
    }

    public function testTracerRequestedAfterBindComesDirectlyFromTheWorkerProvider(): void
    {
        $provider = new DeferredTracerProvider;
        $workerTracer = Double::for(TracerInterface::class)->strict();
        $workerProvider = Double::for(TracerProviderInterface::class)->strict();
        $workerProvider->expects('getTracer')
            ->with('worker', null, null, [])
            ->returns($workerTracer);

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
