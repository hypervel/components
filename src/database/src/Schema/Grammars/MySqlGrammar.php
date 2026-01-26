<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema\Grammars;

use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use Override;
use RuntimeException;

class MySqlGrammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected array $modifiers = [
        'Unsigned', 'Charset', 'Collate', 'VirtualAs', 'StoredAs', 'Nullable',
        'Default', 'OnUpdate', 'Invisible', 'Increment', 'Comment', 'After', 'First',
    ];

    /**
     * The possible column serials.
     *
     * @var string[]
     */
    protected array $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * The commands to be executed outside of create or alter commands.
     *
     * @var string[]
     */
    protected array $fluentCommands = ['AutoIncrementStartingValues'];

    /**
     * Compile a create database command.
     */
    public function compileCreateDatabase(string $name): string
    {
        $sql = parent::compileCreateDatabase($name);

        if ($charset = $this->connection->getConfig('charset')) {
            $sql .= sprintf(' default character set %s', $this->wrapValue($charset));
        }

        if ($collation = $this->connection->getConfig('collation')) {
            $sql .= sprintf(' default collate %s', $this->wrapValue($collation));
        }

        return $sql;
    }

    /**
     * Compile the query to determine the schemas.
     */
    public function compileSchemas(): string
    {
        return 'select schema_name as name, schema_name = schema() as `default` from information_schema.schemata where '
            . $this->compileSchemaWhereClause(null, 'schema_name')
            . ' order by schema_name';
    }

    /**
     * Compile the query to determine if the given table exists.
     */
    public function compileTableExists(?string $schema, string $table): string
    {
        return sprintf(
            'select exists (select 1 from information_schema.tables where '
            . "table_schema = %s and table_name = %s and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')) as `exists`",
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Compile the query to determine the tables.
     */
    public function compileTables(string|array|null $schema): string
    {
        return sprintf(
            'select table_name as `name`, table_schema as `schema`, (data_length + index_length) as `size`, '
            . 'table_comment as `comment`, engine as `engine`, table_collation as `collation` '
            . "from information_schema.tables where table_type in ('BASE TABLE', 'SYSTEM VERSIONED') and "
            . $this->compileSchemaWhereClause($schema, 'table_schema')
            . ' order by table_schema, table_name',
            $this->quoteString($schema)
        );
    }

    /**
     * Compile the query to determine the views.
     */
    public function compileViews(string|array|null $schema): string
    {
        return 'select table_name as `name`, table_schema as `schema`, view_definition as `definition` '
            . 'from information_schema.views where '
            . $this->compileSchemaWhereClause($schema, 'table_schema')
            . ' order by table_schema, table_name';
    }

    /**
     * Compile the query to compare the schema.
     */
    protected function compileSchemaWhereClause(string|array|null $schema, string $column): string
    {
        return $column . (match (true) {
            ! empty($schema) && is_array($schema) => ' in (' . $this->quoteString($schema) . ')',
            ! empty($schema) => ' = ' . $this->quoteString($schema),
            default => " not in ('information_schema', 'mysql', 'ndbinfo', 'performance_schema', 'sys')",
        });
    }

    /**
     * Compile the query to determine the columns.
     */
    public function compileColumns(?string $schema, string $table): string
    {
        return sprintf(
            'select column_name as `name`, data_type as `type_name`, column_type as `type`, '
            . 'collation_name as `collation`, is_nullable as `nullable`, '
            . 'column_default as `default`, column_comment as `comment`, '
            . 'generation_expression as `expression`, extra as `extra` '
            . 'from information_schema.columns where table_schema = %s and table_name = %s '
            . 'order by ordinal_position asc',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Compile the query to determine the indexes.
     */
    public function compileIndexes(?string $schema, string $table): string
    {
        return sprintf(
            'select index_name as `name`, group_concat(column_name order by seq_in_index) as `columns`, '
            . 'index_type as `type`, not non_unique as `unique` '
            . 'from information_schema.statistics where table_schema = %s and table_name = %s '
            . 'group by index_name, index_type, non_unique',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Compile the query to determine the foreign keys.
     */
    public function compileForeignKeys(?string $schema, string $table): string
    {
        return sprintf(
            'select kc.constraint_name as `name`, '
            . 'group_concat(kc.column_name order by kc.ordinal_position) as `columns`, '
            . 'kc.referenced_table_schema as `foreign_schema`, '
            . 'kc.referenced_table_name as `foreign_table`, '
            . 'group_concat(kc.referenced_column_name order by kc.ordinal_position) as `foreign_columns`, '
            . 'rc.update_rule as `on_update`, '
            . 'rc.delete_rule as `on_delete` '
            . 'from information_schema.key_column_usage kc join information_schema.referential_constraints rc '
            . 'on kc.constraint_schema = rc.constraint_schema and kc.constraint_name = rc.constraint_name '
            . 'where kc.table_schema = %s and kc.table_name = %s and kc.referenced_table_name is not null '
            . 'group by kc.constraint_name, kc.referenced_table_schema, kc.referenced_table_name, rc.update_rule, rc.delete_rule',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Compile a create table command.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $sql = $this->compileCreateTable(
            $blueprint,
            $command
        );

        // Once we have the primary SQL, we can add the encoding option to the SQL for
        // the table.  Then, we can check if a storage engine has been supplied for
        // the table. If so, we will add the engine declaration to the SQL query.
        $sql = $this->compileCreateEncoding(
            $sql,
            $blueprint
        );

        // Finally, we will append the engine configuration onto this SQL statement as
        // the final thing we do before returning this finished SQL. Once this gets
        // added the query will be ready to execute against the real connections.
        return $this->compileCreateEngine($sql, $blueprint);
    }

    /**
     * Create the main create table clause.
     */
    protected function compileCreateTable(Blueprint $blueprint, Fluent $command): string
    {
        $tableStructure = $this->getColumns($blueprint);

        if ($primaryKey = $this->getCommandByName($blueprint, 'primary')) {
            $tableStructure[] = sprintf(
                'primary key %s(%s)',
                $primaryKey->algorithm ? 'using ' . $primaryKey->algorithm : '',
                $this->columnize($primaryKey->columns)
            );

            $primaryKey->shouldBeSkipped = true;
        }

        return sprintf(
            '%s table %s (%s)',
            $blueprint->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $tableStructure)
        );
    }

    /**
     * Append the character set specifications to a command.
     */
    protected function compileCreateEncoding(string $sql, Blueprint $blueprint): string
    {
        // First we will set the character set if one has been set on either the create
        // blueprint itself or on the root configuration for the connection that the
        // table is being created on. We will add these to the create table query.
        if (isset($blueprint->charset)) {
            $sql .= ' default character set ' . $blueprint->charset;
        } elseif (! is_null($charset = $this->connection->getConfig('charset'))) {
            $sql .= ' default character set ' . $charset;
        }

        // Next we will add the collation to the create table statement if one has been
        // added to either this create table blueprint or the configuration for this
        // connection that the query is targeting. We'll add it to this SQL query.
        if (isset($blueprint->collation)) {
            $sql .= " collate '{$blueprint->collation}'";
        } elseif (! is_null($collation = $this->connection->getConfig('collation'))) {
            $sql .= " collate '{$collation}'";
        }

        return $sql;
    }

    /**
     * Append the engine specifications to a command.
     */
    protected function compileCreateEngine(string $sql, Blueprint $blueprint): string
    {
        if (isset($blueprint->engine)) {
            return $sql . ' engine = ' . $blueprint->engine;
        }
        if (! is_null($engine = $this->connection->getConfig('engine'))) {
            return $sql . ' engine = ' . $engine;
        }

        return $sql;
    }

    /**
     * Compile an add column command.
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add %s%s%s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column),
            $command->column->instant ? ', algorithm=instant' : '',
            $command->column->lock ? ', lock=' . $command->column->lock : ''
        );
    }

    /**
     * Compile the auto-incrementing column starting values.
     */
    public function compileAutoIncrementStartingValues(Blueprint $blueprint, Fluent $command): ?string
    {
        if ($command->column->autoIncrement
            && $value = $command->column->get('startingValue', $command->column->get('from'))) {
            return 'alter table ' . $this->wrapTable($blueprint) . ' auto_increment = ' . $value;
        }

        return null;
    }

    #[Override]
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): array|string
    {
        $isMaria = $this->connection->isMaria();
        $version = $this->connection->getServerVersion();

        if (($isMaria && version_compare($version, '10.5.2', '<'))
            || (! $isMaria && version_compare($version, '8.0.3', '<'))) {
            return $this->compileLegacyRenameColumn($blueprint, $command);
        }

        return parent::compileRenameColumn($blueprint, $command);
    }

    /**
     * Compile a rename column command for legacy versions of MySQL.
     */
    protected function compileLegacyRenameColumn(Blueprint $blueprint, Fluent $command): string
    {
        $column = (new Collection($this->connection->getSchemaBuilder()->getColumns($blueprint->getTable())))
            ->firstWhere('name', $command->from);

        $modifiers = $this->addModifiers($column['type'], $blueprint, new ColumnDefinition([
            'change' => true,
            'type' => match ($column['type_name']) {
                'bigint' => 'bigInteger',
                'int' => 'integer',
                'mediumint' => 'mediumInteger',
                'smallint' => 'smallInteger',
                'tinyint' => 'tinyInteger',
                default => $column['type_name'],
            },
            'nullable' => $column['nullable'],
            'default' => $column['default'] && (str_starts_with(strtolower($column['default']), 'current_timestamp') || $column['default'] === 'NULL')
                ? new Expression($column['default'])
                : $column['default'],
            'autoIncrement' => $column['auto_increment'],
            'collation' => $column['collation'],
            'comment' => $column['comment'],
            'virtualAs' => ! is_null($column['generation']) && $column['generation']['type'] === 'virtual'
                ? $column['generation']['expression']
                : null,
            'storedAs' => ! is_null($column['generation']) && $column['generation']['type'] === 'stored'
                ? $column['generation']['expression']
                : null,
        ]));

        return sprintf(
            'alter table %s change %s %s %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to),
            $modifiers
        );
    }

    #[Override]
    public function compileChange(Blueprint $blueprint, Fluent $command): array|string
    {
        $column = $command->column;

        $sql = sprintf(
            'alter table %s %s %s%s %s',
            $this->wrapTable($blueprint),
            is_null($column->renameTo) ? 'modify' : 'change',
            $this->wrap($column),
            is_null($column->renameTo) ? '' : ' ' . $this->wrap($column->renameTo),
            $this->getType($column)
        );

        $sql = $this->addModifiers($sql, $blueprint, $column);

        if ($column->instant) {
            $sql .= ', algorithm=instant';
        }

        if ($column->lock) {
            $sql .= ', lock=' . $column->lock;
        }

        return $sql;
    }

    /**
     * Compile a primary key command.
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add primary key %s(%s)%s',
            $this->wrapTable($blueprint),
            $command->algorithm ? 'using ' . $command->algorithm : '',
            $this->columnize($command->columns),
            $command->lock ? ', lock=' . $command->lock : ''
        );
    }

    /**
     * Compile a unique key command.
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileKey($blueprint, $command, 'unique');
    }

    /**
     * Compile a plain index key command.
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileKey($blueprint, $command, 'index');
    }

    /**
     * Compile a fulltext index key command.
     */
    public function compileFullText(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileKey($blueprint, $command, 'fulltext');
    }

    /**
     * Compile a spatial index key command.
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileKey($blueprint, $command, 'spatial index');
    }

    /**
     * Compile an index creation command.
     */
    protected function compileKey(Blueprint $blueprint, Fluent $command, string $type): string
    {
        return sprintf(
            'alter table %s add %s %s%s(%s)%s',
            $this->wrapTable($blueprint),
            $type,
            $this->wrap($command->index),
            $command->algorithm ? ' using ' . $command->algorithm : '',
            $this->columnize($command->columns),
            $command->lock ? ', lock=' . $command->lock : ''
        );
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
     * Compile a drop column command.
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('drop', $this->wrapArray($command->columns));

        $sql = 'alter table ' . $this->wrapTable($blueprint) . ' ' . implode(', ', $columns);

        if ($command->instant) {
            $sql .= ', algorithm=instant';
        }

        if ($command->lock) {
            $sql .= ', lock=' . $command->lock;
        }

        return $sql;
    }

    /**
     * Compile a drop primary key command.
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table ' . $this->wrapTable($blueprint) . ' drop primary key';
    }

    /**
     * Compile a drop unique key command.
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        $index = $this->wrap($command->index);

        return "alter table {$this->wrapTable($blueprint)} drop index {$index}";
    }

    /**
     * Compile a drop index command.
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        $index = $this->wrap($command->index);

        return "alter table {$this->wrapTable($blueprint)} drop index {$index}";
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
     * Compile a foreign key command.
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        $sql = parent::compileForeign($blueprint, $command);

        if ($command->lock) {
            $sql .= ', lock=' . $command->lock;
        }

        return $sql;
    }

    /**
     * Compile a drop foreign key command.
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        $index = $this->wrap($command->index);

        return "alter table {$this->wrapTable($blueprint)} drop foreign key {$index}";
    }

    /**
     * Compile a rename table command.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "rename table {$from} to " . $this->wrapTable($command->to);
    }

    /**
     * Compile a rename index command.
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s rename index %s to %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to)
        );
    }

    /**
     * Compile the SQL needed to drop all tables.
     */
    public function compileDropAllTables(array $tables): string
    {
        return 'drop table ' . implode(', ', $this->escapeNames($tables));
    }

    /**
     * Compile the SQL needed to drop all views.
     */
    public function compileDropAllViews(array $views): string
    {
        return 'drop view ' . implode(', ', $this->escapeNames($views));
    }

    /**
     * Compile the command to enable foreign key constraints.
     */
    #[Override]
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=1;';
    }

    /**
     * Compile the command to disable foreign key constraints.
     */
    #[Override]
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=0;';
    }

    /**
     * Compile a table comment command.
     */
    public function compileTableComment(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s comment = %s',
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
        return "char({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     */
    protected function typeString(Fluent $column): string
    {
        return "varchar({$column->length})";
    }

    /**
     * Create the column definition for a tiny text type.
     */
    protected function typeTinyText(Fluent $column): string
    {
        return 'tinytext';
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
        return 'mediumtext';
    }

    /**
     * Create the column definition for a long text type.
     */
    protected function typeLongText(Fluent $column): string
    {
        return 'longtext';
    }

    /**
     * Create the column definition for a big integer type.
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    /**
     * Create the column definition for an integer type.
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'int';
    }

    /**
     * Create the column definition for a medium integer type.
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return 'mediumint';
    }

    /**
     * Create the column definition for a tiny integer type.
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'tinyint';
    }

    /**
     * Create the column definition for a small integer type.
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'smallint';
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
        return 'double';
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
        return 'tinyint(1)';
    }

    /**
     * Create the column definition for an enumeration type.
     */
    protected function typeEnum(Fluent $column): string
    {
        return sprintf('enum(%s)', $this->quoteString($column->allowed));
    }

    /**
     * Create the column definition for a set enumeration type.
     */
    protected function typeSet(Fluent $column): string
    {
        return sprintf('set(%s)', $this->quoteString($column->allowed));
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
        return 'json';
    }

    /**
     * Create the column definition for a date type.
     */
    protected function typeDate(Fluent $column): string
    {
        $isMaria = $this->connection->isMaria();
        $version = $this->connection->getServerVersion();

        if ($isMaria || version_compare($version, '8.0.13', '>=')) {
            if ($column->useCurrent) {
                $column->default(new Expression('(CURDATE())'));
            }
        }

        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     */
    protected function typeDateTime(Fluent $column): string
    {
        $current = $column->precision ? "CURRENT_TIMESTAMP({$column->precision})" : 'CURRENT_TIMESTAMP';

        if ($column->useCurrent) {
            $column->default(new Expression($current));
        }

        if ($column->useCurrentOnUpdate) {
            $column->onUpdate(new Expression($current));
        }

        return $column->precision ? "datetime({$column->precision})" : 'datetime';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     */
    protected function typeDateTimeTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a time type.
     */
    protected function typeTime(Fluent $column): string
    {
        return $column->precision ? "time({$column->precision})" : 'time';
    }

    /**
     * Create the column definition for a time (with time zone) type.
     */
    protected function typeTimeTz(Fluent $column): string
    {
        return $this->typeTime($column);
    }

    /**
     * Create the column definition for a timestamp type.
     */
    protected function typeTimestamp(Fluent $column): string
    {
        $current = $column->precision ? "CURRENT_TIMESTAMP({$column->precision})" : 'CURRENT_TIMESTAMP';

        if ($column->useCurrent) {
            $column->default(new Expression($current));
        }

        if ($column->useCurrentOnUpdate) {
            $column->onUpdate(new Expression($current));
        }

        return $column->precision ? "timestamp({$column->precision})" : 'timestamp';
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     */
    protected function typeTimestampTz(Fluent $column): string
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a year type.
     */
    protected function typeYear(Fluent $column): string
    {
        $isMaria = $this->connection->isMaria();
        $version = $this->connection->getServerVersion();

        if ($isMaria || version_compare($version, '8.0.13', '>=')) {
            if ($column->useCurrent) {
                $column->default(new Expression('(YEAR(CURDATE()))'));
            }
        }

        return 'year';
    }

    /**
     * Create the column definition for a binary type.
     */
    protected function typeBinary(Fluent $column): string
    {
        if ($column->length) {
            return $column->fixed ? "binary({$column->length})" : "varbinary({$column->length})";
        }

        return 'blob';
    }

    /**
     * Create the column definition for a uuid type.
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'char(36)';
    }

    /**
     * Create the column definition for an IP address type.
     */
    protected function typeIpAddress(Fluent $column): string
    {
        return 'varchar(45)';
    }

    /**
     * Create the column definition for a MAC address type.
     */
    protected function typeMacAddress(Fluent $column): string
    {
        return 'varchar(17)';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     */
    protected function typeGeometry(Fluent $column): string
    {
        $subtype = $column->subtype ? strtolower($column->subtype) : null;

        if (! in_array($subtype, ['point', 'linestring', 'polygon', 'geometrycollection', 'multipoint', 'multilinestring', 'multipolygon'])) {
            $subtype = null;
        }

        return sprintf(
            '%s%s',
            $subtype ?? 'geometry',
            match (true) {
                $column->srid && $this->connection->isMaria() => ' ref_system_id=' . $column->srid,
                (bool) $column->srid => ' srid ' . $column->srid,
                default => '',
            }
        );
    }

    /**
     * Create the column definition for a spatial Geography type.
     */
    protected function typeGeography(Fluent $column): string
    {
        return $this->typeGeometry($column);
    }

    /**
     * Create the column definition for a generated, computed column type.
     *
     * @throws RuntimeException
     */
    protected function typeComputed(Fluent $column): void
    {
        throw new RuntimeException('This database driver requires a type, see the virtualAs / storedAs modifiers.');
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
     * Get the SQL for a generated virtual column modifier.
     */
    protected function modifyVirtualAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($virtualAs = $column->virtualAsJson)) {
            if ($this->isJsonSelector($virtualAs)) {
                $virtualAs = $this->wrapJsonSelector($virtualAs);
            }

            return " as ({$virtualAs})";
        }

        if (! is_null($virtualAs = $column->virtualAs)) {
            return " as ({$this->getValue($virtualAs)})";
        }

        return null;
    }

    /**
     * Get the SQL for a generated stored column modifier.
     */
    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($storedAs = $column->storedAsJson)) {
            if ($this->isJsonSelector($storedAs)) {
                $storedAs = $this->wrapJsonSelector($storedAs);
            }

            return " as ({$storedAs}) stored";
        }

        if (! is_null($storedAs = $column->storedAs)) {
            return " as ({$this->getValue($storedAs)}) stored";
        }

        return null;
    }

    /**
     * Get the SQL for an unsigned column modifier.
     */
    protected function modifyUnsigned(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->unsigned) {
            return ' unsigned';
        }

        return null;
    }

    /**
     * Get the SQL for a character set column modifier.
     */
    protected function modifyCharset(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->charset)) {
            return ' character set ' . $column->charset;
        }

        return null;
    }

    /**
     * Get the SQL for a collation column modifier.
     */
    protected function modifyCollate(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->collation)) {
            return " collate '{$column->collation}'";
        }

        return null;
    }

    /**
     * Get the SQL for a nullable column modifier.
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        if (is_null($column->virtualAs)
            && is_null($column->virtualAsJson)
            && is_null($column->storedAs)
            && is_null($column->storedAsJson)) {
            return $column->nullable ? ' null' : ' not null';
        }

        if ($column->nullable === false) {
            return ' not null';
        }

        return null;
    }

    /**
     * Get the SQL for an invisible column modifier.
     */
    protected function modifyInvisible(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->invisible)) {
            return ' invisible';
        }

        return null;
    }

    /**
     * Get the SQL for a default column modifier.
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->default)) {
            return ' default ' . $this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for an "on update" column modifier.
     */
    protected function modifyOnUpdate(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->onUpdate)) {
            return ' on update ' . $this->getValue($column->onUpdate);
        }

        return null;
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return $this->hasCommand($blueprint, 'primary') || ($column->change && ! $column->primary)
                ? ' auto_increment'
                : ' auto_increment primary key';
        }

        return null;
    }

    /**
     * Get the SQL for a "first" column modifier.
     */
    protected function modifyFirst(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->first)) {
            return ' first';
        }

        return null;
    }

    /**
     * Get the SQL for an "after" column modifier.
     */
    protected function modifyAfter(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->after)) {
            return ' after ' . $this->wrap($column->after);
        }

        return null;
    }

    /**
     * Get the SQL for a "comment" column modifier.
     */
    protected function modifyComment(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->comment)) {
            return " comment '" . addslashes($column->comment) . "'";
        }

        return null;
    }

    /**
     * Wrap a single string in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        if ($value !== '*') {
            return '`' . str_replace('`', '``', $value) . '`';
        }

        return $value;
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_unquote(json_extract(' . $field . $path . '))';
    }
}
