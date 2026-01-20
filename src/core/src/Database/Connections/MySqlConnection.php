<?php

declare(strict_types=1);

namespace Hypervel\Database\Connections;

use Hyperf\Database\MySqlConnection as BaseMySqlConnection;
use Hyperf\Database\Query\Grammars\MySqlGrammar as BaseMySqlGrammar;
use Hyperf\Stringable\Str;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use PDO;

class MySqlConnection extends BaseMySqlConnection
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
     * Determine if the connected database is a MariaDB database.
     */
    public function isMaria(): bool
    {
        return str_contains($this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB');
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        $version = $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return str_contains($version, 'MariaDB')
            ? Str::between($version, '5.5.5-', '-MariaDB')
            : $version;
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): BaseMySqlGrammar
    {
        ($grammar = new MySqlGrammar())->setTablePrefix($this->tablePrefix);

        return $grammar;
    }
}
