<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry;

use Closure;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Engine\Channel;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use Hypervel\OpenTelemetry\Contracts\Instrumentation;
use Hypervel\OpenTelemetry\Deferred\Logs\DeferredLoggerProvider;
use Hypervel\OpenTelemetry\Deferred\Metrics\DeferredMeterProvider;
use Hypervel\OpenTelemetry\Deferred\Trace\DeferredTracerProvider;
use Hypervel\OpenTelemetry\Exporters\ConsoleExporterFactory;
use Hypervel\OpenTelemetry\Exporters\OtlpExporterFactory;
use Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\WebSocketInstrumentation;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\ProviderSet;
use InvalidArgumentException;
use LogicException;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use Swoole\Coroutine\CanceledException;
use Throwable;

class OpenTelemetryManager
{
    /** @var array<string, Closure> */
    protected array $customCreators = [];

    /** @var array<string, ExporterFactory> */
    protected array $exporterFactories = [];

    /** @var list<Closure> */
    protected array $exceptionEnrichers = [];

    protected ?Closure $urlTemplateResolver = null;

    protected ?Closure $userResolver = null;

    protected ?Closure $cacheKeyResolver = null;

    protected ?Closure $redisQueryTextResolver = null;

    protected ?ProviderSet $providers = null;

    protected ?ProcessIdentity $identity = null;

    /** @var array<string, mixed> */
    protected array $normalizedConfiguration = [];

    protected bool $bound = false;

    protected bool $closed = false;

    protected bool $flushing = false;

    protected bool $closing = false;

    protected ?Channel $flushCompletion = null;

    /**
     * Create a new OpenTelemetry manager.
     */
    public function __construct(
        protected Container $container,
        protected Repository $config,
        protected ConfigurationNormalizer $normalizer,
        protected ProviderFactory $providerFactory,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected MeterProviderInterface $meterProvider,
        protected TracerProviderInterface $tracerProvider,
        protected LoggerProviderInterface $loggerProvider,
        protected ?DeferredMeterProvider $deferredMeterProvider,
        protected ?DeferredTracerProvider $deferredTracerProvider,
        protected ?DeferredLoggerProvider $deferredLoggerProvider,
        protected TextMapPropagatorInterface $textMapPropagator,
        protected ResponsePropagatorInterface $responsePropagator,
        protected bool $enabled,
    ) {
    }

    /**
     * Return a meter for an instrumentation scope.
     */
    public function meter(
        string $name = 'hypervel.application',
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): MeterInterface {
        return $this->meterProvider->getMeter($name, $version, $schemaUrl, $attributes);
    }

    /**
     * Return a tracer for an instrumentation scope.
     */
    public function tracer(
        string $name = 'hypervel.application',
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): TracerInterface {
        return $this->tracerProvider->getTracer($name, $version, $schemaUrl, $attributes);
    }

    /**
     * Return a logger for an instrumentation scope.
     */
    public function logger(
        string $name = 'hypervel.application',
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): LoggerInterface {
        return $this->loggerProvider->getLogger($name, $version, $schemaUrl, $attributes);
    }

    /**
     * Run a callback inside an internal span.
     */
    public function trace(string $name, Closure $callback, iterable $attributes = []): mixed
    {
        $span = $this->tracer()
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes($attributes)
            ->startSpan();
        $scope = $span->activate();
        $cancelled = false;

        try {
            return $callback($span);
        } catch (CanceledException $exception) {
            $cancelled = true;

            throw $exception;
        } catch (Throwable $exception) {
            $context = Context::getCurrent();
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR);
            $this->exceptionContexts->associate(
                $exception,
                $context,
                $this->origins->resolve($context, $this->identity),
            );

            throw $exception;
        } finally {
            $scope->detach();

            if (! $cancelled) {
                $span->end();
            }
        }
    }

    /**
     * Return the configured text-map propagator.
     */
    public function propagator(): TextMapPropagatorInterface
    {
        return $this->textMapPropagator;
    }

    /**
     * Return the configured response propagator.
     *
     * @internal
     */
    public function responsePropagator(): ResponsePropagatorInterface
    {
        return $this->responsePropagator;
    }

    /**
     * Inject the current or supplied context into an array carrier.
     *
     * @param array<string, mixed> $carrier
     * @return array<string, mixed>
     */
    public function inject(array $carrier = [], ?ContextInterface $context = null): array
    {
        $this->textMapPropagator->inject($carrier, ArrayAccessGetterSetter::getInstance(), $context);

        return $carrier;
    }

    /**
     * Extract context from an array carrier.
     *
     * @param array<string, mixed> $carrier
     */
    public function extract(array $carrier, ?ContextInterface $context = null): ContextInterface
    {
        return $this->textMapPropagator->extract(
            $carrier,
            ArrayAccessGetterSetter::getInstance(),
            $context,
        );
    }

    /**
     * Bind worker-local SDK providers to the pre-fork graph.
     *
     * @internal
     */
    public function bind(ProcessIdentity $identity): void
    {
        if (! $this->enabled || $this->bound) {
            return;
        }

        if ($this->closed) {
            throw new LogicException(
                'An OpenTelemetry manager owns one producing-process SDK lifecycle and cannot bind again after shutdown; use a new process or application instance.',
            );
        }

        $configuration = $this->config->array('opentelemetry');
        $configuration['enabled'] = $this->enabled;
        $this->normalizedConfiguration = $this->normalizer->normalize($configuration);

        try {
            $this->providers = $this->providerFactory->create(
                $this->normalizedConfiguration,
                $identity,
                $this->resolveExporterFactory(...),
            );

            $this->deferredMeterProvider?->bind($this->providers->metrics ?? new NoopMeterProvider);
            $this->deferredTracerProvider?->bind($this->providers->traces ?? new NoopTracerProvider);
            $this->deferredLoggerProvider?->bind($this->providers->logs ?? NoopLoggerProvider::getInstance());
            $this->container->instance(ProcessIdentity::class, $identity);
        } catch (Throwable $exception) {
            $this->deferredTracerProvider?->unbind();
            $this->deferredLoggerProvider?->unbind();
            $this->deferredMeterProvider?->unbind();
            $this->providers = null;
            $this->exporterFactories = [];

            throw $exception;
        }

        // Instrumentation hooks have no uniform removal API, so binding becomes
        // one-shot before the first worker-lifetime registration can occur.
        $this->identity = $identity;
        $this->bound = true;
        $this->registerInstrumentations($identity);
    }

    /**
     * Flush every enabled provider.
     */
    public function flush(): bool
    {
        // Public callers only need to know whether this invocation acquired
        // ownership and completed successfully.
        return $this->flushSignals(['traces', 'logs', 'metrics']) ?? false;
    }

    /**
     * Flush selected signals without overlapping another export operation.
     *
     * @internal
     * @param list<string> $signals
     * @return null|bool true when completed or nothing is bound, false on provider failure,
     *                   or null when another flush or shutdown owns the graph
     */
    public function flushSignals(array $signals): ?bool
    {
        if (! $this->bound) {
            return true;
        }

        if ($this->flushing || $this->closing) {
            return null;
        }

        $this->flushing = true;
        $success = true;

        try {
            foreach ($signals as $signal) {
                $provider = match ($signal) {
                    'traces' => $this->providers?->traces,
                    'logs' => $this->providers?->logs,
                    'metrics' => $this->providers?->metrics,
                    default => throw new InvalidArgumentException(
                        "OpenTelemetry signal [{$signal}] is not supported.",
                    ),
                };

                if ($provider !== null && ! $provider->forceFlush()) {
                    $success = false;
                }
            }
        } finally {
            $this->flushing = false;

            if ($this->flushCompletion !== null) {
                $completion = $this->flushCompletion;
                $this->flushCompletion = null;
                $completion->close();
            }
        }

        return $success;
    }

    /**
     * Shut down every enabled provider and release worker-local delegates.
     *
     * @internal
     */
    public function shutdown(): bool
    {
        if (! $this->bound) {
            return true;
        }

        if ($this->closing) {
            return false;
        }

        $this->closing = true;

        if ($this->flushing) {
            $this->flushCompletion ??= new Channel(1);
            $this->flushCompletion->pop();
        }

        $success = true;
        $exception = null;

        try {
            foreach ([$this->providers?->traces, $this->providers?->logs, $this->providers?->metrics] as $provider) {
                try {
                    if ($provider !== null && ! $provider->shutdown()) {
                        $success = false;
                    }
                } catch (Throwable $throwable) {
                    $success = false;
                    $exception ??= $throwable;
                }
            }
        } finally {
            $this->deferredTracerProvider?->unbind();
            $this->deferredLoggerProvider?->unbind();
            $this->deferredMeterProvider?->unbind();
            $this->providers = null;
            $this->bound = false;
            $this->closed = true;
            $this->closing = false;
            $this->exporterFactories = [];
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $success;
    }

    /**
     * Determine whether a worker-local graph is bound.
     *
     * @internal
     */
    public function isBound(): bool
    {
        return $this->bound;
    }

    /**
     * Determine whether the logs signal is active in this process.
     *
     * @internal
     */
    public function logsEnabled(): bool
    {
        return $this->bound && $this->providers?->logs !== null;
    }

    /**
     * Return normalized worker configuration.
     *
     * @internal
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->normalizedConfiguration;
    }

    /**
     * Register a custom exporter driver.
     *
     * Boot-only. The creator persists for the worker lifetime and must be registered before telemetry binding.
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->customCreators[$driver] = $factory;
        unset($this->exporterFactories[$driver]);
    }

    /**
     * Register an exception-record enricher.
     *
     * Boot-only. The callback persists for the worker lifetime and affects every reported exception.
     */
    public function enrichExceptionsUsing(Closure $enricher): void
    {
        $this->exceptionEnrichers[] = $enricher;
    }

    /**
     * Return registered exception-record enrichers.
     *
     * @internal
     * @return list<Closure>
     */
    public function exceptionEnrichers(): array
    {
        return $this->exceptionEnrichers;
    }

    /**
     * Set the outgoing URL-template resolver.
     *
     * Boot-only. The callback persists for the worker lifetime and affects every traced outgoing request.
     */
    public function resolveUrlTemplateUsing(Closure $resolver): void
    {
        $this->urlTemplateResolver = $resolver;
    }

    /**
     * Return the outgoing URL-template resolver.
     *
     * @internal
     */
    public function urlTemplateResolver(): ?Closure
    {
        return $this->urlTemplateResolver;
    }

    /**
     * Set the authenticated-user resolver.
     *
     * Boot-only. The callback persists for the worker lifetime and affects every request that collects user context.
     */
    public function resolveUserUsing(Closure $resolver): void
    {
        $this->userResolver = $resolver;
    }

    /**
     * Return the authenticated-user resolver.
     *
     * @internal
     */
    public function userResolver(): ?Closure
    {
        return $this->userResolver;
    }

    /**
     * Set the cache-key resolver.
     *
     * Boot-only. The callback persists for the worker lifetime and affects enabled cache-key capture.
     */
    public function resolveCacheKeyUsing(Closure $resolver): void
    {
        $this->cacheKeyResolver = $resolver;
    }

    /**
     * Return the cache-key resolver.
     *
     * @internal
     */
    public function cacheKeyResolver(): ?Closure
    {
        return $this->cacheKeyResolver;
    }

    /**
     * Set the Redis query-text resolver.
     *
     * Boot-only. The callback persists for the worker lifetime and affects enabled Redis query-text capture.
     */
    public function resolveRedisQueryTextUsing(Closure $resolver): void
    {
        $this->redisQueryTextResolver = $resolver;
    }

    /**
     * Return the Redis query-text resolver.
     *
     * @internal
     */
    public function redisQueryTextResolver(): ?Closure
    {
        return $this->redisQueryTextResolver;
    }

    /**
     * Resolve and register active worker-local instrumentations.
     */
    protected function registerInstrumentations(ProcessIdentity $identity): void
    {
        $instrumentations = $this->normalizedConfiguration['instrumentation'];

        if ($instrumentations === []) {
            return;
        }

        foreach ($instrumentations as $class => $options) {
            if ($options === false
                || ($identity->type !== ProcessIdentity::EVENT
                    && in_array($class, [HttpServerInstrumentation::class, WebSocketInstrumentation::class], true))
            ) {
                continue;
            }

            $instrumentation = $this->container->make($class);

            if (! $instrumentation instanceof Instrumentation) {
                throw new InvalidArgumentException(
                    "OpenTelemetry instrumentation [{$class}] must implement [" . Instrumentation::class . '].',
                );
            }

            $instrumentation->register($options);
        }
    }

    /**
     * Resolve and cache an exporter factory.
     */
    protected function resolveExporterFactory(string $driver): ExporterFactory
    {
        if (isset($this->exporterFactories[$driver])) {
            return $this->exporterFactories[$driver];
        }

        $factory = isset($this->customCreators[$driver])
            ? ($this->customCreators[$driver])($this->container)
            : match ($driver) {
                'otlp' => $this->container->make(OtlpExporterFactory::class),
                'console' => $this->container->make(ConsoleExporterFactory::class),
                default => throw new InvalidArgumentException(
                    "OpenTelemetry exporter driver [{$driver}] is not supported.",
                ),
            };

        if (! $factory instanceof ExporterFactory) {
            throw new InvalidArgumentException(
                "OpenTelemetry exporter driver [{$driver}] must resolve an [" . ExporterFactory::class . '] instance.',
            );
        }

        return $this->exporterFactories[$driver] = $factory;
    }
}
