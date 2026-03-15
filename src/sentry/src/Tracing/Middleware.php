<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Closure;
use Hypervel\Http\Request;
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
     * The timestamp of application bootstrap completion.
     *
     * Static because it's set once during worker boot (before any request
     * coroutine exists) and consumed by the first request. Scoped instances
     * created per-coroutine read this shared value.
     */
    private static ?float $bootedTimestamp = null;

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

        $this->hydrateResponseData($response);

        $this->finishTransaction();
    }

    /**
     * Set the timestamp of application bootstrap completion.
     *
     * @internal this method should only be invoked right after the application has finished "booting"
     */
    public static function setBootedTimestamp(?float $timestamp = null): void
    {
        self::$bootedTimestamp = $timestamp ?? microtime(true);
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

        // Prevent starting a new transaction if we are already in a transaction
        if ($hub->getTransaction() !== null) {
            return;
        }

        $requestStartTime = $request->server(
            'REQUEST_TIME_FLOAT',
            defined('HYPERVEL_START')
                ? HYPERVEL_START
                : microtime(true)
        );

        $context = continueTrace(
            $request->header('sentry-trace', ''),
            $request->header('baggage', '')
        );

        $requestPath = '/' . ltrim($request->path(), '/');

        $context->setOp('http.server');
        $context->setName($requestPath);
        $context->setOrigin('auto.http.server');
        $context->setSource(TransactionSource::url());
        $context->setStartTimestamp($requestStartTime);

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

        $bootstrapSpan = $this->addAppBootstrapSpan();

        $this->appSpan = $this->transaction->startChild(
            SpanContext::make()
                ->setOp('middleware.handle')
                ->setOrigin('auto.http.server')
                ->setStartTimestamp($bootstrapSpan ? $bootstrapSpan->getEndTimestamp() : microtime(true))
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
     * Add the application bootstrap span to the transaction.
     */
    private function addAppBootstrapSpan(): ?Span
    {
        if (self::$bootedTimestamp === null) {
            return null;
        }

        $span = $this->transaction->startChild(
            SpanContext::make()
                ->setOp('app.bootstrap')
                ->setOrigin('auto.http.server')
                ->setStartTimestamp($this->transaction->getStartTimestamp())
                ->setEndTimestamp(self::$bootedTimestamp)
        );

        $this->addBootDetailTimeSpans($span);

        // Consume the booted timestamp — only the first request after worker boot gets this span
        self::$bootedTimestamp = null;

        return $span;
    }

    /**
     * Add detail spans for the boot process.
     */
    private function addBootDetailTimeSpans(Span $bootstrap): void
    {
        // This constant should be defined right after the composer `autoload.php` require statement
        // define('SENTRY_AUTOLOAD', microtime(true));
        if (! defined('SENTRY_AUTOLOAD') || ! SENTRY_AUTOLOAD) {
            return;
        }

        $bootstrap->startChild(
            SpanContext::make()
                ->setOp('app.php.autoload')
                ->setOrigin('auto.http.server')
                ->setStartTimestamp($this->transaction->getStartTimestamp())
                ->setEndTimestamp(SENTRY_AUTOLOAD)
        );
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
        return ! $this->didRouteMatch && config('sentry.tracing.missing_routes', false) === false;
    }
}
