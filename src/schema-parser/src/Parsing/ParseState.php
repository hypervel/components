<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Domain;
use Hypervel\SchemaParser\EnumType;
use Hypervel\SchemaParser\Index;
use Hypervel\SchemaParser\Issue;
use Hypervel\SchemaParser\TableReference;
use Hypervel\SchemaParser\View;

/**
 * The definitions, identity lookups and issues of one parse.
 *
 * Definitions are keyed by schema and name without joining them with a dot,
 * which quoted names can contain.
 *
 * @internal
 */
final class ParseState
{
    /** @var array<string, TableDefinition> */
    public array $tables = [];

    /** @var array<array-key, array<string, true>> table keys by name */
    public array $tableNames = [];

    /** @var array<string, View> */
    public array $views = [];

    /** @var array<array-key, array<string, true>> view keys by name */
    public array $viewNames = [];

    /** @var array<string, EnumType> */
    public array $enums = [];

    /** @var array<string, Domain> domains with their own facts, resolved when the schema is frozen */
    public array $domains = [];

    /** @var array<array-key, array<string, true>> domain keys by name */
    public array $domainNames = [];

    /** @var array<string, array<string, true>> inheriting and partition tables by parent key */
    public array $children = [];

    /** @var array<string, array<string, true>> keys of the stored tables with each schema-qualified index name */
    private array $indexTables = [];

    /** @var array<string, list<string>> the schema-qualified index names recorded for each stored table */
    private array $indexNames = [];

    /** @var array<string, ColumnType> types by their declared SQL text */
    public array $types = [];

    /** @var list<Issue> */
    public array $issues = [];

    /** The schema of unqualified relation names, selected by a MySQL USE statement. */
    public ?string $defaultSchema = null;

    /** @var array<string, array{bool, bool}> MySQL SQL modes saved in user variables, as backslash-escape and ANSI-quote flags */
    public array $savedSqlModes = [];

    /** The start of the statement being parsed. */
    public int $offset = 0;

    private int $lineOffset = 0;

    private int $line = 1;

    /**
     * Create the state for parsing the given SQL.
     */
    public function __construct(public readonly string $sql)
    {
    }

    /**
     * Create an issue located at the current statement.
     */
    public function issue(string $message): Issue
    {
        $this->line += substr_count($this->sql, "\n", $this->lineOffset, $this->offset - $this->lineOffset);
        $this->lineOffset = $this->offset;

        return new Issue($message, $this->line);
    }

    /**
     * Get the identity key of a schema-qualified name.
     */
    public static function key(?string $schema, string $name): string
    {
        return ($schema === null ? 'n:' : 's:' . strlen($schema) . ':' . $schema) . $name;
    }

    /**
     * Add or replace a table definition and return its key.
     */
    public function addTable(TableDefinition $table): string
    {
        $key = self::key($table->schema, $table->name);
        $this->tables[$key] = $table;
        $this->tableNames[$table->name][$key] = true;
        $this->refreshIndexNames($key);

        return $key;
    }

    /**
     * Remove a table definition, unlinking it from its inheritance and partition parents.
     */
    public function removeTable(string $key): void
    {
        $table = $this->tables[$key];

        foreach ($table->partitionOf === null ? $table->parents : [...$table->parents, $table->partitionOf] as $parent) {
            $parentKey = $this->tableKey($parent);

            if ($parentKey !== null) {
                unset($this->children[$parentKey][$key]);
            }
        }

        unset($this->tableNames[$table->name][$key], $this->tables[$key], $this->children[$key]);
        $this->refreshIndexNames($key);
    }

    /**
     * Add an index to a stored table.
     */
    public function addIndex(string $key, Index $index): void
    {
        $table = $this->tables[$key];
        $table->indexes[] = $index;

        if ($index->name !== null) {
            $name = self::key($table->schema, $index->name);
            $this->indexTables[$name][$key] = true;
            $this->indexNames[$key][] = $name;
        }
    }

    /**
     * Record the index names of a stored table after its indexes change.
     */
    public function refreshIndexNames(string $key): void
    {
        $table = $this->tables[$key] ?? null;
        $names = [];

        if ($table !== null) {
            $prefix = self::key($table->schema, '');

            foreach ($table->indexes as $index) {
                if ($index->name !== null) {
                    $names[] = $prefix . $index->name;
                }
            }
        }

        $previous = $this->indexNames[$key] ?? [];

        if ($names === $previous) {
            return;
        }

        foreach ($previous as $name) {
            unset($this->indexTables[$name][$key]);

            if ($this->indexTables[$name] === []) {
                unset($this->indexTables[$name]);
            }
        }

        foreach ($names as $name) {
            $this->indexTables[$name][$key] = true;
        }

        $this->indexNames[$key] = $names;
    }

    /**
     * Determine whether a stored table has an index with the given schema-qualified name.
     */
    public function hasIndex(?string $schema, string $name): bool
    {
        return isset($this->indexTables[self::key($schema, $name)]);
    }

    /**
     * Find a table by exact identity or a unique unqualified name.
     */
    public function tableKey(TableReference $table): ?string
    {
        return $this->find($this->tables, $this->tableNames, $table->schema, $table->name);
    }

    /**
     * Add or replace a view.
     */
    public function addView(View $view): void
    {
        $key = self::key($view->schema, $view->name);
        $this->views[$key] = $view;
        $this->viewNames[$view->name][$key] = true;
    }

    /**
     * Remove a view.
     */
    public function removeView(string $key): void
    {
        unset($this->viewNames[$this->views[$key]->name][$key], $this->views[$key]);
    }

    /**
     * Find a view by exact identity or a unique unqualified name.
     */
    public function viewKey(TableReference $view): ?string
    {
        return $this->find($this->views, $this->viewNames, $view->schema, $view->name);
    }

    /**
     * Find a domain by exact identity or a unique unqualified name.
     */
    public function domainKey(?string $schema, string $name): ?string
    {
        return $this->find($this->domains, $this->domainNames, $schema, $name);
    }

    /**
     * Determine whether any schema object has been read.
     */
    public function hasDefinitions(): bool
    {
        return $this->tables !== [] || $this->views !== [] || $this->enums !== [] || $this->domains !== [];
    }

    /**
     * Resolve an exact identity, or an unqualified name only when it is unique.
     *
     * @param array<string, mixed> $definitions
     * @param array<array-key, array<string, true>> $names
     */
    private function find(array $definitions, array $names, ?string $schema, string $name): ?string
    {
        if ($schema !== null) {
            $key = self::key($schema, $name);

            return isset($definitions[$key]) ? $key : null;
        }

        $matches = $names[$name] ?? [];

        return count($matches) === 1 ? array_key_first($matches) : null;
    }
}
