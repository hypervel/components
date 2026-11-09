<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\OpenTelemetry\Contracts\Instrumentation;
use Hypervel\OpenTelemetry\Support\InstrumentationOptions;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SemConv\Version;
use Swoole\Coroutine\CanceledException;
use Throwable;

abstract class AbstractInstrumentation implements Instrumentation
{
    protected InstrumentationOptions $options;

    /**
     * Register the instrumentation with normalized options.
     */
    final public function register(array $options): void
    {
        $this->options = new InstrumentationOptions($options);
        $this->registerInstrumentation();
    }

    /**
     * Register listeners, observers, and instruments.
     */
    abstract protected function registerInstrumentation(): void;

    /**
     * Determine whether trace output is enabled.
     */
    protected function tracesEnabled(): bool
    {
        return $this->options->enabled('traces');
    }

    /**
     * Determine whether a metric is enabled.
     */
    protected function metricEnabled(string $name): bool
    {
        return $this->options->metricEnabled($name);
    }

    /**
     * Determine whether the throwable is a direct coroutine cancellation.
     *
     * Ending an operation by direct cancellation is control flow, not a failure.
     */
    protected function isDirectCancellation(?Throwable $exception): bool
    {
        // @phpstan-ignore hypervel.cancellation.exactType (only a direct cancellation is not a failure; a cleanup failure carrying it is recorded as one)
        return $exception instanceof CanceledException;
    }

    /**
     * Return the semantic conventions schema URL for Hypervel instrumentation scopes.
     *
     * Every tracer, meter and logger obtained for one scope must pass the same URL.
     */
    protected function schemaUrl(): string
    {
        return Version::VERSION_1_45_0->url();
    }

    /**
     * Index callback observers by their matching instrument name.
     *
     * The SDK supplies observers in the same order as the instruments passed
     * to batchObserve(), so the unchanged name order is the mapping contract.
     *
     * @param list<string> $names
     * @param list<ObserverInterface> $observers
     * @return array<string, ObserverInterface>
     */
    protected function indexObservers(array $names, array $observers): array
    {
        $indexed = [];

        foreach ($names as $index => $name) {
            $indexed[$name] = $observers[$index];
        }

        return $indexed;
    }
}
