<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema\Grammars;

use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\IndexDefinition;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use RuntimeException;

class SQLiteGrammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected array $modifiers = ['Increment', 'Nullable', 'Default', 'Collate', 'VirtualAs', 'StoredAs'];

    /**
     * The columns available as serials.
     *
     * @var string[]
     */
    protected array $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * Get the commands to be compiled on the alter command.
     */
    public function getAlterCommands(): array
    {
        $alterCommands = ['change', 'primary', 'dropPrimary', 'foreign', 'dropForeign'];

        if (version_compare($this->connection->getServerVersion(), '3.35', '<')) {
            $alterCommands[] = 'dropColumn';
        }

        return $alterCommands;
    }

    /**
     * Compile the query to determine the SQL text that describes the given object.
     */
    public function compileSqlCreateStatement(?string $schema, string $name, string $type = 'table'): string
    {
        return sprintf('select "sql" from %s.sqlite_master where type = %s and name = %s',
            $this->wrapValue($schema ?? 'main'),
            $this->quoteString($type),
            $this->quoteString($name)
        );
    }

    /**
     * Compile the query to determine if the dbstat table is available.
     */
    public function compileDbstatExists(): string
    {
        return "select exists (select 1 from pragma_compile_options where compile_options = 'ENABLE_DBSTAT_VTAB') as enabled";
    }

    /**
     * Compile the query to determine the schemas.
     */
    public function compileSchemas(): string
    {
        return 'select name, file as path, name = \'main\' as "default" from pragma_database_list order by name';
    }

    /**
     * Compile the query to determine if the given table exists.
     */
    public function compileTableExists(?string $schema, string $table): string
    {
        return sprintf(
            'select exists (select 1 from %s.sqlite_master where name = %s and type = \'table\') as "exists"',
            $this->wrapValue($schema ?? 'main'),
            $this->quoteString($table)
        );
    }

    /**
     * Compile the query to determine the tables.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileTables(string|array|null $schema, bool $withSize = false): string
    {
        return 'select tl.name as name, tl.schema as schema'
            .($withSize ? ', (select sum(s.pgsize) '
                .'from (select tl.name as name union select il.name as name from pragma_index_list(tl.name, tl.schema) as il) as es '
                .'join dbstat(tl.schema) as s on s.name = es.name) as size' : '')
            .' from pragma_table_list as tl where'
            .(match (true) {
                ! empty($schema) && is_array($schema) => ' tl.schema in ('.$this->quoteString($schema).') and',
                ! empty($schema) => ' tl.schema = '.$this->quoteString($schema).' and',
                default => '',
            })
            ." tl.type in ('table', 'virtual') and tl.name not like 'sqlite\_%' escape '\' "
            .'order by tl.schema, tl.name';
    }

    /**
     * Compile the query for legacy versions of SQLite to determine the tables.
     */
    public function compileLegacyTables(string $schema, bool $withSize = false): string
    {
        return $withSize
            ? sprintf(
                'select m.tbl_name as name, %s as schema, sum(s.pgsize) as size from %s.sqlite_master as m '
                .'join dbstat(%s) as s on s.name = m.name '
                ."where m.type in ('table', 'index') and m.tbl_name not like 'sqlite\_%%' escape '\' "
                .'group by m.tbl_name '
                .'order by m.tbl_name',
                $this->quoteString($schema),
                $this->wrapValue($schema),
                $this->quoteString($schema)
            )
            : sprintf(
                'select name, %s as schema from %s.sqlite_master '
                ."where type = 'table' and name not like 'sqlite\_%%' escape '\' order by name",
                $this->quoteString($schema),
                $this->wrapValue($schema)
            );
    }

    /**
     * Compile the query to determine the views.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileViews(string|array|null $schema): string
    {
        return sprintf(
            "select name, %s as schema, sql as definition from %s.sqlite_master where type = 'view' order by name",
            $this->quoteString($schema),
            $this->wrapValue($schema)
        );
    }

    /**
     * Compile the query to determine the columns.
     */
    public function compileColumns(?string $schema, string $table): string
    {
        return sprintf(
            'select name, type, not "notnull" as "nullable", dflt_value as "default", pk as "primary", hidden as "extra" '
            .'from pragma_table_xinfo(%s, %s) order by cid asc',
            $this->quoteString($table),
            $this->quoteString($schema ?? 'main')
        );
    }

    /**
     * Compile the query to determine the indexes.
     */
    public function compileIndexes(?string $schema, string $table): string
    {
        return sprintf(
            'select \'primary\' as name, group_concat(col) as columns, 1 as "unique", 1 as "primary" '
            .'from (select name as col from pragma_table_xinfo(%s, %s) where pk > 0 order by pk, cid) group by name '
            .'union select name, group_concat(col) as columns, "unique", origin = \'pk\' as "primary" '
            .'from (select il.*, ii.name as col from pragma_index_list(%s, %s) il, pragma_index_info(il.name, %s) ii order by il.seq, ii.seqno) '
            .'group by name, "unique", "primary"',
            $table = $this->quoteString($table),
            $schema = $this->quoteString($schema ?? 'main'),
            $table,
            $schema,
            $schema
        );
    }

    /**
     * Compile the query to determine the foreign keys.
     */
    public function compileForeignKeys(?string $schema, string $table): string
    {
        return sprintf(
            'select group_concat("from") as columns, %s as foreign_schema, "table" as foreign_table, '
            .'group_concat("to") as foreign_columns, on_update, on_delete '
            .'from (select * from pragma_foreign_key_list(%s, %s) order by id desc, seq) '
            .'group by id, "table", on_update, on_delete',
            $schema = $this->quoteString($schema ?? 'main'),
            $this->quoteString($table),
            $schema
        );
    }

    /**
     * Compile a create table command.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('%s table %s (%s%s%s)',
            $blueprint->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            $this->addForeignKeys($this->getCommandsByName($blueprint, 'foreign')),
            $this->addPrimaryKeys($this->getCommandByName($blueprint, 'primary'))
        );
    }

    /**
     * Get the foreign key syntax for a table creation statement.
     *
     * @param  \Hypervel\Database\Schema\ForeignKeyDefinition[]  $foreignKeys
     */
    protected function addForeignKeys(array $foreignKeys): string
    {
        return (new Collection($foreignKeys))->reduce(function ($sql, $foreign) {
            // Once we have all the foreign key commands for the table creation statement
            // we'll loop through each of them and add them to the create table SQL we
            // are building, since SQLite needs foreign keys on the tables creation.
            return $sql.$this->getForeignKey($foreign);
        }, '');
    }

    /**
     * Get the SQL for the foreign key.
     */
    protected function getForeignKey(Fluent $foreign): string
    {
        // We need to columnize the columns that the foreign key is being defined for
        // so that it is a properly formatted list. Once we have done this, we can
        // return the foreign key SQL declaration to the calling method for use.
        $sql = sprintf(', foreign key(%s) references %s(%s)',
            $this->columnize($foreign->columns),
            $this->wrapTable($foreign->on),
            $this->columnize((array) $foreign->references)
        );

        if (! is_null($foreign->onDelete)) {
            $sql .= " on delete {$foreign->onDelete}";
        }

        // If this foreign key specifies the action to be taken on update we will add
        // that to the statement here. We'll append it to this SQL and then return
        // this SQL so we can keep adding any other foreign constraints to this.
        if (! is_null($foreign->onUpdate)) {
            $sql .= " on update {$foreign->onUpdate}";
        }

        return $sql;
    }

    /**
     * Get the primary key syntax for a table creation statement.
     */
    protected function addPrimaryKeys(?Fluent $primary): ?string
    {
        if (! is_null($primary)) {
            return ", primary key ({$this->columnize($primary->columns)})";
        }

        return null;
    }

    /**
     * Compile alter table commands for adding columns.
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('alter table %s add column %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column)
        );
    }

    /**
     * Compile alter table command into a series of SQL statements.
     *
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint, Fluent $command): array
    {
        $columnNames = [];
        $autoIncrementColumn = null;

        $columns = (new Collection($blueprint->getState()->getColumns()))
            ->map(function ($column) use ($blueprint, &$columnNames, &$autoIncrementColumn) {
                $name = $this->wrap($column);

                $autoIncrementColumn = $column->autoIncrement ? $column->name : $autoIncrementColumn;

                if (is_null($column->virtualAs) && is_null($column->virtualAsJson) &&
                    is_null($column->storedAs) && is_null($column->storedAsJson)) {
                    $columnNames[] = $name;
                }

                return $this->addModifiers(
                    $this->wrap($column).' '.($column->full_type_definition ?? $this->getType($column)),
                    $blueprint,
                    $column
                );
            })->all();

        $indexes = (new Collection($blueprint->getState()->getIndexes()))
            ->reject(fn ($index) => str_starts_with('sqlite_', $index->index))
            ->map(fn ($index) => $this->{'compile'.ucfirst($index->name)}($blueprint, $index))
            ->all();

        [, $tableName] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());
        $tempTable = $this->wrapTable($blueprint, '__temp__'.$this->connection->getTablePrefix());
        $table = $this->wrapTable($blueprint);
        $columnNames = implode(', ', $columnNames);

        $foreignKeyConstraintsEnabled = $this->connection->scalar($this->pragma('foreign_keys'));

        return array_filter(array_merge([
            $foreignKeyConstraintsEnabled ? $this->compileDisableForeignKeyConstraints() : null,
            sprintf('create table %s (%s%s%s)',
                $tempTable,
                implode(', ', $columns),
                $this->addForeignKeys($blueprint->getState()->getForeignKeys()),
                $autoIncrementColumn ? '' : $this->addPrimaryKeys($blueprint->getState()->getPrimaryKey())
            ),
            sprintf('insert into %s (%s) select %s from %s', $tempTable, $columnNames, $columnNames, $table),
            sprintf('drop table %s', $table),
            sprintf('alter table %s rename to %s', $tempTable, $this->wrapTable($tableName)),
        ], $indexes, [$foreignKeyConstraintsEnabled ? $this->compileEnableForeignKeyConstraints() : null]));
    }

    #[\Override]
    public function compileChange(Blueprint $blueprint, Fluent $command): array|string
    {
        // Handled on table alteration...
        return [];
    }

    /**
     * Compile a primary key command.
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table creation or alteration...
        return null;
    }

    /**
     * Compile a unique key command.
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

        return sprintf('create unique index %s%s on %s (%s)',
            $schema ? $this->wrapValue($schema).'.' : '',
            $this->wrap($command->index),
            $this->wrapTable($table),
            $this->columnize($command->columns)
        );
    }

    /**
     * Compile a plain index key command.
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

        return sprintf('create index %s%s on %s (%s)',
            $schema ? $this->wrapValue($schema).'.' : '',
            $this->wrap($command->index),
            $this->wrapTable($table),
            $this->columnize($command->columns)
        );
    }

    /**
     * Compile a spatial index key command.
     *
     * @throws \RuntimeException
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): void
    {
        throw new RuntimeException('The database driver in use does not support spatial indexes.');
    }

    /**
     * Compile a foreign key command.
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table creation or alteration...
        return null;
    }

    /**
     * Compile a drop table command.
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists '.$this->wrapTable($blueprint);
    }

    /**
     * Compile the SQL needed to drop all tables.
     */
    public function compileDropAllTables(?string $schema = null): string
    {
        return sprintf("delete from %s.sqlite_master where type in ('table', 'index', 'trigger')",
            $this->wrapValue($schema ?? 'main')
        );
    }

    /**
     * Compile the SQL needed to drop all views.
     */
    public function compileDropAllViews(?string $schema = null): string
    {
        return sprintf("delete from %s.sqlite_master where type in ('view')",
            $this->wrapValue($schema ?? 'main')
        );
    }

    /**
     * Compile the SQL needed to rebuild the database.
     */
    public function compileRebuild(?string $schema = null): string
    {
        return sprintf('vacuum %s',
            $this->wrapValue($schema ?? 'main')
        );
    }

    /**
     * Compile a drop column command.
     *
     * @return list<string>|null
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): ?array
    {
        if (version_compare($this->connection->getServerVersion(), '3.35', '<')) {
            // Handled on table alteration...

            return null;
        }

        $table = $this->wrapTable($blueprint);

        $columns = $this->prefixArray('drop column', $this->wrapArray($command->columns));

        return (new Collection($columns))->map(fn ($column) => 'alter table '.$table.' '.$column)->all();
    }

    /**
     * Compile a drop primary key command.
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): ?string
    {
        // Handled on table alteration...
        return null;
    }

    /**
     * Compile a drop unique key command.
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile a drop index command.
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        [$schema] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($blueprint->getTable());

        return sprintf('drop index %s%s',
            $schema ? $this->wrapValue($schema).'.' : '',
            $this->wrap($command->index)
        );
    }

    /**
     * Compile a drop spatial index command.
     *
     * @throws \RuntimeException
     */
    public function compileDropSpatialIndex(Blueprint $blueprint, Fluent $command): void
    {
        throw new RuntimeException('The database driver in use does not support spatial indexes.');
    }

    /**
     * Compile a drop foreign key command.
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): ?array
    {
        if (empty($command->columns)) {
            throw new RuntimeException('This database driver does not support dropping foreign keys by name.');
        }

        // Handled on table alteration...
        return null;
    }

    /**
     * Compile a rename table command.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "alter table {$from} rename to ".$this->wrapTable($command->to);
    }

    /**
     * Compile a rename index command.
     *
     * @throws \RuntimeException
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): array
    {
        $indexes = $this->connection->getSchemaBuilder()->getIndexes($blueprint->getTable());

        $index = Arr::first($indexes, fn ($index) => $index['name'] === $command->from);

        if (! $index) {
            throw new RuntimeException("Index [{$command->from}] does not exist.");
        }

        if ($index['primary']) {
            throw new RuntimeException('SQLite does not support altering primary keys.');
        }

        if ($index['unique']) {
            return [
                $this->compileDropUnique($blueprint, new IndexDefinition(['index' => $index['name']])),
                $this->compileUnique($blueprint,
                    new IndexDefinition(['index' => $command->to, 'columns' => $index['columns']])
                ),
            ];
        }

        return [
            $this->compileDropIndex($blueprint, new IndexDefinition(['index' => $index['name']])),
            $this->compileIndex($blueprint,
                new IndexDefinition(['index' => $command->to, 'columns' => $index['columns']])
            ),
        ];
    }

    /**
     * Compile the command to enable foreign key constraints.
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return $this->pragma('foreign_keys', 1);
    }

    /**
     * Compile the command to disable foreign key constraints.
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return $this->pragma('foreign_keys', 0);
    }

    /**
     * Get the SQL to get or set a PRAGMA value.
     */
    public function pragma(string $key, mixed $value = null): string
    {
        return sprintf('pragma %s%s',
            $key,
            is_null($value) ? '' : ' = '.$value
        );
    }

    /**
     * Create the column definition for a char type.
     */
    protected function typeChar(Fluent $column): string
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a string type.
     */
    protected function typeString(Fluent $column): string
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a tiny text type.
     */
    protected function typeTinyText(Fluent $column): string
    {
        return 'text';
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
        return 'integer';
    }

    /**
     * Create the column definition for a big integer type.
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for a medium integer type.
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for a tiny integer type.
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for a small integer type.
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for a float type.
     */
    protected function typeFloat(Fluent $column): string
    {
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
        return 'numeric';
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
        return sprintf(
            'varchar check ("%s" in (%s))',
            $column->name,
            $this->quoteString($column->allowed)
        );
    }

    /**
     * Create the column definition for a json type.
     */
    protected function typeJson(Fluent $column): string
    {
        return $this->connection->getConfig('use_native_json') ? 'json' : 'text';
    }

    /**
     * Create the column definition for a jsonb type.
     */
    protected function typeJsonb(Fluent $column): string
    {
        return $this->connection->getConfig('use_native_jsonb') ? 'jsonb' : 'text';
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
     *
     * Note: "SQLite does not have a storage class set aside for storing dates and/or times."
     *
     * @link https://www.sqlite.org/datatype3.html
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
        return 'time';
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
        if ($column->useCurrent) {
            $column->default(new Expression('CURRENT_TIMESTAMP'));
        }

        return 'datetime';
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
        if ($column->useCurrent) {
            $column->default(new Expression("(CAST(strftime('%Y', 'now') AS INTEGER))"));
        }

        return $this->typeInteger($column);
    }

    /**
     * Create the column definition for a binary type.
     */
    protected function typeBinary(Fluent $column): string
    {
        return 'blob';
    }

    /**
     * Create the column definition for a uuid type.
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'varchar';
    }

    /**
     * Create the column definition for an IP address type.
     */
    protected function typeIpAddress(Fluent $column): string
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a MAC address type.
     */
    protected function typeMacAddress(Fluent $column): string
    {
        return 'varchar';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     */
    protected function typeGeometry(Fluent $column): string
    {
        return 'geometry';
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
     * @throws \RuntimeException
     */
    protected function typeComputed(Fluent $column): void
    {
        throw new RuntimeException('This database driver requires a type, see the virtualAs / storedAs modifiers.');
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
            return " as ({$this->getValue($column->storedAs)}) stored";
        }

        return null;
    }

    /**
     * Get the SQL for a nullable column modifier.
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        if (is_null($column->virtualAs) &&
            is_null($column->virtualAsJson) &&
            is_null($column->storedAs) &&
            is_null($column->storedAsJson)) {
            return $column->nullable ? '' : ' not null';
        }

        if ($column->nullable === false) {
            return ' not null';
        }

        return null;
    }

    /**
     * Get the SQL for a default column modifier.
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->default) && is_null($column->virtualAs) && is_null($column->virtualAsJson) && is_null($column->storedAs)) {
            return ' default '.$this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return ' primary key autoincrement';
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
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract('.$field.$path.')';
    }
}
