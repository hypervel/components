<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use Override;

/**
 * Keep a pre-fork observable gauge and its creation callbacks replayable.
 *
 * @internal
 */
class DeferredObservableGauge implements ObservableGaugeInterface
{
    protected ?ObservableGaugeInterface $delegate = null;

    /**
     * Create a deferred observable gauge.
     *
     * @param list<callable> $callbacks
     */
    public function __construct(
        protected readonly DeferredMeter $meter,
        protected readonly string $name,
        protected readonly ?string $unit,
        protected readonly ?string $description,
        protected readonly array $advisory,
        protected readonly array $callbacks,
    ) {
    }

    /**
     * Bind this handle to an instrument from the given meter.
     */
    public function bind(MeterInterface $meter): void
    {
        $this->delegate = $meter->createObservableGauge(
            $this->name,
            $this->unit,
            $this->description,
            $this->advisory,
            ...$this->callbacks,
        );
    }

    /**
     * Unbind this handle from its worker instrument.
     */
    public function unbind(): void
    {
        $this->delegate = null;
    }

    /**
     * Register an observation callback that follows worker rebinding.
     */
    #[Override]
    public function observe(callable $callback): ObservableCallbackInterface
    {
        return $this->meter->registerObservableCallback($callback, [$this]);
    }

    /**
     * Determine whether this instrument belongs to the given deferred meter.
     */
    public function belongsTo(DeferredMeter $meter): bool
    {
        return $this->meter === $meter;
    }

    /**
     * Return the current worker instrument.
     */
    public function delegate(): ?ObservableGaugeInterface
    {
        return $this->delegate;
    }

    /**
     * Determine whether the current observable gauge is enabled.
     */
    #[Override]
    public function isEnabled(): bool
    {
        return $this->delegate?->isEnabled() ?? false;
    }
}
