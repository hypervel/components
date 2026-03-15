<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Exception;
use Hypervel\Context\Context;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events as DatabaseEvents;
use Hypervel\Routing\Events as RoutingEvents;
use Hypervel\Sentry\Integrations\Integration;
use Hypervel\Sentry\Traits\ResolvesEventOrigin;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Response;

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

    private const CONTEXT_PARENT_SPANS_KEY = '__sentry.tracing.parent_spans';

    public const CONTEXT_CURRENT_SPANS_KEY = '__sentry.tracing.current_spans';

    /**
     * Create a new tracing event handler instance.
     */
    public function __construct(
        private readonly bool $traceSqlQueries = true,
        private readonly bool $traceSqlBindings = true,
        private readonly bool $traceSqlQueryOrigin = true,
        private readonly int $traceSqlQueryOriginThresholdMs = 100,
    ) {
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(Dispatcher $dispatcher): void
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
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
        } catch (Exception) {
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
        if (! $this->traceSqlQueries) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $context = SpanContext::make()
            ->setOp('db.sql.query')
            ->setData([
                'db.name' => $query->connection->getDatabaseName(),
                'db.system' => $query->connection->getDriverName(),
                'server.address' => $query->connection->getConfig('host'),
                'server.port' => $query->connection->getConfig('port'),
            ])
            ->setOrigin('auto.db')
            ->setDescription($query->sql)
            ->setStartTimestamp(microtime(true) - $query->time / 1000);

        $context->setEndTimestamp($context->getStartTimestamp() + $query->time / 1000);

        if ($this->traceSqlBindings) {
            $context->setData(array_merge($context->getData(), [
                'db.sql.bindings' => $query->bindings,
            ]));
        }

        if ($this->traceSqlQueryOrigin && $query->time >= $this->traceSqlQueryOriginThresholdMs) {
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
        $span = $this->popSpan();

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

        $this->pushSpan(
            $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('http.route.response')
                    ->setOrigin('auto.http.server')
            )
        );
    }

    /**
     * Handle a database transaction beginning event.
     */
    protected function transactionBeginningHandler(DatabaseEvents\TransactionBeginning $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $this->pushSpan(
            $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('db.transaction')
                    ->setOrigin('auto.db')
            )
        );
    }

    /**
     * Handle a database transaction committed event.
     */
    protected function transactionCommittedHandler(DatabaseEvents\TransactionCommitted $event): void
    {
        $span = $this->popSpan();

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
        $span = $this->popSpan();

        if ($span !== null) {
            $span->setStatus(SpanStatus::internalError());
            $span->finish();
        }
    }

    /**
     * Push a span onto the coroutine-local stack and set it as current on the hub.
     */
    private function pushSpan(Span $span): void
    {
        $hub = SentrySdk::getCurrentHub();

        $parentStack = Context::get(self::CONTEXT_PARENT_SPANS_KEY, []);
        $parentStack[] = $hub->getSpan();
        Context::set(self::CONTEXT_PARENT_SPANS_KEY, $parentStack);

        $hub->setSpan($span);

        $currentStack = Context::get(self::CONTEXT_CURRENT_SPANS_KEY, []);
        $currentStack[] = $span;
        Context::set(self::CONTEXT_CURRENT_SPANS_KEY, $currentStack);
    }

    /**
     * Pop a span from the coroutine-local stack and restore the parent span.
     */
    private function popSpan(): ?Span
    {
        $currentStack = Context::get(self::CONTEXT_CURRENT_SPANS_KEY, []);

        if ($currentStack === []) {
            return null;
        }

        $parentStack = Context::get(self::CONTEXT_PARENT_SPANS_KEY, []);
        $parent = array_pop($parentStack);
        Context::set(self::CONTEXT_PARENT_SPANS_KEY, $parentStack);

        SentrySdk::getCurrentHub()->setSpan($parent);

        $span = array_pop($currentStack);
        Context::set(self::CONTEXT_CURRENT_SPANS_KEY, $currentStack);

        return $span;
    }
}
