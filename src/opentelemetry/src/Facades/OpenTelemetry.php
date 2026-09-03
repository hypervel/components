<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Facades;

use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\Support\Facades\Facade;

/**
 * @method static void enrichExceptionsUsing(\Closure $enricher)
 * @method static void extend(string $driver, \Closure $factory)
 * @method static \OpenTelemetry\Context\ContextInterface extract(array<string, mixed> $carrier, \OpenTelemetry\Context\ContextInterface|null $context = null)
 * @method static bool flush()
 * @method static array<string, mixed> inject(array<string, mixed> $carrier = [], \OpenTelemetry\Context\ContextInterface|null $context = null)
 * @method static \OpenTelemetry\API\Logs\LoggerInterface logger(string $name = 'hypervel.application', string|null $version = null, string|null $schemaUrl = null, iterable $attributes = [])
 * @method static \OpenTelemetry\API\Metrics\MeterInterface meter(string $name = 'hypervel.application', string|null $version = null, string|null $schemaUrl = null, iterable $attributes = [])
 * @method static \OpenTelemetry\Context\Propagation\TextMapPropagatorInterface propagator()
 * @method static void resolveCacheKeyUsing(\Closure $resolver)
 * @method static void resolveRedisQueryTextUsing(\Closure $resolver)
 * @method static void resolveUrlTemplateUsing(\Closure $resolver)
 * @method static void resolveUserUsing(\Closure $resolver)
 * @method static mixed trace(string $name, \Closure $callback, iterable $attributes = [])
 * @method static \OpenTelemetry\API\Trace\TracerInterface tracer(string $name = 'hypervel.application', string|null $version = null, string|null $schemaUrl = null, iterable $attributes = [])
 *
 * @see \Hypervel\OpenTelemetry\OpenTelemetryManager
 */
class OpenTelemetry extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return OpenTelemetryManager::class;
    }
}
