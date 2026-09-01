<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\ContextInterface;
use Override;

/**
 * Keep a pre-fork gauge usable after a worker meter is bound.
 *
 * @internal
 */
class DeferredGauge implements GaugeInterface
{
    protected ?GaugeInterface $delegate = null;

    /**
     * Create a deferred gauge.
     */
    public function __construct(
        protected readonly string $name,
        protected readonly ?string $unit,
        protected readonly ?string $description,
        protected readonly array $advisory,
    ) {
    }

    /**
     * Bind this handle to an instrument from the given meter.
     */
    public function bind(MeterInterface $meter): void
    {
        $this->delegate = $meter->createGauge(
            $this->name,
            $this->unit,
            $this->description,
            $this->advisory,
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
     * Record a value in the current gauge.
     */
    #[Override]
    public function record(
        float|int $amount,
        iterable $attributes = [],
        ContextInterface|false|null $context = null,
    ): void {
        $this->delegate?->record($amount, $attributes, $context);
    }

    /**
     * Determine whether the current gauge is enabled.
     */
    #[Override]
    public function isEnabled(): bool
    {
        return $this->delegate?->isEnabled() ?? false;
    }
}
