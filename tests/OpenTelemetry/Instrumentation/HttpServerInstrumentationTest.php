<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Context\HeaderBagGetter;
use Hypervel\OpenTelemetry\Context\ResponseHeadersSetter;
use Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\RequestTelemetryState;
use Hypervel\OpenTelemetry\Support\UserContextResolver;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
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
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use RuntimeException;

class HttpServerInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private HttpServerTestClock $clock;

    private InMemorySpanExporter $spanExporter;

    private TracerProvider $tracerProvider;

    private InMemoryMetricExporter $metricExporter;

    private ExportingReader $metricReader;

    private MeterProvider $meterProvider;

    private UserContextResolver $userContexts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->events = new Dispatcher;
        $this->clock = new HttpServerTestClock;
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->metricExporter = new InMemoryMetricExporter;
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->metricReader)
            ->build();
        $this->userContexts = m::mock(UserContextResolver::class);
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

    public function testRecordsTheCompleteServerLifecycleAndMetrics(): void
    {
        $responsePropagator = new HttpServerTestResponsePropagator;
        $this->instrumentation(responsePropagator: $responsePropagator)->register($this->options([
            'url_query' => true,
            'request_headers' => ['x-request-id'],
            'response_headers' => ['x-response'],
            'metrics' => [
                HttpMetrics::HTTP_SERVER_REQUEST_DURATION => true,
                HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS => true,
            ],
        ]));

        $request = Request::create(
            '/users/123?token=secret&safe=yes',
            'GET',
            server: [
                'HTTP_HOST' => 'example.test',
                'HTTP_TRACEPARENT' => '00-11111111111111111111111111111111-2222222222222222-01',
                'HTTP_USER_AGENT' => 'Hypervel Test',
                'HTTP_X_REQUEST_ID' => 'request-1',
                'CONTENT_LENGTH' => '12',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
            ],
        );
        $request->setRouteResolver(
            fn (): Route => new Route('GET', 'users/{user}', fn (): null => null),
        );
        $response = new Response('hello', 201, ['X-Response' => 'done']);

        $this->events->dispatch(new RequestReceived($request, null));

        $activeState = RequestTelemetryState::current();
        $this->assertNotNull($activeState);
        $this->assertSame($activeState->context, Context::getCurrent());

        $this->events->dispatch(new RequestHandled($request, $response));
        $this->assertSame('11111111111111111111111111111111', $response->headers->get('X-Trace-Id'));
        $this->assertSame(1, $responsePropagator->injections);

        $this->events->dispatch(new ResponseSent($request, $response));

        $state = RequestTelemetryState::current();
        $this->assertNotNull($state);
        $this->assertTrue($state->completed);
        $this->assertSame(Context::getRoot(), Context::getCurrent());

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame('GET /users/{user}', $span->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertSame('11111111111111111111111111111111', $span->getTraceId());
        $this->assertSame('2222222222222222', $span->getParentSpanId());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(2_000_000_000, $span->getEndEpochNanos());
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertSame('GET', $attributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertSame('/users/{user}', $attributes[HttpAttributes::HTTP_ROUTE]);
        $this->assertSame(201, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
        $this->assertSame('safe=yes&token=REDACTED', $attributes[UrlAttributes::URL_QUERY]);
        $this->assertSame(12, $attributes[HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE]);
        $this->assertSame(5, $attributes[HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE]);
        $this->assertArrayNotHasKey(NetworkAttributes::NETWORK_PROTOCOL_NAME, $attributes);
        $this->assertSame('1.1', $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION]);
        $this->assertArrayNotHasKey(ServerAttributes::SERVER_ADDRESS, $attributes);
        $this->assertArrayNotHasKey(ServerAttributes::SERVER_PORT, $attributes);
        $this->assertSame(['request-1'], $attributes[HttpAttributes::HTTP_REQUEST_HEADER . '.x-request-id']);
        $this->assertSame(['done'], $attributes[HttpAttributes::HTTP_RESPONSE_HEADER . '.x-response']);

        $this->metricReader->collect();
        $duration = $this->metric(HttpMetrics::HTTP_SERVER_REQUEST_DURATION);
        $this->assertInstanceOf(Histogram::class, $duration->data);
        $durationPoints = $duration->data->dataPoints;
        $this->assertIsArray($durationPoints);
        $this->assertCount(1, $durationPoints);
        $this->assertSame(1, $durationPoints[0]->sum);
        $this->assertSame('/users/{user}', $durationPoints[0]->attributes->get(HttpAttributes::HTTP_ROUTE));
        $this->assertSame(201, $durationPoints[0]->attributes->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame('1.1', $durationPoints[0]->attributes->get(NetworkAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertNull($durationPoints[0]->attributes->get(NetworkAttributes::NETWORK_PROTOCOL_NAME));
        $this->assertNull($durationPoints[0]->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertNull($durationPoints[0]->attributes->get(ServerAttributes::SERVER_PORT));

        $activeRequests = $this->metric(HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS);
        $this->assertInstanceOf(Sum::class, $activeRequests->data);
        $activePoints = $activeRequests->data->dataPoints;
        $this->assertIsArray($activePoints);
        $this->assertCount(1, $activePoints);
        $this->assertSame(0, $activePoints[0]->value);
        $this->assertNull($activePoints[0]->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertNull($activePoints[0]->attributes->get(ServerAttributes::SERVER_PORT));
    }

    public function testUsesWireMethodSemanticsAndReportsFailures(): void
    {
        $this->instrumentation()->register($this->options());
        $request = Request::create('/users/123', 'POST', server: [
            'HTTP_HOST' => 'example.test',
            'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
        ]);
        $request->setRouteResolver(
            fn (): Route => new Route('DELETE', 'users/{user}', fn (): null => null),
        );
        $response = (new Response('failed', 500))->withException($exception = new RuntimeException('Failed'));

        $this->events->dispatch(new RequestReceived($request, null));
        $this->events->dispatch(new ResponseSent($request, $response, $exception));

        $span = $this->spanExporter->getSpans()[0];
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame('POST /users/{user}', $span->getName());
        $this->assertSame('POST', $attributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertArrayNotHasKey(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL, $attributes);
        $this->assertSame(RuntimeException::class, $attributes[ErrorAttributes::ERROR_TYPE]);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());

        $unknownRequest = Request::create('/purge', 'PURGE', server: ['HTTP_HOST' => 'example.test']);
        $unknownRequest->setRouteResolver(
            fn (): Route => new Route('PURGE', 'purge/{resource}', fn (): null => null),
        );
        $this->events->dispatch(new RequestReceived($unknownRequest, null));
        $this->events->dispatch(new ResponseSent($unknownRequest, new Response));

        $unknownSpan = $this->spanExporter->getSpans()[1];
        $unknownAttributes = $unknownSpan->getAttributes()->toArray();
        $this->assertSame('HTTP /purge/{resource}', $unknownSpan->getName());
        $this->assertSame('_OTHER', $unknownAttributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertSame('PURGE', $unknownAttributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL]);

        $lowercaseRequest = Request::create('/lowercase', 'GET', server: ['HTTP_HOST' => 'example.test']);
        $lowercaseRequest->server->set('REQUEST_METHOD', 'get');
        $this->events->dispatch(new RequestReceived($lowercaseRequest, null));
        $this->events->dispatch(new ResponseSent($lowercaseRequest, new Response));

        $lowercaseSpan = $this->spanExporter->getSpans()[2];
        $lowercaseAttributes = $lowercaseSpan->getAttributes()->toArray();
        $this->assertSame('GET', $lowercaseAttributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertSame('get', $lowercaseAttributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL]);
    }

    public function testExcludedRequestsPerformNoTelemetryWork(): void
    {
        $this->instrumentation()->register($this->options([
            'except_methods' => ['POST'],
            'except_paths' => ['health/*'],
        ]));

        $methodRequest = Request::create('/users', 'POST');
        $pathRequest = Request::create('/health/ready', 'GET');
        $this->events->dispatch(new RequestReceived($methodRequest, null));
        $this->events->dispatch(new ResponseSent($methodRequest, new Response));
        $this->events->dispatch(new RequestReceived($pathRequest, null));
        $this->events->dispatch(new ResponseSent($pathRequest, new Response));

        $this->assertNull(RequestTelemetryState::current());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $duration = $this->metric(HttpMetrics::HTTP_SERVER_REQUEST_DURATION);
        $this->assertInstanceOf(Histogram::class, $duration->data);
        $this->assertSame([], $duration->data->dataPoints);
        $this->assertSame(1_000_000_000, $this->clock->timestamp);
    }

    public function testUserContextOnlyModeRetainsMinimalRequestStateWithoutCompletionWork(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'user_context' => true,
            'metrics' => false,
        ]));
        $request = UserContextOnlyTrapRequest::create('/users', 'GET');

        $this->assertTrue($this->events->hasListeners(RequestReceived::class));
        $this->assertFalse($this->events->hasListeners(RequestHandled::class));
        $this->assertFalse($this->events->hasListeners(ResponseSent::class));

        $this->events->dispatch(new RequestReceived($request, null));

        $state = RequestTelemetryState::current();
        $this->assertNotNull($state);
        $this->assertSame($request, $state->request);
        $this->assertSame(0, $state->startedAt);
        $this->assertNull($state->span);
        $this->assertNull($state->context);
        $this->assertFalse($state->completed);
        $this->assertSame(1_000_000_000, $this->clock->timestamp);
    }

    public function testUserContextOnlyModeRetainsRequestExclusions(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'user_context' => true,
            'except_paths' => ['health/*'],
            'metrics' => false,
        ]));

        $this->events->dispatch(new RequestReceived(Request::create('/health/ready', 'GET'), null));

        $this->assertNull(RequestTelemetryState::current());
        $this->assertSame(1_000_000_000, $this->clock->timestamp);
    }

    public function testNonRecordingSpansSkipRecordingOnlyRequestDetails(): void
    {
        $this->tracerProvider->shutdown();
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler(new AlwaysOffSampler)
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->instrumentation()->register($this->options(['url_query' => true]));
        $request = RecordingDetailTrapRequest::create('/users?token=secret', 'GET');

        $this->events->dispatch(new RequestReceived($request, null));
        $this->events->dispatch(new ResponseSent($request, new Response));

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame(1, $this->metric(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)->data->dataPoints[0]->sum);
    }

    public function testNonRecordingSpansWithoutDurationSkipCompletionDetails(): void
    {
        $this->tracerProvider->shutdown();
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler(new AlwaysOffSampler)
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->instrumentation()->register($this->options([
            'metrics' => [
                HttpMetrics::HTTP_SERVER_REQUEST_DURATION => false,
                HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS => true,
            ],
        ]));
        $request = CompletionDetailTrapRequest::create('/users', 'GET');

        $this->events->dispatch(new RequestReceived($request, null));
        $this->events->dispatch(new ResponseSent($request, new CompletionDetailTrapResponse));

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->assertSame(3_000_000_000, $this->clock->timestamp);
        $this->metricReader->collect();
        $activeRequests = $this->metric(HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS);
        $this->assertSame(0, $activeRequests->data->dataPoints[0]->value);
    }

    public function testDoesNotReadTheClientControlledServerAuthority(): void
    {
        $this->instrumentation()->register($this->options());
        $request = HostAccessTrapRequest::create('/users', 'GET', server: [
            'HTTP_HOST' => 'invalid host',
        ]);

        $this->events->dispatch(new RequestReceived($request, null));
        $this->events->dispatch(new ResponseSent($request, new Response));

        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    /**
     * Create the instrumentation under test.
     */
    private function instrumentation(
        ?ResponsePropagatorInterface $responsePropagator = null,
    ): HttpServerInstrumentation {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new HttpServerInstrumentation(
            $this->events,
            $this->tracerProvider,
            $this->meterProvider,
            TraceContextPropagator::getInstance(),
            $responsePropagator ?? NoopResponsePropagator::getInstance(),
            new HeaderBagGetter,
            new ResponseHeadersSetter,
            $this->clock,
            $logContextScopes,
            $this->userContexts,
        );
    }

    /**
     * Return normalized HTTP server options.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_replace([
            'traces' => true,
            'known_methods' => ConfigurationNormalizer::DEFAULT_HTTP_METHODS,
            'except_paths' => [],
            'except_methods' => [],
            'user_context' => false,
            'url_query' => false,
            'sensitive_query_parameters' => [],
            'sensitive_headers' => [],
            'request_headers' => [],
            'response_headers' => [],
            'metrics' => [
                HttpMetrics::HTTP_SERVER_REQUEST_DURATION => true,
                HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS => false,
            ],
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

class HttpServerTestClock implements ClockInterface
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

class HttpServerTestResponsePropagator implements ResponsePropagatorInterface
{
    public int $injections = 0;

    /**
     * Inject the active trace ID into a test response header.
     */
    public function inject(
        mixed &$carrier,
        ?PropagationSetterInterface $setter = null,
        ?ContextInterface $context = null,
    ): void {
        ++$this->injections;
        $setter->set($carrier, 'X-Trace-Id', Span::fromContext($context)->getContext()->getTraceId());
    }
}

class RecordingDetailTrapRequest extends Request
{
    /**
     * Fail if recording-only query detail is resolved.
     */
    public function getQueryString(): ?string
    {
        throw new RuntimeException('Recording-only request details were resolved.');
    }
}

class CompletionDetailTrapRequest extends Request
{
    /**
     * Fail if the completed route is resolved.
     */
    public function route(?string $param = null, mixed $default = null): mixed
    {
        throw new RuntimeException('The completed route was resolved.');
    }

    /**
     * Fail if the completed protocol version is resolved.
     */
    public function getProtocolVersion(): ?string
    {
        throw new RuntimeException('The completed protocol version was resolved.');
    }
}

class CompletionDetailTrapResponse extends Response
{
    /**
     * Fail if the completed response status is resolved.
     */
    public function getStatusCode(): int
    {
        throw new RuntimeException('The completed response status was resolved.');
    }
}

class HostAccessTrapRequest extends Request
{
    /**
     * Fail if HTTP instrumentation reads the client-controlled authority.
     */
    public function getHost(): string
    {
        throw new RuntimeException('The client-controlled server authority was read.');
    }
}

class UserContextOnlyTrapRequest extends Request
{
    /**
     * Fail if request attributes are resolved in user-context-only mode.
     */
    public function getScheme(): string
    {
        throw new RuntimeException('Request attributes were resolved in user-context-only mode.');
    }
}
