<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use BadMethodCallException;
use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Grammars\Grammar;
use Hypervel\Database\Schema\Grammars\MySqlGrammar;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use Hypervel\Support\Traits\Macroable;

use function Hypervel\Support\enum_value;

class Blueprint
{
    use Macroable;

    /**
     * The database connection instance.
     */
    protected Connection $connection;

    /**
     * The schema grammar instance.
     */
    protected Grammar $grammar;

    /**
     * The table the blueprint describes.
     */
    protected string $table;

    /**
     * The columns that should be added to the table.
     *
     * @var \Hypervel\Database\Schema\ColumnDefinition[]
     */
    protected array $columns = [];

    /**
     * The commands that should be run for the table.
     *
     * @var \Hypervel\Support\Fluent[]
     */
    protected array $commands = [];

    /**
     * The storage engine that should be used for the table.
     */
    public ?string $engine = null;

    /**
     * The default character set that should be used for the table.
     */
    public ?string $charset = null;

    /**
     * The collation that should be used for the table.
     */
    public ?string $collation = null;

    /**
     * Whether to make the table temporary.
     */
    public bool $temporary = false;

    /**
     * The column to add new columns after.
     */
    public ?string $after = null;

    /**
     * The blueprint state instance.
     */
    protected ?BlueprintState $state = null;

    /**
     * Create a new schema blueprint.
     */
    public function __construct(Connection $connection, string $table, ?Closure $callback = null)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getSchemaGrammar();
        $this->table = $table;

        if (! is_null($callback)) {
            $callback($this);
        }
    }

    /**
     * Execute the blueprint against the database.
     */
    public function build(): void
    {
        foreach ($this->toSql() as $statement) {
            $this->connection->statement($statement);
        }
    }

    /**
     * Get the raw SQL statements for the blueprint.
     */
    public function toSql(): array
    {
        $this->addImpliedCommands();

        $statements = [];

        // Each type of command has a corresponding compiler function on the schema
        // grammar which is used to build the necessary SQL statements to build
        // the blueprint element, so we'll just call that compilers function.
        $this->ensureCommandsAreValid();

        foreach ($this->commands as $command) {
            if ($command->shouldBeSkipped) {
                continue;
            }

            $method = 'compile' . ucfirst($command->name);

            if (method_exists($this->grammar, $method) || $this->grammar::hasMacro($method)) {
                if ($this->hasState()) {
                    $this->state->update($command);
                }

                if (! is_null($sql = $this->grammar->{$method}($this, $command))) {
                    $statements = array_merge($statements, (array) $sql);
                }
            }
        }

        return $statements;
    }

    /**
     * Ensure the commands on the blueprint are valid for the connection type.
     *
     * @throws BadMethodCallException
     */
    protected function ensureCommandsAreValid(): void
    {
    }

    /**
     * Get all of the commands matching the given names.
     *
     * @deprecated will be removed in a future Laravel version
     */
    protected function commandsNamed(array $names): Collection
    {
        return (new Collection($this->commands))
            ->filter(fn ($command) => in_array($command->name, $names));
    }

    /**
     * Add the commands that are implied by the blueprint's state.
     */
    protected function addImpliedCommands(): void
    {
        $this->addFluentIndexes();
        $this->addFluentCommands();

        if (! $this->creating()) {
            $this->commands = array_map(
                fn ($command) => $command instanceof ColumnDefinition
                    ? $this->createCommand($command->change ? 'change' : 'add', ['column' => $command])
                    : $command,
                $this->commands
            );

            $this->addAlterCommands();
        }
    }

    /**
     * Add the index commands fluently specified on columns.
     */
    protected function addFluentIndexes(): void
    {
        foreach ($this->columns as $column) {
            foreach (['primary', 'unique', 'index', 'fulltext', 'fullText', 'spatialIndex', 'vectorIndex'] as $index) {
                // If the column is supposed to be changed to an auto increment column and
                // the specified index is primary, there is no need to add a command on
                // MySQL, as it will be handled during the column definition instead.
                if ($index === 'primary' && $column->autoIncrement && $column->change && $this->grammar instanceof MySqlGrammar) {
                    continue 2;
                }

                // If the index has been specified on the given column, but is simply equal
                // to "true" (boolean), no name has been specified for this index so the
                // index method can be called without a name and it will generate one.
                if ($column->{$index} === true) {
                    $indexMethod = $index === 'index' && $column->type === 'vector'
                        ? 'vectorIndex'
                        : $index;

                    $this->{$indexMethod}($column->name);
                    $column->{$index} = null;

                    continue 2;
                }

                // If the index has been specified on the given column, but it equals false
                // and the column is supposed to be changed, we will call the drop index
                // method with an array of column to drop it by its conventional name.
                if ($column->{$index} === false && $column->change) {
                    $this->{'drop' . ucfirst($index)}([$column->name]);
                    $column->{$index} = null;

                    continue 2;
                }

                // If the index has been specified on the given column, and it has a string
                // value, we'll go ahead and call the index method and pass the name for
                // the index since the developer specified the explicit name for this.
                if (isset($column->{$index})) {
                    $indexMethod = $index === 'index' && $column->type === 'vector'
                        ? 'vectorIndex'
                        : $index;

                    $this->{$indexMethod}($column->name, $column->{$index});
                    $column->{$index} = null;

                    continue 2;
                }
            }
        }
    }

    /**
     * Add the fluent commands specified on any columns.
     */
    public function addFluentCommands(): void
    {
        foreach ($this->columns as $column) {
            foreach ($this->grammar->getFluentCommands() as $commandName) {
                $this->addCommand($commandName, compact('column'));
            }
        }
    }

    /**
     * Add the alter commands if whenever needed.
     */
    public function addAlterCommands(): void
    {
        if (! $this->grammar instanceof SQLiteGrammar) {
            return;
        }

        $alterCommands = $this->grammar->getAlterCommands();

        [$commands, $lastCommandWasAlter, $hasAlterCommand] = [
            [], false, false,
        ];

        foreach ($this->commands as $command) {
            if (in_array($command->name, $alterCommands)) {
                $hasAlterCommand = true;
                $lastCommandWasAlter = true;
            } elseif ($lastCommandWasAlter) {
                $commands[] = $this->createCommand('alter');
                $lastCommandWasAlter = false;
            }

            $commands[] = $command;
        }

        if ($lastCommandWasAlter) {
            $commands[] = $this->createCommand('alter');
        }

        if ($hasAlterCommand) {
            $this->state = new BlueprintState($this, $this->connection);
        }

        $this->commands = $commands;
    }

    /**
     * Determine if the blueprint has a create command.
     */
    public function creating(): bool
    {
        return (new Collection($this->commands))
            ->contains(fn ($command) => ! $command instanceof ColumnDefinition && $command->name === 'create');
    }

    /**
     * Indicate that the table needs to be created.
     */
    public function create(): Fluent
    {
        return $this->addCommand('create');
    }

    /**
     * Specify the storage engine that should be used for the table.
     */
    public function engine(string $engine): void
    {
        $this->engine = $engine;
    }

    /**
     * Specify that the InnoDB storage engine should be used for the table (MySQL only).
     */
    public function innoDb(): void
    {
        $this->engine('InnoDB');
    }

    /**
     * Specify the character set that should be used for the table.
     */
    public function charset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * Specify the collation that should be used for the table.
     */
    public function collation(string $collation): void
    {
        $this->collation = $collation;
    }

    /**
     * Indicate that the table needs to be temporary.
     */
    public function temporary(): void
    {
        $this->temporary = true;
    }

    /**
     * Indicate that the table should be dropped.
     */
    public function drop(): Fluent
    {
        return $this->addCommand('drop');
    }

    /**
     * Indicate that the table should be dropped if it exists.
     */
    public function dropIfExists(): Fluent
    {
        return $this->addCommand('dropIfExists');
    }

    /**
     * Indicate that the given columns should be dropped.
     */
    public function dropColumn(array|string $columns): Fluent
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        return $this->addCommand('dropColumn', compact('columns'));
    }

    /**
     * Indicate that the given columns should be renamed.
     */
    public function renameColumn(string $from, string $to): Fluent
    {
        return $this->addCommand('renameColumn', compact('from', 'to'));
    }

    /**
     * Indicate that the given primary key should be dropped.
     */
    public function dropPrimary(array|string|null $index = null): Fluent
    {
        return $this->dropIndexCommand('dropPrimary', 'primary', $index);
    }

    /**
     * Indicate that the given unique key should be dropped.
     */
    public function dropUnique(array|string $index): Fluent
    {
        return $this->dropIndexCommand('dropUnique', 'unique', $index);
    }

    /**
     * Indicate that the given index should be dropped.
     */
    public function dropIndex(array|string $index): Fluent
    {
        return $this->dropIndexCommand('dropIndex', 'index', $index);
    }

    /**
     * Indicate that the given fulltext index should be dropped.
     */
    public function dropFullText(array|string $index): Fluent
    {
        return $this->dropIndexCommand('dropFullText', 'fulltext', $index);
    }

    /**
     * Indicate that the given spatial index should be dropped.
     */
    public function dropSpatialIndex(array|string $index): Fluent
    {
        return $this->dropIndexCommand('dropSpatialIndex', 'spatialIndex', $index);
    }

    /**
     * Indicate that the given vector index should be dropped.
     */
    public function dropVectorIndex(array|string $index): Fluent
    {
        return $this->dropIndexCommand('dropVectorIndex', 'vectorIndex', $index);
    }

    /**
     * Indicate that the given foreign key should be dropped.
     */
    public function dropForeign(array|string $index): Fluent
    {
        return $this->dropIndexCommand('dropForeign', 'foreign', $index);
    }

    /**
     * Indicate that the given column and foreign key should be dropped.
     */
    public function dropConstrainedForeignId(string $column): Fluent
    {
        $this->dropForeign([$column]);

        return $this->dropColumn($column);
    }

    /**
     * Indicate that the given foreign key should be dropped.
     */
    public function dropForeignIdFor(object|string $model, ?string $column = null): Fluent
    {
        if (is_string($model)) {
            $model = new $model();
        }

        return $this->dropColumn($column ?: $model->getForeignKey());
    }

    /**
     * Indicate that the given foreign key should be dropped.
     */
    public function dropConstrainedForeignIdFor(object|string $model, ?string $column = null): Fluent
    {
        if (is_string($model)) {
            $model = new $model();
        }

        return $this->dropConstrainedForeignId($column ?: $model->getForeignKey());
    }

    /**
     * Indicate that the given indexes should be renamed.
     */
    public function renameIndex(string $from, string $to): Fluent
    {
        return $this->addCommand('renameIndex', compact('from', 'to'));
    }

    /**
     * Indicate that the timestamp columns should be dropped.
     */
    public function dropTimestamps(): void
    {
        $this->dropColumn('created_at', 'updated_at');
    }

    /**
     * Indicate that the timestamp columns should be dropped.
     */
    public function dropTimestampsTz(): void
    {
        $this->dropTimestamps();
    }

    /**
     * Indicate that the soft delete column should be dropped.
     */
    public function dropSoftDeletes(string $column = 'deleted_at'): void
    {
        $this->dropColumn($column);
    }

    /**
     * Indicate that the soft delete column should be dropped.
     */
    public function dropSoftDeletesTz(string $column = 'deleted_at'): void
    {
        $this->dropSoftDeletes($column);
    }

    /**
     * Indicate that the remember token column should be dropped.
     */
    public function dropRememberToken(): void
    {
        $this->dropColumn('remember_token');
    }

    /**
     * Indicate that the polymorphic columns should be dropped.
     */
    public function dropMorphs(string $name, ?string $indexName = null): void
    {
        $this->dropIndex($indexName ?: $this->createIndexName('index', ["{$name}_type", "{$name}_id"]));

        $this->dropColumn("{$name}_type", "{$name}_id");
    }

    /**
     * Rename the table to a given name.
     */
    public function rename(string $to): Fluent
    {
        return $this->addCommand('rename', compact('to'));
    }

    /**
     * Specify the primary key(s) for the table.
     */
    public function primary(array|string $columns, ?string $name = null, ?string $algorithm = null): Fluent
    {
        return $this->indexCommand('primary', $columns, $name, $algorithm);
    }

    /**
     * Specify a unique index for the table.
     */
    public function unique(array|string $columns, ?string $name = null, ?string $algorithm = null): Fluent
    {
        return $this->indexCommand('unique', $columns, $name, $algorithm);
    }

    /**
     * Specify an index for the table.
     */
    public function index(array|string $columns, ?string $name = null, ?string $algorithm = null): Fluent
    {
        return $this->indexCommand('index', $columns, $name, $algorithm);
    }

    /**
     * Specify a fulltext index for the table.
     */
    public function fullText(array|string $columns, ?string $name = null, ?string $algorithm = null): Fluent
    {
        return $this->indexCommand('fulltext', $columns, $name, $algorithm);
    }

    /**
     * Specify a spatial index for the table.
     */
    public function spatialIndex(array|string $columns, ?string $name = null, ?string $operatorClass = null): Fluent
    {
        return $this->indexCommand('spatialIndex', $columns, $name, null, $operatorClass);
    }

    /**
     * Specify a vector index for the table.
     */
    public function vectorIndex(string $column, ?string $name = null): Fluent
    {
        return $this->indexCommand('vectorIndex', $column, $name, 'hnsw', 'vector_cosine_ops');
    }

    /**
     * Specify a raw index for the table.
     */
    public function rawIndex(string $expression, string $name): Fluent
    {
        return $this->index([new Expression($expression)], $name);
    }

    /**
     * Specify a foreign key for the table.
     */
    public function foreign(array|string $columns, ?string $name = null): ForeignKeyDefinition
    {
        $command = new ForeignKeyDefinition(
            $this->indexCommand('foreign', $columns, $name)->getAttributes()
        );

        $this->commands[count($this->commands) - 1] = $command;

        return $command;
    }

    /**
     * Create a new auto-incrementing big integer column on the table (8-byte, 0 to 18,446,744,073,709,551,615).
     */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($column);
    }

    /**
     * Create a new auto-incrementing integer column on the table (4-byte, 0 to 4,294,967,295).
     */
    public function increments(string $column): ColumnDefinition
    {
        return $this->unsignedInteger($column, true);
    }

    /**
     * Create a new auto-incrementing integer column on the table (4-byte, 0 to 4,294,967,295).
     */
    public function integerIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedInteger($column, true);
    }

    /**
     * Create a new auto-incrementing tiny integer column on the table (1-byte, 0 to 255).
     */
    public function tinyIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedTinyInteger($column, true);
    }

    /**
     * Create a new auto-incrementing small integer column on the table (2-byte, 0 to 65,535).
     */
    public function smallIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedSmallInteger($column, true);
    }

    /**
     * Create a new auto-incrementing medium integer column on the table (3-byte, 0 to 16,777,215).
     */
    public function mediumIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedMediumInteger($column, true);
    }

    /**
     * Create a new auto-incrementing big integer column on the table (8-byte, 0 to 18,446,744,073,709,551,615).
     */
    public function bigIncrements(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column, true);
    }

    /**
     * Create a new char column on the table.
     */
    public function char(string $column, ?int $length = null): ColumnDefinition
    {
        $length = ! is_null($length) ? $length : Builder::$defaultStringLength;

        return $this->addColumn('char', $column, compact('length'));
    }

    /**
     * Create a new string column on the table.
     */
    public function string(string $column, ?int $length = null): ColumnDefinition
    {
        $length = $length ?: Builder::$defaultStringLength;

        return $this->addColumn('string', $column, compact('length'));
    }

    /**
     * Create a new tiny text column on the table (up to 255 characters).
     */
    public function tinyText(string $column): ColumnDefinition
    {
        return $this->addColumn('tinyText', $column);
    }

    /**
     * Create a new text column on the table (up to 65,535 characters / ~64 KB).
     */
    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Create a new medium text column on the table (up to 16,777,215 characters / ~16 MB).
     */
    public function mediumText(string $column): ColumnDefinition
    {
        return $this->addColumn('mediumText', $column);
    }

    /**
     * Create a new long text column on the table (up to 4,294,967,295 characters / ~4 GB).
     */
    public function longText(string $column): ColumnDefinition
    {
        return $this->addColumn('longText', $column);
    }

    /**
     * Create a new integer (4-byte) column on the table.
     * Range: -2,147,483,648 to 2,147,483,647 (signed) or 0 to 4,294,967,295 (unsigned).
     */
    public function integer(string $column, bool $autoIncrement = false, bool $unsigned = false): ColumnDefinition
    {
        return $this->addColumn('integer', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new tiny integer (1-byte) column on the table.
     * Range: -128 to 127 (signed) or 0 to 255 (unsigned).
     */
    public function tinyInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new small integer (2-byte) column on the table.
     * Range: -32,768 to 32,767 (signed) or 0 to 65,535 (unsigned).
     */
    public function smallInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new medium integer (3-byte) column on the table.
     * Range: -8,388,608 to 8,388,607 (signed) or 0 to 16,777,215 (unsigned).
     */
    public function mediumInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): ColumnDefinition
    {
        return $this->addColumn('mediumInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new big integer (8-byte) column on the table.
     * Range: -9,223,372,036,854,775,808 to 9,223,372,036,854,775,807 (signed) or 0 to 18,446,744,073,709,551,615 (unsigned).
     */
    public function bigInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new unsigned integer column on the table (4-byte, 0 to 4,294,967,295).
     */
    public function unsignedInteger(string $column, bool $autoIncrement = false): ColumnDefinition
    {
        return $this->integer($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned tiny integer column on the table (1-byte, 0 to 255).
     */
    public function unsignedTinyInteger(string $column, bool $autoIncrement = false): ColumnDefinition
    {
        return $this->tinyInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned small integer column on the table (2-byte, 0 to 65,535).
     */
    public function unsignedSmallInteger(string $column, bool $autoIncrement = false): ColumnDefinition
    {
        return $this->smallInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned medium integer column on the table (3-byte, 0 to 16,777,215).
     */
    public function unsignedMediumInteger(string $column, bool $autoIncrement = false): ColumnDefinition
    {
        return $this->mediumInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned big integer column on the table (8-byte, 0 to 18,446,744,073,709,551,615).
     */
    public function unsignedBigInteger(string $column, bool $autoIncrement = false): ColumnDefinition
    {
        return $this->bigInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned big integer column on the table (8-byte, 0 to 18,446,744,073,709,551,615).
     */
    public function foreignId(string $column): ForeignIdColumnDefinition
    {
        return $this->addColumnDefinition(new ForeignIdColumnDefinition($this, [
            'type' => 'bigInteger',
            'name' => $column,
            'autoIncrement' => false,
            'unsigned' => true,
        ]));
    }

    /**
     * Create a foreign ID column for the given model.
     */
    public function foreignIdFor(object|string $model, ?string $column = null): ForeignIdColumnDefinition
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $column = $column ?: $model->getForeignKey();

        if ($model->getKeyType() === 'int') {
            return $this->foreignId($column)
                ->table($model->getTable())
                ->referencesModelColumn($model->getKeyName());
        }

        $modelTraits = class_uses_recursive($model);

        if (in_array(HasUlids::class, $modelTraits, true)) {
            return $this->foreignUlid($column, 26)
                ->table($model->getTable())
                ->referencesModelColumn($model->getKeyName());
        }

        return $this->foreignUuid($column)
            ->table($model->getTable())
            ->referencesModelColumn($model->getKeyName());
    }

    /**
     * Create a new float column on the table.
     */
    public function float(string $column, int $precision = 53): ColumnDefinition
    {
        return $this->addColumn('float', $column, compact('precision'));
    }

    /**
     * Create a new double column on the table.
     */
    public function double(string $column): ColumnDefinition
    {
        return $this->addColumn('double', $column);
    }

    /**
     * Create a new decimal column on the table.
     */
    public function decimal(string $column, int $total = 8, int $places = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $column, compact('total', 'places'));
    }

    /**
     * Create a new boolean column on the table.
     */
    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn('boolean', $column);
    }

    /**
     * Create a new enum column on the table.
     */
    public function enum(string $column, array $allowed): ColumnDefinition
    {
        $allowed = array_map(fn ($value) => enum_value($value), $allowed);

        return $this->addColumn('enum', $column, compact('allowed'));
    }

    /**
     * Create a new set column on the table.
     */
    public function set(string $column, array $allowed): ColumnDefinition
    {
        return $this->addColumn('set', $column, compact('allowed'));
    }

    /**
     * Create a new json column on the table.
     */
    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn('json', $column);
    }

    /**
     * Create a new jsonb column on the table.
     */
    public function jsonb(string $column): ColumnDefinition
    {
        return $this->addColumn('jsonb', $column);
    }

    /**
     * Create a new date column on the table.
     */
    public function date(string $column): ColumnDefinition
    {
        return $this->addColumn('date', $column);
    }

    /**
     * Create a new date-time column on the table.
     */
    public function dateTime(string $column, ?int $precision = null): ColumnDefinition
    {
        $precision ??= $this->defaultTimePrecision();

        return $this->addColumn('dateTime', $column, compact('precision'));
    }

    /**
     * Create a new date-time column (with time zone) on the table.
     */
    public function dateTimeTz(string $column, ?int $precision = null): ColumnDefinition
    {
        $precision ??= $this->defaultTimePrecision();

        return $this->addColumn('dateTimeTz', $column, compact('precision'));
    }

    /**
     * Create a new time column on the table.
     */
    public function time(string $column, ?int $precision = null): ColumnDefinition
    {
        $precision ??= $this->defaultTimePrecision();

        return $this->addColumn('time', $column, compact('precision'));
    }

    /**
     * Create a new time column (with time zone) on the table.
     */
    public function timeTz(string $column, ?int $precision = null): ColumnDefinition
    {
        $precision ??= $this->defaultTimePrecision();

        return $this->addColumn('timeTz', $column, compact('precision'));
    }

    /**
     * Create a new timestamp column on the table.
     */
    public function timestamp(string $column, ?int $precision = null): ColumnDefinition
    {
        $precision ??= $this->defaultTimePrecision();

        return $this->addColumn('timestamp', $column, compact('precision'));
    }

    /**
     * Create a new timestamp (with time zone) column on the table.
     */
    public function timestampTz(string $column, ?int $precision = null): ColumnDefinition
    {
        $precision ??= $this->defaultTimePrecision();

        return $this->addColumn('timestampTz', $column, compact('precision'));
    }

    /**
     * Add nullable creation and update timestamps to the table.
     *
     * @return \Hypervel\Support\Collection<int, \Hypervel\Database\Schema\ColumnDefinition>
     */
    public function timestamps(?int $precision = null): Collection
    {
        return new Collection([
            $this->timestamp('created_at', $precision)->nullable(),
            $this->timestamp('updated_at', $precision)->nullable(),
        ]);
    }

    /**
     * Add nullable creation and update timestamps to the table.
     *
     * Alias for self::timestamps().
     *
     * @return \Hypervel\Support\Collection<int, \Hypervel\Database\Schema\ColumnDefinition>
     */
    public function nullableTimestamps(?int $precision = null): Collection
    {
        return $this->timestamps($precision);
    }

    /**
     * Add nullable creation and update timestampTz columns to the table.
     *
     * @return \Hypervel\Support\Collection<int, \Hypervel\Database\Schema\ColumnDefinition>
     */
    public function timestampsTz(?int $precision = null): Collection
    {
        return new Collection([
            $this->timestampTz('created_at', $precision)->nullable(),
            $this->timestampTz('updated_at', $precision)->nullable(),
        ]);
    }

    /**
     * Add nullable creation and update timestampTz columns to the table.
     *
     * Alias for self::timestampsTz().
     *
     * @return \Hypervel\Support\Collection<int, \Hypervel\Database\Schema\ColumnDefinition>
     */
    public function nullableTimestampsTz(?int $precision = null): Collection
    {
        return $this->timestampsTz($precision);
    }

    /**
     * Add creation and update datetime columns to the table.
     *
     * @return \Hypervel\Support\Collection<int, \Hypervel\Database\Schema\ColumnDefinition>
     */
    public function datetimes(?int $precision = null): Collection
    {
        return new Collection([
            $this->datetime('created_at', $precision)->nullable(),
            $this->datetime('updated_at', $precision)->nullable(),
        ]);
    }

    /**
     * Add a "deleted at" timestamp for the table.
     */
    public function softDeletes(string $column = 'deleted_at', ?int $precision = null): ColumnDefinition
    {
        return $this->timestamp($column, $precision)->nullable();
    }

    /**
     * Add a "deleted at" timestampTz for the table.
     */
    public function softDeletesTz(string $column = 'deleted_at', ?int $precision = null): ColumnDefinition
    {
        return $this->timestampTz($column, $precision)->nullable();
    }

    /**
     * Add a "deleted at" datetime column to the table.
     */
    public function softDeletesDatetime(string $column = 'deleted_at', ?int $precision = null): ColumnDefinition
    {
        return $this->datetime($column, $precision)->nullable();
    }

    /**
     * Create a new year column on the table.
     */
    public function year(string $column): ColumnDefinition
    {
        return $this->addColumn('year', $column);
    }

    /**
     * Create a new binary column on the table.
     */
    public function binary(string $column, ?int $length = null, bool $fixed = false): ColumnDefinition
    {
        return $this->addColumn('binary', $column, compact('length', 'fixed'));
    }

    /**
     * Create a new UUID column on the table.
     */
    public function uuid(string $column = 'uuid'): ColumnDefinition
    {
        return $this->addColumn('uuid', $column);
    }

    /**
     * Create a new UUID column on the table with a foreign key constraint.
     */
    public function foreignUuid(string $column): ForeignIdColumnDefinition
    {
        return $this->addColumnDefinition(new ForeignIdColumnDefinition($this, [
            'type' => 'uuid',
            'name' => $column,
        ]));
    }

    /**
     * Create a new ULID column on the table.
     */
    public function ulid(string $column = 'ulid', ?int $length = 26): ColumnDefinition
    {
        return $this->char($column, $length);
    }

    /**
     * Create a new ULID column on the table with a foreign key constraint.
     */
    public function foreignUlid(string $column, ?int $length = 26): ForeignIdColumnDefinition
    {
        return $this->addColumnDefinition(new ForeignIdColumnDefinition($this, [
            'type' => 'char',
            'name' => $column,
            'length' => $length,
        ]));
    }

    /**
     * Create a new IP address column on the table.
     */
    public function ipAddress(string $column = 'ip_address'): ColumnDefinition
    {
        return $this->addColumn('ipAddress', $column);
    }

    /**
     * Create a new MAC address column on the table.
     */
    public function macAddress(string $column = 'mac_address'): ColumnDefinition
    {
        return $this->addColumn('macAddress', $column);
    }

    /**
     * Create a new geometry column on the table.
     */
    public function geometry(string $column, ?string $subtype = null, int $srid = 0): ColumnDefinition
    {
        return $this->addColumn('geometry', $column, compact('subtype', 'srid'));
    }

    /**
     * Create a new geography column on the table.
     */
    public function geography(string $column, ?string $subtype = null, int $srid = 4326): ColumnDefinition
    {
        return $this->addColumn('geography', $column, compact('subtype', 'srid'));
    }

    /**
     * Create a new generated, computed column on the table.
     */
    public function computed(string $column, string $expression): ColumnDefinition
    {
        return $this->addColumn('computed', $column, compact('expression'));
    }

    /**
     * Create a new vector column on the table.
     */
    public function vector(string $column, ?int $dimensions = null): ColumnDefinition
    {
        $options = $dimensions ? compact('dimensions') : [];

        return $this->addColumn('vector', $column, $options);
    }

    /**
     * Add the proper columns for a polymorphic table.
     */
    public function morphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        if (Builder::$defaultMorphKeyType === 'uuid') {
            $this->uuidMorphs($name, $indexName, $after);
        } elseif (Builder::$defaultMorphKeyType === 'ulid') {
            $this->ulidMorphs($name, $indexName, $after);
        } else {
            $this->numericMorphs($name, $indexName, $after);
        }
    }

    /**
     * Add nullable columns for a polymorphic table.
     */
    public function nullableMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        if (Builder::$defaultMorphKeyType === 'uuid') {
            $this->nullableUuidMorphs($name, $indexName, $after);
        } elseif (Builder::$defaultMorphKeyType === 'ulid') {
            $this->nullableUlidMorphs($name, $indexName, $after);
        } else {
            $this->nullableNumericMorphs($name, $indexName, $after);
        }
    }

    /**
     * Add the proper columns for a polymorphic table using numeric IDs (incremental).
     */
    public function numericMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        $this->string("{$name}_type")
            ->after($after);

        $this->unsignedBigInteger("{$name}_id")
            ->after(! is_null($after) ? "{$name}_type" : null);

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add nullable columns for a polymorphic table using numeric IDs (incremental).
     */
    public function nullableNumericMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        $this->string("{$name}_type")
            ->nullable()
            ->after($after);

        $this->unsignedBigInteger("{$name}_id")
            ->nullable()
            ->after(! is_null($after) ? "{$name}_type" : null);

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add the proper columns for a polymorphic table using UUIDs.
     */
    public function uuidMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        $this->string("{$name}_type")
            ->after($after);

        $this->uuid("{$name}_id")
            ->after(! is_null($after) ? "{$name}_type" : null);

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add nullable columns for a polymorphic table using UUIDs.
     */
    public function nullableUuidMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        $this->string("{$name}_type")
            ->nullable()
            ->after($after);

        $this->uuid("{$name}_id")
            ->nullable()
            ->after(! is_null($after) ? "{$name}_type" : null);

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add the proper columns for a polymorphic table using ULIDs.
     */
    public function ulidMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        $this->string("{$name}_type")
            ->after($after);

        $this->ulid("{$name}_id")
            ->after(! is_null($after) ? "{$name}_type" : null);

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add nullable columns for a polymorphic table using ULIDs.
     */
    public function nullableUlidMorphs(string $name, ?string $indexName = null, ?string $after = null): void
    {
        $this->string("{$name}_type")
            ->nullable()
            ->after($after);

        $this->ulid("{$name}_id")
            ->nullable()
            ->after(! is_null($after) ? "{$name}_type" : null);

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add the `remember_token` column to the table.
     */
    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Create a new custom column on the table.
     */
    public function rawColumn(string $column, string $definition): ColumnDefinition
    {
        return $this->addColumn('raw', $column, compact('definition'));
    }

    /**
     * Add a comment to the table.
     */
    public function comment(string $comment): Fluent
    {
        return $this->addCommand('tableComment', compact('comment'));
    }

    /**
     * Create a new index command on the blueprint.
     */
    protected function indexCommand(string $type, array|string $columns, ?string $index, ?string $algorithm = null, ?string $operatorClass = null): Fluent
    {
        $columns = (array) $columns;

        // If no name was specified for this index, we will create one using a basic
        // convention of the table name, followed by the columns, followed by an
        // index type, such as primary or index, which makes the index unique.
        $index = $index ?: $this->createIndexName($type, $columns);

        return $this->addCommand(
            $type,
            compact('index', 'columns', 'algorithm', 'operatorClass')
        );
    }

    /**
     * Create a new drop index command on the blueprint.
     */
    protected function dropIndexCommand(string $command, string $type, array|string $index): Fluent
    {
        $columns = [];

        // If the given "index" is actually an array of columns, the developer means
        // to drop an index merely by specifying the columns involved without the
        // conventional name, so we will build the index name from the columns.
        if (is_array($index)) {
            $index = $this->createIndexName($type, $columns = $index);
        }

        return $this->indexCommand($command, $columns, $index);
    }

    /**
     * Create a default index name for the table.
     */
    protected function createIndexName(string $type, array $columns): string
    {
        $table = $this->table;

        if ($this->connection->getConfig('prefix_indexes')) {
            $table = str_contains($this->table, '.')
                ? substr_replace($this->table, '.' . $this->connection->getTablePrefix(), strrpos($this->table, '.'), 1)
                : $this->connection->getTablePrefix() . $this->table;
        }

        $index = strtolower($table . '_' . implode('_', $columns) . '_' . $type);

        return str_replace(['-', '.'], '_', $index);
    }

    /**
     * Add a new column to the blueprint.
     */
    public function addColumn(string $type, string $name, array $parameters = []): ColumnDefinition
    {
        return $this->addColumnDefinition(new ColumnDefinition(
            array_merge(compact('type', 'name'), $parameters)
        ));
    }

    /**
     * Add a new column definition to the blueprint.
     *
     * @template TColumnDefinition of \Hypervel\Database\Schema\ColumnDefinition
     *
     * @param TColumnDefinition $definition
     * @return TColumnDefinition
     */
    protected function addColumnDefinition(ColumnDefinition $definition): ColumnDefinition
    {
        $this->columns[] = $definition;

        if (! $this->creating()) {
            $this->commands[] = $definition;
        }

        if ($this->after) {
            $definition->after($this->after);

            // @phpstan-ignore property.notFound (name is a Fluent attribute set when column is created)
            $this->after = $definition->name;
        }

        return $definition;
    }

    /**
     * Add the columns from the callback after the given column.
     */
    public function after(string $column, Closure $callback): void
    {
        $this->after = $column;

        $callback($this);

        $this->after = null;
    }

    /**
     * Remove a column from the schema blueprint.
     */
    public function removeColumn(string $name): static
    {
        $this->columns = array_values(array_filter($this->columns, function ($c) use ($name) {
            return $c['name'] != $name;
        }));

        $this->commands = array_values(array_filter($this->commands, function ($c) use ($name) {
            return ! $c instanceof ColumnDefinition || $c['name'] != $name;
        }));

        return $this;
    }

    /**
     * Add a new command to the blueprint.
     */
    protected function addCommand(string $name, array $parameters = []): Fluent
    {
        $this->commands[] = $command = $this->createCommand($name, $parameters);

        return $command;
    }

    /**
     * Create a new Fluent command.
     */
    protected function createCommand(string $name, array $parameters = []): Fluent
    {
        return new Fluent(array_merge(compact('name'), $parameters));
    }

    /**
     * Get the table the blueprint describes.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the table prefix.
     *
     * @deprecated Use DB::getTablePrefix()
     */
    public function getPrefix(): string
    {
        return $this->connection->getTablePrefix();
    }

    /**
     * Get the columns on the blueprint.
     *
     * @return \Hypervel\Database\Schema\ColumnDefinition[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get the commands on the blueprint.
     *
     * @return \Hypervel\Support\Fluent[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Determine if the blueprint has state.
     */
    private function hasState(): bool
    {
        return ! is_null($this->state);
    }

    /**
     * Get the state of the blueprint.
     */
    public function getState(): ?BlueprintState
    {
        return $this->state;
    }

    /**
     * Get the columns on the blueprint that should be added.
     *
     * @return \Hypervel\Database\Schema\ColumnDefinition[]
     */
    public function getAddedColumns(): array
    {
        return array_filter($this->columns, function ($column) {
            return ! $column->change;
        });
    }

    /**
     * Get the columns on the blueprint that should be changed.
     *
     * @deprecated will be removed in a future Laravel version
     *
     * @return \Hypervel\Database\Schema\ColumnDefinition[]
     */
    public function getChangedColumns(): array
    {
        return array_filter($this->columns, function ($column) {
            return (bool) $column->change;
        });
    }

    /**
     * Get the default time precision.
     */
    protected function defaultTimePrecision(): ?int
    {
        return $this->connection->getSchemaBuilder()::$defaultTimePrecision;
    }
}
