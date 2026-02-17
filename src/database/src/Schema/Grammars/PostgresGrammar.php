<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema\Grammars;

use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use LogicException;
use Override;

class PostgresGrammar extends Grammar
{
    /**
     * If this Grammar supports schema changes wrapped in a transaction.
     */
    protected bool $transactions = true;

    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected array $modifiers = ['Collate', 'Nullable', 'Default', 'VirtualAs', 'StoredAs', 'GeneratedAs', 'Increment'];

    /**
     * The columns available as serials.
     *
     * @var string[]
     */
    protected array $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * The commands to be executed outside of create or alter command.
     *
     * @var string[]
     */
    protected array $fluentCommands = ['AutoIncrementStartingValues', 'Comment'];

    /**
     * Compile a create database command.
     */
    public function compileCreateDatabase(string $name): string
    {
        $sql = parent::compileCreateDatabase($name);

        if ($charset = $this->connection->getConfig('charset')) {
            $sql .= sprintf(' encoding %s', $this->wrapValue($charset));
        }

        return $sql;
    }

    /**
     * Compile the query to determine the schemas.
     */
    public function compileSchemas(): string
    {
        return 'select nspname as name, nspname = current_schema() as "default" from pg_namespace where '
            . $this->compileSchemaWhereClause(null, 'nspname')
            . ' order by nspname';
    }

    /**
     * Compile the query to determine if the given table exists.
     */
    public function compileTableExists(?string $schema, string $table): ?string
    {
        return sprintf(
            'select exists (select 1 from pg_class c, pg_namespace n where '
            . "n.nspname = %s and c.relname = %s and c.relkind in ('r', 'p') and n.oid = c.relnamespace)",
            $schema ? $this->quoteString($schema) : 'current_schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Compile the query to determine the tables.
     *
     * @param null|string|string[] $schema
     */
    public function compileTables(string|array|null $schema): string
    {
        return 'select c.relname as name, n.nspname as schema, pg_total_relation_size(c.oid) as size, '
            . "obj_description(c.oid, 'pg_class') as comment from pg_class c, pg_namespace n "
            . "where c.relkind in ('r', 'p') and n.oid = c.relnamespace and "
            . $this->compileSchemaWhereClause($schema, 'n.nspname')
            . ' order by n.nspname, c.relname';
    }

    /**
     * Compile the query to determine the views.
     */
    public function compileViews(string|array|null $schema): string
    {
        return 'select viewname as name, schemaname as schema, definition from pg_views where '
            . $this->compileSchemaWhereClause($schema, 'schemaname')
            . ' order by schemaname, viewname';
    }

    /**
     * Compile the query to determine the user-defined types.
     */
    public function compileTypes(string|array|null $schema): string
    {
        return 'select t.typname as name, n.nspname as schema, t.typtype as type, t.typcategory as category, '
            . "((t.typinput = 'array_in'::regproc and t.typoutput = 'array_out'::regproc) or t.typtype = 'm') as implicit "
            . 'from pg_type t join pg_namespace n on n.oid = t.typnamespace '
            . 'left join pg_class c on c.oid = t.typrelid '
            . 'left join pg_type el on el.oid = t.typelem '
            . 'left join pg_class ce on ce.oid = el.typrelid '
            . "where ((t.typrelid = 0 and (ce.relkind = 'c' or ce.relkind is null)) or c.relkind = 'c') "
            . "and not exists (select 1 from pg_depend d where d.objid in (t.oid, t.typelem) and d.deptype = 'e') and "
            . $this->compileSchemaWhereClause($schema, 'n.nspname');
    }

    /**
     * Compile the query to compare the schema.
     */
    protected function compileSchemaWhereClause(string|array|null $schema, string $column): string
    {
        return $column . (match (true) {
            ! empty($schema) && is_array($schema) => ' in (' . $this->quoteString($schema) . ')',
            ! empty($schema) => ' = ' . $this->quoteString($schema),
            default => " <> 'information_schema' and {$column} not like 'pg\\_%'",
        });
    }

    /**
     * Compile the query to determine the columns.
     */
    public function compileColumns(?string $schema, string $table): string
    {
        return sprintf(
            'select a.attname as name, t.typname as type_name, format_type(a.atttypid, a.atttypmod) as type, '
            . '(select tc.collcollate from pg_catalog.pg_collation tc where tc.oid = a.attcollation) as collation, '
            . 'not a.attnotnull as nullable, '
            . '(select pg_get_expr(adbin, adrelid) from pg_attrdef where c.oid = pg_attrdef.adrelid and pg_attrdef.adnum = a.attnum) as default, '
            . (version_compare($this->connection->getServerVersion(), '12.0', '<') ? "'' as generated, " : 'a.attgenerated as generated, ')
            . 'col_description(c.oid, a.attnum) as comment '
            . 'from pg_attribute a, pg_class c, pg_type t, pg_namespace n '
            . 'where c.relname = %s and n.nspname = %s and a.attnum > 0 and a.attrelid = c.oid and a.atttypid = t.oid and n.oid = c.relnamespace '
            . 'order by a.attnum',
            $this->quoteString($table),
            $schema ? $this->quoteString($schema) : 'current_schema()'
        );
    }

    /**
     * Compile the query to determine the indexes.
     */
    public function compileIndexes(?string $schema, string $table): string
    {
        return sprintf(
            "select ic.relname as name, string_agg(a.attname, ',' order by indseq.ord) as columns, "
            . 'am.amname as "type", i.indisunique as "unique", i.indisprimary as "primary" '
            . 'from pg_index i '
            . 'join pg_class tc on tc.oid = i.indrelid '
            . 'join pg_namespace tn on tn.oid = tc.relnamespace '
            . 'join pg_class ic on ic.oid = i.indexrelid '
            . 'join pg_am am on am.oid = ic.relam '
            . 'join lateral unnest(i.indkey) with ordinality as indseq(num, ord) on true '
            . 'left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = indseq.num '
            . 'where tc.relname = %s and tn.nspname = %s '
            . 'group by ic.relname, am.amname, i.indisunique, i.indisprimary',
            $this->quoteString($table),
            $schema ? $this->quoteString($schema) : 'current_schema()'
        );
    }

    /**
     * Compile the query to determine the foreign keys.
     */
    public function compileForeignKeys(?string $schema, string $table): string
    {
        return sprintf(
            'select c.conname as name, '
            . "string_agg(la.attname, ',' order by conseq.ord) as columns, "
            . 'fn.nspname as foreign_schema, fc.relname as foreign_table, '
            . "string_agg(fa.attname, ',' order by conseq.ord) as foreign_columns, "
            . 'c.confupdtype as on_update, c.confdeltype as on_delete '
            . 'from pg_constraint c '
            . 'join pg_class tc on c.conrelid = tc.oid '
            . 'join pg_namespace tn on tn.oid = tc.relnamespace '
            . 'join pg_class fc on c.confrelid = fc.oid '
            . 'join pg_namespace fn on fn.oid = fc.relnamespace '
            . 'join lateral unnest(c.conkey) with ordinality as conseq(num, ord) on true '
            . 'join pg_attribute la on la.attrelid = c.conrelid and la.attnum = conseq.num '
            . 'join pg_attribute fa on fa.attrelid = c.confrelid and fa.attnum = c.confkey[conseq.ord] '
            . "where c.contype = 'f' and tc.relname = %s and tn.nspname = %s "
            . 'group by c.conname, fn.nspname, fc.relname, c.confupdtype, c.confdeltype',
            $this->quoteString($table),
            $schema ? $this->quoteString($schema) : 'current_schema()'
        );
    }

    /**
     * Compile a create table command.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            '%s table %s (%s)',
            $blueprint->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint))
        );
    }

    /**
     * Compile a column addition command.
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add column %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column)
        );
    }

    /**
     * Compile the auto-incrementing column starting values.
     */
    public function compileAutoIncrementStartingValues(Blueprint $blueprint, Fluent $command): ?string
    {
        if ($command->column->autoIncrement
            && $value = $command->column->get('startingValue', $command->column->get('from'))) {
            [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

            $table = ($schema ? $schema . '.' : '') . $this->connection->getTablePrefix() . $table;

            return 'alter sequence ' . $table . '_' . $command->column->name . '_seq restart with ' . $value;
        }

        return null;
    }

    #[Override]
    public function compileChange(Blueprint $blueprint, Fluent $command): array|string
    {
        $column = $command->column;

        $changes = ['type ' . $this->getType($column) . $this->modifyCollate($blueprint, $column)];

        foreach ($this->modifiers as $modifier) {
            if ($modifier === 'Collate') {
                continue;
            }

            if (method_exists($this, $method = "modify{$modifier}")) {
                $constraints = (array) $this->{$method}($blueprint, $column);

                foreach ($constraints as $constraint) {
                    $changes[] = $constraint;
                }
            }
        }

        return sprintf(
            'alter table %s %s',
            $this->wrapTable($blueprint),
            implode(', ', $this->prefixArray('alter column ' . $this->wrap($column), $changes))
        );
    }

    /**
     * Compile a primary key command.
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->columnize($command->columns);

        return 'alter table ' . $this->wrapTable($blueprint) . " add primary key ({$columns})";
    }

    /**
     * Compile a unique key command.
     *
     * @return string[]
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): array
    {
        $uniqueStatement = 'unique';

        if (! is_null($command->nullsNotDistinct)) {
            $uniqueStatement .= ' nulls ' . ($command->nullsNotDistinct ? 'not distinct' : 'distinct');
        }

        if ($command->online || $command->algorithm) {
            $createIndexSql = sprintf(
                'create unique index %s%s on %s%s (%s)',
                $command->online ? 'concurrently ' : '',
                $this->wrap($command->index),
                $this->wrapTable($blueprint),
                $command->algorithm ? ' using ' . $command->algorithm : '',
                $this->columnize($command->columns)
            );

            $sql = sprintf(
                'alter table %s add constraint %s unique using index %s',
                $this->wrapTable($blueprint),
                $this->wrap($command->index),
                $this->wrap($command->index)
            );
        } else {
            $sql = sprintf(
                'alter table %s add constraint %s %s (%s)',
                $this->wrapTable($blueprint),
                $this->wrap($command->index),
                $uniqueStatement,
                $this->columnize($command->columns)
            );
        }

        if (! is_null($command->deferrable)) {
            $sql .= $command->deferrable ? ' deferrable' : ' not deferrable';
        }

        if ($command->deferrable && ! is_null($command->initiallyImmediate)) {
            $sql .= $command->initiallyImmediate ? ' initially immediate' : ' initially deferred';
        }

        return isset($createIndexSql) ? [$createIndexSql, $sql] : [$sql];
    }

    /**
     * Compile a plain index key command.
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'create index %s%s on %s%s (%s)',
            $command->online ? 'concurrently ' : '',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $command->algorithm ? ' using ' . $command->algorithm : '',
            $this->columnize($command->columns)
        );
    }

    /**
     * Compile a fulltext index key command.
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command): string
    {
        $language = $command->language ?: 'english';

        $columns = array_map(function ($column) use ($language) {
            return "to_tsvector({$this->quoteString($language)}, {$this->wrap($column)})";
        }, $command->columns);

        return sprintf(
            'create index %s%s on %s using gin ((%s))',
            $command->online ? 'concurrently ' : '',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            implode(' || ', $columns)
        );
    }

    /**
     * Compile a spatial index key command.
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): string
    {
        $command->algorithm = 'gist';

        if (! is_null($command->operatorClass)) {
            return $this->compileIndexWithOperatorClass($blueprint, $command);
        }

        return $this->compileIndex($blueprint, $command);
    }

    /**
     * Compile a vector index key command.
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileIndexWithOperatorClass($blueprint, $command);
    }

    /**
     * Compile a spatial index with operator class key command.
     */
    protected function compileIndexWithOperatorClass(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->columnizeWithOperatorClass($command->columns, $command->operatorClass);

        return sprintf(
            'create index %s%s on %s%s (%s)',
            $command->online ? 'concurrently ' : '',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $command->algorithm ? ' using ' . $command->algorithm : '',
            $columns
        );
    }

    /**
     * Convert an array of column names to a delimited string with operator class.
     */
    protected function columnizeWithOperatorClass(array $columns, string $operatorClass): string
    {
        return implode(', ', array_map(function ($column) use ($operatorClass) {
            return $this->wrap($column) . ' ' . $operatorClass;
        }, $columns));
    }

    /**
     * Compile a foreign key command.
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        $sql = parent::compileForeign($blueprint, $command);

        if (! is_null($command->deferrable)) {
            $sql .= $command->deferrable ? ' deferrable' : ' not deferrable';
        }

        if ($command->deferrable && ! is_null($command->initiallyImmediate)) {
            $sql .= $command->initiallyImmediate ? ' initially immediate' : ' initially deferred';
        }

        if (! is_null($command->notValid)) {
            $sql .= ' not valid';
        }

        return $sql;
    }

    /**
     * Compile a drop table command.
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile the SQL needed to drop all tables.
     */
    public function compileDropAllTables(array $tables): string
    {
        return 'drop table ' . implode(', ', $this->escapeNames($tables)) . ' cascade';
    }

    /**
     * Compile the SQL needed to drop all views.
     */
    public function compileDropAllViews(array $views): string
    {
        return 'drop view ' . implode(', ', $this->escapeNames($views)) . ' cascade';
    }

    /**
     * Compile the SQL needed to drop all types.
     */
    public function compileDropAllTypes(array $types): string
    {
        return 'drop type ' . implode(', ', $this->escapeNames($types)) . ' cascade';
    }

    /**
     * Compile the SQL needed to drop all domains.
     */
    public function compileDropAllDomains(array $domains): string
    {
        return 'drop domain ' . implode(', ', $this->escapeNames($domains)) . ' cascade';
    }

    /**
     * Compile a drop column command.
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('drop column', $this->wrapArray($command->columns));

        return 'alter table ' . $this->wrapTable($blueprint) . ' ' . implode(', ', $columns);
    }

    /**
     * Compile a drop primary key command.
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        [, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());
        $index = $this->wrap("{$this->connection->getTablePrefix()}{$table}_pkey");

        return 'alter table ' . $this->wrapTable($blueprint) . " drop constraint {$index}";
    }

    /**
     * Compile a drop unique key command.
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        $index = $this->wrap($command->index);

        return "alter table {$this->wrapTable($blueprint)} drop constraint {$index}";
    }

    /**
     * Compile a drop index command.
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        return "drop index {$this->wrap($command->index)}";
    }

    /**
     * Compile a drop fulltext index command.
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile a drop spatial index command.
     */
    public function compileDropSpatialIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile a drop foreign key command.
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        $index = $this->wrap($command->index);

        return "alter table {$this->wrapTable($blueprint)} drop constraint {$index}";
    }

    /**
     * Compile a rename table command.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "alter table {$from} rename to " . $this->wrapTable($command->to);
    }

    /**
     * Compile a rename index command.
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter index %s rename to %s',
            $this->wrap($command->from),
            $this->wrap($command->to)
        );
    }

    /**
     * Compile the command to enable foreign key constraints.
     */
    #[Override]
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'SET CONSTRAINTS ALL IMMEDIATE;';
    }

    /**
     * Compile the command to disable foreign key constraints.
     */
    #[Override]
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'SET CONSTRAINTS ALL DEFERRED;';
    }

    /**
     * Compile a comment command.
     */
    public function compileComment(Blueprint $blueprint, Fluent $command): ?string
    {
        if (! is_null($comment = $command->column->comment) || $command->column->change) {
            return sprintf(
                'comment on column %s.%s is %s',
                $this->wrapTable($blueprint),
                $this->wrap($command->column->name),
                is_null($comment) ? 'NULL' : "'" . str_replace("'", "''", $comment) . "'"
            );
        }

        return null;
    }

    /**
     * Compile a table comment command.
     */
    public function compileTableComment(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'comment on table %s is %s',
            $this->wrapTable($blueprint),
            "'" . str_replace("'", "''", $command->comment) . "'"
        );
    }

    /**
     * Quote-escape the given tables, views, or types.
     */
    public function escapeNames(array $names): array
    {
        return array_map(
            fn ($name) => (new Collection(explode('.', $name)))->map($this->wrapValue(...))->implode('.'),
            $names
        );
    }

    /**
     * Create the column definition for a char type.
     */
    protected function typeChar(Fluent $column): string
    {
        if ($column->length) {
            return "char({$column->length})";
        }

        return 'char';
    }

    /**
     * Create the column definition for a string type.
     */
    protected function typeString(Fluent $column): string
    {
        if ($column->length) {
            return "varchar({$column->length})";
        }

        return 'varchar';
    }

    /**
     * Create the column definition for a tiny text type.
     */
    protected function typeTinyText(Fluent $column): string
    {
        return 'varchar(255)';
    }

    /**
     * Create the column definition for a text type.
     */
    protected function typeText(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for a medium text type.
     */
    protected function typeMediumText(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for a long text type.
     */
    protected function typeLongText(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for an integer type.
     */
    protected function typeInteger(Fluent $column): string
    {
        return $column->autoIncrement && is_null($column->generatedAs) && ! $column->change ? 'serial' : 'integer';
    }

    /**
     * Create the column definition for a big integer type.
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return $column->autoIncrement && is_null($column->generatedAs) && ! $column->change ? 'bigserial' : 'bigint';
    }

    /**
     * Create the column definition for a medium integer type.
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return $this->typeInteger($column);
    }

    /**
     * Create the column definition for a tiny integer type.
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return $this->typeSmallInteger($column);
    }

    /**
     * Create the column definition for a small integer type.
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return $column->autoIncrement && is_null($column->generatedAs) && ! $column->change ? 'smallserial' : 'smallint';
    }

    /**
     * Create the column definition for a float type.
     */
    protected function typeFloat(Fluent $column): string
    {
        if ($column->precision) {
            return "float({$column->precision})";
        }

        return 'float';
    }

    /**
     * Create the column definition for a double type.
     */
    protected function typeDouble(Fluent $column): string
    {
        return 'double precision';
    }

    /**
     * Create the column definition for a real type.
     */
    protected function typeReal(Fluent $column): string
    {
        return 'real';
    }

    /**
     * Create the column definition for a decimal type.
     */
    protected function typeDecimal(Fluent $column): string
    {
        return "decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'boolean';
    }

    /**
     * Create the column definition for an enumeration type.
     */
    protected function typeEnum(Fluent $column): string
    {
        return sprintf(
            'varchar(255) check ("%s" in (%s))',
            $column->name,
            $this->quoteString($column->allowed)
        );
    }

    /**
     * Create the column definition for a json type.
     */
    protected function typeJson(Fluent $column): string
    {
        return 'json';
    }

    /**
     * Create the column definition for a jsonb type.
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'jsonb';
    }

    /**
     * Create the column definition for a date type.
     */
    protected function typeDate(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression('CURRENT_DATE'));
        }

        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     */
    protected function typeDateTime(Fluent $column): string
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     */
    protected function typeDateTimeTz(Fluent $column): string
    {
        return $this->typeTimestampTz($column);
    }

    /**
     * Create the column definition for a time type.
     */
    protected function typeTime(Fluent $column): string
    {
        return 'time' . (is_null($column->precision) ? '' : "({$column->precision})") . ' without time zone';
    }

    /**
     * Create the column definition for a time (with time zone) type.
     */
    protected function typeTimeTz(Fluent $column): string
    {
        return 'time' . (is_null($column->precision) ? '' : "({$column->precision})") . ' with time zone';
    }

    /**
     * Create the column definition for a timestamp type.
     */
    protected function typeTimestamp(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression('CURRENT_TIMESTAMP'));
        }

        return 'timestamp' . (is_null($column->precision) ? '' : "({$column->precision})") . ' without time zone';
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     */
    protected function typeTimestampTz(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression('CURRENT_TIMESTAMP'));
        }

        return 'timestamp' . (is_null($column->precision) ? '' : "({$column->precision})") . ' with time zone';
    }

    /**
     * Create the column definition for a year type.
     */
    protected function typeYear(Fluent $column): string
    {
        if ($column->useCurrent) {
            $column->default(new Expression('EXTRACT(YEAR FROM CURRENT_DATE)'));
        }

        return $this->typeInteger($column);
    }

    /**
     * Create the column definition for a binary type.
     */
    protected function typeBinary(Fluent $column): string
    {
        return 'bytea';
    }

    /**
     * Create the column definition for a uuid type.
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'uuid';
    }

    /**
     * Create the column definition for an IP address type.
     */
    protected function typeIpAddress(Fluent $column): string
    {
        return 'inet';
    }

    /**
     * Create the column definition for a MAC address type.
     */
    protected function typeMacAddress(Fluent $column): string
    {
        return 'macaddr';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     */
    protected function typeGeometry(Fluent $column): string
    {
        if ($column->subtype) {
            return sprintf(
                'geometry(%s%s)',
                strtolower($column->subtype),
                $column->srid ? ',' . $column->srid : ''
            );
        }

        return 'geometry';
    }

    /**
     * Create the column definition for a spatial Geography type.
     */
    protected function typeGeography(Fluent $column): string
    {
        if ($column->subtype) {
            return sprintf(
                'geography(%s%s)',
                strtolower($column->subtype),
                $column->srid ? ',' . $column->srid : ''
            );
        }

        return 'geography';
    }

    /**
     * Create the column definition for a vector type.
     */
    protected function typeVector(Fluent $column): string
    {
        return isset($column->dimensions) && $column->dimensions !== ''
            ? "vector({$column->dimensions})"
            : 'vector';
    }

    /**
     * Get the SQL for a collation column modifier.
     */
    protected function modifyCollate(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->collation)) {
            return ' collate ' . $this->wrapValue($column->collation);
        }

        return null;
    }

    /**
     * Get the SQL for a nullable column modifier.
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): string
    {
        if ($column->change) {
            return $column->nullable ? 'drop not null' : 'set not null';
        }

        return $column->nullable ? ' null' : ' not null';
    }

    /**
     * Get the SQL for a default column modifier.
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->change) {
            if (! $column->autoIncrement || ! is_null($column->generatedAs)) {
                return is_null($column->default) ? 'drop default' : 'set default ' . $this->getDefaultValue($column->default);
            }

            return null;
        }

        if (! is_null($column->default)) {
            return ' default ' . $this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! $column->change
            && ! $this->hasCommand($blueprint, 'primary')
            && (in_array($column->type, $this->serials) || ($column->generatedAs !== null))
            && $column->autoIncrement) {
            return ' primary key';
        }

        return null;
    }

    /**
     * Get the SQL for a generated virtual column modifier.
     */
    protected function modifyVirtualAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->change) {
            if (array_key_exists('virtualAs', $column->getAttributes())) {
                return is_null($column->virtualAs)
                    ? 'drop expression if exists'
                    : throw new LogicException('This database driver does not support modifying generated columns.');
            }

            return null;
        }

        if (! is_null($column->virtualAs)) {
            return " generated always as ({$this->getValue($column->virtualAs)}) virtual";
        }

        return null;
    }

    /**
     * Get the SQL for a generated stored column modifier.
     */
    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->change) {
            if (array_key_exists('storedAs', $column->getAttributes())) {
                return is_null($column->storedAs)
                    ? 'drop expression if exists'
                    : throw new LogicException('This database driver does not support modifying generated columns.');
            }

            return null;
        }

        if (! is_null($column->storedAs)) {
            return " generated always as ({$this->getValue($column->storedAs)}) stored";
        }

        return null;
    }

    /**
     * Get the SQL for an identity column modifier.
     *
     * @return null|list<string>|string
     */
    protected function modifyGeneratedAs(Blueprint $blueprint, Fluent $column): array|string|null
    {
        $sql = null;

        if (! is_null($column->generatedAs)) {
            $sql = sprintf(
                ' generated %s as identity%s',
                $column->always ? 'always' : 'by default',
                ! is_bool($column->generatedAs) && ! empty($column->generatedAs) ? " ({$column->generatedAs})" : ''
            );
        }

        if ($column->change) {
            $changes = $column->autoIncrement && is_null($sql) ? [] : ['drop identity if exists'];

            if (! is_null($sql)) {
                $changes[] = 'add ' . $sql;
            }

            return $changes;
        }

        return $sql;
    }
}
