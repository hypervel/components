<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Override;

/**
 * @property \Hypervel\Database\Schema\Grammars\MySqlGrammar $grammar
 */
class MySqlBuilder extends Builder
{
    /**
     * Drop all tables from the database.
     */
    #[Override]
    public function dropAllTables(): void
    {
        $tables = $this->getTableListing($this->getCurrentSchemaListing());

        if (empty($tables)) {
            return;
        }

        $this->withoutForeignKeyConstraints(function () use ($tables): void {
            $this->executeStatements([
                $this->grammar->compileDropAllTables($tables),
            ]);
        });
    }

    /**
     * Drop all views from the database.
     */
    #[Override]
    public function dropAllViews(): void
    {
        $views = array_column($this->getViews($this->getCurrentSchemaListing()), 'schema_qualified_name');

        if (empty($views)) {
            return;
        }

        $this->executeStatements([
            $this->grammar->compileDropAllViews($views),
        ]);
    }

    /**
     * Determine whether foreign key constraints are enabled.
     */
    #[Override]
    protected function foreignKeyConstraintsAreEnabled(): bool
    {
        // Foreign-key checks are session state, so inspect the write PDO that schema changes use.
        return (bool) $this->connection->scalar('select @@foreign_key_checks', [], false);
    }

    /**
     * Get the names of current schemas for the connection.
     */
    #[Override]
    public function getCurrentSchemaListing(): array
    {
        return [$this->connection->getDatabaseName()];
    }
}
