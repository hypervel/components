<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Concerns\ParsesSearchPath;

/**
 * @property \Hypervel\Database\Schema\Grammars\PostgresGrammar $grammar
 */
class PostgresBuilder extends Builder
{
    use ParsesSearchPath;

    /**
     * Drop all tables from the database.
     */
    #[\Override]
    public function dropAllTables(): void
    {
        $tables = [];

        $excludedTables = $this->connection->getConfig('dont_drop') ?? ['spatial_ref_sys'];

        foreach ($this->getTables($this->getCurrentSchemaListing()) as $table) {
            if (empty(array_intersect([$table['name'], $table['schema_qualified_name']], $excludedTables))) {
                $tables[] = $table['schema_qualified_name'];
            }
        }

        if (empty($tables)) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileDropAllTables($tables)
        );
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
     * Drop all types from the database.
     */
    #[\Override]
    public function dropAllTypes(): void
    {
        $types = [];
        $domains = [];

        foreach ($this->getTypes($this->getCurrentSchemaListing()) as $type) {
            if (! $type['implicit']) {
                if ($type['type'] === 'domain') {
                    $domains[] = $type['schema_qualified_name'];
                } else {
                    $types[] = $type['schema_qualified_name'];
                }
            }
        }

        if (! empty($types)) {
            $this->connection->statement($this->grammar->compileDropAllTypes($types));
        }

        if (! empty($domains)) {
            $this->connection->statement($this->grammar->compileDropAllDomains($domains));
        }
    }

    /**
     * Get the current schemas for the connection.
     */
    #[\Override]
    public function getCurrentSchemaListing(): array
    {
        return array_map(
            fn ($schema) => $schema === '$user' ? $this->connection->getConfig('username') : $schema,
            $this->parseSearchPath(
                $this->connection->getConfig('search_path')
                    ?: $this->connection->getConfig('schema')
                    ?: 'public'
            )
        );
    }
}
