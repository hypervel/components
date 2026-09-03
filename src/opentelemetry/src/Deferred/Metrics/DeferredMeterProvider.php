<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use Hypervel\OpenTelemetry\Deferred\InstrumentationScope;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Override;

/**
 * Defer real meter creation until a worker-local provider is available.
 */
class DeferredMeterProvider implements MeterProviderInterface
{
    protected ?MeterProviderInterface $delegate = null;

    /** @var list<DeferredMeter> */
    protected array $meters = [];

    /**
     * Return a meter for the given instrumentation scope.
     */
    #[Override]
    public function getMeter(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): MeterInterface {
        if ($this->delegate !== null) {
            return $this->delegate->getMeter($name, $version, $schemaUrl, $attributes);
        }

        return $this->meters[] = new DeferredMeter(
            new InstrumentationScope($name, $version, $schemaUrl, $attributes),
        );
    }

    /**
     * Bind every pre-fork meter to a worker-local provider.
     */
    public function bind(MeterProviderInterface $provider): void
    {
        $this->delegate = $provider;

        foreach ($this->meters as $meter) {
            $meter->bind($provider);
        }
    }

    /**
     * Unbind every pre-fork meter from its worker-local provider.
     */
    public function unbind(): void
    {
        foreach ($this->meters as $meter) {
            $meter->unbind();
        }

        $this->delegate = null;
    }
}
