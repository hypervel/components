<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Trace;

use Hypervel\OpenTelemetry\Deferred\InstrumentationScope;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Override;

/**
 * Defer real tracer creation until a worker-local provider is available.
 */
class DeferredTracerProvider implements TracerProviderInterface
{
    protected ?TracerProviderInterface $delegate = null;

    /** @var list<DeferredTracer> */
    protected array $tracers = [];

    /**
     * Return a tracer for the given instrumentation scope.
     */
    #[Override]
    public function getTracer(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): TracerInterface {
        if ($this->delegate !== null) {
            return $this->delegate->getTracer($name, $version, $schemaUrl, $attributes);
        }

        return $this->tracers[] = new DeferredTracer(
            new InstrumentationScope($name, $version, $schemaUrl, $attributes),
        );
    }

    /**
     * Bind every pre-fork tracer to a worker-local provider.
     */
    public function bind(TracerProviderInterface $provider): void
    {
        $this->delegate = $provider;

        foreach ($this->tracers as $tracer) {
            $tracer->bind($provider);
        }
    }

    /**
     * Unbind every pre-fork tracer from its worker-local provider.
     */
    public function unbind(): void
    {
        foreach ($this->tracers as $tracer) {
            $tracer->unbind();
        }

        $this->delegate = null;
    }
}
