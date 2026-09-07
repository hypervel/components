<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use ArrayObject;
use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Engine\Channel;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use Hypervel\OpenTelemetry\Contracts\Instrumentation;
use Hypervel\OpenTelemetry\Deferred\Logs\DeferredLoggerProvider;
use Hypervel\OpenTelemetry\Deferred\Metrics\DeferredMeterProvider;
use Hypervel\OpenTelemetry\Deferred\Trace\DeferredTracerProvider;
use Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\ProviderFactory;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\ProviderSet;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\go;

class OpenTelemetryManagerTest extends TestCase
{
    public function testLogsSignalAvailabilityTracksTheBoundProviderLifecycle(): void
    {
        [$manager] = $this->manager();

        $this->assertFalse($manager->logsEnabled());

        $manager->bind(ProcessIdentity::eventWorker(0));

        $this->assertTrue($manager->logsEnabled());

        $manager->shutdown();

        $this->assertFalse($manager->logsEnabled());

        $configuration = $this->configuration();
        $configuration['logs']['exporter'] = 'none';
        [$disabledManager] = $this->manager(configuration: $configuration);
        $disabledManager->bind(ProcessIdentity::eventWorker(0));

        $this->assertFalse($disabledManager->logsEnabled());
        $disabledManager->shutdown();
    }

    public function testPreForkHandlesRecordThroughWorkerProvidersAfterBinding(): void
    {
        [$manager, $exporters] = $this->manager();
        $counter = $manager->meter('test')->createCounter('test.counter');
        $tracer = $manager->tracer('test');
        $logger = $manager->logger('test');

        $counter->add(1);
        $tracer->spanBuilder('before-bind')->startSpan()->end();
        $logger->logRecordBuilder()->setBody('before bind')->emit();
        $manager->bind(ProcessIdentity::eventWorker(0));
        $counter->add(2);
        $tracer->spanBuilder('after-bind')->startSpan()->end();
        $logger->logRecordBuilder()->setBody('after bind')->emit();

        $this->assertTrue($manager->flush());
        $this->assertCount(1, $exporters->metricExporter->collect());
        $this->assertSame(
            ['after-bind'],
            array_map(static fn ($span): string => $span->getName(), $exporters->spanExporter->getSpans()),
        );
        $this->assertCount(1, $exporters->logExporter->getStorage());
        $this->assertSame(1, $exporters->spanCalls);
        $this->assertSame(1, $exporters->metricCalls);
        $this->assertSame(1, $exporters->logCalls);

        $this->assertTrue($manager->shutdown());
    }

    public function testCustomExporterCreatorIsResolvedOnceAcrossSignals(): void
    {
        $creatorCalls = 0;
        [$manager, $exporters, $container] = $this->manager(function (Container $resolvedContainer) use (&$creatorCalls, &$container, &$exporters): ExporterFactory {
            ++$creatorCalls;
            $this->assertSame($container, $resolvedContainer);

            return $exporters;
        });

        $manager->bind(ProcessIdentity::cli());
        $manager->bind(ProcessIdentity::cli());

        $this->assertSame(1, $creatorCalls);
        $this->assertTrue($manager->isBound());
        $this->assertSame('fixture', $manager->configuration()['metrics']['exporter']);

        $manager->shutdown();
    }

    public function testBindResolvesAndRegistersConfiguredInstrumentationAfterProviderBinding(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            ManagerTestInstrumentation::class => ['metrics' => true, 'custom' => 'value'],
        ];
        $identity = ProcessIdentity::eventWorker(2);
        [$manager, , $container] = $this->manager(configuration: $configuration);
        $instrumentation = new ManagerTestInstrumentation;
        $container->shouldReceive('instance')
            ->once()
            ->with(ProcessIdentity::class, $identity)
            ->andReturn($identity);
        $container->shouldReceive('make')
            ->once()
            ->with(ManagerTestInstrumentation::class)
            ->andReturn($instrumentation);

        $manager->bind($identity);

        $this->assertSame(['metrics' => true, 'custom' => 'value'], $instrumentation->options);
        $manager->shutdown();
    }

    public function testBindSkipsEventWorkerInstrumentationInCliProcesses(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [HttpServerInstrumentation::class => true];
        [$manager] = $this->manager(configuration: $configuration);

        $manager->bind(ProcessIdentity::cli());

        $this->assertTrue($manager->isBound());
        $manager->shutdown();
    }

    public function testBindRegistersIdentityWithoutInstrumentationsAndRetainsItAfterShutdown(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [];
        $identity = ProcessIdentity::cli();
        [$manager, , $container, $exceptionContexts] = $this->manager(configuration: $configuration);
        $container->shouldReceive('instance')
            ->once()
            ->with(ProcessIdentity::class, $identity)
            ->andReturn($identity);
        $exceptionContexts->enable();

        $manager->bind($identity);
        $manager->shutdown();
        $expected = new RuntimeException('failure');

        try {
            $manager->trace('after-shutdown', static function () use ($expected): never {
                throw $expected;
            });
            $this->fail('The callback exception was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $handoff = $exceptionContexts->take($expected);

        $this->assertNotNull($handoff);
        $this->assertSame(OperationOrigin::CONSOLE, $handoff->origin);
    }

    public function testTraceActivatesTheSpanReturnsTheCallbackValueAndPropagatesContext(): void
    {
        [$manager, $exporters] = $this->manager();
        $manager->bind(ProcessIdentity::cli());

        $result = $manager->trace('operation', function ($span) use ($manager): string {
            $this->assertSame($span, Span::getCurrent());
            $carrier = $manager->inject();
            $extracted = $manager->extract($carrier);
            $this->assertSame($span->getContext()->getTraceId(), Span::fromContext($extracted)->getContext()->getTraceId());

            return 'result';
        }, ['operation.type' => 'test']);

        $this->assertSame('result', $result);
        $this->assertTrue($manager->flush());
        $span = $exporters->spanExporter->getSpans()[0];
        $this->assertSame('operation', $span->getName());
        $this->assertSame('test', $span->getAttributes()->get('operation.type'));

        $manager->shutdown();
    }

    public function testTraceRecordsAndRethrowsTheSameException(): void
    {
        [$manager, $exporters, , $exceptionContexts] = $this->manager();
        $exceptionContexts->enable();
        $manager->bind(ProcessIdentity::cli());
        $expected = new RuntimeException('failure');

        try {
            $manager->trace('failing', static function () use ($expected): never {
                throw $expected;
            });
            $this->fail('The callback exception was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $manager->flush();
        $span = $exporters->spanExporter->getSpans()[0];
        $events = $span->getEvents();

        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertCount(1, $events);
        $this->assertSame('exception', $events[0]->getName());
        $handoff = $exceptionContexts->take($expected);
        $this->assertNotNull($handoff);
        $this->assertSame($span->getSpanId(), Span::fromContext($handoff->context)->getContext()->getSpanId());
        $this->assertSame(OperationOrigin::CONSOLE, $handoff->origin);

        $manager->shutdown();
    }

    public function testTraceDetachesAndRethrowsCancellationWithoutCompletingTheSpan(): void
    {
        [$manager, $exporters] = $this->manager();
        $manager->bind(ProcessIdentity::cli());
        $parent = Span::getCurrent();
        $cancellation = new CanceledException;

        try {
            $manager->trace('cancelled', static function () use ($cancellation): never {
                throw $cancellation;
            });

            $this->fail('Expected the traced callback to propagate coroutine cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame($parent, Span::getCurrent());
        $this->assertTrue($manager->flush());
        $this->assertSame([], $exporters->spanExporter->getSpans());

        $manager->shutdown();
    }

    public function testShutdownUnbindsHandlesAndIsIdempotent(): void
    {
        [$manager, $exporters] = $this->manager();
        $tracer = $manager->tracer('test');
        $manager->bind(ProcessIdentity::cli());
        $tracer->spanBuilder('exported')->startSpan()->end();

        $this->assertTrue($manager->shutdown());
        $this->assertTrue($manager->shutdown());
        $this->assertFalse($manager->isBound());

        $tracer->spanBuilder('dropped')->startSpan()->end();

        $this->assertSame(
            ['exported'],
            array_map(static fn ($span): string => $span->getName(), $exporters->spanExporter->getSpans()),
        );
    }

    public function testFailedProviderConstructionCanBeCorrectedAndRetried(): void
    {
        $expected = new RuntimeException('Unable to create exporter.');
        $attempts = 0;
        [$manager, $exporters] = $this->manager(
            function () use (&$attempts, $expected, &$exporters): ExporterFactory {
                if (++$attempts === 1) {
                    throw $expected;
                }

                return $exporters;
            },
        );

        try {
            $manager->bind(ProcessIdentity::cli());
            $this->fail('A provider-construction failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertFalse($manager->isBound());

        $manager->bind(ProcessIdentity::cli());

        $this->assertTrue($manager->isBound());
        $this->assertSame(2, $attempts);
        $manager->shutdown();
    }

    public function testInstrumentationRegistrationFailureKeepsTheGraphBoundAndOneShot(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            ManagerTestInstrumentation::class => ['metrics' => true],
            FailingManagerTestInstrumentation::class => ['metrics' => true],
        ];
        $identity = ProcessIdentity::cli();
        [$manager, , $container] = $this->manager(configuration: $configuration);
        $instrumentation = new ManagerTestInstrumentation;
        $failingInstrumentation = new FailingManagerTestInstrumentation;
        $expected = new RuntimeException('Unable to register instrumentation.');
        $failingInstrumentation->exception = $expected;
        $container->shouldReceive('instance')
            ->once()
            ->with(ProcessIdentity::class, $identity)
            ->andReturn($identity);
        $container->shouldReceive('make')
            ->once()
            ->with(ManagerTestInstrumentation::class)
            ->andReturn($instrumentation);
        $container->shouldReceive('make')
            ->once()
            ->with(FailingManagerTestInstrumentation::class)
            ->andReturn($failingInstrumentation);

        try {
            $manager->bind($identity);
            $this->fail('An instrumentation-registration failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertTrue($manager->isBound());
        $manager->bind($identity);
        $this->assertSame(1, $instrumentation->registrations);
        $this->assertSame(1, $failingInstrumentation->registrations);
        $this->assertTrue($manager->shutdown());
        $this->assertFalse($manager->isBound());
    }

    public function testManagerCannotBindASecondLifecycleAfterShutdown(): void
    {
        $configuration = $this->configuration();
        $configuration['traces']['processors'] = ['custom.span.processor'];
        $configuration['logs']['processors'] = ['custom.log.processor'];
        [$manager, , $container] = $this->manager(configuration: $configuration);
        $spanProcessor = m::mock(SpanProcessorInterface::class);
        $spanProcessor->shouldReceive('shutdown')->once()->andReturnTrue();
        $logProcessor = m::mock(LogRecordProcessorInterface::class);
        $logProcessor->shouldReceive('shutdown')->once()->andReturnTrue();
        $container->shouldReceive('make')->once()->with('custom.span.processor')->andReturn($spanProcessor);
        $container->shouldReceive('make')->once()->with('custom.log.processor')->andReturn($logProcessor);
        $manager->bind(ProcessIdentity::cli());
        $manager->shutdown();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('one producing-process SDK lifecycle');

        $manager->bind(ProcessIdentity::cli());
    }

    public function testConcurrentManualFlushReturnsFalseWithoutOverlappingTheProviderGraph(): void
    {
        $flushStarted = new Channel(1);
        $releaseFlush = new Channel(1);
        $flushFinished = new Channel(1);
        $traces = m::mock(TracerProviderInterface::class);
        $traces->shouldReceive('forceFlush')->once()->andReturnUsing(
            static function () use ($flushStarted, $releaseFlush): bool {
                $flushStarted->push(true);

                return $releaseFlush->pop(1.0) === true;
            },
        );
        $traces->shouldReceive('shutdown')->once()->andReturnTrue();
        $providerFactory = m::mock(ProviderFactory::class);
        $providerFactory->shouldReceive('create')->once()->andReturn(new ProviderSet(null, $traces, null));
        [$manager] = $this->manager(providerFactory: $providerFactory);
        $manager->bind(ProcessIdentity::cli());

        go(static fn (): bool => $flushFinished->push($manager->flush()));

        $this->assertTrue($flushStarted->pop(1.0));
        $this->assertNull($manager->flushSignals(['traces']));
        $this->assertFalse($manager->flush());
        $this->assertTrue($releaseFlush->push(true));
        $this->assertTrue($flushFinished->pop(1.0));
        $this->assertTrue($manager->shutdown());
    }

    public function testShutdownWaitsForAnInflightFlushBeforeClosingProviders(): void
    {
        $flushStarted = new Channel(1);
        $releaseFlush = new Channel(1);
        $flushFinished = new Channel(1);
        $shutdownFinished = new Channel(1);
        $traces = m::mock(TracerProviderInterface::class);
        $traces->shouldReceive('forceFlush')->once()->andReturnUsing(
            static function () use ($flushStarted, $releaseFlush): bool {
                $flushStarted->push(true);

                return $releaseFlush->pop(1.0) === true;
            },
        );
        $traces->shouldReceive('shutdown')->once()->andReturnTrue();
        $providerFactory = m::mock(ProviderFactory::class);
        $providerFactory->shouldReceive('create')->once()->andReturn(new ProviderSet(null, $traces, null));
        [$manager] = $this->manager(providerFactory: $providerFactory);
        $manager->bind(ProcessIdentity::cli());

        go(static fn (): bool => $flushFinished->push($manager->flush()));
        $this->assertTrue($flushStarted->pop(1.0));
        go(static fn (): bool => $shutdownFinished->push($manager->shutdown()));

        $this->assertFalse($shutdownFinished->pop(0.001));
        $this->assertTrue($releaseFlush->push(true));
        $this->assertTrue($flushFinished->pop(1.0));
        $this->assertTrue($shutdownFinished->pop(1.0));
        $this->assertFalse($manager->isBound());
    }

    /**
     * Create a manager with recording exporters.
     *
     * @return array{OpenTelemetryManager, ManagerExporterFactory, Container, ExceptionContextRegistry}
     */
    private function manager(
        ?callable $creator = null,
        ?array $configuration = null,
        ?ProviderFactory $providerFactory = null,
    ): array {
        $container = m::mock(Container::class);
        $container->shouldReceive('instance')
            ->byDefault()
            ->andReturnUsing(static fn (string $abstract, mixed $instance): mixed => $instance);
        $exporters = new ManagerExporterFactory;
        $deferredMetrics = new DeferredMeterProvider;
        $deferredTraces = new DeferredTracerProvider;
        $deferredLogs = new DeferredLoggerProvider;
        $exceptionContexts = new ExceptionContextRegistry;
        $manager = new OpenTelemetryManager(
            $container,
            new Repository(['opentelemetry' => $configuration ?? $this->configuration()]),
            new ConfigurationNormalizer,
            $providerFactory ?? new ProviderFactory($container),
            $exceptionContexts,
            new OperationOrigin,
            $deferredMetrics,
            $deferredTraces,
            $deferredLogs,
            $deferredMetrics,
            $deferredTraces,
            $deferredLogs,
            TraceContextPropagator::getInstance(),
            NoopResponsePropagator::getInstance(),
            true,
        );
        $manager->extend('fixture', $creator === null
            ? static fn (): ExporterFactory => $exporters
            : Closure::fromCallable($creator));

        return [$manager, $exporters, $container, $exceptionContexts];
    }

    /**
     * Return a complete manager configuration fixture.
     *
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        return [
            'enabled' => true,
            'internal_metrics' => false,
            'service' => [
                'name' => 'manager-test',
                'version' => null,
                'environment' => 'testing',
                'instance_id' => 'test',
            ],
            'resource_attributes' => [],
            'propagators' => ['tracecontext'],
            'response_propagators' => ['none'],
            'metrics' => [
                'provider' => null,
                'exporter' => 'fixture',
                'export_interval' => 60000,
                'temporality' => 'cumulative',
                'exemplar_filter' => 'trace_based',
                'views' => [],
            ],
            'traces' => [
                'provider' => null,
                'exporter' => 'fixture',
                'sampler' => 'parentbased_always_on',
                'sampler_arg' => 1.0,
                'schedule_delay' => 5000,
                'max_queue_size' => 2048,
                'max_export_batch_size' => 512,
                'processors' => [],
            ],
            'logs' => [
                'provider' => null,
                'exporter' => 'fixture',
                'schedule_delay' => 1000,
                'max_queue_size' => 2048,
                'max_export_batch_size' => 512,
                'processors' => [],
            ],
            'log_context' => [
                'enabled' => false,
                'trace_id_key' => 'trace_id',
                'span_id_key' => 'span_id',
            ],
            'server_processes' => ['except' => []],
            'exporters' => [
                'fixture' => ['driver' => 'fixture'],
            ],
        ];
    }
}

class ManagerExporterFactory implements ExporterFactory
{
    public InMemorySpanExporter $spanExporter;

    public InMemoryMetricExporter $metricExporter;

    public InMemoryLogExporter $logExporter;

    public int $spanCalls = 0;

    public int $metricCalls = 0;

    public int $logCalls = 0;

    /**
     * Create recording exporters for every signal.
     */
    public function __construct()
    {
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->metricExporter = new InMemoryMetricExporter(new ArrayObject);
        $this->logExporter = new InMemoryLogExporter(new ArrayObject);
    }

    /**
     * Return the recording span exporter.
     */
    public function spanExporter(array $config): SpanExporterInterface
    {
        ++$this->spanCalls;

        return $this->spanExporter;
    }

    /**
     * Return the recording metric exporter.
     */
    public function metricExporter(array $config): MetricExporterInterface
    {
        ++$this->metricCalls;

        return $this->metricExporter;
    }

    /**
     * Return the recording log exporter.
     */
    public function logExporter(array $config): LogRecordExporterInterface
    {
        ++$this->logCalls;

        return $this->logExporter;
    }
}

class ManagerTestInstrumentation implements Instrumentation
{
    /** @var array<string, mixed> */
    public array $options = [];

    public int $registrations = 0;

    /**
     * Register fixture instrumentation options.
     */
    public function register(array $options): void
    {
        ++$this->registrations;
        $this->options = $options;
    }
}

class FailingManagerTestInstrumentation implements Instrumentation
{
    public int $registrations = 0;

    public RuntimeException $exception;

    /**
     * Fail fixture instrumentation registration.
     */
    public function register(array $options): void
    {
        ++$this->registrations;

        throw $this->exception;
    }
}
