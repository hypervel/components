<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Renderer;

use Hypervel\Context\Context;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events\QueryExecuted;

class Listener
{
    /**
     * The Context key for storing executed queries.
     */
    protected const QUERIES_CONTEXT_KEY = '__foundation.exception_renderer.queries';

    /**
     * The maximum number of queries to store.
     */
    protected const MAX_QUERIES = 101;

    /**
     * Register the appropriate listeners on the given event dispatcher.
     */
    public function registerListeners(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, $this->onQueryExecuted(...));
    }

    /**
     * Return the queries that have been executed.
     *
     * @return array<int, array{connectionName: string, time: float, sql: string, bindings: array}>
     */
    public function queries(): array
    {
        return Context::get(self::QUERIES_CONTEXT_KEY, []);
    }

    /**
     * Listen for the query executed event.
     */
    public function onQueryExecuted(QueryExecuted $event): void
    {
        $queries = Context::get(self::QUERIES_CONTEXT_KEY, []);

        if (count($queries) === self::MAX_QUERIES) {
            return;
        }

        $queries[] = [
            'connectionName' => $event->connectionName,
            'time' => $event->time,
            'sql' => $event->sql,
            'bindings' => $event->connection->prepareBindings($event->bindings),
        ];

        Context::set(self::QUERIES_CONTEXT_KEY, $queries);
    }
}
