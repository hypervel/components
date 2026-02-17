<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Database\Schema\SchemaProxy;

/**
 * @method static void defaultStringLength(int $length)
 * @method static bool hasTable(string $table)
 * @method static bool hasColumn(string $table, string $column)
 * @method static bool hasColumns(string $table, array $columns)
 * @method static void whenTableHasColumn(string $table, string $column, \Closure $callback)
 * @method static void whenTableDoesntHaveColumn(string $table, string $column, \Closure $callback)
 * @method static array getTables()
 * @method static array getViews()
 * @method static bool hasView(string $view)
 * @method static string getColumnType(string $table, string $column)
 * @method static array getColumnListing(string $table)
 * @method static array getColumns()
 * @method static array getIndexes(string $table)
 * @method static array getIndexListing(string $table)
 * @method static bool hasIndex(string $table, array|string $index, string|null $type = null)
 * @method static void table(string $table, \Closure $callback)
 * @method static void create(string $table, \Closure $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void dropAllTables()
 * @method static void dropAllViews()
 * @method static void rename(string $from, string $to)
 * @method static bool enableForeignKeyConstraints()
 * @method static bool disableForeignKeyConstraints()
 * @method static array getForeignKeys(string $table)
 * @method static \Hypervel\Database\Connection getConnection()
 * @method static \Hypervel\Database\Schema\Builder setConnection(\Hypervel\Database\Connection $connection)
 * @method static void blueprintResolver(\Closure $resolver)
 *
 * @see \Hypervel\Database\Schema\Builder
 */
class Schema extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SchemaProxy::class;
    }
}
