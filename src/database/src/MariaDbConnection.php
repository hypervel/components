<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\Query\Grammars\MariaDbGrammar;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar as MariaDbSchemaGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Database\Schema\MariaDbSchemaState;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;

class MariaDbConnection extends MySqlConnection
{
    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'MariaDB';
    }

    /**
     * Determine if the connected database is a MariaDB database.
     */
    public function isMaria(): bool
    {
        return true;
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        return Str::between(parent::getServerVersion(), '5.5.5-', '-MariaDB');
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): MariaDbGrammar
    {
        return new MariaDbGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): MariaDbBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MariaDbBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): MariaDbSchemaGrammar
    {
        return new MariaDbSchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection.
     */
    #[\Override]
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): MariaDbSchemaState
    {
        return new MariaDbSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): MariaDbProcessor
    {
        return new MariaDbProcessor;
    }
}
