<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Console\WipeCommand;

/**
 * @method static void allowQueryDurationHandlersToRunAgain()
 * @method static string[] availableDrivers()
 * @method static \Hypervel\Database\ConnectionInterface build(array $config)
 * @method static \Hypervel\Database\ConnectionInterface connection(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Database\ConnectionInterface connectUsing(string $name, array $config, bool $force = false)
 * @method static void disconnect(\UnitEnum|string|null $name = null)
 * @method static void extend(string $name, callable $resolver)
 * @method static void flushMacros()
 * @method static void flushState()
 * @method static void forgetExtension(string $name)
 * @method static array<string, \Hypervel\Database\Connection> getConnections()
 * @method static string|null getDefaultConnection()
 * @method static bool hasMacro(string $name)
 * @method static void macro(string $name, callable|object $macro)
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static void purge(\UnitEnum|string|null $name = null)
 * @method static void purgeConnections()
 * @method static \Hypervel\Database\Connection reconnect(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Database\DatabaseManager setApplication(\Hypervel\Contracts\Foundation\Application $app)
 * @method static void setDefaultConnection(string|null $name)
 * @method static void setReconnector(callable $reconnector)
 * @method static string[] supportedDrivers()
 * @method static mixed usingConnection(\UnitEnum|string $name, callable $callback)
 * @method static void whenQueryingForLongerThan(\DateTimeInterface|\Carbon\CarbonInterval|int|float $threshold, callable $handler)
 * @method static int affectingStatement(string $query, array $bindings = [])
 * @method static void afterCommit(callable $callback)
 * @method static void afterCommitOrNow(callable $callback)
 * @method static void afterRollBack(callable $callback)
 * @method static \Hypervel\Database\PdoConnection beforeExecuting(\Closure $callback)
 * @method static \Hypervel\Database\PdoConnection beforeStartingTransaction(\Closure $callback)
 * @method static void beginTransaction()
 * @method static void bindValues(\PDOStatement $statement, array $bindings)
 * @method static void clearBeforeExecutingCallbacks()
 * @method static void commit()
 * @method static void configureSessionUsing(\Hypervel\Database\SessionConfigurator $configurator)
 * @method static \Generator<int, mixed> cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = [])
 * @method static int delete(string $query, array $bindings = [])
 * @method static void disableQueryLog()
 * @method static void enableQueryLog()
 * @method static string escape(mixed $value, bool $binary = false)
 * @method static void flushQueryLog()
 * @method static void forgetRecordModificationState()
 * @method static ($option is null ? array<string, mixed> : mixed) getConfig(string|null $option = null)
 * @method static string getDatabaseName()
 * @method static string getDriverName()
 * @method static string getDriverTitle()
 * @method static int getErrorCount()
 * @method static \Hypervel\Contracts\Events\Dispatcher|null getEventDispatcher()
 * @method static string|int getLastInsertId(string|null $sequence = null)
 * @method static string|null getName()
 * @method static \PDO getPdo()
 * @method static \Hypervel\Database\Query\Processors\Processor getPostProcessor()
 * @method static \Hypervel\Database\Query\Grammars\Grammar getQueryGrammar()
 * @method static array[] getQueryLog()
 * @method static \PDO|\Closure|null getRawPdo()
 * @method static array getRawQueryLog()
 * @method static \PDO|\Closure|null getRawReadPdo()
 * @method static \PDO getReadPdo()
 * @method static \Closure|null getResolver(string $driver)
 * @method static \Hypervel\Database\Schema\Builder getSchemaBuilder()
 * @method static \Hypervel\Database\Schema\Grammars\Grammar|null getSchemaGrammar()
 * @method static \Hypervel\Database\Schema\SchemaState getSchemaState(\Hypervel\Filesystem\Filesystem|null $files = null, callable|null $processFactory = null)
 * @method static string getServerVersion()
 * @method static string getTablePrefix()
 * @method static \Hypervel\Database\DatabaseTransactionsManager|null getTransactionManager()
 * @method static bool hasModifiedRecords()
 * @method static bool insert(string $query, array $bindings = [])
 * @method static bool inTransaction()
 * @method static void listen(\Closure $callback)
 * @method static bool logging()
 * @method static void logQuery(string $query, array $bindings, float|null $time = null)
 * @method static array prepareBindings(array $bindings)
 * @method static array[] pretend(\Closure $callback)
 * @method static bool pretending()
 * @method static \Hypervel\Database\Query\Builder query()
 * @method static \Hypervel\Database\Query\Expression raw(mixed $value)
 * @method static void reconnectIfMissingConnection()
 * @method static void recordsHaveBeenModified(bool $value = true)
 * @method static void resetForPool()
 * @method static void resetTotalQueryDuration()
 * @method static void resolverFor(string $driver, \Closure $callback)
 * @method static void rollBack(int|null $toLevel = null)
 * @method static mixed scalar(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static array select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = [])
 * @method static array selectFromWriteConnection(string $query, array $bindings = [])
 * @method static mixed selectOne(string $query, array $bindings = [], bool $useReadPdo = true)
 * @method static array selectResultSets(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = [])
 * @method static \Hypervel\Database\PdoConnection setDatabaseName(string $database)
 * @method static \Hypervel\Database\PdoConnection setEventDispatcher(\Hypervel\Contracts\Events\Dispatcher $events)
 * @method static \Hypervel\Database\PdoConnection setPdo(\PDO|\Closure|null $pdo)
 * @method static \Hypervel\Database\PdoConnection setPostProcessor(\Hypervel\Database\Query\Processors\Processor $processor)
 * @method static \Hypervel\Database\PdoConnection setQueryGrammar(\Hypervel\Database\Query\Grammars\Grammar $grammar)
 * @method static \Hypervel\Database\PdoConnection setReadPdo(\PDO|\Closure|null $pdo)
 * @method static \Hypervel\Database\PdoConnection setReadPdoConfig(array $config)
 * @method static \Hypervel\Database\PdoConnection setRecordModificationState(bool $value)
 * @method static \Hypervel\Database\PdoConnection setSchemaGrammar(\Hypervel\Database\Schema\Grammars\Grammar $grammar)
 * @method static \Hypervel\Database\PdoConnection setTablePrefix(string $prefix)
 * @method static \Hypervel\Database\PdoConnection setTransactionManager(\Hypervel\Database\DatabaseTransactionsManager $manager)
 * @method static bool statement(string $query, array $bindings = [])
 * @method static \Hypervel\Database\Query\Builder table(\Closure|\Hypervel\Database\Query\Builder|\UnitEnum|string $table, string|null $as = null)
 * @method static int|null threadCount()
 * @method static float totalQueryDuration()
 * @method static mixed transaction(\Closure $callback, int $attempts = 1)
 * @method static bool unprepared(string $query)
 * @method static void unsetEventDispatcher()
 * @method static void unsetTransactionManager()
 * @method static int update(string $query, array $bindings = [])
 * @method static void useDefaultPostProcessor()
 * @method static void useDefaultQueryGrammar()
 * @method static void useDefaultSchemaGrammar()
 * @method static \Hypervel\Database\PdoConnection useWriteConnectionWhenReading(bool $value = true)
 * @method static mixed withoutPretending(\Closure $callback)
 * @method static mixed withoutTablePrefix(\Closure $callback)
 *
 * @see \Hypervel\Database\DatabaseManager
 *
 * @mixin \Hypervel\Database\ConnectionInterface
 */
class DB extends Facade
{
    /**
     * Indicate that destructive Artisan commands should be prohibited.
     *
     * Prohibits: db:wipe, migrate:fresh, migrate:refresh, migrate:reset, and migrate:rollback
     *
     * Boot-only. The prohibition flags persist in static command state for the
     * worker lifetime and affect every subsequent destructive database command.
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

    /**
     * Get methods that should be excluded from the generated facade docblock.
     *
     * The connection mixin supplies transactionLevel()'s impurity metadata;
     * name-based exclusion preserves richer manager methods with the same name.
     *
     * @return array<int, string>
     */
    protected static function ignoredFacadeDocumenterMethods(): array
    {
        return ['transactionLevel'];
    }
}
