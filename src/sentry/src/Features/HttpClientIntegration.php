<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use GuzzleHttp\Psr7\Uri;
use Hypervel\Http\Client\Events\ConnectionFailed;
use Hypervel\Http\Client\Events\RequestSending;
use Hypervel\Http\Client\Events\ResponseReceived;
use Hypervel\Http\Client\Factory;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Integration;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

use function Sentry\getBaggage;
use function Sentry\getTraceparent;

class HttpClientIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    private const FEATURE_KEY = 'http_client_requests';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(): void
    {
        $dispatcher = $this->container->make('events');
        $factory = $this->container->make(Factory::class);

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $dispatcher->listen(RequestSending::class, [$this, 'handleRequestSendingHandlerForTracing']);
            $dispatcher->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandlerForTracing']);
            $dispatcher->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandlerForTracing']);

            $factory->globalRequestMiddleware([$this, 'attachTracingHeadersToRequest']);
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $dispatcher->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandlerForBreadcrumb']);
            $dispatcher->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandlerForBreadcrumb']);
        }
    }

    public function attachTracingHeadersToRequest(RequestInterface $request): RequestInterface
    {
        if ($this->shouldAttachTracingHeaders($request)) {
            return $request
                ->withHeader('baggage', getBaggage())
                ->withHeader('sentry-trace', getTraceparent());
        }

        return $request;
    }

    public function handleRequestSendingHandlerForTracing(RequestSending $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $fullUri = $this->getFullUri($event->request->url());
        $partialUri = $this->getPartialUri($fullUri);

        $this->pushSpan(
            $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('http.client')
                    ->setData([
                        'url' => $partialUri,
                        // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                        'http.query' => $fullUri->getQuery(),
                        'http.fragment' => $fullUri->getFragment(),
                        'http.request.method' => $event->request->method(),
                        'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
                    ])
                    ->setOrigin('auto.http.client')
                    ->setDescription($event->request->method() . ' ' . $partialUri)
            )
        );
    }

    public function handleResponseReceivedHandlerForTracing(ResponseReceived $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->setData(array_merge($span->getData(), [
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.response.status_code' => $event->response->status(),
                'http.response.body.size' => $event->response->toPsrResponse()->getBody()->getSize(),
            ]));
            $span->setHttpStatus($event->response->status());
            $span->finish();
        }
    }

    public function handleConnectionFailedHandlerForTracing(ConnectionFailed $event): void
    {
        $this->maybeFinishSpan(SpanStatus::internalError());
    }

    public function handleResponseReceivedHandlerForBreadcrumb(ResponseReceived $event): void
    {
        $level = Breadcrumb::LEVEL_INFO;

        if ($event->response->clientError()) {
            $level = Breadcrumb::LEVEL_WARNING;
        } elseif ($event->response->serverError()) {
            $level = Breadcrumb::LEVEL_ERROR;
        }

        $fullUri = $this->getFullUri($event->request->url());

        Integration::addBreadcrumb(new Breadcrumb(
            $level,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            [
                'url' => $this->getPartialUri($fullUri),
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.query' => $fullUri->getQuery(),
                'http.fragment' => $fullUri->getFragment(),
                'http.request.method' => $event->request->method(),
                'http.response.status_code' => $event->response->status(),
                'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
                'http.response.body.size' => $event->response->toPsrResponse()->getBody()->getSize(),
            ]
        ));
    }

    public function handleConnectionFailedHandlerForBreadcrumb(ConnectionFailed $event): void
    {
        $fullUri = $this->getFullUri($event->request->url());

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_ERROR,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            [
                'url' => $this->getPartialUri($fullUri),
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.query' => $fullUri->getQuery(),
                'http.fragment' => $fullUri->getFragment(),
                'http.request.method' => $event->request->method(),
                'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
            ]
        ));
    }

    /**
     * Construct a full URI.
     */
    private function getFullUri(string $url): UriInterface
    {
        return new Uri($url);
    }

    /**
     * Construct a partial URI, excluding the authority, query and fragment parts.
     */
    private function getPartialUri(UriInterface $uri): string
    {
        return (string) Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);
    }

    private function shouldAttachTracingHeaders(RequestInterface $request): bool
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client === null) {
            return false;
        }

        $sdkOptions = $client->getOptions();

        // Check if the request destination is allow listed in the trace_propagation_targets option.
        return $sdkOptions->getTracePropagationTargets() === null
            || in_array($request->getUri()->getHost(), $sdkOptions->getTracePropagationTargets(), true);
    }
}
