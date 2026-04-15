<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static void defaultStringLength(int $length)
 * @method static void defaultTimePrecision(int|null $precision)
 * @method static void defaultMorphKeyType(string $type)
 * @method static void flushState()
 * @method static void morphUsingUuids()
 * @method static void morphUsingUlids()
 * @method static bool createDatabase(string $name)
 * @method static bool dropDatabaseIfExists(string $name)
 * @method static array getSchemas()
 * @method static bool hasTable(string $table)
 * @method static bool hasView(string $view)
 * @method static array getTables(null|string|string[] $schema = null)
 * @method static array getTableListing(array|string|null $schema = null, bool $schemaQualified = true)
 * @method static array getViews(array|string|null $schema = null)
 * @method static array getTypes(array|string|null $schema = null)
 * @method static bool hasColumn(string $table, string $column)
 * @method static bool hasColumns(string $table, array<string> $columns)
 * @method static void whenTableHasColumn(string $table, string $column, \Closure $callback)
 * @method static void whenTableDoesntHaveColumn(string $table, string $column, \Closure $callback)
 * @method static void whenTableHasIndex(string $table, array|string $index, \Closure $callback, string|null $type = null)
 * @method static void whenTableDoesntHaveIndex(string $table, array|string $index, \Closure $callback, string|null $type = null)
 * @method static string getColumnType(string $table, string $column, bool $fullDefinition = false)
 * @method static array getColumnListing(string $table)
 * @method static array getColumns(string $table)
 * @method static array getIndexes(string $table)
 * @method static array getIndexListing(string $table)
 * @method static bool hasIndex(string $table, array|string $index, string|null $type = null)
 * @method static array getForeignKeys(string $table)
 * @method static void table(string $table, \Closure $callback)
 * @method static void create(string $table, \Closure $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void dropColumns(string $table, array<string>|string $columns)
 * @method static void dropAllTables()
 * @method static void dropAllViews()
 * @method static void dropAllTypes()
 * @method static void rename(string $from, string $to)
 * @method static bool enableForeignKeyConstraints()
 * @method static bool disableForeignKeyConstraints()
 * @method static mixed withoutForeignKeyConstraints(\Closure $callback)
 * @method static void ensureVectorExtensionExists(string|null $schema = null)
 * @method static void ensureExtensionExists(string $name, string|null $schema = null)
 * @method static null|string[] getCurrentSchemaListing()
 * @method static string|null getCurrentSchemaName()
 * @method static array parseSchemaAndTable(string $reference, string|bool|null $withDefaultSchema = null)
 * @method static \Hypervel\Database\Connection getConnection()
 * @method static void blueprintResolver(\Closure $resolver)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Database\Schema\Builder
 */
class Schema extends Facade
{
    /**
     * Indicates if the resolved facade should be cached.
     */
    protected static bool $cached = false;

    /**
     * Get a schema builder instance for a connection.
     */
    public static function connection(?string $name = null): \Hypervel\Database\Schema\Builder
    {
        return static::$app['db']->connection($name)->getSchemaBuilder();
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'db.schema';
    }
}
