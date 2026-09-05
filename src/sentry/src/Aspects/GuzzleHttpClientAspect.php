<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
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
 * Instrument every Guzzle transfer with Sentry tracing and breadcrumbs.
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
        $this->tracingEnabled = $this->config->boolean('sentry.tracing.http_client_requests', true);
        $this->breadcrumbsEnabled = $this->config->boolean('sentry.breadcrumbs.http_client_requests', true);
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

        $span = null;
        $parentSpan = null;
        $hub = SentrySdk::getCurrentHub();

        if ($this->tracingEnabled) {
            $parentSpan = $hub->getSpan();

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

        $existingOnStats = $options['on_stats'] ?? null;
        $recordBreadcrumbs = $this->breadcrumbsEnabled;

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

        if ($span !== null) {
            $hub->setSpan($span);
        }

        try {
            if ($this->tracingEnabled && $this->shouldAttachTracingHeaders($request)) {
                $request = $request
                    ->withHeader('sentry-trace', getTraceparent())
                    ->withHeader('baggage', getBaggage());
                $proceedingJoinPoint->arguments['keys']['request'] = $request;
            }

            /** @var PromiseInterface $promise */
            $promise = $proceedingJoinPoint->process();
        } catch (Throwable $exception) {
            if ($span !== null) {
                self::finishSpan($span);
            }

            throw $exception;
        } finally {
            if ($span !== null) {
                // An outstanding promise must never own the current span in Hypervel's coroutine-scoped Hub.
                $hub->setSpan($parentSpan);
            }
        }

        if ($span === null || $span->getEndTimestamp() !== null) {
            return $promise;
        }

        return self::finalizePromiseFailure($promise, $span);
    }

    /**
     * Finish the span with any available transfer data.
     */
    private static function finishSpan(Span $span, ?TransferStats $stats = null): void
    {
        if ($span->getEndTimestamp() !== null) {
            return;
        }

        $response = $stats?->getResponse();

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
     * Finalize a span when an asynchronous transfer fails or is cancelled.
     */
    private static function finalizePromiseFailure(PromiseInterface $promise, Span $span): PromiseInterface
    {
        $state = $promise->getState();

        if ($state === PromiseInterface::REJECTED) {
            self::finishSpan($span);

            return $promise;
        }

        if ($state !== PromiseInterface::PENDING) {
            return $promise;
        }

        $forwardingPromise = new Promise(
            static function (bool $unwrap) use ($promise): void {
                $promise->wait(false);
            },
            static function () use ($promise, $span): void {
                try {
                    $promise->cancel();
                } finally {
                    self::finishSpan($span);
                }
            }
        );

        $promise->then(
            static function (mixed $value) use ($forwardingPromise): void {
                if ($forwardingPromise->getState() === PromiseInterface::PENDING) {
                    $forwardingPromise->resolve($value);
                }
            },
            static function (mixed $reason) use ($forwardingPromise, $span): void {
                // Guzzle uses the same guard because cancellation can settle a child before queued handlers run.
                if ($forwardingPromise->getState() === PromiseInterface::PENDING) {
                    self::finishSpan($span);
                    $forwardingPromise->reject($reason);
                }
            }
        );

        return $forwardingPromise;
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
