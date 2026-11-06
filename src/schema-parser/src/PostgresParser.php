<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Contracts\Parser;
use Hypervel\SchemaParser\Postgres\ParseState;
use Hypervel\SchemaParser\Postgres\SqlScanner;
use Hypervel\SchemaParser\Postgres\StatementSplitter;
use sad_spirit\pg_builder\exceptions\SyntaxException;
use sad_spirit\pg_builder\Lexer;
use sad_spirit\pg_builder\TokenType;

use function array_slice;
use function array_values;
use function count;
use function in_array;
use function preg_match;
use function rtrim;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * Read the schema of a PostgreSQL database out of plain-text pg_dump output.
 *
 * The grammar accepted here is deliberately the subset pg_dump emits, not all of
 * PostgreSQL DDL. pg_dump is a code generator, so that subset is small, regular
 * and stable across versions.
 *
 * Statements unrelated to represented schema facts are skipped. Mutable
 * definitions and type caches belong to one parse, never to this service.
 *
 * @phpstan-import-type ColumnDefinition from ParseState
 * @phpstan-import-type TableDefinition from ParseState
 * @phpstan-import-type DomainDefinition from ParseState
 */
class PostgresParser implements Parser
{
    /** A quoted or bare identifier. */
    private const string IDENT = '(?:"(?:[^"]|"")++"|[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*)';

    /** Keywords that end the type of a column and begin its constraints. */
    private const array COLUMN_CONSTRAINTS = [
        'COLLATE',
        'DEFAULT',
        'GENERATED',
        'NOT',
        'NULL',
        'CONSTRAINT',
        'CHECK',
        'PRIMARY',
        'UNIQUE',
        'REFERENCES',
        'DEFERRABLE',
        'STORAGE',
        'COMPRESSION',
        'OPTIONS',
    ];

    /** Entries in a CREATE TABLE body that describe the table rather than a column. */
    private const array TABLE_CONSTRAINTS = [
        'CONSTRAINT',
        'PRIMARY',
        'UNIQUE',
        'FOREIGN',
        'CHECK',
        'NOT',
        'EXCLUDE',
        'LIKE',
    ];

    /**
     * Parse a plain-text PostgreSQL schema dump.
     */
    public function parse(string $sql): Schema
    {
        if (str_starts_with($sql, 'PGDMP') || str_contains($sql, "\0")) {
            throw new ParseException('PostgreSQL archives must be exported as plain SQL before parsing.');
        }
        $state = new ParseState($sql);

        foreach ((new StatementSplitter)->split($sql) as $part) {
            $statement = $part['sql'];
            $state->offset = $part['offset'];
            if ($this->hasUnresolvedIdentityChange($statement, $state)) {
                continue;
            }
            if (preg_match('/^SET\s+standard_conforming_strings\s*=\s*(on|off)$/iD', $statement, $matches)) {
                $standard = strcasecmp($matches[1], 'on') === 0;
                if ($standard !== $state->standardConformingStrings) {
                    $state->standardConformingStrings = $standard;
                    $state->literalLexer = new Lexer(['standard_conforming_strings' => $standard]);
                }
                continue;
            }

            $table = $this->readCreateTable($statement, $state);

            if ($table !== null) {
                // A dump that drops and recreates a table leaves the last
                // definition standing, which is what replaying it would give.
                $key = $this->identityKey($table['schema'], $table['name']);
                $state->tables[$key] = $table;
                $state->tableNames[$table['name']][$key] = true;
                foreach ($table['parents'] as $parent) {
                    $parentKey = $this->tableKey($parent, $state);
                    if ($parentKey !== null) {
                        $state->children[$parentKey][$key] = true;
                    }
                }
                if ($table['partitionOf'] !== null) {
                    $parentKey = $this->tableKey($table['partitionOf'], $state);
                    if ($parentKey !== null) {
                        $state->children[$parentKey][$key] = true;
                    }
                }
                continue;
            }

            $enum = $this->readCreateEnum($statement, $state);

            if ($enum !== null) {
                $state->enums[$this->identityKey($enum->schema, $enum->name)] = $enum;
                continue;
            }

            $domain = $this->readCreateDomain($statement, $state);

            if ($domain !== null) {
                $key = $this->identityKey($domain['schema'], $domain['name']);
                $state->domains[$key] = $domain;
                $state->domainNames[$domain['name']][$key] = true;
                continue;
            }

            if ($this->applyAlterTable($statement, $state)
                || $this->applyAlterDomain($statement, $state)
                || $this->readCreateIndex($statement, $state)
                || $this->applyComment($statement, $state)
                || $this->applyDrop($statement, $state)) {
                continue;
            }

            $view = $this->readCreateView($statement, $state);
            if ($view !== null) {
                $key = $this->identityKey($view->schema, $view->name);
                $state->views[$key] = $view;
                $state->viewNames[$view->name][$key] = true;
                continue;
            }
        }

        return $this->freeze($state);
    }

    /**
     * Freeze definitions after defaults, constraints and type dependencies are resolved.
     */
    private function freeze(ParseState $state): Schema
    {
        $resolving = [];
        foreach ($state->domains as $key => $domain) {
            $this->resolveDomain($key, $state, $resolving);
        }

        $tables = [];
        foreach ($state->tables as $definition) {
            $columns = [];
            foreach ($definition['columns'] as $column) {
                $type = $column['type'];
                $domainKey = $this->domainKey($type, $state);
                $domain = $domainKey === null ? null : $state->resolvedDomains[$domainKey];
                if ($type->schema === null && count($state->domainNames[$type->name] ?? []) > 1) {
                    $definition['issues'][] = new Issue("The domain of column [{$column['name']}] is ambiguous without a schema.");
                }
                if ($domain !== null) {
                    $type = new ColumnType($type->name, $type->schema, $type->modifiers, $type->dimensions, base: $domain->type);
                }
                $scalarDomain = $type->dimensions === 0 ? $domain : null;
                $default = $column['default'] ?? $scalarDomain?->default;
                // Domain NOT NULL checks conversion, not already-typed NULLs stored in columns.
                // https://www.postgresql.org/docs/current/sql-createdomain.html#SQL-CREATEDOMAIN-NOTES
                $nullable = $column['nullable'];
                if ($nullable && $column['unvalidatedNotNull']) {
                    $definition['issues'][] = new Issue("The NOT NULL constraint on column [{$column['name']}] has not been validated.");
                }
                $columns[] = new Column(
                    $column['name'],
                    $type,
                    nullable: $nullable,
                    default: $default,
                    autoIncrement: $column['autoIncrement'] || $column['identity'] !== null
                        || ($default !== null && preg_match('/^(?:pg_catalog\.)?nextval\s*\(/i', $default) === 1),
                    identity: $column['identity'],
                    generation: $column['generation'],
                    collation: $column['collation'],
                    comment: $column['comment'],
                );
            }

            foreach ($definition['foreignKeys'] as $position => $foreignKey) {
                if ($foreignKey->foreignColumns !== []) {
                    continue;
                }
                $targetKey = $this->tableKey($foreignKey->foreignTable, $state);
                $primary = $targetKey === null ? null : array_find(
                    $state->tables[$targetKey]['indexes'],
                    static fn (Index $index): bool => $index->primary,
                );
                if ($primary === null) {
                    $definition['issues'][] = new Issue("The referenced columns of foreign table [{$foreignKey->foreignTable->name}] could not be resolved.");
                    continue;
                }
                $definition['foreignKeys'][$position] = new ForeignKey(
                    $foreignKey->name,
                    $foreignKey->columns,
                    $foreignKey->foreignTable,
                    array_column($primary->columns, 'name'),
                    $foreignKey->onUpdate,
                    $foreignKey->onDelete,
                    $foreignKey->match,
                    $foreignKey->deferral,
                    $foreignKey->valid,
                    $foreignKey->enforced,
                );
            }

            $tables[] = new Table(
                $definition['name'],
                $columns,
                $definition['schema'],
                $definition['indexes'],
                $definition['foreignKeys'],
                $definition['checks'],
                comment: $definition['comment'],
                parents: $definition['parents'],
                partitionOf: $definition['partitionOf'],
                partitionBounds: $definition['partitionBounds'],
                issues: $definition['issues'],
            );
        }

        return new Schema($tables, array_values($state->views), array_values($state->enums), array_values($state->resolvedDomains), $state->issues);
    }

    /**
     * Resolve a domain chain once, detecting actual dependency cycles.
     *
     * @param array<string, true> $resolving
     */
    private function resolveDomain(string $key, ParseState $state, array &$resolving): Domain
    {
        if (isset($state->resolvedDomains[$key])) {
            return $state->resolvedDomains[$key];
        }
        $definition = $state->domains[$key];
        if (isset($resolving[$key])) {
            throw new ParseException("The domain [{$definition['name']}] has a cyclic base type.");
        }
        $resolving[$key] = true;
        $type = $definition['type'];
        $parentKey = $this->domainKey($type, $state);
        $parent = $parentKey === null ? null : $this->resolveDomain($parentKey, $state, $resolving);
        if ($type->schema === null && count($state->domainNames[$type->name] ?? []) > 1) {
            $state->issues[] = new Issue("The base type of domain [{$definition['name']}] is ambiguous without a schema.");
        }
        if ($parent !== null) {
            $type = new ColumnType($type->name, $type->schema, $type->modifiers, $type->dimensions, base: $parent->type);
        }
        $scalarParent = $type->dimensions === 0 ? $parent : null;
        unset($resolving[$key]);

        return $state->resolvedDomains[$key] = new Domain(
            $definition['name'],
            $type,
            $definition['schema'],
            $definition['nullable'] && ($scalarParent->nullable ?? true),
            $definition['default'] ?? $scalarParent?->default,
            array_merge($scalarParent->checks ?? [], $definition['checks']),
        );
    }

    /**
     * Find an exact domain or an unambiguous unqualified definition.
     */
    private function domainKey(ColumnType $type, ParseState $state): ?string
    {
        if ($type->schema !== null) {
            $key = $this->identityKey($type->schema, $type->name);
            return isset($state->domains[$key]) ? $key : null;
        }
        $matches = $state->domainNames[$type->name] ?? [];

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * Find an exact table or an unambiguous unqualified definition.
     */
    private function tableKey(TableReference $table, ParseState $state): ?string
    {
        if ($table->schema !== null) {
            $key = $this->identityKey($table->schema, $table->name);
            return isset($state->tables[$key]) ? $key : null;
        }
        $matches = $state->tableNames[$table->name] ?? [];

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * Find an exact view or an unambiguous unqualified definition.
     */
    private function viewKey(TableReference $view, ParseState $state): ?string
    {
        if ($view->schema !== null) {
            $key = $this->identityKey($view->schema, $view->name);
            return isset($state->views[$key]) ? $key : null;
        }
        $matches = $state->viewNames[$view->name] ?? [];

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * Index an identity without conflating unspecified schemas or quoted dots.
     */
    private function identityKey(?string $schema, string $name): string
    {
        return ($schema === null ? 'n:' : 's:' . strlen($schema) . ':' . $schema) . $name;
    }

    /**
     * Flag identity and enum-label changes without replaying their dependent declarations.
     */
    private function hasUnresolvedIdentityChange(string $statement, ParseState $state): bool
    {
        if (! preg_match('/^ALTER\b/i', $statement)
            || ! preg_match('/\b(?:RENAME|SCHEMA|VALUE)\b/i', $statement)) {
            return false;
        }
        $words = $this->topLevelWords($statement);
        $enumChange = preg_match('/^ALTER\s+TYPE\b/i', $statement) === 1;
        foreach ($words as $index => [$word, $offset]) {
            $next = $words[$index + 1] ?? null;
            if ($next === null) {
                break;
            }
            $identity = ($word === 'RENAME' && $next[0] === 'TO') || ($word === 'SET' && $next[0] === 'SCHEMA');
            $labels = $enumChange && in_array($word, ['ADD', 'RENAME'], true) && $next[0] === 'VALUE';
            if (! $identity && ! $labels) {
                continue;
            }
            $tail = substr($statement, $next[1] + strlen($next[0]));
            if ($identity && ! preg_match('/^\s+' . self::IDENT . '\s*$/D', $tail)) {
                continue;
            }
            if ($state->tables !== [] || $state->views !== [] || $state->enums !== [] || $state->domains !== []) {
                $state->issues[] = $this->issue('An ALTER statement changes identities or enum labels that cannot be resolved across dependent definitions.', $state);
            }
            return true;
        }

        return false;
    }

    /**
     * Record unresolved effects on other definitions when a drop cascades.
     */
    private function reportCascade(ParseState $state): void
    {
        if ($state->tables !== [] || $state->views !== [] || $state->enums !== [] || $state->domains !== []) {
            $state->issues[] = $this->issue('Cascading drops can change dependent definitions that are not replayed.', $state);
        }
    }

    /**
     * Read local columns before merging any inherited table definitions.
     *
     * @return null|TableDefinition
     */
    private function readCreateTable(string $statement, ParseState $state): ?array
    {
        $prefix = 'CREATE\s+(?:GLOBAL\s+|LOCAL\s+)?(?:(?<persistence>TEMPORARY|TEMP|UNLOGGED)\s+)?(?<foreign>FOREIGN\s+)?TABLE\b';
        if (preg_match('/^' . $prefix . '/i', $statement) !== 1) {
            return null;
        }
        $pattern = '/^' . $prefix . '\s+(?<conditional>IF\s+NOT\s+EXISTS\s+)?'
            . '(?:(?<schema>' . self::IDENT . ')\s*\.\s*)?(?<table>' . self::IDENT . ')(?<tail>.*)$/isD';

        if (preg_match($pattern, $statement, $matches) !== 1) {
            throw $this->error('Expected a table name', $state);
        }
        $schema = $matches['schema'] === '' ? null : SqlScanner::identifier($matches['schema']);
        $name = SqlScanner::identifier($matches['table']);
        if (in_array(strtoupper($matches['persistence']), ['TEMP', 'TEMPORARY'], true) || $schema === 'pg_temp') {
            return null;
        }
        $key = $this->identityKey($schema, $name);
        if ($matches['conditional'] !== '' && isset($state->tables[$key])) {
            return $state->tables[$key];
        }
        $table = $this->emptyTable($name, $schema);
        $tail = trim($matches['tail']);
        if ($tail === '') {
            throw $this->error('Expected a table definition', $state);
        }
        $localColumns = [];
        $constraints = [];

        if (str_starts_with($tail, '(')) {
            $body = SqlScanner::balanced($tail, 0);
            if ($body === null) {
                throw $this->error('Unterminated table definition', $state);
            }
            foreach (SqlScanner::splitTopLevel($body) as $entry) {
                $words = $this->topLevelWords($entry);
                $first = $words[0][0] ?? '';
                if (($words[0][1] ?? null) === 0 && in_array($first, self::TABLE_CONSTRAINTS, true)
                    && ($first !== 'EXCLUDE' || preg_match('/^EXCLUDE\s*(?:USING\b|\()/i', $entry))) {
                    $constraints[] = $entry;
                    continue;
                }
                $column = $this->readColumn($entry, $words, $state, $table);
                $localColumns[$column['name']] = $column;
            }
            $tail = trim(substr($tail, strlen($body) + 2));
        } elseif (preg_match('/^PARTITION\s+OF\s+/i', $tail, $partition)) {
            $position = strlen($partition[0]);
            $parent = $this->readReference($tail, $position, $state);
            $table['partitionOf'] = $parent;
            $position += strspn($tail, " \t\r\n", $position);
            if (($tail[$position] ?? '') === '(') {
                $this->readGroup($tail, $position, $state);
            }
            $table['partitionBounds'] = $this->readPartitionBounds($tail, $position, $state);
            $parentKey = $this->tableKey($parent, $state);
            if ($parentKey !== null) {
                $table['columns'] = $state->tables[$parentKey]['columns'];
            }
            $table['issues'][] = $this->issue('The partition declaration does not include its complete column and constraint definitions.', $state);
            return $table;
        } else {
            $table['issues'][] = $this->issue('The table declaration does not contain a complete column definition.', $state);
            return $table;
        }

        if (preg_match('/^INHERITS\s*\(/i', $tail, $inheritance)) {
            $open = strlen($inheritance[0]) - 1;
            $parents = SqlScanner::balanced($tail, $open);
            if ($parents === null) {
                throw $this->error('Unterminated inheritance list', $state);
            }
            $conflicts = [];
            foreach (SqlScanner::splitTopLevel($parents) as $parentSql) {
                $position = 0;
                $parent = $this->readReference($parentSql, $position, $state);
                $table['parents'][] = $parent;
                $parentKey = $this->tableKey($parent, $state);
                if ($parentKey === null) {
                    $table['issues'][] = $this->issue("Inherited table [{$parent->name}] could not be resolved.", $state);
                    continue;
                }
                $parentTable = $state->tables[$parentKey];
                if ($parentTable['issues'] !== []) {
                    $table['issues'][] = $this->issue("Inherited table [{$parent->name}] has incomplete definitions.", $state);
                }
                foreach ($parentTable['columns'] as $columnName => $column) {
                    // Identity and comments belong to the parent; serial defaults and generated expressions inherit.
                    if ($column['identity'] !== null) {
                        $column['autoIncrement'] = false;
                    }
                    $column['identity'] = null;
                    $column['comment'] = null;
                    if ($column['noInheritNotNull']) {
                        $column['nullable'] = true;
                    } elseif ($column['unvalidatedNotNull']) {
                        $column['nullable'] = false;
                    }
                    $column['noInheritNotNull'] = false;
                    $column['unvalidatedNotNull'] = false;
                    if (isset($table['columns'][$columnName])) {
                        $inherited = &$table['columns'][$columnName];
                        $inherited['nullable'] = $inherited['nullable'] && $column['nullable'];
                        if ($inherited['default'] !== null && $column['default'] !== null && $inherited['default'] !== $column['default']) {
                            $conflicts[$columnName]['default'] = true;
                        }
                        $inherited['default'] ??= $column['default'];
                        if ($inherited['generation'] !== null && $column['generation'] !== null
                            && ($inherited['generation']->type !== $column['generation']->type || $inherited['generation']->expression !== $column['generation']->expression)) {
                            $conflicts[$columnName]['generation'] = true;
                        }
                        $inherited['generation'] ??= $column['generation'];
                        unset($inherited);
                    } else {
                        $table['columns'][$columnName] = $column;
                    }
                }
                foreach ($parentTable['checks'] as $check) {
                    if ($check->noInherit) {
                        continue;
                    }
                    // Creating a child validates its enforced checks; later ALTER propagation does not.
                    $this->mergeCheck(new CheckConstraint($check->name, $check->expression, $check->enforced, $check->enforced), $table, $state);
                }
            }
            foreach ($conflicts as $columnName => $conflict) {
                if (isset($conflict['default']) && ($localColumns[$columnName]['default'] ?? null) === null) {
                    $table['issues'][] = $this->issue("Inherited defaults for [{$columnName}] conflict without a local default.", $state);
                }
                if (isset($conflict['generation']) && ($localColumns[$columnName]['generation'] ?? null) === null) {
                    $table['issues'][] = $this->issue("Inherited generated expressions for [{$columnName}] conflict without a local expression.", $state);
                }
            }
            $tail = trim(substr($tail, $open + strlen($parents) + 2));
        }

        foreach ($localColumns as $columnName => $column) {
            if (isset($table['columns'][$columnName])) {
                $inherited = $table['columns'][$columnName];
                $column['nullable'] = $column['nullable'] && $inherited['nullable'];
                $column['default'] ??= $inherited['default'];
                $column['generation'] ??= $inherited['generation'];
                $column['collation'] ??= $inherited['collation'];
            }
            $table['columns'][$columnName] = $column;
        }
        foreach ($constraints as $entry) {
            $position = $this->readConstraint($entry, $table, $state);
            if (trim(substr($entry, $position)) !== '') {
                $table['issues'][] = $this->issue('The table constraint has unsupported trailing syntax.', $state);
            }
        }
        foreach ($table['indexes'] as $index) {
            if ($index->primary) {
                foreach ($index->columns as $column) {
                    if ($column->name !== null && isset($table['columns'][$column->name])) {
                        $table['columns'][$column->name]['nullable'] = false;
                    }
                }
            }
        }
        if ($matches['foreign'] !== '' && preg_match('/^SERVER\s+/i', $tail, $server)) {
            $position = strlen($server[0]);
            $this->readIdentifier($tail, $position, $state);
            if (preg_match('/\G\s+OPTIONS\s*/i', $tail, $options, 0, $position)) {
                $position += strlen($options[0]);
                $this->readGroup($tail, $position, $state);
            }
            $tail = trim(substr($tail, $position));
        }
        if ($tail !== '' && ! preg_match('/^(?:PARTITION\s+BY\b|USING\b|WITH\s*\(|WITHOUT\s+OIDS\b|TABLESPACE\b|ON\s+COMMIT\b)/i', $tail)) {
            $table['issues'][] = $this->issue('The table has unsupported trailing declaration syntax.', $state);
        }

        return $table;
    }

    /**
     * Create a mutable table definition for this parsing operation.
     *
     * @return TableDefinition
     */
    private function emptyTable(string $name, ?string $schema): array
    {
        return [
            'name' => $name, 'schema' => $schema, 'columns' => [], 'indexes' => [],
            'foreignKeys' => [], 'checks' => [], 'comment' => null, 'parents' => [],
            'partitionOf' => null, 'partitionBounds' => null, 'issues' => [],
        ];
    }

    /**
     * Read a column using the declaration's existing top-level word scan.
     *
     * @param list<array{string, int}> $words
     * @param TableDefinition $table
     * @return ColumnDefinition
     */
    private function readColumn(string $entry, array $words, ParseState $state, array &$table): array
    {
        $pattern = '/^(?<name>' . self::IDENT . ')\s+(?<rest>.+)$/isA';

        if (preg_match($pattern, $entry, $matches) !== 1) {
            throw $this->error('Expected a column name and type', $state);
        }

        $rest = trim($matches['rest']);
        $restOffset = strlen($entry) - strlen($rest);
        $words = array_values(array_filter($words, static fn (array $word): bool => $word[1] >= $restOffset));
        foreach ($words as &$word) {
            $word[1] -= $restOffset;
        }
        unset($word);

        $typeEnd = strlen($rest);

        foreach ($words as [$word, $offset]) {
            if (! in_array($word, self::COLUMN_CONSTRAINTS, true)) {
                continue;
            }

            $typeEnd = $offset;
            break;
        }

        $type = trim(rtrim(trim(substr($rest, 0, $typeEnd)), ','));

        if ($type === '') {
            throw $this->error('Expected a column type', $state);
        }

        $column = [
            'name' => SqlScanner::identifier($matches['name']),
            'type' => $state->types->resolve($type),
            'nullable' => true, 'noInheritNotNull' => false, 'unvalidatedNotNull' => false,
            'default' => null, 'autoIncrement' => false,
            'identity' => null, 'generation' => null, 'collation' => null, 'comment' => null,
        ];
        $serial = match (strtolower($type)) {
            'smallserial', 'serial2' => 'int2',
            'serial', 'serial4' => 'int4',
            'bigserial', 'serial8' => 'int8',
            default => null,
        };
        if ($serial !== null) {
            $column['type'] = new ColumnType($serial, 'pg_catalog');
            $column['nullable'] = false;
            $column['autoIncrement'] = true;
        }
        $this->readColumnOptions($rest, $words, $typeEnd, $column, $table, $state);

        return $column;
    }

    /**
     * Apply column clauses without rescanning the declaration's top-level words.
     *
     * @param list<array{string, int}> $words
     * @param ColumnDefinition $column
     * @param TableDefinition $table
     */
    private function readColumnOptions(string $sql, array $words, int $position, array &$column, array &$table, ParseState $state): void
    {
        $name = null;
        $length = strlen($sql);
        while ($position < $length) {
            $position += strspn($sql, " \t\r\n", $position);
            if ($position === $length) {
                break;
            }
            if (preg_match('/\GCONSTRAINT\s+/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $name = $this->readIdentifier($sql, $position, $state);
            } elseif (preg_match('/\GDEFAULT\s+/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $column['default'] = $this->readDefault($sql, $words, $position, $state);
                $name = null;
            } elseif (preg_match('/\GNOT\s+NULL\b/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $flags = $this->readConstraintFlags($sql, $position);
                if ($flags['valid']) {
                    $column['nullable'] = false;
                } else {
                    $column['unvalidatedNotNull'] = true;
                }
                $column['noInheritNotNull'] = $flags['noInherit'];
                $name = null;
            } elseif (preg_match('/\GNULL\b/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $name = null;
            } elseif (preg_match('/\GGENERATED\s+(ALWAYS|BY\s+DEFAULT)\s+AS\s*/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                if (preg_match('/\GIDENTITY\b/i', $sql, $identity, 0, $position)) {
                    $position += strlen($identity[0]);
                    $column['identity'] = strcasecmp($matches[1], 'ALWAYS') === 0 ? Identity::Always : Identity::ByDefault;
                    $column['nullable'] = false;
                    $position += strspn($sql, " \t\r\n", $position);
                    if (($sql[$position] ?? '') === '(') {
                        $this->readGroup($sql, $position, $state);
                    }
                } else {
                    $expression = $this->readGroup($sql, $position, $state);
                    $kind = GenerationType::Virtual;
                    if (preg_match('/\G\s*(STORED|VIRTUAL)\b/i', $sql, $generation, 0, $position)) {
                        $position += strlen($generation[0]);
                        $kind = GenerationType::from(strtolower($generation[1]));
                    }
                    $column['generation'] = new Generation($kind, $expression);
                }
                $name = null;
            } elseif (preg_match('/\GCOLLATE\s+/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $start = $position;
                $this->readReference($sql, $position, $state);
                $column['collation'] = substr($sql, $start, $position - $start);
            } elseif (preg_match('/\G(?:CHECK|PRIMARY|UNIQUE|REFERENCES)\b/i', $sql, $matches, 0, $position)) {
                if (strcasecmp($matches[0], 'PRIMARY') === 0) {
                    $column['nullable'] = false;
                }
                $position += $this->readConstraint(substr($sql, $position), $table, $state, $column['name'], $name);
                $name = null;
            } elseif (preg_match('/\G(?:STORAGE|COMPRESSION)\s+/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $this->readIdentifier($sql, $position, $state);
            } elseif (preg_match('/\GOPTIONS\s*/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $this->readGroup($sql, $position, $state);
            } else {
                $table['issues'][] = $this->issue("Column [{$column['name']}] has unsupported declaration syntax.", $state);
                break;
            }
        }
    }

    /**
     * Read a raw default through the next column or domain clause.
     *
     * @param list<array{0: string, 1: int}> $words
     */
    private function readDefault(string $sql, array $words, int &$position, ParseState $state): string
    {
        $position += strspn($sql, " \t\r\n", $position);
        $start = $position;
        $end = strlen($sql);
        $caseDepth = 0;
        foreach ($words as [$word, $offset]) {
            if ($offset < $start) {
                continue;
            }
            if ($word === 'CASE') {
                ++$caseDepth;
            } elseif ($word === 'END' && $caseDepth > 0) {
                --$caseDepth;
            }
            if ($caseDepth > 0 || $offset === $start || ! in_array($word, self::COLUMN_CONSTRAINTS, true)) {
                continue;
            }
            $end = $offset;
            break;
        }
        $expression = trim(substr($sql, $start, $end - $start));
        if ($expression === '') {
            throw $this->error('Expected a default expression', $state);
        }
        $position = $end;

        return $expression;
    }

    /**
     * Read flags that follow a constraint's definition.
     *
     * @return array{deferral: ?Deferral, valid: bool, enforced: bool, noInherit: bool}
     */
    private function readConstraintFlags(string $sql, int &$position): array
    {
        $deferrable = false;
        $initially = Deferral::Immediate;
        $flags = ['deferral' => null, 'valid' => true, 'enforced' => true, 'noInherit' => false];
        while (preg_match('/\G\s*(NOT\s+DEFERRABLE|DEFERRABLE|INITIALLY\s+DEFERRED|INITIALLY\s+IMMEDIATE|NOT\s+VALID|NOT\s+ENFORCED|ENFORCED|NO\s+INHERIT)\b/i', $sql, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $flag = strtoupper(preg_replace('/\s+/', ' ', $matches[1]));
            switch ($flag) {
                case 'DEFERRABLE':
                    $deferrable = true;
                    break;
                case 'NOT DEFERRABLE':
                    $deferrable = false;
                    break;
                case 'INITIALLY DEFERRED':
                    $initially = Deferral::Deferred;
                    break;
                case 'INITIALLY IMMEDIATE':
                    $initially = Deferral::Immediate;
                    break;
                case 'NOT VALID':
                    $flags['valid'] = false;
                    break;
                case 'NOT ENFORCED':
                    $flags['enforced'] = false;
                    break;
                case 'ENFORCED':
                    $flags['enforced'] = true;
                    break;
                case 'NO INHERIT':
                    $flags['noInherit'] = true;
                    break;
            }
        }
        $flags['deferral'] = $deferrable || $initially === Deferral::Deferred ? $initially : null;
        $flags['valid'] = $flags['valid'] && $flags['enforced'];

        return $flags;
    }

    /**
     * Read an identifier without splitting quoted spaces or punctuation.
     */
    private function readIdentifier(string $sql, int &$position, ParseState $state): string
    {
        $position += strspn($sql, " \t\r\n", $position);
        if (preg_match('/\G' . self::IDENT . '/', $sql, $matches, 0, $position) !== 1) {
            throw $this->error('Expected a SQL identifier', $state);
        }
        $position += strlen($matches[0]);

        return SqlScanner::identifier($matches[0]);
    }

    /**
     * Read an optionally schema-qualified object reference.
     */
    private function readReference(string $sql, int &$position, ParseState $state): TableReference
    {
        $name = $this->readIdentifier($sql, $position, $state);
        $next = $position + strspn($sql, " \t\r\n", $position);
        if (($sql[$next] ?? '') !== '.') {
            return new TableReference($name);
        }
        $position = $next + 1;

        return new TableReference($this->readIdentifier($sql, $position, $state), $name);
    }

    /**
     * Read a complete parenthesized group with its original SQL text.
     */
    private function readGroup(string $sql, int &$position, ParseState $state): string
    {
        $position += strspn($sql, " \t\r\n", $position);
        if (($sql[$position] ?? '') !== '(') {
            throw $this->error('Expected a parenthesized expression', $state);
        }
        $body = SqlScanner::balanced($sql, $position);
        if ($body === null) {
            throw $this->error('Unterminated parenthesized expression', $state);
        }
        $group = substr($sql, $position, strlen($body) + 2);
        $position += strlen($group);

        return $group;
    }

    /**
     * Create an issue located at its owning statement.
     */
    private function issue(string $message, ParseState $state): Issue
    {
        $state->diagnosticLine += substr_count($state->sql, "\n", $state->diagnosticOffset, $state->offset - $state->diagnosticOffset);
        $state->diagnosticOffset = $state->offset;

        return new Issue($message, $state->diagnosticLine);
    }

    /**
     * Create a parse error located at its owning statement.
     */
    private function error(string $message, ParseState $state, ?SyntaxException $previous = null): ParseException
    {
        return new ParseException($message, substr_count($state->sql, "\n", 0, $state->offset) + 1, $previous);
    }

    /**
     * Read enum labels with the literal rules active at their declaration.
     */
    private function readCreateEnum(string $statement, ParseState $state): ?EnumType
    {
        $pattern = '/^CREATE\s+TYPE\s+'
            . '(?:(?<schema>' . self::IDENT . ')\s*\.\s*)?(?<name>' . self::IDENT . ')\s+'
            . 'AS\s+ENUM\s*\(/isA';

        if (preg_match($pattern, $statement, $matches) !== 1) {
            return null;
        }

        $body = SqlScanner::balanced($statement, strlen($matches[0]) - 1);

        if ($body === null) {
            throw $this->error('Unterminated enum label list', $state);
        }

        $values = [];

        foreach (SqlScanner::splitTopLevel($body) as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $decoded = $this->readLiteral($value, $state);
            if ($decoded === null) {
                $state->issues[] = $this->issue('An enum label could not be decoded as a string literal.', $state);
            } else {
                $values[] = $decoded;
            }
        }

        return new EnumType(
            SqlScanner::identifier($matches['name']),
            $values,
            $matches['schema'] === '' ? null : SqlScanner::identifier($matches['schema']),
        );
    }

    /**
     * Read a domain without discarding its identity or local constraints.
     *
     * @return null|DomainDefinition
     */
    private function readCreateDomain(string $statement, ParseState $state): ?array
    {
        $pattern = '/^CREATE\s+DOMAIN\s+'
            . '(?:(?<schema>' . self::IDENT . ')\s*\.\s*)?(?<name>' . self::IDENT . ')\s+'
            . '(?:AS\s+)?(?<rest>.+)$/isA';

        if (preg_match($pattern, $statement, $matches) !== 1) {
            return null;
        }

        $rest = trim($matches['rest']);
        $typeEnd = strlen($rest);

        $words = $this->topLevelWords($rest);
        foreach ($words as [$word, $offset]) {
            if (! in_array($word, self::COLUMN_CONSTRAINTS, true)) {
                continue;
            }

            $typeEnd = $offset;
            break;
        }

        $type = trim(substr($rest, 0, $typeEnd));

        if ($type === '') {
            throw $this->error('Expected a domain base type', $state);
        }

        $schema = $matches['schema'] === '' ? null : SqlScanner::identifier($matches['schema']);
        $name = SqlScanner::identifier($matches['name']);
        $domain = [
            'name' => $name, 'schema' => $schema, 'type' => $state->types->resolve($type),
            'nullable' => true, 'default' => null, 'checks' => [],
        ];
        $position = $typeEnd;
        $constraint = null;
        $length = strlen($rest);
        while ($position < $length) {
            $position += strspn($rest, " \t\r\n", $position);
            if ($position === $length) {
                break;
            }
            if (preg_match('/\GCONSTRAINT\s+/i', $rest, $option, 0, $position)) {
                $position += strlen($option[0]);
                $constraint = $this->readIdentifier($rest, $position, $state);
            } elseif (preg_match('/\GDEFAULT\s+/i', $rest, $option, 0, $position)) {
                $position += strlen($option[0]);
                $domain['default'] = $this->readDefault($rest, $words, $position, $state);
            } elseif (preg_match('/\GNOT\s+NULL\b/i', $rest, $option, 0, $position)) {
                $position += strlen($option[0]);
                $domain['nullable'] = false;
                $constraint = null;
            } elseif (preg_match('/\GNULL\b/i', $rest, $option, 0, $position)) {
                $position += strlen($option[0]);
                $constraint = null;
            } elseif (preg_match('/\GCHECK\s*/i', $rest, $option, 0, $position)) {
                $position += strlen($option[0]);
                $expression = $this->readGroup($rest, $position, $state);
                $flags = $this->readConstraintFlags($rest, $position);
                $domain['checks'][] = new CheckConstraint($constraint, $expression, $flags['valid'], $flags['enforced']);
                $constraint = null;
            } elseif (preg_match('/\GCOLLATE\s+/i', $rest, $option, 0, $position)) {
                $position += strlen($option[0]);
                $this->readReference($rest, $position, $state);
            } else {
                $state->issues[] = $this->issue("Domain [{$name}] has unsupported declaration syntax.", $state);
                break;
            }
        }

        return $domain;
    }

    /**
     * Decode one string token using PostgreSQL's existing literal lexer.
     */
    private function readLiteral(string $sql, ParseState $state): ?string
    {
        try {
            $tokens = $state->literalLexer->tokenize($sql);
        } catch (SyntaxException $exception) {
            throw $this->error($exception->getMessage(), $state, $exception);
        }
        if (! $tokens->matches(TokenType::STRING)) {
            return null;
        }
        $value = $tokens->next()->getValue();

        return $tokens->isEOF() ? $value : null;
    }

    /**
     * Apply domain changes emitted separately from CREATE DOMAIN.
     */
    private function applyAlterDomain(string $statement, ParseState $state): bool
    {
        if (! preg_match('/^ALTER\s+DOMAIN\s+/i', $statement, $matches)) {
            return false;
        }
        $position = strlen($matches[0]);
        $reference = $this->readReference($statement, $position, $state);
        $key = $this->domainKey(new ColumnType($reference->name, $reference->schema), $state);
        $action = trim(substr($statement, $position));
        if (preg_match('/^OWNER\s+TO\b/i', $action)) {
            return true;
        }
        if ($key === null) {
            $state->issues[] = $this->issue("The altered domain [{$reference->name}] could not be resolved.", $state);
            return true;
        }
        $domain = &$state->domains[$key];
        if (preg_match('/^ADD\s+(?:CONSTRAINT\s+(?<name>' . self::IDENT . ')\s+)?CHECK\s*/i', $action, $matches)) {
            $position = strlen($matches[0]);
            $expression = $this->readGroup($action, $position, $state);
            $flags = $this->readConstraintFlags($action, $position);
            $domain['checks'][] = new CheckConstraint(
                ($matches['name'] ?? '') === '' ? null : SqlScanner::identifier($matches['name']),
                $expression,
                $flags['valid'],
                $flags['enforced'],
            );
            if (trim(substr($action, $position)) !== '') {
                $state->issues[] = $this->issue('The domain constraint has unsupported trailing syntax.', $state);
            }
        } elseif (preg_match('/^SET\s+DEFAULT\s+(.+)$/isD', $action, $matches)) {
            $domain['default'] = trim($matches[1]);
        } elseif (preg_match('/^DROP\s+DEFAULT$/iD', $action)) {
            $domain['default'] = null;
        } elseif (preg_match('/^(SET|DROP)\s+NOT\s+NULL$/iD', $action, $matches)) {
            $domain['nullable'] = strcasecmp($matches[1], 'DROP') === 0;
        } else {
            $state->issues[] = $this->issue("The ALTER DOMAIN statement for [{$reference->name}] could not be applied.", $state);
        }

        return true;
    }

    /**
     * Apply separate dump constraints and column attributes to their existing table.
     */
    private function applyAlterTable(string $statement, ParseState $state): bool
    {
        if (! preg_match('/^ALTER\s+(?:FOREIGN\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?(?<only>ONLY\s+)?/i', $statement, $matches)) {
            return false;
        }
        $only = ($matches['only'] ?? '') !== '';
        $position = strlen($matches[0]);
        $reference = $this->readReference($statement, $position, $state);
        $key = $this->tableKey($reference, $state);
        foreach (SqlScanner::splitTopLevel(trim(substr($statement, $position))) as $action) {
            if (preg_match('/^DROP\b/i', $action)) {
                $words = $this->topLevelWords($action);
                $last = $words[array_key_last($words)];
                if ($last[0] === 'CASCADE' && trim(substr($action, $last[1] + 7)) === '') {
                    $this->reportCascade($state);
                    continue;
                }
            }
            if (preg_match('/^(?:OWNER\s+TO|SET\s+(?:TABLESPACE|ACCESS\s+METHOD)|CLUSTER\s+ON|REPLICA\s+IDENTITY|(?:ENABLE|DISABLE)(?:\s+(?:ALWAYS|REPLICA))?\s+(?:TRIGGER|RULE)|(?:ENABLE|DISABLE|FORCE|NO\s+FORCE)\s+ROW\s+LEVEL\s+SECURITY)\b/i', $action)) {
                continue;
            }
            if ($this->viewKey($reference, $state) !== null) {
                continue;
            }
            if ($key === null) {
                if (preg_match('/^(?:DROP\s+CONSTRAINT\b|ALTER\s+(?:COLUMN\s+)?' . self::IDENT . '\s+DROP\s+DEFAULT\b)/i', $action)) {
                    continue;
                }
                $state->issues[] = $this->issue("The altered table [{$reference->name}] could not be resolved.", $state);
                continue;
            }
            $table = &$state->tables[$key];
            if (preg_match('/^ALTER\s+(?:COLUMN\s+)?/i', $action, $matches)) {
                $position = strlen($matches[0]);
                $column = $this->readIdentifier($action, $position, $state);
                $clause = trim(substr($action, $position));
                $recursive = ! $only && preg_match('/^(?:SET|DROP)\s+(?:DEFAULT|NOT\s+NULL)\b/i', $clause) === 1;
                foreach ($recursive ? $this->descendants($key, $state) : [$key] as $target) {
                    if ($target !== $key && preg_match('/^DROP\s+NOT\s+NULL$/iD', $clause)
                        && isset($state->tables[$target]['columns'][$column])
                        && ! $state->tables[$target]['columns'][$column]['nullable']) {
                        $state->tables[$target]['issues'][] = $this->issue("The remaining NOT NULL constraints on [{$column}] cannot be resolved after a recursive drop.", $state);
                    }
                    $this->applyColumnChange($column, $clause, $state->tables[$target], $state);
                }
            } elseif (preg_match('/^ADD\s+/i', $action, $matches)) {
                $constraint = substr($action, strlen($matches[0]));
                $checkCount = count($table['checks']);
                $previousPrimary = array_find($table['indexes'], static fn (Index $index): bool => $index->primary);
                $position = $this->readConstraint($constraint, $table, $state);
                if (trim(substr($constraint, $position)) !== '') {
                    $table['issues'][] = $this->issue('The added constraint has unsupported trailing syntax.', $state);
                }
                if (! $only) {
                    $check = $table['checks'][$checkCount] ?? null;
                    $index = array_find($table['indexes'], static fn (Index $index): bool => $index->primary);
                    $notNull = preg_match('/^(?:CONSTRAINT\s+' . self::IDENT . '\s+)?NOT\s+NULL\s+/i', $constraint, $matches) === 1;
                    $notNullColumn = null;
                    if ($notNull) {
                        $position = strlen($matches[0]);
                        $notNullColumn = $this->readIdentifier($constraint, $position, $state);
                    }
                    foreach (array_slice($this->descendants($key, $state), 1) as $childKey) {
                        if ($check !== null && ! $check->noInherit) {
                            $this->mergeCheck($check, $state->tables[$childKey], $state);
                        }
                        if ($index !== null && $index !== $previousPrimary) {
                            foreach ($index->columns as $column) {
                                if ($column->name !== null && isset($state->tables[$childKey]['columns'][$column->name])) {
                                    $state->tables[$childKey]['columns'][$column->name]['nullable'] = false;
                                }
                            }
                        }
                        if ($notNullColumn !== null && isset($table['columns'][$notNullColumn], $state->tables[$childKey]['columns'][$notNullColumn])
                            && ! $table['columns'][$notNullColumn]['noInheritNotNull']) {
                            $child = &$state->tables[$childKey]['columns'][$notNullColumn];
                            $child['nullable'] = $child['nullable'] && $table['columns'][$notNullColumn]['nullable'];
                            $child['unvalidatedNotNull'] = $table['columns'][$notNullColumn]['unvalidatedNotNull'];
                            unset($child);
                        }
                    }
                }
            } elseif (preg_match('/^ATTACH\s+PARTITION\s+/i', $action, $matches)) {
                $position = strlen($matches[0]);
                $child = $this->readReference($action, $position, $state);
                $childKey = $this->tableKey($child, $state);
                if ($childKey === null) {
                    $table['issues'][] = $this->issue("Partition [{$child->name}] could not be resolved.", $state);
                } else {
                    $state->tables[$childKey]['partitionOf'] = new TableReference($table['name'], $table['schema']);
                    $state->tables[$childKey]['partitionBounds'] = $this->readPartitionBounds($action, $position, $state);
                    $state->children[$key][$childKey] = true;
                }
            } else {
                $table['issues'][] = $this->issue('An ALTER TABLE action could not be represented.', $state);
            }
            unset($table);
        }

        return true;
    }

    /**
     * Visit each existing descendant once, including a child reached through multiple parents.
     *
     * @return list<string>
     */
    private function descendants(string $key, ParseState $state): array
    {
        $keys = [$key];
        $seen = [$key => true];
        for ($position = 0; $position < count($keys); ++$position) {
            foreach ($state->children[$keys[$position]] ?? [] as $child => $present) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $keys[] = $child;
                }
            }
        }

        return $keys;
    }

    /**
     * Read only the partition bound, leaving storage and foreign-server clauses outside it.
     */
    private function readPartitionBounds(string $sql, int &$position, ParseState $state): string
    {
        $position += strspn($sql, " \t\r\n", $position);
        $start = $position;
        if (preg_match('/\GDEFAULT\b/i', $sql, $matches, 0, $position)) {
            $position += strlen($matches[0]);
        } elseif (preg_match('/\GFOR\s+VALUES\s+(IN|FROM|WITH)\s*/i', $sql, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $this->readGroup($sql, $position, $state);
            if (strcasecmp($matches[1], 'FROM') === 0) {
                if (! preg_match('/\G\s+TO\s*/i', $sql, $upper, 0, $position)) {
                    throw $this->error('Expected an upper partition bound', $state);
                }
                $position += strlen($upper[0]);
                $this->readGroup($sql, $position, $state);
            }
        } else {
            throw $this->error('Expected a partition bound', $state);
        }

        return substr($sql, $start, $position - $start);
    }

    /**
     * Apply dump-time defaults, nullability and identity without copying a complete table.
     *
     * @param TableDefinition $table
     */
    private function applyColumnChange(string $name, string $clause, array &$table, ParseState $state): void
    {
        if (preg_match('/^(?:SET\s+(?:STATISTICS|STORAGE|COMPRESSION)|SET\s*\(|RESET\s*\(|OPTIONS\s*\()/i', $clause)) {
            return;
        }
        if (! isset($table['columns'][$name])) {
            $table['issues'][] = $this->issue("The altered column [{$name}] could not be resolved.", $state);
            return;
        }
        $column = &$table['columns'][$name];
        if (preg_match('/^SET\s+DEFAULT\s+(.+)$/isD', $clause, $matches)) {
            $column['default'] = trim($matches[1]);
        } elseif (preg_match('/^DROP\s+DEFAULT$/iD', $clause)) {
            $column['default'] = null;
        } elseif (preg_match('/^(SET|DROP)\s+NOT\s+NULL$/iD', $clause, $matches)) {
            $column['nullable'] = strcasecmp($matches[1], 'DROP') === 0;
            $column['unvalidatedNotNull'] = false;
        } elseif (preg_match('/^ADD\s+(GENERATED\s+.+)$/isD', $clause, $matches)) {
            $this->readColumnOptions($matches[1], [], 0, $column, $table, $state);
        } elseif (preg_match('/^SET\s+GENERATED\s+(ALWAYS|BY\s+DEFAULT)$/iD', $clause, $matches)) {
            $column['identity'] = strcasecmp($matches[1], 'ALWAYS') === 0 ? Identity::Always : Identity::ByDefault;
        } elseif (preg_match('/^DROP\s+IDENTITY(?:\s+IF\s+EXISTS)?$/iD', $clause)) {
            $column['identity'] = null;
        } else {
            $table['issues'][] = $this->issue("The ALTER COLUMN action for [{$name}] could not be represented.", $state);
        }
    }

    /**
     * Read a standalone index and retain its key and payload distinction.
     */
    private function readCreateIndex(string $statement, ParseState $state): bool
    {
        if (! preg_match('/^CREATE\s+(?<unique>UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?/i', $statement, $matches)) {
            return false;
        }
        $unique = ($matches['unique'] ?? '') !== '';
        $position = strlen($matches[0]);
        $name = $this->readIdentifier($statement, $position, $state);
        if (! preg_match('/\G\s+ON\s+(?:ONLY\s+)?/i', $statement, $matches, 0, $position)) {
            throw $this->error('Expected an index table', $state);
        }
        $position += strlen($matches[0]);
        $reference = $this->readReference($statement, $position, $state);
        $key = $this->tableKey($reference, $state);
        if ($key === null) {
            if ($this->viewKey($reference, $state) !== null) {
                return true;
            }
            $state->issues[] = $this->issue("Indexed table [{$reference->name}] could not be resolved.", $state);
            return true;
        }
        $table = &$state->tables[$key];
        $method = 'btree';
        if (preg_match('/\G\s+USING\s+/i', $statement, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $method = strtolower($this->readIdentifier($statement, $position, $state));
        }
        $columns = $this->readIndexColumns($this->readGroup($statement, $position, $state), $table, $state);
        $include = $this->readIndexOptions($statement, $position, $state);
        $nullsNotDistinct = false;
        if (preg_match('/\G\s+NULLS\s+(NOT\s+)?DISTINCT\b/i', $statement, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $nullsNotDistinct = ($matches[1] ?? '') !== '';
            $this->readIndexOptions($statement, $position, $state);
        }
        $where = null;
        if (preg_match('/\G\s+WHERE\s+/i', $statement, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $where = trim(substr($statement, $position));
            $position = strlen($statement);
        }
        if (trim(substr($statement, $position)) !== '') {
            $table['issues'][] = $this->issue("Index [{$name}] has unsupported trailing syntax.", $state);
        }
        $table['indexes'][] = new Index($name, $columns, $include, $method, $unique, nullsNotDistinct: $nullsNotDistinct, where: $where);

        return true;
    }

    /**
     * Apply decoded table and column comments while retaining qualified identity.
     */
    private function applyComment(string $statement, ParseState $state): bool
    {
        if (! preg_match('/^COMMENT\s+ON\s+(FOREIGN\s+TABLE|TABLE|COLUMN)\s+/i', $statement, $matches)) {
            return false;
        }
        $isColumn = strcasecmp($matches[1], 'COLUMN') === 0;
        $position = strlen($matches[0]);
        $parts = [$this->readIdentifier($statement, $position, $state)];
        while (preg_match('/\G\s*\.\s*/', $statement, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $parts[] = $this->readIdentifier($statement, $position, $state);
        }
        $column = $isColumn ? array_pop($parts) : null;
        if (count($parts) < 1 || count($parts) > 2) {
            throw $this->error('Expected a table or column comment target', $state);
        }
        $reference = new TableReference($parts[count($parts) - 1], count($parts) === 2 ? $parts[0] : null);
        if (! preg_match('/\G\s+IS\s+/i', $statement, $matches, 0, $position)) {
            throw $this->error('Expected a comment value', $state);
        }
        $position += strlen($matches[0]);
        $value = trim(substr($statement, $position));
        $comment = strcasecmp($value, 'NULL') === 0 ? null : $this->readLiteral($value, $state);
        $key = $this->tableKey($reference, $state);
        if ($key === null) {
            // View comments are not represented, but COMMENT ON COLUMN also accepts views.
            if ($this->viewKey($reference, $state) === null) {
                $state->issues[] = $this->issue("Commented table [{$reference->name}] could not be resolved.", $state);
            }
        } elseif ($comment === null && strcasecmp($value, 'NULL') !== 0) {
            $state->tables[$key]['issues'][] = $this->issue('A comment could not be decoded as a string literal.', $state);
        } elseif ($column === null) {
            $state->tables[$key]['comment'] = $comment;
        } elseif (isset($state->tables[$key]['columns'][$column])) {
            $state->tables[$key]['columns'][$column]['comment'] = $comment;
        } else {
            $state->tables[$key]['issues'][] = $this->issue("Commented column [{$column}] could not be resolved.", $state);
        }

        return true;
    }

    /**
     * Read named views without inferring the types produced by their queries.
     */
    private function readCreateView(string $statement, ParseState $state): ?View
    {
        if (! preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:(?<temporary>TEMPORARY|TEMP)\s+)?(?<materialized>MATERIALIZED\s+)?VIEW\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $statement, $matches)) {
            return null;
        }
        $position = strlen($matches[0]);
        $reference = $this->readReference($statement, $position, $state);
        if (($matches['temporary'] ?? '') !== '' || $reference->schema === 'pg_temp') {
            return null;
        }
        $materialized = ($matches['materialized'] ?? '') !== '';
        $tail = substr($statement, $position);
        foreach ($this->topLevelWords($tail) as [$word, $offset]) {
            if ($word !== 'AS') {
                continue;
            }
            $query = trim(substr($tail, $offset + 2));
            $query = preg_replace('/\s+WITH\s+(?:(?:LOCAL|CASCADED)\s+)?CHECK\s+OPTION\s*$/iD', '', $query);
            if ($materialized) {
                $query = preg_replace('/\s+WITH\s+(?:NO\s+)?DATA\s*$/iD', '', $query);
            }
            if ($query === '') {
                throw $this->error('Expected a view query', $state);
            }
            return new View($reference->name, $query, $reference->schema, $materialized);
        }
        $state->issues[] = $this->issue("View [{$reference->name}] has no readable query definition.", $state);

        return new View($reference->name, '', $reference->schema, $materialized);
    }

    /**
     * Remove dump-cleaned objects before their replacement declarations.
     */
    private function applyDrop(string $statement, ParseState $state): bool
    {
        if (! preg_match('/^DROP\b/i', $statement)) {
            return false;
        }
        if (! preg_match('/^DROP\s+(FOREIGN\s+TABLE|TABLE|MATERIALIZED\s+VIEW|VIEW|DOMAIN|TYPE|INDEX|SCHEMA)\s+(?:CONCURRENTLY\s+)?(?:IF\s+EXISTS\s+)?/i', $statement, $matches)) {
            $words = $this->topLevelWords($statement);
            $last = $words[array_key_last($words)];
            if ($last[0] === 'CASCADE' && trim(substr($statement, $last[1] + 7)) === '') {
                $this->reportCascade($state);
            }
            return true;
        }
        $kind = strtoupper(preg_replace('/\s+/', ' ', $matches[1]));
        $position = strlen($matches[0]);
        $references = [];
        while (true) {
            $references[] = $this->readReference($statement, $position, $state);
            $position += strspn($statement, " \t\r\n", $position);
            if (($statement[$position] ?? '') !== ',') {
                break;
            }
            ++$position;
        }
        $option = trim(substr($statement, $position));
        if (strcasecmp($option, 'CASCADE') === 0) {
            $this->reportCascade($state);
        } elseif ($option !== '' && strcasecmp($option, 'RESTRICT') !== 0) {
            throw $this->error('Expected a DROP option or another object name', $state);
        }
        foreach ($references as $reference) {
            $key = $this->identityKey($reference->schema, $reference->name);
            if ($reference->schema === null && $kind !== 'INDEX' && $kind !== 'SCHEMA') {
                $candidates = match ($kind) {
                    'TABLE', 'FOREIGN TABLE' => $state->tableNames[$reference->name] ?? [],
                    'DOMAIN' => $state->domainNames[$reference->name] ?? [],
                    'TYPE' => array_filter($state->enums, static fn (EnumType $enum): bool => $enum->name === $reference->name),
                    default => $state->viewNames[$reference->name] ?? [],
                };
                if (count($candidates) > 1) {
                    $state->issues[] = $this->issue("Dropped object [{$reference->name}] is ambiguous without a schema.", $state);
                    continue;
                }
                $key = array_key_first($candidates) ?? $key;
            }
            if ($kind === 'TABLE' || $kind === 'FOREIGN TABLE') {
                if (isset($state->tables[$key])) {
                    $table = $state->tables[$key];
                    foreach ([...$table['parents'], ...($table['partitionOf'] === null ? [] : [$table['partitionOf']])] as $parent) {
                        $parentKey = $this->tableKey($parent, $state);
                        if ($parentKey !== null) {
                            unset($state->children[$parentKey][$key]);
                        }
                    }
                    unset($state->tableNames[$table['name']][$key], $state->tables[$key], $state->children[$key]);
                }
            } elseif ($kind === 'DOMAIN') {
                unset($state->domains[$key], $state->domainNames[$reference->name][$key]);
            } elseif ($kind === 'TYPE') {
                unset($state->enums[$key]);
            } elseif ($kind === 'INDEX') {
                $this->dropIndex($reference, $state);
            } elseif ($kind === 'SCHEMA') {
                if (strcasecmp($option, 'CASCADE') !== 0
                    && (array_any($state->tables, static fn (array $table): bool => $table['schema'] === $reference->name)
                        || array_any($state->views, static fn (View $view): bool => $view->schema === $reference->name)
                        || array_any($state->domains, static fn (array $domain): bool => $domain['schema'] === $reference->name)
                        || array_any($state->enums, static fn (EnumType $enum): bool => $enum->schema === $reference->name))) {
                    $state->issues[] = $this->issue('A dropped schema still has represented definitions.', $state);
                }
            } else {
                unset($state->views[$key], $state->viewNames[$reference->name][$key]);
            }
        }

        return true;
    }

    /**
     * Remove an explicitly named index without choosing between ambiguous schemas.
     */
    private function dropIndex(TableReference $reference, ParseState $state): void
    {
        $found = null;
        foreach ($state->tables as $key => $table) {
            if ($reference->schema !== null && $reference->schema !== $table['schema']) {
                continue;
            }
            foreach ($table['indexes'] as $position => $index) {
                if ($index->name !== $reference->name) {
                    continue;
                }
                if ($found !== null) {
                    $state->issues[] = $this->issue("Dropped index [{$reference->name}] is ambiguous without a schema.", $state);
                    return;
                }
                $found = [$key, $position];
            }
        }
        if ($found !== null) {
            array_splice($state->tables[$found[0]]['indexes'], $found[1], 1);
        }
    }

    /**
     * Read an inline or table constraint and return the consumed length.
     *
     * @param TableDefinition $table
     */
    private function readConstraint(string $sql, array &$table, ParseState $state, ?string $column = null, ?string $name = null): int
    {
        $position = 0;
        if (preg_match('/^CONSTRAINT\s+/i', $sql, $matches)) {
            $position = strlen($matches[0]);
            $name = $this->readIdentifier($sql, $position, $state);
            $position += strspn($sql, " \t\r\n", $position);
        }
        if (preg_match('/\GCHECK\s*/i', $sql, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $expression = $this->readGroup($sql, $position, $state);
            $flags = $this->readConstraintFlags($sql, $position);
            $this->mergeCheck(new CheckConstraint($name, $expression, $flags['valid'], $flags['enforced'], $flags['noInherit']), $table, $state);
        } elseif (preg_match('/\GNOT\s+NULL\s+/i', $sql, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $column = $this->readIdentifier($sql, $position, $state);
            $flags = $this->readConstraintFlags($sql, $position);
            if (isset($table['columns'][$column])) {
                if ($flags['valid']) {
                    $table['columns'][$column]['nullable'] = false;
                } else {
                    $table['columns'][$column]['unvalidatedNotNull'] = true;
                }
                $table['columns'][$column]['noInheritNotNull'] = $flags['noInherit'];
            } else {
                $table['issues'][] = $this->issue("The NOT NULL column [{$column}] could not be resolved.", $state);
            }
        } elseif (preg_match('/\G(PRIMARY\s+KEY|UNIQUE)\b/i', $sql, $matches, 0, $position)) {
            $primary = str_starts_with(strtoupper($matches[1]), 'PRIMARY');
            $position += strlen($matches[0]);
            $nullsNotDistinct = false;
            if (preg_match('/\G\s+NULLS\s+(NOT\s+)?DISTINCT\b/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $nullsNotDistinct = ($matches[1] ?? '') !== '';
            }
            $existing = null;
            if (preg_match('/\G\s+USING\s+INDEX\s+/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $indexName = $this->readIdentifier($sql, $position, $state);
                foreach ($table['indexes'] as &$candidate) {
                    if ($candidate->name === $indexName) {
                        $existing = &$candidate;
                        break;
                    }
                }
                unset($candidate);
                if ($existing === null) {
                    $table['issues'][] = $this->issue("Constraint index [{$indexName}] could not be resolved.", $state);
                }
                $columns = $existing->columns ?? [];
                $include = $existing->include ?? [];
            } else {
                $columns = $column === null
                    ? $this->readIndexColumns($this->readGroup($sql, $position, $state), $table, $state)
                    : [new IndexColumn($column)];
                $include = $this->readIndexOptions($sql, $position, $state);
            }
            $flags = $this->readConstraintFlags($sql, $position);
            $index = new Index(
                $name ?? $existing?->name,
                $columns,
                $include,
                $existing->type ?? 'btree',
                unique: true,
                primary: $primary,
                constraint: true,
                nullsNotDistinct: $existing->nullsNotDistinct ?? $nullsNotDistinct,
                deferral: $flags['deferral'],
                where: $existing?->where,
            );
            if ($existing === null) {
                $table['indexes'][] = $index;
            } else {
                $existing = $index;
            }
            if ($primary) {
                foreach ($columns as $key) {
                    if ($key->name !== null && isset($table['columns'][$key->name])) {
                        $table['columns'][$key->name]['nullable'] = false;
                    }
                }
            }
        } elseif (preg_match('/\G(?:FOREIGN\s+KEY|REFERENCES)\b/i', $sql, $matches, 0, $position)) {
            $position += strlen($matches[0]);
            $temporal = false;
            if ($column === null) {
                [$columns, $temporal] = $this->readForeignColumns($this->readGroup($sql, $position, $state), $state);
                if (! preg_match('/\G\s*REFERENCES\s+/i', $sql, $matches, 0, $position)) {
                    throw $this->error('Expected a referenced foreign table', $state);
                }
                $position += strlen($matches[0]);
            } else {
                $columns = [$column];
            }
            $reference = $this->readReference($sql, $position, $state);
            $position += strspn($sql, " \t\r\n", $position);
            $foreignColumns = [];
            if (($sql[$position] ?? '') === '(') {
                [$foreignColumns, $foreignTemporal] = $this->readForeignColumns($this->readGroup($sql, $position, $state), $state);
                $temporal = $temporal || $foreignTemporal;
            }
            if ($temporal) {
                $table['issues'][] = $this->issue('Temporal foreign key semantics are not represented.', $state);
            }
            $match = ForeignKeyMatch::Simple;
            $onUpdate = $onDelete = ReferentialAction::NoAction;
            while (true) {
                if (preg_match('/\G\s*MATCH\s+(FULL|PARTIAL|SIMPLE)\b/i', $sql, $matches, 0, $position)) {
                    $position += strlen($matches[0]);
                    $match = ForeignKeyMatch::from(strtolower($matches[1]));
                } elseif (preg_match('/\G\s*ON\s+(UPDATE|DELETE)\s+(NO\s+ACTION|RESTRICT|CASCADE|SET\s+NULL|SET\s+DEFAULT)\b/i', $sql, $matches, 0, $position)) {
                    $position += strlen($matches[0]);
                    $action = ReferentialAction::from(strtolower(preg_replace('/\s+/', ' ', $matches[2])));
                    if (strcasecmp($matches[1], 'UPDATE') === 0) {
                        $onUpdate = $action;
                    } else {
                        $onDelete = $action;
                    }
                    $position += strspn($sql, " \t\r\n", $position);
                    if (($sql[$position] ?? '') === '(') {
                        $this->readGroup($sql, $position, $state);
                        $table['issues'][] = $this->issue('A foreign key action applies to only some of its columns.', $state);
                    }
                } else {
                    break;
                }
            }
            $flags = $this->readConstraintFlags($sql, $position);
            $table['foreignKeys'][] = new ForeignKey($name, $columns, $reference, $foreignColumns, $onUpdate, $onDelete, $match, $flags['deferral'], $flags['valid'], $flags['enforced']);
        } else {
            $table['issues'][] = $this->issue('A table constraint could not be represented.', $state);
            return strlen($sql);
        }

        return $position;
    }

    /**
     * Merge equal named checks while preserving stronger inherited or local guarantees.
     *
     * @param TableDefinition $table
     */
    private function mergeCheck(CheckConstraint $check, array &$table, ParseState $state): void
    {
        if ($check->name !== null) {
            foreach ($table['checks'] as &$existing) {
                if ($existing->name !== $check->name) {
                    continue;
                }
                if ($existing->expression !== $check->expression) {
                    $table['issues'][] = $this->issue("Check [{$check->name}] has conflicting expressions.", $state);
                    return;
                }
                $existing = new CheckConstraint(
                    $check->name,
                    $check->expression,
                    $existing->valid || $check->valid,
                    $existing->enforced || $check->enforced,
                    $existing->noInherit && $check->noInherit,
                );
                return;
            }
            unset($existing);
        }
        $table['checks'][] = $check;
    }

    /**
     * Read foreign key columns, distinguishing a temporal modifier from a column named period.
     *
     * @return array{list<string>, bool}
     */
    private function readForeignColumns(string $group, ParseState $state): array
    {
        $columns = [];
        $temporal = false;
        foreach (SqlScanner::splitTopLevel(substr($group, 1, -1)) as $entry) {
            $position = 0;
            if (preg_match('/^PERIOD\s+(?=' . self::IDENT . ')/i', $entry, $matches)) {
                $position = strlen($matches[0]);
                $temporal = true;
            }
            $columns[] = $this->readIdentifier($entry, $position, $state);
            if (trim(substr($entry, $position)) !== '') {
                throw $this->error('Expected a foreign key column identifier', $state);
            }
        }

        return [$columns, $temporal];
    }

    /**
     * Read index keys without confusing expressions or payloads with column names.
     *
     * @param TableDefinition $table
     * @return list<IndexColumn>
     */
    private function readIndexColumns(string $group, array &$table, ParseState $state): array
    {
        $columns = [];
        foreach (SqlScanner::splitTopLevel(substr($group, 1, -1)) as $entry) {
            $position = 0;
            $name = $expression = $collation = $operatorClass = null;
            $descending = false;
            $nullsFirst = null;
            if (str_starts_with($entry, '(')) {
                $expression = $this->readGroup($entry, $position, $state);
            } else {
                $reference = $this->readReference($entry, $position, $state);
                $next = $position + strspn($entry, " \t\r\n", $position);
                if (($entry[$next] ?? '') === '(') {
                    $position = $next;
                    $this->readGroup($entry, $position, $state);
                    $expression = substr($entry, 0, $position);
                } elseif ($reference->schema !== null) {
                    $expression = substr($entry, 0, $position);
                    $table['issues'][] = $this->issue('An index key has an unsupported qualified column expression.', $state);
                } else {
                    $name = $reference->name;
                }
            }
            while ($position < strlen($entry)) {
                $position += strspn($entry, " \t\r\n", $position);
                if ($position === strlen($entry)) {
                    break;
                }
                if (preg_match('/\GCOLLATE\s+/i', $entry, $matches, 0, $position)) {
                    $position += strlen($matches[0]);
                    $start = $position;
                    $this->readReference($entry, $position, $state);
                    $collation = substr($entry, $start, $position - $start);
                } elseif (preg_match('/\G(ASC|DESC)\b/i', $entry, $matches, 0, $position)) {
                    $position += strlen($matches[0]);
                    $descending = strcasecmp($matches[1], 'DESC') === 0;
                } elseif (preg_match('/\GNULLS\s+(FIRST|LAST)\b/i', $entry, $matches, 0, $position)) {
                    $position += strlen($matches[0]);
                    $nullsFirst = strcasecmp($matches[1], 'FIRST') === 0;
                } elseif (preg_match('/\GWITHOUT\s+OVERLAPS\b/i', $entry, $matches, 0, $position)) {
                    $table['issues'][] = $this->issue('Temporal index semantics are not represented.', $state);
                    $name = null;
                    $expression = $entry;
                    $position = strlen($entry);
                } elseif ($operatorClass === null && preg_match('/\G' . self::IDENT . '/', $entry, $matches, 0, $position)) {
                    $start = $position;
                    $this->readReference($entry, $position, $state);
                    $next = $position + strspn($entry, " \t\r\n", $position);
                    if (($entry[$next] ?? '') === '(') {
                        $position = $next;
                        $this->readGroup($entry, $position, $state);
                    }
                    $operatorClass = substr($entry, $start, $position - $start);
                } else {
                    $table['issues'][] = $this->issue('An index key has unsupported trailing syntax.', $state);
                    break;
                }
            }
            $columns[] = new IndexColumn($name, $expression, $descending, $nullsFirst, collation: $collation, operatorClass: $operatorClass);
        }

        return $columns;
    }

    /**
     * Read payload columns while consuming unrepresented physical index settings.
     *
     * @return list<string>
     */
    private function readIndexOptions(string $sql, int &$position, ParseState $state): array
    {
        $include = [];
        while (true) {
            if (preg_match('/\G\s*INCLUDE\s*/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $include = $this->identifierList($this->readGroup($sql, $position, $state), $state);
            } elseif (preg_match('/\G\s*WITH\s*/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $this->readGroup($sql, $position, $state);
            } elseif (preg_match('/\G\s*(?:USING\s+INDEX\s+)?TABLESPACE\s+/i', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                $this->readIdentifier($sql, $position, $state);
            } else {
                break;
            }
        }

        return $include;
    }

    /**
     * Read a parenthesized list of complete column identifiers.
     *
     * @return list<string>
     */
    private function identifierList(string $group, ParseState $state): array
    {
        $result = [];

        foreach (SqlScanner::splitTopLevel(substr($group, 1, -1)) as $part) {
            $position = 0;
            $result[] = $this->readIdentifier($part, $position, $state);
            if (trim(substr($part, $position)) !== '') {
                throw $this->error('Expected a column identifier', $state);
            }
        }

        return $result;
    }

    /**
     * Return the upper-cased words of $sql outside quotes, parentheses and arrays,
     * paired with their offsets.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function topLevelWords(string $sql): array
    {
        $words = [];
        $depth = 0;
        $length = strlen($sql);
        $previous = '';

        for ($i = 0; $i < $length; ++$i) {
            $i += strspn($sql, " \t\r\n", $i);
            if ($i === $length) {
                break;
            }
            if ($depth > 0) {
                $i += strcspn($sql, "'\"$-/()[]", $i);
                if ($i === $length) {
                    break;
                }
            }
            $skipped = SqlScanner::skip($sql, $i);

            if ($skipped !== $i) {
                $previous = $sql[$skipped];
                $i = $skipped;
                continue;
            }

            $char = $sql[$i];
            if (SqlScanner::isIdentifierPart($char)) {
                $start = $i;
                preg_match('/\G[A-Za-z0-9_$\x80-\xff]+/', $sql, $match, 0, $i);
                $i += strlen($match[0]);
                $next = $i + strspn($sql, " \t\r\n", $i);
                if ($depth === 0 && $previous !== '.' && ($sql[$next] ?? '') !== '.') {
                    $words[] = [strtoupper(substr($sql, $start, $i - $start)), $start];
                }
                $previous = $sql[$i - 1];
                --$i;
                continue;
            }
            if ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            }
            if (! str_contains(" \t\r\n", $char)) {
                $previous = $char;
            }
        }

        return $words;
    }
}
