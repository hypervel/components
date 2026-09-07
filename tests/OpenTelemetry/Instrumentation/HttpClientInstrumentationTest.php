<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Context\PsrRequestHeadersSetter;
use Hypervel\OpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Nyholm\Psr7\Request as NyholmRequest;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\UrlIncubatingAttributes;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class HttpClientInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Factory $factory;

    private HttpClientTestClock $clock;

    private InMemorySpanExporter $spanExporter;

    private TracerProvider $tracerProvider;

    private InMemoryMetricExporter $metricExporter;

    private ExportingReader $metricReader;

    private MeterProvider $meterProvider;

    private OpenTelemetryManager $manager;

    private ExceptionContextRegistry $exceptionContexts;

    private ?Closure $urlTemplateResolver = null;

    private int $urlTemplateResolverCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->factory = new Factory;
        $this->clock = new HttpClientTestClock;
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->metricExporter = new InMemoryMetricExporter;
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->metricReader)
            ->build();
        $this->manager = m::mock(OpenTelemetryManager::class);
        $this->manager->shouldReceive('urlTemplateResolver')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (): ?Closure {
                ++$this->urlTemplateResolverCalls;

                return $this->urlTemplateResolver;
            });
        $this->exceptionContexts = new ExceptionContextRegistry;
    }

    protected function tearDownInCoroutine(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testRecordsThePhysicalRequestLifecycleWithoutActivatingTheClientSpan(): void
    {
        $this->urlTemplateResolver = static fn (): string => '/users/{user}';
        $seenContext = null;
        $traceparent = null;

        $this->factory->fake(function ($request) use (&$seenContext, &$traceparent) {
            $seenContext = Context::getCurrent();
            $traceparent = $request->header('traceparent')[0] ?? null;

            return Create::promiseFor(new PsrResponse(
                201,
                ['Content-Length' => '5', 'X-Response' => 'done'],
                'hello',
                '2',
            ));
        });
        $this->instrumentation()->register($this->options([
            'url_query' => true,
            'request_headers' => ['x-request-id', 'authorization'],
            'response_headers' => ['x-response', 'set-cookie'],
        ]));
        $ambient = Context::getCurrent();

        $promise = $this->factory
            ->async()
            ->withHeaders([
                'Content-Length' => '12',
                'X-Request-Id' => 'request-1',
                'Authorization' => 'Bearer secret',
            ])
            ->get('https://alice:secret@example.test:8443/users/1?token=secret&safe=yes#profile');

        $this->assertSame($ambient, Context::getCurrent());
        $response = $promise->wait();
        $this->assertSame($ambient, Context::getCurrent());
        $this->assertSame($ambient, $seenContext);
        $this->assertIsString($traceparent);

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame(201, $response->status());
        $this->assertSame('GET /users/{user}', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(2_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('GET', $attributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertSame('example.test', $attributes[ServerAttributes::SERVER_ADDRESS]);
        $this->assertSame(8443, $attributes[ServerAttributes::SERVER_PORT]);
        $this->assertSame(
            'https://REDACTED:REDACTED@example.test:8443/users/1?token=REDACTED&safe=yes#profile',
            $attributes[UrlAttributes::URL_FULL],
        );
        $this->assertSame('https', $attributes[UrlAttributes::URL_SCHEME]);
        $this->assertSame('/users/{user}', $attributes[UrlIncubatingAttributes::URL_TEMPLATE]);
        $this->assertSame(12, $attributes[HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE]);
        $this->assertSame(5, $attributes[HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE]);
        $this->assertSame(201, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
        $this->assertSame('2', $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION]);
        $this->assertArrayNotHasKey(NetworkAttributes::NETWORK_PROTOCOL_NAME, $attributes);
        $this->assertSame(['request-1'], $attributes[HttpAttributes::HTTP_REQUEST_HEADER . '.x-request-id']);
        $this->assertSame(['REDACTED'], $attributes[HttpAttributes::HTTP_REQUEST_HEADER . '.authorization']);
        $this->assertSame(['done'], $attributes[HttpAttributes::HTTP_RESPONSE_HEADER . '.x-response']);
        $this->assertSame(1, $this->urlTemplateResolverCalls);

        $this->metricReader->collect();
        $duration = $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION);
        $this->assertInstanceOf(Histogram::class, $duration->data);
        $points = $duration->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);
        $this->assertSame(1, $points[0]->sum);
        $this->assertSame('GET', $points[0]->attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('example.test', $points[0]->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(8443, $points[0]->attributes->get(ServerAttributes::SERVER_PORT));
        $this->assertSame(201, $points[0]->attributes->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame('2', $points[0]->attributes->get(NetworkAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertNull($points[0]->attributes->get(UrlAttributes::URL_FULL));
        $this->assertNull($points[0]->attributes->get(UrlAttributes::URL_SCHEME));
        $this->assertNull($points[0]->attributes->get(UrlIncubatingAttributes::URL_TEMPLATE));
    }

    public function testUsesTheExactMethodExposedAtEachSupportedClientBoundary(): void
    {
        $this->factory->fake();
        $this->instrumentation()->register($this->options([
            'known_methods' => ['get'],
            'metrics' => [HttpMetrics::HTTP_CLIENT_REQUEST_DURATION => false],
        ]));

        $this->factory->send('gEt', 'https://example.test/easy');
        $this->factory->buildClient()->send(new NyholmRequest('get', 'https://example.test/built'));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(2, $spans);
        $easyAttributes = $spans[0]->getAttributes()->toArray();
        $builtAttributes = $spans[1]->getAttributes()->toArray();

        $this->assertSame('HTTP', $spans[0]->getName());
        $this->assertSame('_OTHER', $easyAttributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertSame('GET', $easyAttributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL]);
        $this->assertSame('get', $spans[1]->getName());
        $this->assertSame('get', $builtAttributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertArrayNotHasKey(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL, $builtAttributes);
    }

    public function testMarksFulfilledClientErrorResponsesOnSpansAndMetrics(): void
    {
        $responses = [Factory::response('', 404), Factory::response('', 500)];
        $this->factory->fake(static function () use (&$responses) {
            return array_shift($responses);
        });
        $this->instrumentation()->register($this->options());

        $this->factory->get('https://example.test/missing');
        $this->factory->get('https://example.test/failing');

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(2, $spans);

        foreach ([404, 500] as $index => $statusCode) {
            $this->assertSame(StatusCode::STATUS_ERROR, $spans[$index]->getStatus()->getCode());
            $this->assertSame(
                (string) $statusCode,
                $spans[$index]->getAttributes()->get(ErrorAttributes::ERROR_TYPE),
            );
        }

        $this->metricReader->collect();
        $points = $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(2, $points);
        $this->assertSame('404', $points[0]->attributes->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('500', $points[1]->attributes->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testAssociatesAnOrdinaryFailureWithTheEndedClientSpan(): void
    {
        $failure = new RuntimeException('transport failed');
        $this->exceptionContexts->enable();
        $this->factory->fake(static function () use ($failure): never {
            throw $failure;
        });
        $this->instrumentation()->register($this->options());

        try {
            $this->factory->buildClient()->send(new NyholmRequest('GET', 'https://example.test'));
            $this->fail('The transport failure was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];
        $handoff = $this->exceptionContexts->take($failure);

        $this->assertNotNull($handoff);
        $this->assertSame(
            $span->getSpanId(),
            Span::fromContext($handoff->context)->getContext()->getSpanId(),
        );
        $this->assertNull($handoff->origin);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertCount(1, $span->getEvents());
        $this->assertSame('exception', $span->getEvents()[0]->getName());

        $this->metricReader->collect();
        $point = $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints[0];
        $this->assertSame(RuntimeException::class, $point->attributes->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testPromiseCancellationPropagatesWithoutCompletionTelemetry(): void
    {
        $cancellation = new CanceledException;
        $this->factory->fake(static fn () => Create::rejectionFor($cancellation));
        $this->instrumentation()->register($this->options());

        try {
            $this->factory->async()->get('https://example.test')->wait();
            $this->fail('The cancellation was not rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame([], $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints);
        $this->assertSame(2_000_000_000, $this->clock->timestamp);
    }

    public function testUrlTemplateResolverCancellationStopsBeforeThePhysicalSend(): void
    {
        $cancellation = new CanceledException;
        $physicalSends = 0;
        $this->urlTemplateResolver = static function () use ($cancellation): never {
            throw $cancellation;
        };
        $this->factory->fake(function () use (&$physicalSends) {
            ++$physicalSends;

            return Factory::response();
        });
        $this->instrumentation()->register($this->options());

        try {
            $this->factory->get('https://example.test');
            $this->fail('The resolver cancellation was not rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(0, $physicalSends);
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame([], $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints);
    }

    public function testManualModeTracesOnlyMarkedRequestsWhileMetricsRemainIndependent(): void
    {
        $this->factory->fake();
        $this->instrumentation()->register($this->options(['manual' => true]));

        $this->factory->get('https://example.test/untraced');
        $this->factory->withTrace()->get('https://example.test/traced');

        $this->assertTrue(PendingRequest::hasMacro('withTrace'));
        $this->assertTrue(PendingRequest::hasMacro('withoutTrace'));
        $this->assertCount(1, $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $points = $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);
        $this->assertSame(2, $points[0]->count);
    }

    public function testAutomaticOptOutWithMetricsDisabledTakesTheZeroWorkFastPath(): void
    {
        $this->factory->fake();
        $this->instrumentation()->register($this->options([
            'metrics' => [HttpMetrics::HTTP_CLIENT_REQUEST_DURATION => false],
        ]));

        $this->factory->withoutTrace()->get('https://example.test');

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->assertSame(1_000_000_000, $this->clock->timestamp);
        $this->assertSame(0, $this->urlTemplateResolverCalls);
        $this->assertSame([], $this->metricExporter->collect());
    }

    public function testMetricsOnlyModeRegistersNoTraceMacrosOrResolverWork(): void
    {
        $this->factory->fake();
        $this->urlTemplateResolver = static fn (): string => '/unused';
        $this->instrumentation()->register($this->options(['traces' => false]));

        $this->factory->withOptions(['hypervel_otel_trace' => true])->get('https://example.test');

        $this->assertFalse(PendingRequest::hasMacro('withTrace'));
        $this->assertFalse(PendingRequest::hasMacro('withoutTrace'));
        $this->assertSame(0, $this->urlTemplateResolverCalls);
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(1, $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints);
    }

    public function testMacroCollisionFailsBeforeEitherPackageMacroOrMiddlewareIsRegistered(): void
    {
        PendingRequest::macro('withTrace', static fn (): string => 'application');

        try {
            $this->instrumentation()->register($this->options([
                'metrics' => [HttpMetrics::HTTP_CLIENT_REQUEST_DURATION => false],
            ]));
            $this->fail('The macro collision was not rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('withTrace', $exception->getMessage());
        }

        $this->assertFalse(PendingRequest::hasMacro('withoutTrace'));
        $this->assertSame([], $this->factory->getGlobalMiddleware());
    }

    public function testNonRecordingSpansSkipTraceOnlyUrlTemplateResolution(): void
    {
        $this->tracerProvider->shutdown();
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler(new AlwaysOffSampler)
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->urlTemplateResolver = static fn (): string => '/unused';
        $this->factory->fake();
        $this->instrumentation()->register($this->options(['url_query' => true]));

        $this->factory->get('https://example.test/users?token=secret');

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->assertSame(0, $this->urlTemplateResolverCalls);
        $this->metricReader->collect();
        $this->assertCount(1, $this->metric(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)->data->dataPoints);
    }

    public function testResendCountIncludesRedirectsAndEarlierHypervelAttempts(): void
    {
        $send = 0;
        $this->factory->fake(function () use (&$send) {
            return match (++$send) {
                1 => Factory::response('', 302, ['Location' => '/redirect']),
                2 => Factory::response('', 500),
                default => Factory::response('', 200),
            };
        });
        $this->instrumentation()->register($this->options([
            'metrics' => [HttpMetrics::HTTP_CLIENT_REQUEST_DURATION => false],
        ]));

        $response = $this->factory
            ->retry(2, throw: false)
            ->get('https://example.test/start');

        $this->assertSame(200, $response->status());
        $this->assertSame(3, $send);
        $spans = $this->spanExporter->getSpans();
        $this->assertCount(3, $spans);
        $this->assertNull($spans[0]->getAttributes()->get(HttpAttributes::HTTP_REQUEST_RESEND_COUNT));
        $this->assertSame(1, $spans[1]->getAttributes()->get(HttpAttributes::HTTP_REQUEST_RESEND_COUNT));
        $this->assertSame(2, $spans[2]->getAttributes()->get(HttpAttributes::HTTP_REQUEST_RESEND_COUNT));
    }

    /**
     * Create the instrumentation under test.
     */
    private function instrumentation(): HttpClientInstrumentation
    {
        return new HttpClientInstrumentation(
            $this->factory,
            $this->tracerProvider,
            $this->meterProvider,
            TraceContextPropagator::getInstance(),
            new PsrRequestHeadersSetter,
            $this->clock,
            $this->manager,
            $this->exceptionContexts,
            new OperationOrigin,
            ProcessIdentity::eventWorker(0),
        );
    }

    /**
     * Return normalized HTTP client options.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_replace([
            'traces' => true,
            'known_methods' => ConfigurationNormalizer::DEFAULT_HTTP_METHODS,
            'manual' => false,
            'url_query' => false,
            'sensitive_query_parameters' => [],
            'sensitive_headers' => [],
            'request_headers' => [],
            'response_headers' => [],
            'metrics' => [HttpMetrics::HTTP_CLIENT_REQUEST_DURATION => true],
        ], $overrides);
    }

    /**
     * Return one exported metric by name.
     */
    private function metric(string $name): Metric
    {
        foreach ($this->metricExporter->collect() as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        $this->fail("Metric [{$name}] was not exported.");
    }
}

class HttpClientTestClock implements ClockInterface
{
    public int $timestamp = 1_000_000_000;

    /**
     * Return the next deterministic timestamp.
     */
    public function now(): int
    {
        $timestamp = $this->timestamp;
        $this->timestamp += ClockInterface::NANOS_PER_SECOND;

        return $timestamp;
    }
}
