<?php

declare(strict_types=1);

namespace Hypervel\Database\Connections;

use Hyperf\Database\Query\Grammars\Grammar as BaseGrammar;
use Hyperf\Database\SQLite\SQLiteConnection as BaseSQLiteConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;

class SQLiteConnection extends BaseSQLiteConnection
{
    /**
     * Get a new query builder instance.
     */
    public function query(): Builder
    {
        return new Builder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        return (string) $this->getPdo()->query('SELECT sqlite_version()')->fetchColumn();
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): BaseGrammar
    {
        return $this->withTablePrefix(new SQLiteGrammar());
    }
}
