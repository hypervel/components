<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hyperf\DbConnection\Db as HyperfDb;

/**
 * @method static void beforeExecuting(\Closure $closure)
 * @method static \Hyperf\Database\Query\Builder table((\Hyperf\Database\Query\Expression|string) $table)
 * @method static \Hyperf\Database\Query\Expression raw(mixed $value)
 * @method static mixed selectOne(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static array select(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static \Generator cursor(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static bool insert(string $query, array $bindings = [])
 * @method static int update(string $query, array $bindings = [])
 * @method static int delete(string $query, array $bindings = [])
 * @method static bool statement(string $query, array $bindings = [])
 * @method static int affectingStatement(string $query, array $bindings = [])
 * @method static bool unprepared(string $query)
 * @method static array prepareBindings(array $bindings)
 * @method static mixed transaction(\Closure $callback, int $attempts = 1)
 * @method static void beginTransaction()
 * @method static void rollBack()
 * @method static void commit()
 * @method static int transactionLevel()
 * @method static array pretend(\Closure $callback)
 * @method static \Hyperf\Database\ConnectionInterface connection(?string $pool = null)
 *
 * @see \Hyperf\DbConnection\Db
 */
class DB extends Facade
{
    protected static function getFacadeAccessor()
    {
        return HyperfDb::class;
    }
}
