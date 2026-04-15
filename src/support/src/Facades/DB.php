<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Console\WipeCommand;

/**
 * @method static \Hypervel\Database\ConnectionInterface connection(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Database\ConnectionInterface build(array $config)
 * @method static string calculateDynamicConnectionName(array $config)
 * @method static \Hypervel\Database\ConnectionInterface connectUsing(string $name, array $config, bool $force = false)
 * @method static void purge(\UnitEnum|string|null $name = null)
 * @method static void disconnect(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Database\Connection reconnect(\UnitEnum|string|null $name = null)
 * @method static mixed usingConnection(\UnitEnum|string $name, callable $callback)
 * @method static string|null getDefaultConnection()
 * @method static void setDefaultConnection(string|null $name)
 * @method static string[] supportedDrivers()
 * @method static string[] availableDrivers()
 * @method static void extend(string $name, callable $resolver)
 * @method static void forgetExtension(string $name)
 * @method static array getConnections()
 * @method static void purgeConnections()
 * @method static void setReconnector(callable $reconnector)
 * @method static \Hypervel\Database\DatabaseManager setApplication(\Hypervel\Contracts\Foundation\Application $app)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void useDefaultQueryGrammar()
 * @method static void useDefaultSchemaGrammar()
 * @method static void useDefaultPostProcessor()
 * @method static \Hypervel\Database\Schema\Builder getSchemaBuilder()
 * @method static \Hypervel\Database\Schema\SchemaState getSchemaState(\Hypervel\Filesystem\Filesystem|null $files = null, callable|null $processFactory = null)
 * @method static \Hypervel\Database\Query\Builder table(\Closure|\Hypervel\Database\Query\Builder|\UnitEnum|string $table, string|null $as = null)
 * @method static \Hypervel\Database\Query\Builder query()
 * @method static mixed selectOne(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static mixed scalar(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static array selectFromWriteConnection(string $query, array $bindings = [])
 * @method static array select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = [])
 * @method static array selectResultSets(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static \Generator cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = [])
 * @method static bool insert(string $query, array $bindings = [])
 * @method static int update(string $query, array $bindings = [])
 * @method static int delete(string $query, array $bindings = [])
 * @method static bool statement(string $query, array $bindings = [])
 * @method static int affectingStatement(string $query, array $bindings = [])
 * @method static bool unprepared(string $query)
 * @method static int|null threadCount()
 * @method static array[] pretend(\Closure $callback)
 * @method static mixed withoutPretending(\Closure $callback)
 * @method static void bindValues(\PDOStatement $statement, array $bindings)
 * @method static array prepareBindings(array $bindings)
 * @method static void logQuery(string $query, array $bindings, float|null $time = null)
 * @method static void whenQueryingForLongerThan(\DateTimeInterface|\Carbon\CarbonInterval|int|float $threshold, callable $handler)
 * @method static void allowQueryDurationHandlersToRunAgain()
 * @method static float totalQueryDuration()
 * @method static void resetTotalQueryDuration()
 * @method static void reconnectIfMissingConnection()
 * @method static \Hypervel\Database\Connection beforeStartingTransaction(\Closure $callback)
 * @method static \Hypervel\Database\Connection beforeExecuting(\Closure $callback)
 * @method static void clearBeforeExecutingCallbacks()
 * @method static void resetForPool()
 * @method static int getErrorCount()
 * @method static void listen(\Closure $callback)
 * @method static \Hypervel\Database\Query\Expression raw(mixed $value)
 * @method static string escape(mixed $value, bool $binary = false)
 * @method static bool hasModifiedRecords()
 * @method static void recordsHaveBeenModified(bool $value = true)
 * @method static \Hypervel\Database\Connection setRecordModificationState(bool $value)
 * @method static void forgetRecordModificationState()
 * @method static \Hypervel\Database\Connection useWriteConnectionWhenReading(bool $value = true)
 * @method static \PDO getPdo()
 * @method static \PDO|\Closure|null getRawPdo()
 * @method static \PDO getReadPdo()
 * @method static \PDO|\Closure|null getRawReadPdo()
 * @method static \Hypervel\Database\Connection setPdo(\PDO|\Closure|null $pdo)
 * @method static \Hypervel\Database\Connection setReadPdo(\PDO|\Closure|null $pdo)
 * @method static \Hypervel\Database\Connection setReadPdoConfig(array $config)
 * @method static string|null getName()
 * @method static mixed getConfig(string|null $option = null)
 * @method static string getDriverName()
 * @method static string getDriverTitle()
 * @method static \Hypervel\Database\Query\Grammars\Grammar getQueryGrammar()
 * @method static \Hypervel\Database\Connection setQueryGrammar(\Hypervel\Database\Query\Grammars\Grammar $grammar)
 * @method static \Hypervel\Database\Schema\Grammars\Grammar|null getSchemaGrammar()
 * @method static \Hypervel\Database\Connection setSchemaGrammar(\Hypervel\Database\Schema\Grammars\Grammar $grammar)
 * @method static \Hypervel\Database\Query\Processors\Processor getPostProcessor()
 * @method static \Hypervel\Database\Connection setPostProcessor(\Hypervel\Database\Query\Processors\Processor $processor)
 * @method static \Hypervel\Contracts\Events\Dispatcher|null getEventDispatcher()
 * @method static \Hypervel\Database\Connection setEventDispatcher(\Hypervel\Contracts\Events\Dispatcher $events)
 * @method static void unsetEventDispatcher()
 * @method static \Hypervel\Database\Connection setTransactionManager(\Hypervel\Database\DatabaseTransactionsManager $manager)
 * @method static \Hypervel\Database\DatabaseTransactionsManager|null getTransactionManager()
 * @method static void unsetTransactionManager()
 * @method static bool pretending()
 * @method static array[] getQueryLog()
 * @method static array getRawQueryLog()
 * @method static void flushQueryLog()
 * @method static void enableQueryLog()
 * @method static void disableQueryLog()
 * @method static bool logging()
 * @method static string getDatabaseName()
 * @method static \Hypervel\Database\Connection setDatabaseName(string $database)
 * @method static string getTablePrefix()
 * @method static \Hypervel\Database\Connection setTablePrefix(string $prefix)
 * @method static mixed withoutTablePrefix(\Closure $callback)
 * @method static string getServerVersion()
 * @method static void resolverFor(string $driver, \Closure $callback)
 * @method static \Closure|null getResolver(string $driver)
 * @method static mixed transaction(\Closure $callback, int $attempts = 1)
 * @method static void beginTransaction()
 * @method static void commit()
 * @method static void rollBack(int|null $toLevel = null)
 * @method static int transactionLevel()
 * @method static void afterCommit(callable $callback)
 * @method static void afterRollBack(callable $callback)
 *
 * @see \Hypervel\Database\DatabaseManager
 */
class DB extends Facade
{
    /**
     * Indicate that destructive Artisan commands should be prohibited.
     *
     * Prohibits: db:wipe, migrate:fresh, migrate:refresh, migrate:reset, and migrate:rollback
     */
    public static function prohibitDestructiveCommands(bool $prohibit = true): void
    {
        FreshCommand::prohibit($prohibit);
        RefreshCommand::prohibit($prohibit);
        ResetCommand::prohibit($prohibit);
        RollbackCommand::prohibit($prohibit);
        WipeCommand::prohibit($prohibit);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'db';
    }
}
