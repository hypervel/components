<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Sentry\Integration;
use Psr\Http\Message\RequestInterface;
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
 * This catches ALL Guzzle usage: Http:: facade, direct new Client(),
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
        private readonly \Hypervel\Contracts\Config\Repository $config,
    ) {
        $this->tracingEnabled = $this->config->get('sentry.tracing.http_client_requests', true) === true;
        $this->breadcrumbsEnabled = $this->config->get('sentry.breadcrumbs.http_client_requests', true) === true;
    }

    /**
     * Intercept the Guzzle transfer method.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        if (! $this->tracingEnabled && ! $this->breadcrumbsEnabled) {
            return $proceedingJoinPoint->process();
        }

        $options = $proceedingJoinPoint->arguments['keys']['options'] ?? [];

        // Check for per-request or per-client opt-out
        if ($this->isOptedOut($options, $proceedingJoinPoint->getInstance())) {
            return $proceedingJoinPoint->process();
        }

        /** @var RequestInterface $request */
        $request = $proceedingJoinPoint->arguments['keys']['request'];

        // Inject trace headers before the request is sent
        if ($this->tracingEnabled && $this->shouldAttachTracingHeaders($request)) {
            $request = $request
                ->withHeader('sentry-trace', getTraceparent())
                ->withHeader('baggage', getBaggage());
            $proceedingJoinPoint->arguments['keys']['request'] = $request;
        }

        // Start a child span for tracing (finished in the on_stats callback)
        $span = null;
        $parentSpan = null;
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

                SentrySdk::getCurrentHub()->setSpan($span);
            }
        }

        // Inject on_stats callback for breadcrumb recording and span finishing.
        // on_stats fires when the transfer completes (sync or async), giving us
        // the response data needed to finish the span with accurate status codes.
        $existingOnStats = $options['on_stats'] ?? null;
        $recordBreadcrumbs = $this->breadcrumbsEnabled;

        $proceedingJoinPoint->arguments['keys']['options']['on_stats'] = static function (TransferStats $stats) use ($existingOnStats, $span, $parentSpan, $recordBreadcrumbs): void {
            if ($recordBreadcrumbs) {
                self::recordBreadcrumb($stats);
            }

            if ($span !== null) {
                self::finishSpan($span, $parentSpan, $stats);
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

                if ($parentSpan !== null) {
                    SentrySdk::getCurrentHub()->setSpan($parentSpan);
                }
            }

            throw $exception;
        }
    }

    /**
     * Finish the span with response data from the transfer stats.
     */
    private static function finishSpan(Span $span, ?Span $parentSpan, TransferStats $stats): void
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

        if ($parentSpan !== null) {
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
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
    private function isOptedOut(array $options, ?object $client): bool
    {
        // Per-request opt-out
        if (($options['no_sentry_aspect'] ?? null) === true) {
            return true;
        }

        // Per-client opt-out via client config
        if ($client instanceof Client) {
            $clientConfig = (fn () => $this->config)->call($client);
            if (($clientConfig['no_sentry_aspect'] ?? null) === true) {
                return true;
            }
        }

        return false;
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
    private static function buildPartialUri(\Psr\Http\Message\UriInterface $uri): string
    {
        $result = $uri->getScheme() . '://' . $uri->getHost();

        $port = $uri->getPort();
        if ($port !== null) {
            $result .= ':' . $port;
        }

        return $result . $uri->getPath();
    }
}
