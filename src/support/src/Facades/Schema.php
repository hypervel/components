<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Database\Schema\SchemaProxy;

/**
 * @method static bool hasTable(string $table)
 * @method static bool createDatabase(string $name)
 * @method static bool hasView(string $name)
 * @method static array getViews()
 * @method static bool dropDatabaseIfExists(string $name)
 * @method static array getColumnListing(string $table)
 * @method static array getColumnTypeListing(string $table)
 * @method static void dropAllTables()
 * @method static void dropAllViews()
 * @method static array getAllTables()
 * @method static array getTables()
 * @method static array getAllViews()
 * @method static array getIndexes(string $table)
 * @method static array getIndexListing(string $table)
 * @method static bool hasIndex(string $table, array|string $index, ?string $type = null)
 * @method static bool hasColumn(string $table, string $column)
 * @method static bool hasColumns(string $table, array $columns)
 * @method static string getColumnType(string $table, string $column)
 * @method static void table(string $table, \Closure $callback)
 * @method static void create(string $table, \Closure $callback))
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void rename(string $from, string $to)
 * @method static bool enableForeignKeyConstraints()
 * @method static bool disableForeignKeyConstraints()
 * @method static \Hyperf\Database\Connection getConnection()
 * @method static Builder setConnection(\Hyperf\Database\Connection $connection)
 * @method static void blueprintResolver(\Closure $resolver)
 *
 * @see \Hyperf\Database\Schema\Builder
 */
class Schema extends Facade
{
    protected static function getFacadeAccessor()
    {
        return SchemaProxy::class;
    }
}
