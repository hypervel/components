<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Deferred;

use Closure;
use Generator;
use Hypervel\OpenTelemetry\Deferred\Metrics\DeferredMeterProvider;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
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
    use VerifiesDoubles;

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

        $workerCounter = Double::for(CounterInterface::class)->strict();
        $workerCounter->expects('isEnabled')->returns(true);
        $workerCounter->expects('add')->with(2, ['result' => 'ok'], null);
        $workerUpDownCounter = Double::for(UpDownCounterInterface::class)->strict();
        $workerUpDownCounter->expects('isEnabled')->returns(true);
        $workerUpDownCounter->expects('add')->with(-2, [], null);
        $workerHistogram = Double::for(HistogramInterface::class)->strict();
        $workerHistogram->expects('isEnabled')->returns(true);
        $workerHistogram->expects('record')->with(1.5, [], null);
        $workerGauge = Double::for(GaugeInterface::class)->strict();
        $workerGauge->expects('isEnabled')->returns(true);
        $workerGauge->expects('record')->with(20, [], null);
        $workerMeter = Double::for(MeterInterface::class)->strict();
        $workerMeter->expects('createCounter')
            ->with('billing.count', '{item}', 'Count items', ['key' => 'value'])
            ->returns($workerCounter);
        $workerMeter->expects('createUpDownCounter')
            ->with('billing.active', null, null, [])
            ->returns($workerUpDownCounter);
        $workerMeter->expects('createHistogram')
            ->with('billing.duration', 's', null, [])
            ->returns($workerHistogram);
        $workerMeter->expects('createGauge')
            ->with('billing.size', 'By', null, [])
            ->returns($workerGauge);
        $workerProvider = Double::for(MeterProviderInterface::class)->strict();
        $workerProvider->expects('getMeter')
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->returns($workerMeter);

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
        $firstCounterToken->expects('detach');
        $firstBatchToken->expects('detach');

        $provider->bind($secondProvider);

        $secondCounterToken->expects('detach');
        $secondBatchToken->expects('detach');
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
        $workerCounter = Double::for(ObservableCounterInterface::class)->strict();
        $workerCounter->allows('isEnabled')->returns(true);
        $workerMeter = Double::for(MeterInterface::class)->strict();
        $workerMeter->expects('createObservableCounter')
            ->with('runtime.count', null, null, [])
            ->returns($workerCounter);
        $workerProvider = Double::for(MeterProviderInterface::class)->strict();
        $workerProvider->expects('getMeter')
            ->with('runtime', null, null, [])
            ->returns($workerMeter);

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
        $delegateToken = Double::for(ObservableCallbackInterface::class)->strict();
        $delegateToken->expects('detach');
        $workerCounter = Double::for(ObservableCounterInterface::class)->strict();
        $workerCounter->expects('observe')
            ->with(Argument::type(Closure::class))
            ->resolves(function (Closure $callback) use ($delegateToken): ObservableCallbackInterface {
                $observer = Double::for(ObserverInterface::class)->strict();
                $observer->expects('observe')->with(1);
                $callback($observer);

                return $delegateToken;
            });
        $workerMeter = Double::for(MeterInterface::class)->strict();
        $workerMeter->expects('createObservableCounter')
            ->with('runtime.count', null, null, [])
            ->returns($workerCounter);
        $workerProvider = Double::for(MeterProviderInterface::class)->strict();
        $workerProvider->expects('getMeter')
            ->with('runtime', null, null, [])
            ->returns($workerMeter);

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
        $foreignGauge = Double::for(ObservableGaugeInterface::class)->strict();
        $callback = static function (ObserverInterface $counter, ObserverInterface $gauge): void {
            $counter->observe(1);
            $gauge->observe(2);
        };
        $counterDelegate = Double::for(ObservableCounterInterface::class)->strict();
        $delegateToken = Double::for(ObservableCallbackInterface::class)->strict();
        $workerMeter = Double::for(MeterInterface::class)->strict();
        $workerMeter->expects('createObservableCounter')
            ->with('runtime.count', null, null, [])
            ->returns($counterDelegate);
        $workerMeter->expects('batchObserve')
            ->with(Argument::same($callback), Argument::same($counterDelegate), Argument::same($foreignGauge))
            ->returns($delegateToken);
        $workerProvider = Double::for(MeterProviderInterface::class)->strict();
        $workerProvider->expects('getMeter')
            ->with('runtime', null, null, [])
            ->returns($workerMeter);

        $token = $meter->batchObserve($callback, $counter, $foreignGauge);
        $provider->bind($workerProvider);

        $delegateToken->expects('detach');
        $token->detach();
    }

    public function testMeterAndInstrumentsRequestedAfterBindComeDirectlyFromTheWorkerProvider(): void
    {
        $provider = new DeferredMeterProvider;
        $workerCounter = Double::for(CounterInterface::class)->strict();
        $workerMeter = Double::for(MeterInterface::class)->strict();
        $workerMeter->expects('createCounter')
            ->with('worker.count')
            ->returns($workerCounter);
        $workerProvider = Double::for(MeterProviderInterface::class)->strict();
        $workerProvider->expects('getMeter')
            ->with('worker', null, null, [])
            ->returns($workerMeter);

        $provider->bind($workerProvider);
        $meter = $provider->getMeter('worker');

        $this->assertSame($workerMeter, $meter);
        $this->assertSame($workerCounter, $meter->createCounter('worker.count'));
    }

    /**
     * Create a worker provider configured for observable replay.
     *
     * @return array{DoubleInterface&MeterProviderInterface, DoubleInterface&ObservableCallbackInterface, DoubleInterface&ObservableCallbackInterface}
     */
    private function observableProvider(
        callable $counterCreation,
        callable $gaugeCreation,
        callable $counterCallback,
        callable $batchCallback,
    ): array {
        $counterToken = Double::for(ObservableCallbackInterface::class)->strict();
        $batchToken = Double::for(ObservableCallbackInterface::class)->strict();
        $counter = Double::for(ObservableCounterInterface::class)->strict();
        $counter->allows('isEnabled')->returns(true);
        $counter->expects('observe')->with(Argument::same($counterCallback))->returns($counterToken);
        $gauge = Double::for(ObservableGaugeInterface::class)->strict();
        $gauge->allows('isEnabled')->returns(true);
        $upDownCounter = Double::for(ObservableUpDownCounterInterface::class)->strict();
        $upDownCounter->allows('isEnabled')->returns(true);
        $meter = Double::for(MeterInterface::class)->strict();
        $meter->expects('createObservableCounter')
            ->with('runtime.count', null, null, [], Argument::same($counterCreation))
            ->returns($counter);
        $meter->expects('createObservableGauge')
            ->with('runtime.gauge', null, null, [], Argument::same($gaugeCreation))
            ->returns($gauge);
        $meter->expects('createObservableUpDownCounter')
            ->with('runtime.active', null, null, [])
            ->returns($upDownCounter);
        $meter->expects('batchObserve')
            ->with(Argument::same($batchCallback), Argument::same($counter), Argument::same($gauge))
            ->returns($batchToken);
        $provider = Double::for(MeterProviderInterface::class)->strict();
        $provider->expects('getMeter')
            ->with('runtime', null, null, [])
            ->returns($meter);

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
