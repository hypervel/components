<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Deferred;

use Closure;
use Generator;
use Hypervel\OpenTelemetry\Deferred\Metrics\DeferredMeterProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use WeakReference;

class DeferredMetricsTest extends TestCase
{
    public function testPreBindSynchronousInstrumentsDropMeasurementsAndBindWithTheirDescriptors(): void
    {
        $provider = new DeferredMeterProvider;
        $attributes = $this->scopeAttributes();
        $meter = $provider->getMeter('billing', '1.0', 'https://schema.test', $attributes);
        $counter = $meter->createCounter('billing.count', '{item}', 'Count items', ['key' => 'value']);
        $upDownCounter = $meter->createUpDownCounter('billing.active');
        $histogram = $meter->createHistogram('billing.duration', 's');
        $gauge = $meter->createGauge('billing.size', 'By');

        $counter->add(1);
        $upDownCounter->add(-1);
        $histogram->record(0.5);
        $gauge->record(10);

        $this->assertFalse($counter->isEnabled());
        $this->assertFalse($upDownCounter->isEnabled());
        $this->assertFalse($histogram->isEnabled());
        $this->assertFalse($gauge->isEnabled());
        $this->assertFalse($attributes->valid());

        $workerCounter = m::mock(CounterInterface::class);
        $workerCounter->shouldReceive('isEnabled')->once()->andReturnTrue();
        $workerCounter->shouldReceive('add')->once()->with(2, ['result' => 'ok'], null);
        $workerUpDownCounter = m::mock(UpDownCounterInterface::class);
        $workerUpDownCounter->shouldReceive('isEnabled')->once()->andReturnTrue();
        $workerUpDownCounter->shouldReceive('add')->once()->with(-2, [], null);
        $workerHistogram = m::mock(HistogramInterface::class);
        $workerHistogram->shouldReceive('isEnabled')->once()->andReturnTrue();
        $workerHistogram->shouldReceive('record')->once()->with(1.5, [], null);
        $workerGauge = m::mock(GaugeInterface::class);
        $workerGauge->shouldReceive('isEnabled')->once()->andReturnTrue();
        $workerGauge->shouldReceive('record')->once()->with(20, [], null);
        $workerMeter = m::mock(MeterInterface::class);
        $workerMeter->shouldReceive('createCounter')
            ->once()
            ->with('billing.count', '{item}', 'Count items', ['key' => 'value'])
            ->andReturn($workerCounter);
        $workerMeter->shouldReceive('createUpDownCounter')
            ->once()
            ->with('billing.active', null, null, [])
            ->andReturn($workerUpDownCounter);
        $workerMeter->shouldReceive('createHistogram')
            ->once()
            ->with('billing.duration', 's', null, [])
            ->andReturn($workerHistogram);
        $workerMeter->shouldReceive('createGauge')
            ->once()
            ->with('billing.size', 'By', null, [])
            ->andReturn($workerGauge);
        $workerProvider = m::mock(MeterProviderInterface::class);
        $workerProvider->shouldReceive('getMeter')
            ->once()
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->andReturn($workerMeter);

        $provider->bind($workerProvider);

        $this->assertTrue($counter->isEnabled());
        $this->assertTrue($upDownCounter->isEnabled());
        $this->assertTrue($histogram->isEnabled());
        $this->assertTrue($gauge->isEnabled());
        $counter->add(2, ['result' => 'ok']);
        $upDownCounter->add(-2);
        $histogram->record(1.5);
        $gauge->record(20);

        $provider->unbind();

        $this->assertFalse($counter->isEnabled());
        $counter->add(3);
    }

    public function testObservableCreationAndRegistrationsReplayWithoutResurrectingDetachedCallbacks(): void
    {
        $provider = new DeferredMeterProvider;
        $meter = $provider->getMeter('runtime');
        $counterCreation = static function (ObserverInterface $observer): void {
            $observer->observe(1);
        };
        $gaugeCreation = static function (ObserverInterface $observer): void {
            $observer->observe(2);
        };
        $counterCallback = static function (ObserverInterface $observer): void {
            $observer->observe(3);
        };
        $detachedCallback = static function (ObserverInterface $observer): void {
            $observer->observe(4);
        };
        $batchCallback = static function (ObserverInterface $counter, ObserverInterface $gauge): void {
            $counter->observe(5);
            $gauge->observe(6);
        };
        $counter = $meter->createObservableCounter('runtime.count', callbacks: $counterCreation);
        $gauge = $meter->createObservableGauge('runtime.gauge', advisory: $gaugeCreation);
        $upDownCounter = $meter->createObservableUpDownCounter('runtime.active');
        $counterToken = $counter->observe($counterCallback);
        $detachedToken = $gauge->observe($detachedCallback);
        $batchToken = $meter->batchObserve($batchCallback, $counter, $gauge);

        $detachedToken->detach();

        [$firstProvider, $firstCounterToken, $firstBatchToken] = $this->observableProvider(
            $counterCreation,
            $gaugeCreation,
            $counterCallback,
            $batchCallback,
        );

        $provider->bind($firstProvider);

        $this->assertTrue($counter->isEnabled());
        $this->assertTrue($gauge->isEnabled());
        $this->assertTrue($upDownCounter->isEnabled());

        [$secondProvider, $secondCounterToken, $secondBatchToken] = $this->observableProvider(
            $counterCreation,
            $gaugeCreation,
            $counterCallback,
            $batchCallback,
        );
        $firstCounterToken->shouldReceive('detach')->once();
        $firstBatchToken->shouldReceive('detach')->once();

        $provider->bind($secondProvider);

        $secondCounterToken->shouldReceive('detach')->once();
        $secondBatchToken->shouldReceive('detach')->once();
        $counterToken->detach();
        $batchToken->detach();

        $provider->unbind();
    }

    public function testBoundObjectCallbackIsDetachedWhenItsTargetIsCollected(): void
    {
        $provider = new DeferredMeterProvider;
        $meter = $provider->getMeter('runtime');
        $counter = $meter->createObservableCounter('runtime.count');
        $target = new DeferredMetricsCallbackTarget;
        $targetReference = WeakReference::create($target);
        $counter->observe($target->callback());
        $workerCounter = m::mock(ObservableCounterInterface::class);
        $workerCounter->shouldReceive('isEnabled')->andReturnTrue();
        $workerMeter = m::mock(MeterInterface::class);
        $workerMeter->shouldReceive('createObservableCounter')
            ->once()
            ->with('runtime.count', null, null, [])
            ->andReturn($workerCounter);
        $workerProvider = m::mock(MeterProviderInterface::class);
        $workerProvider->shouldReceive('getMeter')
            ->once()
            ->with('runtime', null, null, [])
            ->andReturn($workerMeter);

        unset($target);
        gc_collect_cycles();

        $this->assertNull($targetReference->get());

        $provider->bind($workerProvider);
    }

    public function testCollectingBoundObjectDetachesItsCurrentDelegateToken(): void
    {
        $provider = new DeferredMeterProvider;
        $meter = $provider->getMeter('runtime');
        $counter = $meter->createObservableCounter('runtime.count');
        $target = new DeferredMetricsCallbackTarget;
        $targetReference = WeakReference::create($target);
        $counter->observe([$target, 'observe']);
        $delegateToken = m::mock(ObservableCallbackInterface::class);
        $delegateToken->shouldReceive('detach')->once();
        $workerCounter = m::mock(ObservableCounterInterface::class);
        $workerCounter->shouldReceive('observe')
            ->once()
            ->with(m::type(Closure::class))
            ->andReturnUsing(function (Closure $callback) use ($delegateToken): ObservableCallbackInterface {
                $observer = m::mock(ObserverInterface::class);
                $observer->shouldReceive('observe')->once()->with(1);
                $callback($observer);

                return $delegateToken;
            });
        $workerMeter = m::mock(MeterInterface::class);
        $workerMeter->shouldReceive('createObservableCounter')
            ->once()
            ->with('runtime.count', null, null, [])
            ->andReturn($workerCounter);
        $workerProvider = m::mock(MeterProviderInterface::class);
        $workerProvider->shouldReceive('getMeter')
            ->once()
            ->with('runtime', null, null, [])
            ->andReturn($workerMeter);

        $provider->bind($workerProvider);
        $this->assertSame(1, $target->observations);
        unset($target);
        gc_collect_cycles();

        $this->assertNull($targetReference->get());
    }

    public function testBatchObservationMapsOwnedInstrumentsAndLeavesForeignInstrumentsToTheSdk(): void
    {
        $provider = new DeferredMeterProvider;
        $meter = $provider->getMeter('runtime');
        $counter = $meter->createObservableCounter('runtime.count');
        $foreignGauge = m::mock(ObservableGaugeInterface::class);
        $callback = static function (ObserverInterface $counter, ObserverInterface $gauge): void {
            $counter->observe(1);
            $gauge->observe(2);
        };
        $counterDelegate = m::mock(ObservableCounterInterface::class);
        $delegateToken = m::mock(ObservableCallbackInterface::class);
        $workerMeter = m::mock(MeterInterface::class);
        $workerMeter->shouldReceive('createObservableCounter')
            ->once()
            ->with('runtime.count', null, null, [])
            ->andReturn($counterDelegate);
        $workerMeter->shouldReceive('batchObserve')
            ->once()
            ->with($callback, $counterDelegate, $foreignGauge)
            ->andReturn($delegateToken);
        $workerProvider = m::mock(MeterProviderInterface::class);
        $workerProvider->shouldReceive('getMeter')
            ->once()
            ->with('runtime', null, null, [])
            ->andReturn($workerMeter);

        $token = $meter->batchObserve($callback, $counter, $foreignGauge);
        $provider->bind($workerProvider);

        $delegateToken->shouldReceive('detach')->once();
        $token->detach();
    }

    public function testMeterAndInstrumentsRequestedAfterBindComeDirectlyFromTheWorkerProvider(): void
    {
        $provider = new DeferredMeterProvider;
        $workerCounter = m::mock(CounterInterface::class);
        $workerMeter = m::mock(MeterInterface::class);
        $workerMeter->shouldReceive('createCounter')
            ->once()
            ->with('worker.count')
            ->andReturn($workerCounter);
        $workerProvider = m::mock(MeterProviderInterface::class);
        $workerProvider->shouldReceive('getMeter')
            ->once()
            ->with('worker', null, null, [])
            ->andReturn($workerMeter);

        $provider->bind($workerProvider);
        $meter = $provider->getMeter('worker');

        $this->assertSame($workerMeter, $meter);
        $this->assertSame($workerCounter, $meter->createCounter('worker.count'));
    }

    /**
     * Create a worker provider configured for observable replay.
     *
     * @return array{MeterProviderInterface, ObservableCallbackInterface, ObservableCallbackInterface}
     */
    private function observableProvider(
        callable $counterCreation,
        callable $gaugeCreation,
        callable $counterCallback,
        callable $batchCallback,
    ): array {
        $counterToken = m::mock(ObservableCallbackInterface::class);
        $batchToken = m::mock(ObservableCallbackInterface::class);
        $counter = m::mock(ObservableCounterInterface::class);
        $counter->shouldReceive('isEnabled')->andReturnTrue();
        $counter->shouldReceive('observe')->once()->with($counterCallback)->andReturn($counterToken);
        $gauge = m::mock(ObservableGaugeInterface::class);
        $gauge->shouldReceive('isEnabled')->andReturnTrue();
        $upDownCounter = m::mock(ObservableUpDownCounterInterface::class);
        $upDownCounter->shouldReceive('isEnabled')->andReturnTrue();
        $meter = m::mock(MeterInterface::class);
        $meter->shouldReceive('createObservableCounter')
            ->once()
            ->with('runtime.count', null, null, [], $counterCreation)
            ->andReturn($counter);
        $meter->shouldReceive('createObservableGauge')
            ->once()
            ->with('runtime.gauge', null, null, [], $gaugeCreation)
            ->andReturn($gauge);
        $meter->shouldReceive('createObservableUpDownCounter')
            ->once()
            ->with('runtime.active', null, null, [])
            ->andReturn($upDownCounter);
        $meter->shouldReceive('batchObserve')
            ->once()
            ->with($batchCallback, $counter, $gauge)
            ->andReturn($batchToken);
        $provider = m::mock(MeterProviderInterface::class);
        $provider->shouldReceive('getMeter')
            ->once()
            ->with('runtime', null, null, [])
            ->andReturn($meter);

        return [$provider, $counterToken, $batchToken];
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

class DeferredMetricsCallbackTarget
{
    public int $observations = 0;

    /**
     * Return an object-bound observation callback.
     */
    public function callback(): Closure
    {
        return function (ObserverInterface $observer): void {
            $this->observe($observer);
        };
    }

    /**
     * Observe a value.
     */
    public function observe(ObserverInterface $observer): void
    {
        ++$this->observations;
        $observer->observe(1);
    }
}
