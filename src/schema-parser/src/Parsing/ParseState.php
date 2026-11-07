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

    /** @var array<string, array<string, true>> the parent keys of each child, kept because the parents' names may stop resolving */
    private array $parentKeys = [];

    /** @var null|array<string, array<string, array<string, true>>> keys of the stored tables with each index name, by the name's identity and then its schema-qualified key, once a lookup needs them */
    private ?array $indexTables = null;

    /** @var array<string, array<string, string>> the identities of the index names recorded for each stored table, by their schema-qualified keys */
    private array $indexNames = [];

    /** @var array<string, ColumnType> types by their declared SQL text */
    public array $types = [];

    /** @var list<Issue> */
    public array $issues = [];

    /** The schema of unqualified relation names: SQLite's main database, or the one a MySQL USE statement selects. */
    public ?string $defaultSchema = null;

    /** @var array<string, array{bool, bool}> MySQL SQL modes saved in user variables, as backslash-escape and ANSI-quote flags */
    public array $savedSqlModes = [];

    /** The start of the statement being parsed. */
    public int $offset = 0;

    private int $lineOffset = 0;

    private int $line = 1;

    /**
     * Create the state for parsing the given SQL.
     *
     * @param bool $caseInsensitive whether schema, relation and index names compare without ASCII case, as SQLite's do
     */
    public function __construct(
        public readonly string $sql,
        private readonly bool $caseInsensitive = false,
    ) {
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
     * Get the identity of a name, without its ASCII case where names compare case-insensitively.
     */
    public function normalize(string $name): string
    {
        return $this->caseInsensitive ? strtolower($name) : $name;
    }

    /**
     * Get the identity key of a schema-qualified name.
     */
    public function key(?string $schema, string $name): string
    {
        if ($this->caseInsensitive) {
            $schema = $schema === null ? null : strtolower($schema);
            $name = strtolower($name);
        }

        return ($schema === null ? 'n:' : 's:' . strlen($schema) . ':' . $schema) . $name;
    }

    /**
     * Add or replace a table definition and return its key.
     */
    public function addTable(TableDefinition $table): string
    {
        $key = $this->key($table->schema, $table->name);
        $this->tables[$key] = $table;
        $this->tableNames[$this->normalize($table->name)][$key] = true;
        $this->refreshIndexNames($key);

        return $key;
    }

    /**
     * Remove a table definition and its inheritance and partition links.
     */
    public function removeTable(string $key): void
    {
        foreach ($this->parentKeys[$key] ?? [] as $parentKey => $present) {
            unset($this->children[$parentKey][$key]);
        }

        foreach ($this->children[$key] ?? [] as $childKey => $present) {
            unset($this->parentKeys[$childKey][$key]);
        }

        unset(
            $this->tableNames[$this->normalize($this->tables[$key]->name)][$key],
            $this->tables[$key],
            $this->children[$key],
            $this->parentKeys[$key],
        );
        $this->refreshIndexNames($key);
    }

    /**
     * Record that a stored table inherits from, or is a partition of, another stored table.
     */
    public function linkChild(string $parentKey, string $childKey): void
    {
        $this->children[$parentKey][$childKey] = true;
        $this->parentKeys[$childKey][$parentKey] = true;
    }

    /**
     * Add an index to a stored table.
     */
    public function addIndex(string $key, Index $index): void
    {
        $table = $this->tables[$key];
        $table->indexes[] = $index;

        if ($index->name !== null && $this->indexTables !== null) {
            $identity = $this->normalize($index->name);
            $qualified = $this->key($table->schema, '') . $identity;
            $this->indexTables[$identity][$qualified][$key] = true;
            $this->indexNames[$key][$qualified] = $identity;
        }
    }

    /**
     * Remove a stored table's index with the given name.
     */
    public function removeIndex(string $key, string $name): void
    {
        $table = $this->tables[$key];
        $name = $this->normalize($name);

        foreach ($table->indexes as $position => $index) {
            if ($index->name !== null && $this->normalize($index->name) === $name) {
                array_splice($table->indexes, $position, 1);
                $this->refreshIndexNames($key);

                return;
            }
        }
    }

    /**
     * Record the index names of a stored table after its indexes change.
     */
    public function refreshIndexNames(string $key): void
    {
        if ($this->indexTables === null) {
            return;
        }

        $table = $this->tables[$key] ?? null;
        $names = [];

        if ($table !== null) {
            $prefix = $this->key($table->schema, '');

            foreach ($table->indexes as $index) {
                if ($index->name !== null) {
                    $identity = $this->normalize($index->name);
                    $names[$prefix . $identity] = $identity;
                }
            }
        }

        $previous = $this->indexNames[$key] ?? [];

        if ($names === $previous) {
            return;
        }

        foreach ($previous as $qualified => $identity) {
            unset($this->indexTables[$identity][$qualified][$key]);

            if ($this->indexTables[$identity][$qualified] === []) {
                unset($this->indexTables[$identity][$qualified]);
            }

            if ($this->indexTables[$identity] === []) {
                unset($this->indexTables[$identity]);
            }
        }

        foreach ($names as $qualified => $identity) {
            $this->indexTables[$identity][$qualified][$key] = true;
        }

        $this->indexNames[$key] = $names;
    }

    /**
     * Determine whether a stored table has an index with the given schema-qualified name.
     */
    public function hasIndex(?string $schema, string $name): bool
    {
        return isset($this->indexTables()[$this->normalize($name)][$this->key($schema, $name)]);
    }

    /**
     * Find the stored table with the given schema-qualified index name, when only one has it.
     */
    public function indexTableKey(?string $schema, string $name): ?string
    {
        $keys = $this->indexTables()[$this->normalize($name)][$this->key($schema, $name)] ?? [];

        return count($keys) === 1 ? array_key_first($keys) : null;
    }

    /**
     * Get the keys of the stored tables with an index of the given name, in any schema.
     *
     * @return list<string>
     */
    public function indexTableKeys(string $name): array
    {
        $keys = [];

        foreach ($this->indexTables()[$this->normalize($name)] ?? [] as $tables) {
            $keys += $tables;
        }

        return array_keys($keys);
    }

    /**
     * Get the index name lookup, recording the stored tables' indexes when it is first needed.
     *
     * Most dumps never look an index up by name, so the lookup is not maintained until one does.
     *
     * @return array<string, array<string, array<string, true>>>
     */
    private function indexTables(): array
    {
        if ($this->indexTables === null) {
            $this->indexTables = [];

            foreach (array_keys($this->tables) as $key) {
                $this->refreshIndexNames($key);
            }
        }

        return $this->indexTables;
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
        $key = $this->key($view->schema, $view->name);
        $this->views[$key] = $view;
        $this->viewNames[$this->normalize($view->name)][$key] = true;
    }

    /**
     * Remove a view.
     */
    public function removeView(string $key): void
    {
        unset($this->viewNames[$this->normalize($this->views[$key]->name)][$key], $this->views[$key]);
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
            $key = $this->key($schema, $name);

            return isset($definitions[$key]) ? $key : null;
        }

        $matches = $names[$this->normalize($name)] ?? [];

        return count($matches) === 1 ? array_key_first($matches) : null;
    }
}
