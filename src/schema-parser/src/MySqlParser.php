<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Contracts\Parser;
use Hypervel\SchemaParser\MySql\SqlParser;

/**
 * @phpstan-import-type Field from SqlParser
 * @phpstan-import-type Index from SqlParser as RawIndex
 * @phpstan-import-type Table from SqlParser as RawTable
 */
class MySqlParser implements Parser
{
    /**
     * Parse a MySQL or MariaDB schema dump.
     */
    public function parse(string $sql): Schema
    {
        $reader = new SqlParser;
        $definitions = $reader->parse($sql);
        $primaryKeys = [];

        foreach ($definitions as $definition) {
            $columns = [];
            foreach ($definition['indexes'] ?? [] as $index) {
                if ($index['type'] === 'PRIMARY') {
                    $columns = array_column($index['cols'] ?? [], 'name');
                    break;
                }
            }
            $schemaKey = $definition['database'] === null ? '' : 's:' . $definition['database'];
            $primaryKeys[$definition['name']][$schemaKey] = $columns;
        }

        $tables = [];
        foreach ($definitions as $definition) {
            $tables[] = $this->table($definition, $primaryKeys);
        }

        $views = [];
        foreach ($reader->views as $definition) {
            $views[] = new View($definition['name'], $definition['definition'], $definition['database']);
        }

        return new Schema(tables: $tables, views: $views, issues: $reader->issues);
    }

    /**
     * Build a table after all referenced primary keys are known.
     *
     * @param RawTable $definition
     * @param array<array-key, array<string, list<string>>> $primaryKeys
     */
    private function table(array $definition, array $primaryKeys): Table
    {
        $schemaKey = $definition['database'] === null ? '' : 's:' . $definition['database'];
        $primaryColumns = array_fill_keys($primaryKeys[$definition['name']][$schemaKey], true);
        $issues = $definition['issues'] ?? [];
        $columns = [];
        $indexes = [];
        $foreignKeys = [];
        $checks = [];

        if (isset($definition['like'])) {
            $issues[] = new Issue('CREATE TABLE LIKE does not contain a complete column definition.');
        }
        $tail = $definition['more'] ?? [];
        $selectsColumns = array_any($tail, static fn (string $token): bool => strtoupper($token) === 'SELECT');
        if ($tail !== []) {
            if ($selectsColumns) {
                $issues[] = new Issue('CREATE TABLE SELECT can add columns that are not declared in the schema.');
            } elseif (strtoupper($tail[0]) === 'WITH' && strtoupper($tail[1] ?? '') === 'SYSTEM' && strtoupper($tail[2] ?? '') === 'VERSIONING') {
                $issues[] = new Issue('System-versioned tables can have implicit columns and temporal rules not represented by the dump.');
            } elseif (strtoupper($tail[0]) !== 'PARTITION' || strtoupper($tail[1] ?? '') !== 'BY') {
                $issues[] = new Issue('The table contains unsupported options that may hide schema facts.');
            }
        }
        if (($definition['fields'] ?? []) === [] && ! isset($definition['like']) && ! $selectsColumns) {
            $issues[] = new Issue('The table declaration does not contain a complete column definition.');
        }

        foreach ($definition['fields'] ?? [] as $field) {
            $columns[] = $this->column($field, isset($primaryColumns[$field['name']]));
            if (($field['more'] ?? []) !== []) {
                $issues[] = new Issue("Column [{$field['name']}] contains unsupported declaration syntax.");
            }
        }

        foreach ($definition['indexes'] ?? [] as $index) {
            if (($index['more'] ?? []) !== []) {
                $issues[] = new Issue('An index or constraint contains unsupported declaration syntax.');
            }

            if ($index['type'] === 'CHECK') {
                $checks[] = new CheckConstraint($index['constraint_name'] ?? null, $index['expression'], enforced: $index['enforced']);
            } elseif ($index['type'] === 'FOREIGN') {
                $foreignColumns = array_column($index['ref_cols'] ?? [], 'name');
                $foreignSchema = $index['ref_database'] ?? $definition['database'];

                if ($foreignColumns === []) {
                    $targets = $primaryKeys[$index['ref_table']] ?? [];
                    $foreignColumns = $foreignSchema !== null
                        ? ($targets['s:' . $foreignSchema] ?? [])
                        : (count($targets) === 1 ? $targets[array_key_first($targets)] : []);

                    if ($foreignColumns === []) {
                        $issues[] = new Issue("The referenced columns of foreign table [{$index['ref_table']}] could not be resolved.");
                    }
                }

                $foreignKeys[] = new ForeignKey(
                    $index['constraint_name'] ?? $index['name'] ?? null,
                    array_column($index['cols'] ?? [], 'name'),
                    new TableReference($index['ref_table'], $foreignSchema),
                    $foreignColumns,
                    ReferentialAction::from(strtolower($index['ref_on_update'] ?? 'NO ACTION')),
                    ReferentialAction::from(strtolower($index['ref_on_delete'] ?? 'NO ACTION')),
                    ForeignKeyMatch::from(strtolower($index['ref_match'] ?? 'SIMPLE')),
                );
            } else {
                $indexes[] = $this->index($index);
            }
        }

        $properties = $definition['props'] ?? [];

        return new Table(
            $definition['name'],
            $columns,
            $definition['database'],
            $indexes,
            $foreignKeys,
            $checks,
            comment: $definition['comment'] ?? null,
            collation: $properties['COLLATE'] ?? null,
            engine: $definition['engine'] ?? null,
            issues: $issues,
        );
    }

    /**
     * Map a declared column without converting SQL types to PHP types.
     *
     * @param Field $field
     */
    private function column(array $field, bool $primary): Column
    {
        $modifiers = $field['modifiers'] ?? [];
        if (isset($field['fsp'])) {
            $modifiers = [$field['fsp']];
        } elseif (isset($field['length'])) {
            $modifiers = [$field['length']];
            if (isset($field['decimals'])) {
                $modifiers[] = $field['decimals'];
            }
        }

        $autoIncrement = $field['auto_increment'] ?? false;

        return new Column(
            $field['name'],
            new ColumnType(
                strtolower($field['type']),
                modifiers: $modifiers,
                unsigned: ($field['unsigned'] ?? false) || ($field['zerofill'] ?? false),
                values: $field['values'] ?? [],
            ),
            nullable: ($field['null'] ?? true) && ! $primary && ! $autoIncrement,
            default: $field['default'] ?? null,
            autoIncrement: $autoIncrement,
            generation: isset($field['generation'])
                ? new Generation(GenerationType::from($field['generation_type']), $field['generation'])
                : null,
            onUpdate: $field['on_update'] ?? null,
            invisible: $field['invisible'] ?? false,
            collation: $field['collation'] ?? null,
            comment: $field['comment'] ?? null,
        );
    }

    /**
     * Map the index's ordered keys and declared method.
     *
     * @param RawIndex $definition
     */
    private function index(array $definition): Index
    {
        $columns = [];
        foreach ($definition['cols'] ?? [] as $column) {
            $columns[] = new IndexColumn(
                $column['name'] ?? null,
                $column['expression'] ?? null,
                descending: ($column['direction'] ?? 'ASC') === 'DESC',
                length: isset($column['length']) ? (int) $column['length'] : null,
            );
        }

        $primary = $definition['type'] === 'PRIMARY';
        $unique = $primary || $definition['type'] === 'UNIQUE';
        $method = $definition['mode'] ?? (in_array($definition['type'], ['FULLTEXT', 'SPATIAL', 'VECTOR'], true) ? $definition['type'] : null);

        return new Index(
            $primary ? 'PRIMARY' : ($definition['name'] ?? $definition['constraint_name'] ?? null),
            $columns,
            type: $method === null ? null : strtolower($method),
            unique: $unique,
            primary: $primary,
            constraint: $unique,
        );
    }
}
