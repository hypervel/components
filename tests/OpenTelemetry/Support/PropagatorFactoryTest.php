<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Hypervel\OpenTelemetry\Support\PropagatorFactory;
use Hypervel\Tests\TestCase;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\MultiResponsePropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\SDK\Registry;

class PropagatorFactoryTest extends TestCase
{
    public function testCreatesNoopSingleAndMultipleTextMapPropagators(): void
    {
        $factory = new PropagatorFactory;

        $this->assertInstanceOf(NoopTextMapPropagator::class, $factory->text([]));
        $this->assertInstanceOf(TraceContextPropagator::class, $factory->text(['tracecontext']));
        $this->assertInstanceOf(MultiTextMapPropagator::class, $factory->text(['tracecontext', 'baggage']));
    }

    public function testCreatesNoopSingleAndMultipleResponsePropagators(): void
    {
        $factory = new PropagatorFactory;
        $first = new NoopResponsePropagator;
        $second = new NoopResponsePropagator;

        Registry::registerResponsePropagator('hypervel-test-first', $first, true);
        Registry::registerResponsePropagator('hypervel-test-second', $second, true);

        $this->assertInstanceOf(NoopResponsePropagator::class, $factory->response([]));
        $this->assertSame($first, $factory->response(['hypervel-test-first']));
        $this->assertInstanceOf(
            MultiResponsePropagator::class,
            $factory->response(['hypervel-test-first', 'hypervel-test-second']),
        );
    }

    public function testUnknownNamesBecomeNoopPropagators(): void
    {
        $factory = new PropagatorFactory;

        $this->assertInstanceOf(NoopTextMapPropagator::class, $factory->text(['missing-text-propagator']));
        $this->assertInstanceOf(NoopResponsePropagator::class, $factory->response(['missing-response-propagator']));
    }

    public function testRegisteredTextMapPropagatorsRemainAvailable(): void
    {
        $this->assertInstanceOf(BaggagePropagator::class, (new PropagatorFactory)->text(['baggage']));
    }
}
