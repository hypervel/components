<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\Context\Propagation\MultiResponsePropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Registry;
use RuntimeException;

class PropagatorFactory
{
    use LogsMessagesTrait;

    /**
     * Create a text-map propagator from registered names.
     *
     * @param list<string> $names
     */
    public function text(array $names): TextMapPropagatorInterface
    {
        $propagators = array_map($this->textPropagator(...), $names);

        return match (count($propagators)) {
            0 => NoopTextMapPropagator::getInstance(),
            1 => $propagators[0],
            default => new MultiTextMapPropagator($propagators),
        };
    }

    /**
     * Create a response propagator from registered names.
     *
     * @param list<string> $names
     */
    public function response(array $names): ResponsePropagatorInterface
    {
        $propagators = array_map($this->responsePropagator(...), $names);

        return match (count($propagators)) {
            0 => NoopResponsePropagator::getInstance(),
            1 => $propagators[0],
            default => new MultiResponsePropagator($propagators),
        };
    }

    /**
     * Resolve one registered text-map propagator.
     */
    protected function textPropagator(string $name): TextMapPropagatorInterface
    {
        try {
            return Registry::textMapPropagator($name);
        } catch (RuntimeException $exception) {
            self::logWarning($exception->getMessage());

            return NoopTextMapPropagator::getInstance();
        }
    }

    /**
     * Resolve one registered response propagator.
     */
    protected function responsePropagator(string $name): ResponsePropagatorInterface
    {
        try {
            return Registry::responsePropagator($name);
        } catch (RuntimeException $exception) {
            self::logWarning($exception->getMessage());

            return NoopResponsePropagator::getInstance();
        }
    }
}
