<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\Query\Grammars\MariaDbGrammar as QueryGrammar;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar as SchemaGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Database\Schema\MariaDbSchemaState;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;

class MariaDbConnection extends MySqlConnection
{
    /**
     * {@inheritdoc}
     */
    public function getDriverTitle()
    {
        return 'MariaDB';
    }

    /**
     * Determine if the connected database is a MariaDB database.
     *
     * @return bool
     */
    public function isMaria()
    {
        return true;
    }

    /**
     * Get the server version for the connection.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        return Str::between(parent::getServerVersion(), '5.5.5-', '-MariaDB');
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Hypervel\Database\Query\Grammars\MariaDbGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Hypervel\Database\Schema\MariaDbBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MariaDbBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Hypervel\Database\Schema\Grammars\MariaDbGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection.
     *
     * @param  \Hypervel\Filesystem\Filesystem|null  $files
     * @param  callable|null  $processFactory
     * @return \Hypervel\Database\Schema\MariaDbSchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new MariaDbSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Hypervel\Database\Query\Processors\MariaDbProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new MariaDbProcessor;
    }
}
