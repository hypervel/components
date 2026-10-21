<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Hypervel\Sentry\Integration;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Sentry\Breadcrumb;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpSpanDataCollector;
use Sentry\DataCollection\HttpUrlCollector;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Throwable;

use function Sentry\getBaggage;
use function Sentry\getTraceparent;

/**
 * Instrument the requests of every Guzzle client: the HTTP client, directly constructed
 * clients and third-party SDKs built on Guzzle.
 *
 * A client or a single request opts out with the `sentry_enabled` option set to false.
 */
class HttpClientIntegration extends Feature
{
    use ResolvesEventOrigin;

    private const string FEATURE_KEY = 'http_client_requests';

    private const string MIDDLEWARE_NAME = 'sentry';

    private bool $tracingEnabled;

    private bool $breadcrumbsEnabled;

    private bool $traceOrigin;

    private int $traceOriginThresholdMs;

    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        // An active SDK still propagates trace headers when local recording is disabled.
        return true;
    }

    /**
     * Instrument outgoing Guzzle requests.
     *
     * Boot-only. The middleware persists for the worker lifetime and affects every Guzzle client.
     */
    public function onBoot(): void
    {
        $userConfig = $this->getUserConfig();

        $this->tracingEnabled = $this->isTracingFeatureEnabled(self::FEATURE_KEY);
        $this->breadcrumbsEnabled = $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
        $this->traceOrigin = $userConfig['tracing']['http_client_requests_origin'] === true;
        $this->traceOriginThresholdMs = $userConfig['tracing']['http_client_requests_origin_threshold_ms'];

        Client::pushGlobalMiddleware($this->instrumentRequests(...), self::MIDDLEWARE_NAME);
    }

    /**
     * Trace each request and record its completed transfer.
     */
    protected function instrumentRequests(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (($options['sentry_enabled'] ?? true) === false) {
                return $handler($request, $options);
            }

            if ($this->shouldAttachTracingHeaders($request)) {
                $request = $request
                    ->withHeader('sentry-trace', getTraceparent())
                    ->withHeader('baggage', getBaggage());
            }

            $span = null;
            if ($this->tracingEnabled) {
                $parentSpan = SentrySdk::getCurrentHub()->getSpan();

                if ($parentSpan !== null && $parentSpan->getSampled()) {
                    $span = $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('http.client')
                            ->setOrigin('auto.http.guzzle')
                            ->setDescription($request->getMethod() . ' ' . self::buildPartialUri($request->getUri()))
                    );
                }
            }

            if ($span === null && ! $this->breadcrumbsEnabled) {
                return $handler($request, $options);
            }

            // Guzzle applies the request options and middleware after this point and calls on_stats once
            // per redirect, so keep the last prepared request and record once when the transfer settles.
            $stats = null;
            $callerOnStats = $options['on_stats'] ?? null;
            $options['on_stats'] = static function (TransferStats $transferStats) use (&$stats, $callerOnStats): void {
                $stats = $transferStats;

                if ($callerOnStats !== null) {
                    $callerOnStats($transferStats);
                }
            };

            // An async request can settle after its caller has returned, so capture its origin now. SDKs such as AWS
            // also send synchronous calls this way, below their own middleware and any storage decorators.
            $backtrace = $span !== null && $this->traceOrigin && ($options['synchronous'] ?? false) !== true
                ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64)
                : null;

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                $this->recordTransfer($span, $request, $stats, $exception instanceof ResponseException ? $exception->getResponse() : null, $backtrace);

                throw $exception;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($span, $request, &$stats, $backtrace): ResponseInterface {
                    $this->recordTransfer($span, $request, $stats, $response, $backtrace);

                    return $response;
                },
                function (mixed $reason) use ($span, $request, &$stats, $backtrace): PromiseInterface {
                    $this->recordTransfer($span, $request, $stats, $reason instanceof ResponseException ? $reason->getResponse() : null, $backtrace);

                    return Create::rejectionFor($reason);
                }
            );
        };
    }

    /**
     * Finish the span and record the breadcrumb of a settled transfer.
     *
     * The last transfer stats hold the prepared request of the final redirect, including the
     * request options and middleware changes; the entry request is used when there are none.
     */
    private function recordTransfer(?Span $span, RequestInterface $request, ?TransferStats $stats, ?ResponseInterface $response, ?array $backtrace): void
    {
        $request = $stats?->getRequest() ?? $request;
        $uri = $request->getUri();
        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());
        $data = [
            'url' => self::buildPartialUri($uri),
            'http.fragment' => $uri->getFragment(),
            'http.request.method' => $request->getMethod(),
            'http.request.body.size' => $request->getBody()->getSize(),
            ...self::collectUrlData($policy, $uri),
        ];
        $responseData = $response === null ? [] : [
            'http.response.status_code' => $response->getStatusCode(),
            'http.response.body.size' => $response->getBody()->getSize(),
        ];

        if ($span !== null) {
            $span->setDescription($request->getMethod() . ' ' . $data['url']);
            $span->setData([
                ...$data,
                ...HttpSpanDataCollector::collectPsr7Request($policy, HttpMessageType::outgoingRequest(), $request),
                ...$responseData,
            ]);

            if ($response === null) {
                $span->setStatus(SpanStatus::internalError());
            } else {
                $span->setData(HttpSpanDataCollector::collectPsr7Response($policy, HttpMessageType::incomingResponse(), $response));
                $span->setHttpStatus($response->getStatusCode());
            }

            if ($this->traceOrigin && (microtime(true) - $span->getStartTimestamp()) * 1000 >= $this->traceOriginThresholdMs) {
                // Synchronous completion sits below the promise queue and middleware, so look deeper than other origins.
                $origin = $this->resolveEventOrigin($backtrace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40));

                if ($origin !== null) {
                    $span->setData($origin);
                }
            }

            $span->finish();
        }

        if ($this->breadcrumbsEnabled) {
            $statusCode = $response?->getStatusCode();
            $breadcrumbData = [...$data, ...$responseData];

            if ($stats?->getTransferTime() !== null) {
                $breadcrumbData['duration'] = $stats->getTransferTime() * 1000;
            }

            Integration::addBreadcrumb(new Breadcrumb(
                match (true) {
                    $statusCode === null, $statusCode >= 500 => Breadcrumb::LEVEL_ERROR,
                    $statusCode >= 400 => Breadcrumb::LEVEL_WARNING,
                    default => Breadcrumb::LEVEL_INFO,
                },
                Breadcrumb::TYPE_HTTP,
                'http',
                null,
                $breadcrumbData
            ));
        }
    }

    /**
     * Collect the query string and full URL of an outgoing request.
     *
     * @return array<string, string>
     */
    private static function collectUrlData(DataCollectionPolicy $policy, UriInterface $uri): array
    {
        // The legacy options always collected the query string, even when it is empty
        if ($policy->isLegacyMode()) {
            return ['http.query' => $uri->getQuery()];
        }

        $data = [];

        $queryString = HttpUrlCollector::collectQueryString($policy, $uri->getQuery());
        if ($queryString !== null) {
            $data['http.query'] = $queryString;
        }

        $fullUrl = HttpUrlCollector::collect($policy, HttpMessageType::outgoingRequest(), $uri);
        if ($fullUrl !== null) {
            $data['url.full'] = $fullUrl;
        }

        return $data;
    }

    /**
     * Determine if tracing headers should be attached to the request.
     */
    private function shouldAttachTracingHeaders(RequestInterface $request): bool
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client === null) {
            return false;
        }

        $targets = $client->getOptions()->getTracePropagationTargets();

        // When null, attach to all targets
        if ($targets === null) {
            return true;
        }

        return in_array($request->getUri()->getHost(), $targets, true);
    }

    /**
     * Build a partial URI string excluding query and fragment.
     */
    private static function buildPartialUri(UriInterface $uri): string
    {
        $result = $uri->getScheme() . '://' . $uri->getHost();

        $port = $uri->getPort();
        if ($port !== null) {
            $result .= ':' . $port;
        }

        return $result . $uri->getPath();
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        Client::removeGlobalMiddleware(self::MIDDLEWARE_NAME);
    }
}
