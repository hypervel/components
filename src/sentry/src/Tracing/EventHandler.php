<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Events as DatabaseEvents;
use Hypervel\Routing\Events as RoutingEvents;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Hypervel\Sentry\Integration;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EventHandler
{
    use ResolvesEventOrigin;

    /**
     * Map event handlers to events.
     *
     * @var array<class-string, string>
     */
    protected static array $eventHandlerMap = [
        RoutingEvents\RouteMatched::class => 'routeMatched',
        DatabaseEvents\QueryExecuted::class => 'queryExecuted',
        RoutingEvents\ResponsePrepared::class => 'responsePrepared',
        RoutingEvents\PreparingResponse::class => 'responsePreparing',
        DatabaseEvents\TransactionBeginning::class => 'transactionBeginning',
        DatabaseEvents\TransactionCommitted::class => 'transactionCommitted',
        DatabaseEvents\TransactionRolledBack::class => 'transactionRolledBack',
    ];

    private const CONTEXT_RESPONSE_SPANS_KEY = '__sentry.tracing.response_spans';

    public const CONTEXT_TRANSACTION_SPANS_KEY = '__sentry.tracing.transaction_spans';

    private const CONTEXT_CLEANUP_REGISTERED_KEY = '__sentry.tracing.cleanup_registered';

    private readonly bool $traceSqlQueries;

    private readonly bool $traceSqlBindings;

    private readonly bool $traceSqlQueryOrigin;

    private readonly int $traceSqlQueryOriginThresholdMs;

    /**
     * Create a new tracing event handler instance.
     */
    public function __construct(array $config)
    {
        $this->traceSqlQueries = ($config['sql_queries'] ?? true) === true;
        $this->traceSqlBindings = ($config['sql_bindings'] ?? false) === true;
        $this->traceSqlQueryOrigin = ($config['sql_origin'] ?? true) === true;
        $this->traceSqlQueryOriginThresholdMs = $config['sql_origin_threshold_ms'] ?? 100;
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(Dispatcher $dispatcher): void
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
            if ($eventName === DatabaseEvents\QueryExecuted::class && ! $this->traceSqlQueries) {
                continue;
            }

            $dispatcher->listen($eventName, [$this, $handler]);
        }
    }

    /**
     * Pass through the event and capture any errors.
     */
    public function __call(string $method, array $arguments): void
    {
        $handlerMethod = "{$method}Handler";

        if (! method_exists($this, $handlerMethod)) {
            throw new RuntimeException("Missing tracing event handler: {$handlerMethod}");
        }

        try {
            $this->{$handlerMethod}(...$arguments);
        } catch (Throwable) {
            // Ignore to prevent bubbling up errors in the SDK
        }
    }

    /**
     * Handle a route matched event.
     */
    protected function routeMatchedHandler(RoutingEvents\RouteMatched $match): void
    {
        $transaction = SentrySdk::getCurrentHub()->getTransaction();

        if ($transaction === null) {
            return;
        }

        [$transactionName, $transactionSource] = Integration::extractNameAndSourceForRoute($match->route);

        $transaction->setName($transactionName);
        $transaction->getMetadata()->setSource($transactionSource);
    }

    /**
     * Handle a query executed event.
     */
    protected function queryExecutedHandler(DatabaseEvents\QueryExecuted $query): void
    {
        $transactionSpans = CoroutineContext::get(self::CONTEXT_TRANSACTION_SPANS_KEY, []);
        $connectionSpans = $transactionSpans[spl_object_id($query->connection)] ?? [];
        $parentSpan = $connectionSpans === []
            ? SentrySdk::getCurrentHub()->getSpan()
            : end($connectionSpans);

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $now = microtime(true);
        $context = SpanContext::make()
            ->setOp('db.sql.query')
            ->setData([
                'db.name' => $query->connection->getDatabaseName(),
                'db.system' => $query->connection->getDriverName(),
                'server.address' => $query->connection->getConfig('host'),
                'server.port' => $query->connection->getConfig('port'),
            ])
            ->setOrigin('auto.db')
            ->setDescription($query->sql);

        if ($query->time === null) {
            $context
                ->setStartTimestamp($now)
                ->setEndTimestamp($now);
        } else {
            $context
                ->setStartTimestamp($now - $query->time / 1000)
                ->setEndTimestamp($now);
        }

        if ($this->traceSqlBindings) {
            $context->setData(array_merge($context->getData(), [
                'db.sql.bindings' => $query->bindings,
            ]));
        }

        if ($this->traceSqlQueryOrigin
            && $query->time !== null
            && $query->time >= $this->traceSqlQueryOriginThresholdMs) {
            $queryOrigin = $this->resolveEventOrigin();

            if ($queryOrigin !== null) {
                $context->setData(array_merge($context->getData(), $queryOrigin));
            }
        }

        $parentSpan->startChild($context);
    }

    /**
     * Handle a response prepared event.
     */
    protected function responsePreparedHandler(RoutingEvents\ResponsePrepared $event): void
    {
        $span = $this->popResponseSpan();

        if ($span !== null) {
            $span->finish();
        }
    }

    /**
     * Handle a response preparing event.
     */
    protected function responsePreparingHandler(RoutingEvents\PreparingResponse $event): void
    {
        // If the response is already a Response object there is no need to handle the event
        // since there isn't going to be any real work going on, the response is already as
        // prepared as it can be. We ignore it to prevent logging a very short duplicated span.
        if ($event->response instanceof Response) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $this->pushResponseSpan(
            $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('http.route.response')
                    ->setOrigin('auto.http.server')
            ),
            $parentSpan,
        );
    }

    /**
     * Handle a database transaction beginning event.
     */
    protected function transactionBeginningHandler(DatabaseEvents\TransactionBeginning $event): void
    {
        $connectionId = spl_object_id($event->connection);
        $transactionSpans = CoroutineContext::get(self::CONTEXT_TRANSACTION_SPANS_KEY, []);
        $connectionSpans = $transactionSpans[$connectionId] ?? [];
        $parentSpan = $connectionSpans === []
            ? SentrySdk::getCurrentHub()->getSpan()
            : end($connectionSpans);

        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $connectionSpans[] = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('db.transaction')
                ->setOrigin('auto.db')
        );
        $transactionSpans[$connectionId] = $connectionSpans;
        CoroutineContext::set(self::CONTEXT_TRANSACTION_SPANS_KEY, $transactionSpans);
        $this->registerCleanup();
    }

    /**
     * Handle a database transaction committed event.
     */
    protected function transactionCommittedHandler(DatabaseEvents\TransactionCommitted $event): void
    {
        $span = $this->popTransactionSpan($event);

        if ($span !== null) {
            $span->setStatus(SpanStatus::ok());
            $span->finish();
        }
    }

    /**
     * Handle a database transaction rolled back event.
     */
    protected function transactionRolledBackHandler(DatabaseEvents\TransactionRolledBack $event): void
    {
        $span = $this->popTransactionSpan($event);

        if ($span !== null) {
            $span->setStatus(SpanStatus::internalError());
            $span->finish();
        }
    }

    /**
     * Push a response span and install it as current on the Hub.
     */
    private function pushResponseSpan(Span $span, Span $parent): void
    {
        $responseSpans = CoroutineContext::get(self::CONTEXT_RESPONSE_SPANS_KEY, []);
        $responseSpans[] = ['span' => $span, 'parent' => $parent];
        CoroutineContext::set(self::CONTEXT_RESPONSE_SPANS_KEY, $responseSpans);
        SentrySdk::getCurrentHub()->setSpan($span);
        $this->registerCleanup();
    }

    /**
     * Pop a response span and restore its parent on the Hub.
     */
    private function popResponseSpan(): ?Span
    {
        $responseSpans = CoroutineContext::get(self::CONTEXT_RESPONSE_SPANS_KEY, []);

        if ($responseSpans === []) {
            return null;
        }

        $entry = array_pop($responseSpans);
        CoroutineContext::set(self::CONTEXT_RESPONSE_SPANS_KEY, $responseSpans);
        SentrySdk::getCurrentHub()->setSpan($entry['parent']);

        return $entry['span'];
    }

    /**
     * Pop the current transaction span for an exact connection.
     */
    private function popTransactionSpan(
        DatabaseEvents\TransactionCommitted|DatabaseEvents\TransactionRolledBack $event
    ): ?Span {
        $transactionSpans = CoroutineContext::get(self::CONTEXT_TRANSACTION_SPANS_KEY, []);
        $connectionId = spl_object_id($event->connection);
        $connectionSpans = $transactionSpans[$connectionId] ?? [];

        if ($connectionSpans === []) {
            return null;
        }

        $span = array_pop($connectionSpans);

        if ($connectionSpans === []) {
            unset($transactionSpans[$connectionId]);
        } else {
            $transactionSpans[$connectionId] = $connectionSpans;
        }

        CoroutineContext::set(self::CONTEXT_TRANSACTION_SPANS_KEY, $transactionSpans);

        return $span;
    }

    /**
     * Register cleanup for response and transaction spans without terminals.
     */
    private function registerCleanup(): void
    {
        if (CoroutineContext::get(self::CONTEXT_CLEANUP_REGISTERED_KEY, false)) {
            return;
        }

        CoroutineContext::set(self::CONTEXT_CLEANUP_REGISTERED_KEY, true);
        Coroutine::defer(static function (): void {
            $hub = SentrySdk::getCurrentHub();
            $responseSpans = CoroutineContext::get(self::CONTEXT_RESPONSE_SPANS_KEY, []);

            while (($entry = array_pop($responseSpans)) !== null) {
                $hub->setSpan($entry['parent']);
                self::finishAbandonedSpan($entry['span']);
            }

            CoroutineContext::forget(self::CONTEXT_RESPONSE_SPANS_KEY);

            $transactionSpans = CoroutineContext::get(self::CONTEXT_TRANSACTION_SPANS_KEY, []);

            foreach ($transactionSpans as $connectionSpans) {
                while (($span = array_pop($connectionSpans)) !== null) {
                    self::finishAbandonedSpan($span);
                }
            }

            CoroutineContext::forget(self::CONTEXT_TRANSACTION_SPANS_KEY);
            CoroutineContext::forget(self::CONTEXT_CLEANUP_REGISTERED_KEY);
        });
    }

    /**
     * Finish an abandoned span as an internal failure.
     */
    private static function finishAbandonedSpan(Span $span): void
    {
        if ($span->getEndTimestamp() !== null) {
            return;
        }

        $span->setStatus(SpanStatus::internalError());
        $span->finish();
    }
}
