<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\SchemaParser\Domain;
use Hypervel\SchemaParser\EnumType;
use Hypervel\SchemaParser\Table;

use function array_key_exists;
use function in_array;
use function is_string;
use function mb_strtolower;
use function strlen;
use function strtolower;

/**
 * The schema facts of the connection that migrations run on, which its pooled and
 * routed connections share.
 *
 * A table missing from an incomplete connection is unknown rather than absent.
 *
 * @internal
 */
class SchemaConnection
{
    /** @var array<string, SchemaTable> tables and views, by identity key */
    public array $tables = [];

    /** @var array<string, null|Domain|EnumType> PostgreSQL user types by identity key, null when the definition or its presence is uncertain */
    public array $types = [];

    /** Whether every relation is known, so that a missing table is a claim. */
    public bool $complete = true;

    /** @var list<array{path: string, line: int, message: string}> the schema dumps that could not be read */
    public array $unusableDumps = [];

    /**
     * Create a new schema connection instance.
     */
    public function __construct(public string $name, public string $driver)
    {
    }

    /**
     * Copy the tables, so that each branch of a migration changes its own copy.
     */
    public function __clone(): void
    {
        foreach ($this->tables as $key => $table) {
            $this->tables[$key] = clone $table;
        }
    }

    /**
     * Get the identity key of a schema-qualified name, which ignores ASCII case where SQLite does.
     */
    public function key(?string $schema, string $name): string
    {
        if ($this->driver === 'sqlite') {
            $schema = $schema === null ? null : strtolower($schema);
            $name = strtolower($name);
        }

        return ($schema === null ? 'n:' : 's:' . strlen($schema) . ':' . $schema) . $name;
    }

    /**
     * Find the identity key of a relation, or return null when it is absent and false when it is ambiguous.
     *
     * Unqualified MySQL and SQLite names use the connection's default schema. An unqualified PostgreSQL
     * name resolves through the search path. An unqualified definition was created in the first existing
     * schema of its connection's path, so it only matches the same path when no qualified candidate exists.
     *
     * @param list<string> $searchPath
     */
    public function findTableKey(?string $schema, string $name, ?string $defaultSchema, array $searchPath): false|string|null
    {
        if ($this->driver !== 'pgsql') {
            $key = $this->key($schema ?? $defaultSchema, $name);

            return isset($this->tables[$key]) ? $key : null;
        }

        $unqualifiedKey = $this->key(null, $name);
        $unqualified = $this->tables[$unqualifiedKey] ?? null;

        if ($schema !== null) {
            $key = $this->key($schema, $name);

            return $unqualified !== null ? false : (isset($this->tables[$key]) ? $key : null);
        }

        foreach ($searchPath as $searchSchema) {
            $key = $this->key($searchSchema, $name);

            if (isset($this->tables[$key])) {
                return $unqualified === null ? $key : false;
            }
        }

        if ($unqualified === null) {
            return null;
        }

        return $unqualified->searchPath === $searchPath ? $unqualifiedKey : false;
    }

    /**
     * Add a parsed table, linking the known relations it inherits from or partitions.
     *
     * @param bool $complete whether the input it comes from is free of schema-level issues
     */
    public function addTable(Table $definition, ConnectionConfig $connection, bool $complete): void
    {
        $table = new SchemaTable($definition->name, $definition->schema ?? $connection->definitionSchema());
        $table->searchPath = $table->schema === null ? $connection->searchPath : null;
        $table->complete = $complete && $definition->isComplete();

        foreach ($definition->columns as $column) {
            $table->setColumn(new SchemaColumn($column->name, $column->type, $column->nullable));
        }

        foreach ([...$definition->parents, ...($definition->partitionOf === null ? [] : [$definition->partitionOf])] as $parent) {
            $key = $this->findTableKey($parent->schema, $parent->name, $connection->defaultSchema, $connection->searchPath);

            if (is_string($key)) {
                $table->parents[] = $key;
            }
        }

        $this->tables[$this->key($table->schema, $table->name)] = $table;
    }

    /**
     * Get the identity keys of other relations whose schema and name differ only in letter case.
     *
     * MySQL compares database and table names without case on some servers, so a change to one
     * spelling can change another. Identities stay exact, since other servers keep them distinct.
     *
     * @return list<string>
     */
    public function caseVariants(?string $schema, string $name): array
    {
        if (! in_array($this->driver, ['mysql', 'mariadb'], true)) {
            return [];
        }

        $exact = $this->key($schema, $name);
        $folded = $this->key($schema === null ? null : mb_strtolower($schema), mb_strtolower($name));
        $variants = [];

        foreach ($this->tables as $key => $table) {
            if ($key !== $exact && $this->key($table->schema === null ? null : mb_strtolower($table->schema), mb_strtolower($table->name)) === $folded) {
                $variants[] = $key;
            }
        }

        return $variants;
    }

    /**
     * Make a relation and its known inheritance and partition descendants unknown.
     *
     * The relation may no longer exist or have its earlier columns. PostgreSQL applies
     * ALTER TABLE without ONLY, and the drop of a partitioned table, to descendants too.
     */
    public function forgetTable(?string $schema, string $name): void
    {
        $key = $this->key($schema, $name);
        $table = $this->tables[$key] ??= new SchemaTable($name, $schema);
        $table->columns = [];
        $table->complete = false;

        $this->forgetDescendants($key);
    }

    /**
     * Make the known inheritance and partition descendants of a relation unknown.
     */
    public function forgetDescendants(string $key): void
    {
        $forgotten = [$key];

        for ($index = 0; isset($forgotten[$index]); ++$index) {
            foreach ($this->tables as $childKey => $child) {
                if (in_array($forgotten[$index], $child->parents, true) && ! in_array($childKey, $forgotten, true)) {
                    $child->columns = [];
                    $child->complete = false;
                    $forgotten[] = $childKey;
                }
            }
        }
    }

    /**
     * Remove a relation that no longer exists, making its descendants unknown.
     */
    public function dropTable(string $key): void
    {
        $this->forgetDescendants($key);

        unset($this->tables[$key]);
    }

    /**
     * Make a user type uncertain after a script altered or dropped it.
     *
     * A qualified name is marked even when nothing recorded it, which stops any search along
     * a path through its schema, so a definition recorded without a schema stays known: it was
     * created in a path schema. An unqualified name can denote any recorded definition along
     * the search path.
     *
     * @param list<string> $searchPath
     */
    public function forgetType(?string $schema, string $name, array $searchPath): void
    {
        if ($schema !== null) {
            $this->types[$this->key($schema, $name)] = null;

            return;
        }

        foreach ([null, ...$searchPath] as $candidate) {
            $key = $this->key($candidate, $name);

            if (array_key_exists($key, $this->types)) {
                $this->types[$key] = null;
            }
        }
    }

    /**
     * Make every fact unknown.
     */
    public function forget(): void
    {
        $this->tables = [];
        $this->types = [];
        $this->complete = false;
    }
}
