<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HypervelResponse;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\OpenTelemetry\Context\HeaderBagGetter;
use Hypervel\OpenTelemetry\Context\ResponseHeadersSetter;
use Hypervel\OpenTelemetry\Support\HttpTelemetryAttributes;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\RequestTelemetryState;
use Hypervel\OpenTelemetry\Support\UserContextResolver;
use Hypervel\Routing\Route;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HttpServerInstrumentation extends AbstractInstrumentation
{
    protected const array DURATION_BOUNDARIES = [
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1,
        2.5,
        5,
        7.5,
        10,
    ];

    /** @var array<string, true> */
    protected array $knownMethods = [];

    /** @var array<string, true> */
    protected array $excludedMethods = [];

    /** @var list<string> */
    protected array $excludedPaths = [];

    protected bool $userContext = false;

    protected bool $userContextOnly = false;

    protected ?TracerInterface $tracer = null;

    protected ?HistogramInterface $duration = null;

    protected ?UpDownCounterInterface $activeRequests = null;

    protected HttpTelemetryAttributes $httpAttributes;

    /**
     * Create HTTP server instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected TextMapPropagatorInterface $propagator,
        protected ResponsePropagatorInterface $responsePropagator,
        protected HeaderBagGetter $headerGetter,
        protected ResponseHeadersSetter $responseSetter,
        protected ClockInterface $clock,
        protected LogContextScopeFactory $logContextScopes,
        protected UserContextResolver $userContexts,
    ) {
    }

    /**
     * Register HTTP server listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        /** @var list<string> $excludedMethods */
        $excludedMethods = $this->options->get('except_methods');
        /** @var list<string> $excludedPaths */
        $excludedPaths = $this->options->get('except_paths');

        $this->excludedMethods = array_fill_keys(array_map(strtoupper(...), $excludedMethods), true);
        $this->excludedPaths = $excludedPaths;
        $this->userContext = $this->options->enabled('user_context');
        $tracesEnabled = $this->tracesEnabled();
        $durationEnabled = $this->metricEnabled(HttpMetrics::HTTP_SERVER_REQUEST_DURATION);
        $activeRequestsEnabled = $this->metricEnabled(HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS);
        $this->userContextOnly = ! $tracesEnabled
            && ! $durationEnabled
            && ! $activeRequestsEnabled
            && $this->userContext;

        if ($this->userContextOnly) {
            $this->events->listen(RequestReceived::class, function (RequestReceived $event): void {
                $this->requestReceived($event);
            });

            return;
        }

        /** @var list<string> $knownMethods */
        $knownMethods = $this->options->get('known_methods');
        /** @var list<string> $sensitiveQueryParameters */
        $sensitiveQueryParameters = $this->options->get('sensitive_query_parameters');
        /** @var list<string> $sensitiveHeaders */
        $sensitiveHeaders = $this->options->get('sensitive_headers');
        /** @var list<string> $requestHeaders */
        $requestHeaders = $this->options->get('request_headers');
        /** @var list<string> $responseHeaders */
        $responseHeaders = $this->options->get('response_headers');

        $this->knownMethods = array_fill_keys($knownMethods, true);
        $this->httpAttributes = new HttpTelemetryAttributes(
            $this->options->enabled('url_query'),
            $sensitiveQueryParameters,
            $sensitiveHeaders,
            $requestHeaders,
            $responseHeaders,
        );

        if ($tracesEnabled) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.http.server');
        }

        $meter = $durationEnabled || $activeRequestsEnabled
            ? $this->meterProvider->getMeter('hypervel.http.server')
            : null;

        if ($durationEnabled) {
            $this->duration = $meter->createHistogram(
                HttpMetrics::HTTP_SERVER_REQUEST_DURATION,
                's',
                'Duration of HTTP server requests.',
                ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
            );
        }

        if ($activeRequestsEnabled) {
            $this->activeRequests = $meter->createUpDownCounter(
                HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS,
                '{request}',
                'Number of active HTTP server requests.',
            );
        }

        $this->events->listen(RequestReceived::class, function (RequestReceived $event): void {
            $this->requestReceived($event);
        });
        $this->events->listen(ResponseSent::class, function (ResponseSent $event): void {
            $this->responseSent($event);
        });

        if ($this->tracer !== null && ! $this->responsePropagator instanceof NoopResponsePropagator) {
            $this->events->listen(RequestHandled::class, function (RequestHandled $event): void {
                $this->requestHandled($event);
            });
        }
    }

    /**
     * Start telemetry for an accepted request.
     */
    protected function requestReceived(RequestReceived $event): void
    {
        $request = $event->request;

        if ($request === null
            || isset($this->excludedMethods[$request->getRealMethod()])
            || ($this->excludedPaths !== [] && $request->is(...$this->excludedPaths))
        ) {
            return;
        }

        if ($this->userContextOnly) {
            RequestTelemetryState::set(new RequestTelemetryState(
                $request,
                // Timing is unused here; keeping the field non-null avoids a check in metric modes.
                0,
                null,
                null,
                null,
                null,
                [],
                false,
            ));

            return;
        }

        $methodAttributes = $this->methodAttributes($request);
        $activeRequestAttributes = $this->activeRequestAttributes($request, $methodAttributes);
        $startedAt = $this->tracer === null && $this->duration === null
            ? 0
            : $this->clock->now();
        $span = null;
        $context = null;
        $scope = null;
        $logContextScope = null;

        if ($this->tracer !== null) {
            $parent = $this->propagator->extract($request->headers, $this->headerGetter);
            $span = $this->tracer
                ->spanBuilder($this->httpAttributes->spanName(
                    $methodAttributes[HttpAttributes::HTTP_REQUEST_METHOD],
                ))
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($activeRequestAttributes)
                ->startSpan();
            $context = $span->storeInContext($parent);
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());

            if ($span->isRecording()) {
                $span->setAttributes($this->requestTraceAttributes($request, $methodAttributes));
            }
        }

        $activeRequestRecorded = $this->activeRequests !== null;

        if ($activeRequestRecorded) {
            $this->activeRequests->add(1, $activeRequestAttributes, $context);
        }

        RequestTelemetryState::set(new RequestTelemetryState(
            $request,
            $startedAt,
            $span,
            $context,
            $scope,
            $logContextScope,
            $activeRequestAttributes,
            $activeRequestRecorded,
        ));
    }

    /**
     * Inject configured response propagation before transport send.
     */
    protected function requestHandled(RequestHandled $event): void
    {
        $state = RequestTelemetryState::current();

        if ($event->exception instanceof CanceledException
            || $event->response === null
            || $state === null
            || $state->completed
            || $state->request !== $event->request
            || $state->context === null
        ) {
            return;
        }

        $response = $event->response;
        $this->responsePropagator->inject($response, $this->responseSetter, $state->context);
    }

    /**
     * Complete telemetry at the response transport boundary.
     */
    protected function responseSent(ResponseSent $event): void
    {
        $state = RequestTelemetryState::current();

        if ($state === null || $state->completed || $state->request !== $event->request) {
            return;
        }

        $state->completed = true;
        $recordingSpan = $state->span?->isRecording() ?? false;
        $finishedAt = $state->span === null && $this->duration === null
            ? 0
            : $this->clock->now();
        $response = null;
        $errorType = null;
        $completionAttributes = [];

        if ($recordingSpan || $this->duration !== null) {
            $response = $event->response;
            $statusCode = $response?->getStatusCode();
            $exception = $event->exception ?? $this->responseException($response);
            $errorType = $exception !== null
                ? $exception::class
                : ($statusCode !== null && $statusCode >= 500 ? (string) $statusCode : null);
            $completionAttributes = $this->completionAttributes(
                $state->request,
                $state->activeRequestAttributes,
                $statusCode,
                $errorType,
            );
        }

        try {
            if ($recordingSpan) {
                $state->span->updateName($this->httpAttributes->spanName(
                    $completionAttributes[HttpAttributes::HTTP_REQUEST_METHOD],
                    $completionAttributes[HttpAttributes::HTTP_ROUTE] ?? null,
                ));
                $state->span->setAttributes($completionAttributes);

                if ($response !== null) {
                    $state->span->setAttributes($this->responseTraceAttributes($response));
                }

                if ($this->userContext) {
                    $state->span->setAttributes($this->userContexts->resolve($state));
                }

                if ($errorType !== null) {
                    $state->span->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            if ($this->duration !== null) {
                $this->duration->record(
                    ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
                    $completionAttributes,
                    $state->context,
                );
            }
        } finally {
            if ($state->activeRequestRecorded) {
                $this->activeRequests?->add(-1, $state->activeRequestAttributes, $state->context);
                $state->activeRequestRecorded = false;
            }

            $state->logContextScope?->close();
            $state->logContextScope = null;
            $state->scope?->detach();
            $state->scope = null;
            $state->span?->end($finishedAt);
        }
    }

    /**
     * Return normalized method attributes for a server request.
     *
     * @return array<string, string>
     */
    protected function methodAttributes(Request $request): array
    {
        $method = $request->getRealMethod();
        $originalMethod = $request->server->get('REQUEST_METHOD', 'GET');
        $known = isset($this->knownMethods[$method]);
        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $known
                ? $method
                : HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER,
        ];

        if (! $known || $originalMethod !== $method) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL] = $originalMethod;
        }

        return $attributes;
    }

    /**
     * Return attributes available when request processing starts.
     *
     * @param array<string, string> $methodAttributes
     * @return array<string, null|array|bool|float|int|string>
     */
    protected function activeRequestAttributes(Request $request, array $methodAttributes): array
    {
        return [
            HttpAttributes::HTTP_REQUEST_METHOD => $methodAttributes[HttpAttributes::HTTP_REQUEST_METHOD],
            UrlAttributes::URL_SCHEME => $request->getScheme(),
        ];
    }

    /**
     * Return recording-only request attributes.
     *
     * @param array<string, string> $methodAttributes
     * @return array<string, null|array|bool|float|int|string>
     */
    protected function requestTraceAttributes(Request $request, array $methodAttributes): array
    {
        return array_filter(array_replace(
            $methodAttributes,
            [
                UrlAttributes::URL_PATH => $request->getPathInfo(),
                UrlAttributes::URL_QUERY => $this->httpAttributes->query($request->getQueryString()),
                NetworkAttributes::NETWORK_PROTOCOL_VERSION => $this->httpAttributes->protocolVersion(
                    $request->getProtocolVersion(),
                ),
                ClientAttributes::CLIENT_ADDRESS => $request->getClientIp(),
                UserAgentAttributes::USER_AGENT_ORIGINAL => $request->headers->get('User-Agent'),
                HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE => $this->httpAttributes->contentLength(
                    $request->headers->get('Content-Length'),
                ),
            ],
            $this->httpAttributes->requestHeaderAttributes($request->headers->all()),
        ), static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Return attributes available when request processing completes.
     *
     * @param array<string, null|array|bool|float|int|string> $startAttributes
     * @return array<string, null|array|bool|float|int|string>
     */
    protected function completionAttributes(
        Request $request,
        array $startAttributes,
        ?int $statusCode,
        ?string $errorType,
    ): array {
        $route = $request->route();
        $routeTemplate = $route instanceof Route ? '/' . ltrim($route->uri(), '/') : null;

        return array_filter(array_replace($startAttributes, [
            HttpAttributes::HTTP_ROUTE => $routeTemplate,
            HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $statusCode,
            NetworkAttributes::NETWORK_PROTOCOL_VERSION => $this->httpAttributes->protocolVersion(
                $request->getProtocolVersion(),
            ),
            ErrorAttributes::ERROR_TYPE => $errorType,
        ]), static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Return recording-only response attributes.
     *
     * @return array<string, null|array|bool|float|int|string>
     */
    protected function responseTraceAttributes(Response $response): array
    {
        $contentLength = $this->httpAttributes->contentLength($response->headers->get('Content-Length'));

        if ($contentLength === null && ($content = $response->getContent()) !== false) {
            $contentLength = strlen($content);
        }

        return array_filter(array_replace(
            [HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE => $contentLength],
            $this->httpAttributes->responseHeaderAttributes($response->headers->all()),
        ), static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Return the application exception retained by a Hypervel response.
     */
    protected function responseException(?Response $response): ?Throwable
    {
        return match (true) {
            $response instanceof HypervelResponse,
            $response instanceof JsonResponse,
            $response instanceof RedirectResponse => $response->exception,
            default => null,
        };
    }
}
