<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use RuntimeException;

class QueryWatcher extends Watcher
{
    use FetchesStackTrace;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $app->make(Dispatcher::class)
            ->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    /**
     * Record a query was executed.
     */
    public function recordQuery(QueryExecuted $event): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        $time = $event->time;

        if ($caller = $this->getCallerFromStackTrace()) {
            Telescope::recordQuery(IncomingEntry::make([
                'connection' => $event->connectionName,
                'driver' => $event->connection->getDriverName(),
                'bindings' => [],
                'sql' => $this->replaceBindings($event),
                'time' => number_format($time, 2, '.', ''),
                'slow' => isset($this->options['slow']) && $time >= $this->options['slow'],
                'file' => $caller['file'],
                'line' => $caller['line'],
                'hash' => $this->familyHash($event),
            ])->tags($this->tags($event)));
        }
    }

    /**
     * Get the tags for the query.
     */
    protected function tags(QueryExecuted $event): array
    {
        return isset($this->options['slow']) && $event->time >= $this->options['slow'] ? ['slow'] : [];
    }

    /**
     * Calculate the family look-up hash for the query event.
     */
    public function familyHash(QueryExecuted $event): string
    {
        return hash('xxh128', $event->sql);
    }

    /**
     * Format the given bindings to strings.
     */
    protected function formatBindings(QueryExecuted $event): array
    {
        return $event->connection->prepareBindings($event->bindings);
    }

    /**
     * Replace the placeholders with the actual bindings.
     */
    public function replaceBindings(QueryExecuted $event): string
    {
        $sql = $event->sql;

        foreach ($this->formatBindings($event) as $key => $binding) {
            $isPositional = is_numeric($key);
            $regex = $isPositional
                ? "/(?<!\\?)\\?(?!\\?)(?=(?:[^'\\\\']*'[^'\\\\']*')*[^'\\\\']*$)/"
                : '/(?<!:):' . preg_quote((string) $key, '/') . "(?![A-Za-z0-9_])(?=(?:[^'\\\\']*'[^'\\\\']*')*[^'\\\\']*$)/";

            if ($binding === null) {
                $binding = 'null';
            } elseif (! is_int($binding) && ! is_float($binding)) {
                $binding = $this->quoteStringBinding($event, $binding);
            }

            $sql = preg_replace_callback(
                $regex,
                static fn (): string => (string) $binding,
                $sql,
                $isPositional ? 1 : -1
            );
        }

        return $sql;
    }

    /**
     * Quote a non-numeric binding.
     *
     * @param BinaryParameter|resource|string $binding
     */
    protected function quoteStringBinding(QueryExecuted $event, mixed $binding): string
    {
        if (is_resource($binding) || gettype($binding) === 'resource (closed)') {
            $binding = (string) $binding;
        }

        try {
            return $event->connection->escape($binding);
        } catch (RuntimeException) {
            return '[REDACTED: UNESCAPABLE BINDING]';
        }
    }
}
