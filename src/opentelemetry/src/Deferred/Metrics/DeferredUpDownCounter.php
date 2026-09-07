<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use Override;

/**
 * Keep a pre-fork up-down counter usable after a worker meter is bound.
 *
 * @internal
 */
class DeferredUpDownCounter implements UpDownCounterInterface
{
    protected ?UpDownCounterInterface $delegate = null;

    /**
     * Create a deferred up-down counter.
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
        $this->delegate = $meter->createUpDownCounter(
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
     * Add a value to the current up-down counter.
     */
    #[Override]
    public function add(mixed $amount, iterable $attributes = [], mixed $context = null): void
    {
        $this->delegate?->add($amount, $attributes, $context);
    }

    /**
     * Determine whether the current up-down counter is enabled.
     */
    #[Override]
    public function isEnabled(): bool
    {
        return $this->delegate?->isEnabled() ?? false;
    }
}
