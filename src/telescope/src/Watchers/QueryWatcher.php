<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Database\Events\QueryExecuted;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\Traits\FetchesStackTrace;
use PDO;
use PDOException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class QueryWatcher extends Watcher
{
    use FetchesStackTrace;

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
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
        return md5($event->sql);
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
            $regex = is_numeric($key)
                ? "/\\?(?=(?:[^'\\\\']*'[^'\\\\']*')*[^'\\\\']*$)/"
                : "/:{$key}(?=(?:[^'\\\\']*'[^'\\\\']*')*[^'\\\\']*$)/";

            if ($binding === null) {
                $binding = 'null';
            } elseif (! is_int($binding) && ! is_float($binding)) {
                $binding = $this->quoteStringBinding($event, $binding);
            }

            $sql = preg_replace(
                $regex,
                (string) $binding,
                $sql,
                is_numeric($key) ? 1 : -1
            );
        }

        return $sql;
    }

    /**
     * Add quotes to string bindings.
     */
    protected function quoteStringBinding(QueryExecuted $event, string $binding): string
    {
        try {
            $pdo = $event->connection->getPdo();

            if ($pdo instanceof PDO) {
                return $pdo->quote($binding);
            }
        } catch (PDOException $e) {
            throw_if($e->getCode() !== 'IM001', $e);
        }

        // Fallback when PDO::quote function is missing...
        $binding = \strtr($binding, [
            chr(26) => '\Z',
            chr(8) => '\b',
            '"' => '\"',
            "'" => "\\'",
            '\\' => '\\\\',
        ]);

        return "'" . $binding . "'";
    }
}
