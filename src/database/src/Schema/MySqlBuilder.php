<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

/**
 * @property \Hypervel\Database\Schema\Grammars\MySqlGrammar $grammar
 */
class MySqlBuilder extends Builder
{
    /**
     * Drop all tables from the database.
     */
    #[\Override]
    public function dropAllTables(): void
    {
        $tables = $this->getTableListing($this->getCurrentSchemaListing());

        if (empty($tables)) {
            return;
        }

        $this->disableForeignKeyConstraints();

        try {
            $this->connection->statement(
                $this->grammar->compileDropAllTables($tables)
            );
        } finally {
            $this->enableForeignKeyConstraints();
        }
    }

    /**
     * Drop all views from the database.
     */
    #[\Override]
    public function dropAllViews(): void
    {
        $views = array_column($this->getViews($this->getCurrentSchemaListing()), 'schema_qualified_name');

        if (empty($views)) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileDropAllViews($views)
        );
    }

    /**
     * Get the names of current schemas for the connection.
     */
    #[\Override]
    public function getCurrentSchemaListing(): array
    {
        return [$this->connection->getDatabaseName()];
    }
}
