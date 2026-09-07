<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\ContextInterface;
use Override;

/**
 * Keep a pre-fork counter usable after a worker meter is bound.
 *
 * @internal
 */
class DeferredCounter implements CounterInterface
{
    protected ?CounterInterface $delegate = null;

    /**
     * Create a deferred counter.
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
        $this->delegate = $meter->createCounter(
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
     * Add a value to the current counter.
     */
    #[Override]
    public function add(
        float|int $amount,
        iterable $attributes = [],
        ContextInterface|false|null $context = null,
    ): void {
        $this->delegate?->add($amount, $attributes, $context);
    }

    /**
     * Determine whether the current counter is enabled.
     */
    #[Override]
    public function isEnabled(): bool
    {
        return $this->delegate?->isEnabled() ?? false;
    }
}
