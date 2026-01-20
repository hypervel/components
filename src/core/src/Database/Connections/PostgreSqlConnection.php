<?php

declare(strict_types=1);

namespace Hypervel\Database\Connections;

use Hyperf\Database\PgSQL\PostgreSqlConnection as BasePostgreSqlConnection;
use Hyperf\Database\PgSQL\Query\Grammars\PostgresGrammar as BasePostgresGrammar;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use PDO;

class PostgreSqlConnection extends BasePostgreSqlConnection
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
        return $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Escape a boolean value for safe SQL embedding.
     */
    protected function escapeBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): BasePostgresGrammar
    {
        ($grammar = new PostgresGrammar())->setTablePrefix($this->tablePrefix);

        return $grammar;
    }
}
