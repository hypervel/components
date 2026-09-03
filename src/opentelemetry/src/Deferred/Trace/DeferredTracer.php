<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Trace;

use Hypervel\OpenTelemetry\Deferred\InstrumentationScope;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Override;

/**
 * Keep a pre-fork tracer handle usable after a worker provider is bound.
 *
 * @internal
 */
class DeferredTracer implements TracerInterface
{
    protected ?TracerInterface $delegate = null;

    /**
     * Create a deferred tracer.
     */
    public function __construct(protected readonly InstrumentationScope $instrumentationScope)
    {
    }

    /**
     * Bind this handle to a tracer from the given provider.
     */
    public function bind(TracerProviderInterface $provider): void
    {
        $this->delegate = $provider->getTracer(
            $this->instrumentationScope->name,
            $this->instrumentationScope->version,
            $this->instrumentationScope->schemaUrl,
            $this->instrumentationScope->attributes,
        );
    }

    /**
     * Unbind this handle from its worker tracer.
     */
    public function unbind(): void
    {
        $this->delegate = null;
    }

    /**
     * Create a span builder from the current tracer.
     */
    #[Override]
    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        return ($this->delegate ?? NoopTracer::getInstance())->spanBuilder($spanName);
    }

    /**
     * Determine whether the current tracer is enabled.
     */
    #[Override]
    public function isEnabled(): bool
    {
        return $this->delegate?->isEnabled() ?? false;
    }
}
