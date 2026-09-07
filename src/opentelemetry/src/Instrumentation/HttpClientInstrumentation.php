<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\OpenTelemetry\Context\PsrRequestHeadersSetter;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\HttpTelemetryAttributes;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use LogicException;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\UrlIncubatingAttributes;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;

class HttpClientInstrumentation extends AbstractInstrumentation
{
    use LogsMessagesTrait;

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

    protected const string TRACE_OPTION = 'hypervel_otel_trace';

    protected const string REDIRECT_COUNT_OPTION = '__redirect_count';

    /** @var array<string, true> */
    protected array $knownMethods = [];

    protected bool $manual = false;

    protected ?TracerInterface $tracer = null;

    protected ?HistogramInterface $duration = null;

    protected HttpTelemetryAttributes $httpAttributes;

    /**
     * Create HTTP client instrumentation.
     */
    public function __construct(
        protected Factory $factory,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected TextMapPropagatorInterface $propagator,
        protected PsrRequestHeadersSetter $requestSetter,
        protected ClockInterface $clock,
        protected OpenTelemetryManager $manager,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
    ) {
    }

    /**
     * Register HTTP client middleware and instruments.
     */
    protected function registerInstrumentation(): void
    {
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
        $this->manual = $this->options->enabled('manual');
        $this->httpAttributes = new HttpTelemetryAttributes(
            $this->options->enabled('url_query'),
            $sensitiveQueryParameters,
            $sensitiveHeaders,
            $requestHeaders,
            $responseHeaders,
        );

        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.http.client');
        }

        if ($this->metricEnabled(HttpMetrics::HTTP_CLIENT_REQUEST_DURATION)) {
            $this->duration = $this->meterProvider
                ->getMeter('hypervel.http.client')
                ->createHistogram(
                    HttpMetrics::HTTP_CLIENT_REQUEST_DURATION,
                    's',
                    'Duration of HTTP client requests.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
        }

        if ($this->tracer !== null) {
            $this->registerTraceMacros();
        }

        $this->factory->globalMiddleware($this->middleware());
    }

    /**
     * Register per-request trace controls.
     */
    protected function registerTraceMacros(): void
    {
        if (PendingRequest::hasMacro('withTrace') || PendingRequest::hasMacro('withoutTrace')) {
            throw new LogicException(
                'The HTTP client macros [withTrace] and [withoutTrace] are reserved by OpenTelemetry instrumentation.',
            );
        }

        $option = self::TRACE_OPTION;

        PendingRequest::macro('withTrace', function () use ($option) {
            return $this->withOptions([$option => true]);
        });
        PendingRequest::macro('withoutTrace', function () use ($option) {
            return $this->withOptions([$option => false]);
        });
    }

    /**
     * Return the physical-send middleware.
     */
    protected function middleware(): Closure
    {
        return function (callable $handler): Closure {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                $trace = $this->tracer !== null && $this->shouldTrace($options);

                if (! $trace && $this->duration === null) {
                    return $handler($request, $options);
                }

                $methodAttributes = $this->methodAttributes($request);
                $requestAttributes = $this->requestAttributes($request, $methodAttributes);
                $startedAt = $this->clock->now();
                $parent = Context::getCurrent();
                $context = $parent;
                $span = null;

                try {
                    if ($trace) {
                        $span = $this->tracer
                            ->spanBuilder($this->httpAttributes->spanName(
                                $methodAttributes[HttpAttributes::HTTP_REQUEST_METHOD],
                            ))
                            ->setSpanKind(SpanKind::KIND_CLIENT)
                            ->setParent($parent)
                            ->setStartTimestamp($startedAt)
                            ->setAttributes($requestAttributes)
                            ->startSpan();
                        $context = $span->storeInContext($parent);
                        $this->propagator->inject($request, $this->requestSetter, $context);

                        if ($span->isRecording()) {
                            $span->setAttributes($this->requestTraceAttributes(
                                $request,
                                $methodAttributes,
                                $options,
                            ));
                            $this->applyUrlTemplate($span, $request, $methodAttributes);
                        }
                    }

                    $promise = $handler($request, $options);
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->completeFailure(
                        $exception,
                        $span,
                        $context,
                        $startedAt,
                        $requestAttributes,
                    );

                    throw $exception;
                }

                return $promise->then(
                    function (ResponseInterface $response) use (
                        $span,
                        $context,
                        $startedAt,
                        $requestAttributes,
                    ): ResponseInterface {
                        $this->completeResponse(
                            $response,
                            $span,
                            $context,
                            $startedAt,
                            $requestAttributes,
                        );

                        return $response;
                    },
                    function (mixed $reason) use (
                        $span,
                        $context,
                        $startedAt,
                        $requestAttributes,
                    ): PromiseInterface {
                        if ($reason instanceof CanceledException) {
                            throw $reason;
                        }

                        if ($reason instanceof Throwable) {
                            $this->completeFailure(
                                $reason,
                                $span,
                                $context,
                                $startedAt,
                                $requestAttributes,
                            );
                        } else {
                            if ($span?->isRecording()) {
                                $span->setAttribute(
                                    ErrorAttributes::ERROR_TYPE,
                                    ErrorAttributes::ERROR_TYPE_VALUE_OTHER,
                                );
                                $span->setStatus(StatusCode::STATUS_ERROR);
                            }

                            $this->complete(
                                $span,
                                $context,
                                $startedAt,
                                array_replace($requestAttributes, [
                                    ErrorAttributes::ERROR_TYPE => ErrorAttributes::ERROR_TYPE_VALUE_OTHER,
                                ]),
                            );
                        }

                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }

    /**
     * Determine whether this request should create a client span.
     */
    protected function shouldTrace(array $options): bool
    {
        return ($options[self::TRACE_OPTION] ?? ! $this->manual) === true;
    }

    /**
     * Return the method attributes for a physical request.
     *
     * @return array<string, string>
     */
    protected function methodAttributes(RequestInterface $request): array
    {
        $method = $request->getMethod();
        $known = isset($this->knownMethods[$method]);
        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $known
                ? $method
                : HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER,
        ];

        if (! $known) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL] = $method;
        }

        return $attributes;
    }

    /**
     * Return attributes shared by the span and duration metric.
     *
     * @param array<string, string> $methodAttributes
     * @return array<string, int|string>
     */
    protected function requestAttributes(RequestInterface $request, array $methodAttributes): array
    {
        $uri = $request->getUri();

        return array_filter([
            HttpAttributes::HTTP_REQUEST_METHOD => $methodAttributes[HttpAttributes::HTTP_REQUEST_METHOD],
            ServerAttributes::SERVER_ADDRESS => $uri->getHost(),
            ServerAttributes::SERVER_PORT => $this->effectivePort($uri),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Return recording-only request attributes.
     *
     * @param array<string, string> $methodAttributes
     * @return array<string, null|array|bool|float|int|string>
     */
    protected function requestTraceAttributes(
        RequestInterface $request,
        array $methodAttributes,
        array $options,
    ): array {
        $uri = $request->getUri();
        $resendCount = ($options[PendingRequest::PRIOR_SENDS_OPTION] ?? 0)
            + ($options[self::REDIRECT_COUNT_OPTION] ?? 0);

        return array_filter(array_replace(
            $methodAttributes,
            [
                UrlAttributes::URL_FULL => $this->httpAttributes->fullUrl($uri),
                UrlAttributes::URL_SCHEME => $uri->getScheme(),
                HttpAttributes::HTTP_REQUEST_RESEND_COUNT => $resendCount > 0 ? $resendCount : null,
                HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE => $this->httpAttributes->contentLength(
                    $request->getHeaderLine('Content-Length'),
                ),
            ],
            $this->httpAttributes->requestHeaderAttributes($request->getHeaders()),
        ), static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Apply an application-provided URL template to a recording span.
     *
     * @param array<string, string> $methodAttributes
     */
    protected function applyUrlTemplate(
        SpanInterface $span,
        RequestInterface $request,
        array $methodAttributes,
    ): void {
        $resolver = $this->manager->urlTemplateResolver();

        if ($resolver === null) {
            return;
        }

        try {
            $template = $resolver($request);

            if ($template !== null && ! is_string($template)) {
                throw new UnexpectedValueException(
                    'The OpenTelemetry URL-template resolver must return a string or null.',
                );
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            self::logError('OpenTelemetry URL-template resolution failed.', ['exception' => $exception]);

            return;
        }

        if ($template !== null) {
            $span->setAttribute(UrlIncubatingAttributes::URL_TEMPLATE, $template);
            $span->updateName($this->httpAttributes->spanName(
                $methodAttributes[HttpAttributes::HTTP_REQUEST_METHOD],
                $template,
            ));
        }
    }

    /**
     * Complete a successful physical request.
     *
     * @param array<string, int|string> $requestAttributes
     */
    protected function completeResponse(
        ResponseInterface $response,
        ?SpanInterface $span,
        ContextInterface $context,
        int $startedAt,
        array $requestAttributes,
    ): void {
        $statusCode = $response->getStatusCode();
        $errorType = $statusCode >= 400 ? (string) $statusCode : null;
        $attributes = array_filter(array_replace($requestAttributes, [
            HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $statusCode,
            NetworkAttributes::NETWORK_PROTOCOL_VERSION => $response->getProtocolVersion(),
            ErrorAttributes::ERROR_TYPE => $errorType,
        ]), static fn (mixed $value): bool => $value !== null);

        if ($span?->isRecording()) {
            $span->setAttributes(array_replace(
                $attributes,
                array_filter([
                    HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE => $this->httpAttributes->contentLength(
                        $response->getHeaderLine('Content-Length'),
                    ),
                ], static fn (mixed $value): bool => $value !== null),
                $this->httpAttributes->responseHeaderAttributes($response->getHeaders()),
            ));

            if ($errorType !== null) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
        }

        $this->complete($span, $context, $startedAt, $attributes);
    }

    /**
     * Complete a failed physical request.
     *
     * @param array<string, int|string> $requestAttributes
     */
    protected function completeFailure(
        Throwable $exception,
        ?SpanInterface $span,
        ContextInterface $context,
        int $startedAt,
        array $requestAttributes,
    ): void {
        if ($span !== null) {
            $this->exceptionContexts->associate(
                $exception,
                $context,
                $this->origins->resolve($context, $this->identity),
            );

            if ($span->isRecording()) {
                $span->recordException($exception);
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
        }

        $this->complete(
            $span,
            $context,
            $startedAt,
            array_replace($requestAttributes, [ErrorAttributes::ERROR_TYPE => $exception::class]),
        );
    }

    /**
     * Record the duration and end the client span.
     *
     * @param array<string, int|string> $attributes
     */
    protected function complete(
        ?SpanInterface $span,
        ContextInterface $context,
        int $startedAt,
        array $attributes,
    ): void {
        $finishedAt = $this->clock->now();

        $this->duration?->record(
            ($finishedAt - $startedAt) / ClockInterface::NANOS_PER_SECOND,
            $attributes,
            $context,
        );
        $span?->end($finishedAt);
    }

    /**
     * Return the effective HTTP port.
     */
    protected function effectivePort(UriInterface $uri): ?int
    {
        if (($port = $uri->getPort()) !== null) {
            return $port;
        }

        return match (strtolower($uri->getScheme())) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
