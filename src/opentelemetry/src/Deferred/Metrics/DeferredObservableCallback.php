<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use Closure;
use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use Override;
use WeakReference;

/**
 * Keep one detachable observable callback active across worker rebinding.
 *
 * @internal
 */
class DeferredObservableCallback implements ObservableCallbackInterface
{
    protected ?ObservableCallbackInterface $delegate = null;

    protected bool $active = true;

    /**
     * Create a deferred observable callback.
     *
     * @param non-empty-list<AsynchronousInstrument> $instruments
     */
    public function __construct(
        protected readonly DeferredMeter $meter,
        protected readonly Closure $callback,
        protected readonly array $instruments,
        protected readonly bool $batch,
        protected readonly ?WeakReference $target,
    ) {
    }

    /**
     * Bind this callback to the given worker meter.
     */
    public function bind(MeterInterface $meter): void
    {
        $this->unbind();

        if (! $this->active) {
            return;
        }

        $instruments = $this->meter->mapObservableInstruments($this->instruments);

        if ($this->batch) {
            $instrument = array_shift($instruments);
            $this->delegate = $meter->batchObserve($this->callback, $instrument, ...$instruments);

            return;
        }

        $instrument = $instruments[0];

        if ($instrument instanceof ObservableCounterInterface
            || $instrument instanceof ObservableGaugeInterface
            || $instrument instanceof ObservableUpDownCounterInterface) {
            $this->delegate = $instrument->observe($this->callback);
        }
    }

    /**
     * Unbind this callback from its current worker instrument.
     */
    public function unbind(): void
    {
        $this->delegate?->detach();
        $this->delegate = null;
    }

    /**
     * Detach this callback permanently.
     */
    #[Override]
    public function detach(): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;
        $this->unbind();
        $this->meter->forgetObservableCallback($this, $this->target);
    }
}
