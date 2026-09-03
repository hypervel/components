<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Cache\Events\CacheFailedOver;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheLocksFlushed;
use Hypervel\Cache\Events\CacheLocksFlushFailed;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyRetrievalFailed;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\ManyKeysRetrievalFailed;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;

class CacheInstrumentation extends AbstractInstrumentation
{
    use LogsMessagesTrait;

    protected const string OPERATIONS_METRIC = 'hypervel.cache.operations';

    protected bool $traceEvents = false;

    protected bool $captureKey = false;

    protected ?int $keyMaxLength = null;

    protected ?CounterInterface $operations = null;

    /**
     * Create cache instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected MeterProviderInterface $meterProvider,
        protected OpenTelemetryManager $openTelemetry,
    ) {
    }

    /**
     * Register cache listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        $this->traceEvents = $this->tracesEnabled();

        if ($this->traceEvents) {
            $this->captureKey = $this->options->enabled('key');
            /** @var null|int $keyMaxLength */
            $keyMaxLength = $this->options->get('key_max_length');
            $this->keyMaxLength = $keyMaxLength;
        }

        if ($this->metricEnabled(self::OPERATIONS_METRIC)) {
            $this->operations = $this->meterProvider
                ->getMeter('hypervel.cache')
                ->createCounter(
                    self::OPERATIONS_METRIC,
                    '{operation}',
                    'Number of completed cache operations.',
                );
        }

        if (! $this->traceEvents && $this->operations === null) {
            return;
        }

        $this->events->listen(CacheHit::class, function (CacheHit $event): void {
            $this->record('get', 'hit', $event->storeName, key: $event->key);
        });
        $this->events->listen(CacheMissed::class, function (CacheMissed $event): void {
            $this->record('get', 'miss', $event->storeName, key: $event->key);
        });
        $this->events->listen(KeyRetrievalFailed::class, function (KeyRetrievalFailed $event): void {
            $this->record('get', 'failure', $event->storeName, exception: $event->exception, key: $event->key);
        });
        $this->events->listen(ManyKeysRetrievalFailed::class, function (ManyKeysRetrievalFailed $event): void {
            $this->record('get', 'failure', $event->storeName, count: count($event->keys), exception: $event->exception);
        });
        $this->events->listen(KeyWritten::class, function (KeyWritten $event): void {
            $this->record('put', 'success', $event->storeName, key: $event->key, timeToLive: $event->seconds);
        });
        $this->events->listen(KeyWriteFailed::class, function (KeyWriteFailed $event): void {
            $this->record(
                'put',
                'failure',
                $event->storeName,
                exception: $event->exception,
                key: $event->key,
                timeToLive: $event->seconds,
            );
        });
        $this->events->listen(KeyForgotten::class, function (KeyForgotten $event): void {
            $this->record('forget', 'success', $event->storeName, key: $event->key);
        });
        $this->events->listen(KeyForgetFailed::class, function (KeyForgetFailed $event): void {
            $this->record('forget', 'failure', $event->storeName, exception: $event->exception, key: $event->key);
        });
        $this->events->listen(CacheFlushed::class, function (CacheFlushed $event): void {
            $this->record('flush', 'success', $event->storeName);
        });
        $this->events->listen(CacheFlushFailed::class, function (CacheFlushFailed $event): void {
            $this->record('flush', 'failure', $event->storeName, exception: $event->exception);
        });
        $this->events->listen(CacheLocksFlushed::class, function (CacheLocksFlushed $event): void {
            $this->record('lock_flush', 'success', $event->storeName);
        });
        $this->events->listen(CacheLocksFlushFailed::class, function (CacheLocksFlushFailed $event): void {
            $this->record('lock_flush', 'failure', $event->storeName, exception: $event->exception);
        });
        $this->events->listen(CacheFailedOver::class, function (CacheFailedOver $event): void {
            $this->record(
                'failover',
                'failure',
                $event->failoverStoreName,
                exception: $event->exception,
                failedStore: $event->storeName,
            );
        });
    }

    /**
     * Record one completed cache operation.
     */
    protected function record(
        string $operation,
        string $result,
        ?string $store,
        int $count = 1,
        ?Throwable $exception = null,
        ?string $key = null,
        ?int $timeToLive = null,
        ?string $failedStore = null,
    ): void {
        $span = $this->recordingSpan();

        if ($span === null && $this->operations === null) {
            return;
        }

        $attributes = array_filter([
            'hypervel.cache.operation' => $operation,
            'hypervel.cache.store' => $store,
            'result' => $result,
            ErrorAttributes::ERROR_TYPE => $exception === null ? null : $exception::class,
            'hypervel.cache.failed_store' => $failedStore,
        ], static fn (mixed $value): bool => $value !== null);
        $resolvedKey = null;

        if ($span !== null && $key !== null && $this->captureKey) {
            // The application resolver may cancel, and cancellation must emit no completion telemetry.
            $resolvedKey = $this->key($key, $store);
        }

        $this->operations?->add($count, $attributes);

        if ($span !== null) {
            if ($resolvedKey !== null) {
                $attributes['hypervel.cache.key'] = $resolvedKey;
            }

            if ($timeToLive !== null) {
                $attributes['hypervel.cache.ttl'] = $timeToLive;
            }

            $span->addEvent("hypervel.cache.{$operation}", $attributes);
        }
    }

    /**
     * Return the recording active span when trace events are enabled.
     */
    protected function recordingSpan(): ?SpanInterface
    {
        if (! $this->traceEvents) {
            return null;
        }

        $span = Span::getCurrent();

        return $span->isRecording() ? $span : null;
    }

    /**
     * Resolve the bounded cache key.
     */
    protected function key(string $key, ?string $store): ?string
    {
        $resolver = $this->openTelemetry->cacheKeyResolver();

        if ($resolver !== null) {
            try {
                $key = $resolver($key, $store);

                if ($key !== null && ! is_string($key)) {
                    throw new UnexpectedValueException(
                        'The OpenTelemetry cache-key resolver must return a string or null.',
                    );
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                self::logError('OpenTelemetry cache-key resolution failed.', ['exception' => $exception]);

                return null;
            }
        }

        if ($key === null || $this->keyMaxLength === null) {
            return $key;
        }

        return mb_substr($key, 0, $this->keyMaxLength, 'UTF-8');
    }
}
