<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\SdkCapabilities;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Throwable;

use function Sentry\getBaggage;
use function Sentry\getTraceparent;

/**
 * AOP aspect that instruments all Guzzle HTTP client requests.
 *
 * Intercepts GuzzleHttp\Client::transfer() to provide:
 * - Trace header injection (sentry-trace, baggage)
 * - Span creation and finishing for tracing
 * - Breadcrumb recording via on_stats callback
 * - Preservation of any existing on_stats callback
 * - Per-request opt-out via the no_sentry_aspect option
 *
 * This catches all Guzzle usage: Http:: facade, direct new Client(),
 * and third-party packages using Guzzle internally.
 */
class GuzzleHttpClientAspect extends AbstractAspect
{
    public array $classes = [
        Client::class . '::transfer',
    ];

    private readonly bool $tracingEnabled;

    private readonly bool $breadcrumbsEnabled;

    /**
     * Create a new aspect instance.
     */
    public function __construct(
        private readonly Repository $config,
        SdkCapabilities $capabilities,
    ) {
        $this->tracingEnabled = $capabilities->canRecordSpans()
            && $this->config->boolean('sentry.tracing.http_client_requests', true);
        $this->breadcrumbsEnabled = $capabilities->canRecordBreadcrumbs()
            && $this->config->boolean('sentry.breadcrumbs.http_client_requests', true);
    }

    /**
     * Intercept the Guzzle transfer method.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $options = $proceedingJoinPoint->arguments['keys']['options'] ?? [];

        if ($this->isOptedOut($options)) {
            return $proceedingJoinPoint->process();
        }

        /** @var RequestInterface $request */
        $request = $proceedingJoinPoint->arguments['keys']['request'];

        // Inject trace headers before the request is sent
        if ($this->shouldAttachTracingHeaders($request)) {
            $request = $request
                ->withHeader('sentry-trace', getTraceparent())
                ->withHeader('baggage', getBaggage());
            $proceedingJoinPoint->arguments['keys']['request'] = $request;
        }

        // Start a child span for tracing (finished in the on_stats callback)
        $span = null;
        if ($this->tracingEnabled) {
            $parentSpan = SentrySdk::getCurrentHub()->getSpan();

            if ($parentSpan !== null && $parentSpan->getSampled()) {
                $method = $request->getMethod();
                $uri = $request->getUri();
                $partialUri = self::buildPartialUri($uri);

                $span = $parentSpan->startChild(
                    SpanContext::make()
                        ->setOp('http.client')
                        ->setData([
                            'url' => $partialUri,
                            'http.query' => $uri->getQuery(),
                            'http.fragment' => $uri->getFragment(),
                            'http.request.method' => $method,
                        ])
                        ->setOrigin('auto.http.guzzle')
                        ->setDescription($method . ' ' . $partialUri)
                );
            }
        }

        // Inject on_stats callback for breadcrumb recording and span finishing.
        // on_stats fires when the transfer completes (sync or async), giving us
        // the response data needed to finish the span with accurate status codes.
        $existingOnStats = $options['on_stats'] ?? null;
        $recordBreadcrumbs = $this->breadcrumbsEnabled;

        if ($span === null && ! $recordBreadcrumbs) {
            return $proceedingJoinPoint->process();
        }

        $proceedingJoinPoint->arguments['keys']['options']['on_stats'] = static function (TransferStats $stats) use ($existingOnStats, $span, $recordBreadcrumbs): void {
            if ($recordBreadcrumbs) {
                self::recordBreadcrumb($stats);
            }

            if ($span !== null) {
                self::finishSpan($span, $stats);
            }

            if (is_callable($existingOnStats)) {
                $existingOnStats($stats);
            }
        };

        try {
            return $proceedingJoinPoint->process();
        } catch (Throwable $exception) {
            // on_stats may not fire on connection failure — ensure span is finished
            if ($span !== null && $span->getEndTimestamp() === null) {
                $span->setStatus(SpanStatus::internalError());
                $span->finish();
            }

            throw $exception;
        }
    }

    /**
     * Finish the span with response data from the transfer stats.
     */
    private static function finishSpan(Span $span, TransferStats $stats): void
    {
        $response = $stats->getResponse();

        if ($response !== null) {
            $span->setData(array_merge($span->getData(), [
                'http.response.status_code' => $response->getStatusCode(),
                'http.response.body.size' => $response->getBody()->getSize(),
            ]));
            $span->setHttpStatus($response->getStatusCode());
        } else {
            $span->setStatus(SpanStatus::internalError());
        }

        $span->finish();
    }

    /**
     * Record a breadcrumb from the transfer stats.
     */
    private static function recordBreadcrumb(TransferStats $stats): void
    {
        $request = $stats->getRequest();
        $response = $stats->getResponse();
        $uri = $request->getUri();

        $level = Breadcrumb::LEVEL_INFO;
        if ($response !== null) {
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400 && $statusCode < 500) {
                $level = Breadcrumb::LEVEL_WARNING;
            } elseif ($statusCode >= 500) {
                $level = Breadcrumb::LEVEL_ERROR;
            }
        } else {
            // No response means connection failure
            $level = Breadcrumb::LEVEL_ERROR;
        }

        $partialUri = self::buildPartialUri($uri);

        $data = [
            'url' => $partialUri,
            'http.query' => $uri->getQuery(),
            'http.fragment' => $uri->getFragment(),
            'http.request.method' => $request->getMethod(),
            'http.request.body.size' => $request->getBody()->getSize(),
        ];

        if ($response !== null) {
            $data['http.response.status_code'] = $response->getStatusCode();
            $data['http.response.body.size'] = $response->getBody()->getSize();
        }

        if ($stats->getTransferTime() !== null) {
            $data['duration'] = $stats->getTransferTime() * 1000;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            $level,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            $data
        ));
    }

    /**
     * Determine if the request has opted out of Sentry instrumentation.
     */
    private function isOptedOut(array $options): bool
    {
        return ($options['no_sentry_aspect'] ?? false) === true;
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
}
