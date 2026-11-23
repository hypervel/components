<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Concerns\ParsesSearchPath;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Override;

/**
 * @property PostgresGrammar $grammar
 */
class PostgresBuilder extends Builder
{
    use ParsesSearchPath;

    /**
     * Execute the given schema blueprint.
     */
    #[Override]
    public function executeBlueprint(Blueprint $blueprint): void
    {
        $statements = $blueprint->toSql();

        // CREATE INDEX CONCURRENTLY cannot run in a transaction block, so online lists stay unwrapped.
        if (count($statements) > 1
            && $this->connection->transactionLevel() === 0
            && $this->grammar->supportsSchemaTransactions()
            && $this->commandsAreDeclaredOn($blueprint, PostgresGrammar::class)
            && ! $this->hasOnlineCommand($blueprint)
        ) {
            $this->connection->transaction(
                fn () => $this->executeStatements($statements)
            );

            return;
        }

        $this->executeStatements($statements);
    }

    /**
     * Determine whether the blueprint contains an online command.
     */
    protected function hasOnlineCommand(Blueprint $blueprint): bool
    {
        foreach ($blueprint->getCommands() as $command) {
            if (! $command->shouldBeSkipped && $command->online) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given view exists.
     */
    #[Override]
    public function hasView(string $view): bool
    {
        [$schema, $view] = $this->parseSchemaAndTable($view, withDefaultSchema: true);

        // Without a current schema an unqualified view resolves to nothing, but getViews(null) would search every schema.
        if ($schema === null) {
            return false;
        }

        $view = $this->connection->getTablePrefix() . $view;

        foreach ($this->getViews($schema) as $value) {
            if (strtolower($view) === strtolower($value['name'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop all tables from the database.
     */
    #[Override]
    public function dropAllTables(): void
    {
        $schemas = $this->getCurrentSchemaListing();

        // An empty search path selects no schemas, but getTables([]) would select them all.
        if ($schemas === []) {
            return;
        }

        $tables = [];

        $excludedTables = $this->connection->getConfig('dont_drop') ?? ['spatial_ref_sys'];

        foreach ($this->withoutCoveredPartitions($this->getTables($schemas), $excludedTables) as $table) {
            if (empty(array_intersect([$table['name'], $table['schema_qualified_name']], $excludedTables))) {
                $tables[] = $table['schema_qualified_name'];
            }
        }

        if (empty($tables)) {
            return;
        }

        $this->executeStatements([
            $this->grammar->compileDropAllTables($tables),
        ]);
    }

    /**
     * Drop all views from the database.
     */
    #[Override]
    public function dropAllViews(): void
    {
        $schemas = $this->getCurrentSchemaListing();

        // An empty search path selects no schemas, but getViews([]) would select them all.
        if ($schemas === []) {
            return;
        }

        $views = array_column($this->getViews($schemas), 'schema_qualified_name');

        if (empty($views)) {
            return;
        }

        $this->executeStatements([
            $this->grammar->compileDropAllViews($views),
        ]);
    }

    /**
     * Drop all types from the database.
     */
    #[Override]
    public function dropAllTypes(): void
    {
        $schemas = $this->getCurrentSchemaListing();

        // An empty search path selects no schemas, but getTypes([]) would select them all.
        if ($schemas === []) {
            return;
        }

        $types = [];
        $domains = [];

        foreach ($this->getTypes($schemas) as $type) {
            if (! $type['implicit']) {
                if ($type['type'] === 'domain') {
                    $domains[] = $type['schema_qualified_name'];
                } else {
                    $types[] = $type['schema_qualified_name'];
                }
            }
        }

        if (! empty($types)) {
            $this->executeStatements([
                $this->grammar->compileDropAllTypes($types),
            ]);
        }

        if (! empty($domains)) {
            $this->executeStatements([
                $this->grammar->compileDropAllDomains($domains),
            ]);
        }
    }

    /**
     * Get the current schemas for the connection.
     *
     * An explicitly empty search path returns an empty list.
     *
     * @return string[]
     */
    #[Override]
    public function getCurrentSchemaListing(): array
    {
        return array_map(
            fn ($schema) => $schema === '$user' ? $this->connection->getConfig('username') : $schema,
            // Match the connector's setting precedence; a truthiness check would ignore a schema
            // named "0" and replace an explicitly empty search path.
            $this->parseSearchPath(
                $this->connection->getConfig('search_path')
                    ?? $this->connection->getConfig('schema')
                    ?? 'public'
            )
        );
    }

    /**
     * Get the default schema name for the connection.
     */
    #[Override]
    public function getCurrentSchemaName(): ?string
    {
        // PostgreSQL skips search-path entries that do not exist, so configuration alone
        // cannot determine which schema an unqualified database object resolves against.
        /** @var ?string $schema */
        $schema = $this->connection->scalar('select current_schema()', [], false);

        return $schema;
    }
}
