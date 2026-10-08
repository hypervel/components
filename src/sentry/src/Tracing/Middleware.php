<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Sentry\Http\SensitiveCookieFilter;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpSpanDataCollector;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\continueTrace;

/**
 * @internal
 */
class Middleware
{
    /**
     * The current active transaction.
     */
    protected ?Transaction $transaction = null;

    /**
     * The span for the `app.handle` part of the application.
     */
    protected ?Span $appSpan = null;

    /**
     * Whether a defined route was matched in the application.
     */
    private bool $didRouteMatch = false;

    /**
     * Create a new tracing middleware instance.
     */
    public function __construct(
        private readonly bool $continueAfterResponse = true,
        private readonly bool $traceMissingRoutes = false,
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound(HubInterface::class)) {
            $this->startTransaction($request);
        }

        return $next($request);
    }

    /**
     * Perform cleanup after the response has been sent.
     *
     * When after-response tracing is enabled, a Coroutine::defer() registered
     * in startTransaction() finishes the transaction after deferred work.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->transaction === null) {
            return;
        }

        if ($this->shouldRouteBeIgnored()) {
            $this->discardTransaction();

            return;
        }

        if ($this->appSpan !== null) {
            $this->appSpan->finish();
            $this->appSpan = null;
        }

        $this->transaction->setData($this->collectRequestData());

        $this->hydrateResponseData($response);

        if (! $this->continueAfterResponse) {
            $this->finishTransaction();
        }
    }

    /**
     * Signal that a route was matched for the current request.
     */
    public static function signalRouteWasMatched(): void
    {
        if (! app()->bound(self::class)) {
            return;
        }

        app(self::class)->internalSignalRouteWasMatched();
    }

    /**
     * Finish the current transaction.
     */
    public function finishTransaction(): void
    {
        if ($this->transaction === null) {
            return;
        }

        // Make sure we set the transaction and not have a child span in the Sentry SDK.
        // If the transaction is not on the scope during finish, the trace.context is wrong.
        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        $this->transaction->finish();
        $this->transaction = null;
    }

    /**
     * Start the transaction for the incoming request.
     */
    private function startTransaction(Request $request): void
    {
        $hub = SentrySdk::getCurrentHub();
        $client = $hub->getClient();

        // Prevent starting a new transaction if we are already in a transaction
        if ($hub->getTransaction() !== null) {
            return;
        }

        $context = continueTrace(
            $request->header('sentry-trace', ''),
            $request->header('baggage', '')
        );

        // OTLP external propagation relies on the scope not already carrying a Sentry span,
        // but continueTrace() still needs to run so incoming distributed trace headers can
        // populate the scope propagation context while tracing is disabled.
        if ($client === null || ! $client->getOptions()->isTracingEnabled()) {
            return;
        }

        $requestPath = '/' . ltrim($request->path(), '/');

        $context->setOp('http.server');
        $context->setName($requestPath);
        $context->setOrigin('auto.http.server');
        $context->setSource(TransactionSource::url());
        $context->setStartTimestamp(
            $request->startedAt()->getPreciseTimestamp(6) / 1_000_000
        );

        $context->setData([
            'url' => $requestPath,
            'http.request.method' => strtoupper($request->method()),
        ]);

        $transaction = $hub->startTransaction($context);

        $hub->setSpan($transaction);

        // If this transaction is not sampled, we can stop here to prevent doing work for nothing
        if (! $transaction->getSampled()) {
            return;
        }

        $this->transaction = $transaction;

        if ($this->continueAfterResponse) {
            // This runs before the outer runtime context ends and after later
            // after-response work because coroutine defers are LIFO.
            Coroutine::defer(function (): void {
                $this->finishTransaction();
            });
        }

        // Upstream's `app.bootstrap` span is not ported: workers boot once before serving
        // requests, so no request includes application bootstrap.
        $this->appSpan = $this->transaction->startChild(
            SpanContext::make()
                ->setOp('middleware.handle')
                ->setOrigin('auto.http.server')
        );

        $hub->setSpan($this->appSpan);
    }

    /**
     * Discard the current transaction.
     */
    private function discardTransaction(): void
    {
        $this->appSpan = null;
        $this->transaction = null;
        $this->didRouteMatch = false;

        SentrySdk::getCurrentHub()->setSpan(null);
    }

    /**
     * Collect the request data for the transaction.
     *
     * @return array<string, mixed>
     */
    private function collectRequestData(): array
    {
        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());

        // The legacy options only collected the request data on the event
        if ($policy->isLegacyMode()) {
            return [];
        }

        $request = (new HypervelRequestFetcher)->fetchRequest();
        if ($request === null) {
            return [];
        }

        return HttpSpanDataCollector::collectServerRequest($policy, $request);
    }

    /**
     * Hydrate the transaction with response data.
     */
    private function hydrateResponseData(Response $response): void
    {
        $this->transaction->setHttpStatus($response->getStatusCode());
        $this->transaction->setData([
            'http.response.status_code' => $response->getStatusCode(),
        ]);

        $this->transaction->setData($this->collectResponseData($response));
    }

    /**
     * Collect the response data for the transaction.
     *
     * @return array<string, mixed>
     */
    private function collectResponseData(Response $response): array
    {
        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());

        // The legacy options never collected any response data
        if ($policy->isLegacyMode()) {
            return [];
        }

        $type = HttpMessageType::outgoingResponse();

        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[] = [$cookie->getName(), SensitiveCookieFilter::filterValue($cookie->getName(), $cookie->getValue())];
        }

        // Streamed and file responses have no content (`false`), which the collector skips
        return HttpSpanDataCollector::collectHeaders($policy, $type, $response->headers->all())
            + HttpSpanDataCollector::collectCookiePairs($policy, $type, $cookies)
            + HttpSpanDataCollector::collectBody($policy, $type, $response->getContent(), (string) $response->headers->get('Content-Type', ''));
    }

    /**
     * Mark that a route was matched.
     */
    private function internalSignalRouteWasMatched(): void
    {
        $this->didRouteMatch = true;
    }

    /**
     * Determine if the route should be ignored and the transaction discarded.
     */
    private function shouldRouteBeIgnored(): bool
    {
        return ! $this->didRouteMatch && ! $this->traceMissingRoutes;
    }
}
