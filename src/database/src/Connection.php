<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Exception;
use Generator;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\QueryFailed;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionCommitting;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar as QueryGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Traits\Macroable;
use LogicException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

abstract class Connection implements ConnectionInterface, NonCopyableContext
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;
    use Concerns\ManagesTransactions;
    use InteractsWithTime;
    use Macroable;

    /**
     * The database connection configuration options for reading.
     */
    protected array $readConnectionConfig = [];

    /**
     * The name of the connected database.
     */
    protected string $database;

    /**
     * The configured database name.
     */
    protected string $configuredDatabase;

    /**
     * The table prefix for the connection.
     */
    protected string $tablePrefix = '';

    /**
     * The configured table prefix.
     */
    protected string $configuredTablePrefix = '';

    /**
     * The database connection configuration options.
     */
    protected array $config = [];

    /**
     * The reconnector instance for the connection.
     *
     * @var null|(callable(Connection): mixed)
     */
    protected mixed $reconnector = null;

    /**
     * The query grammar implementation.
     */
    protected QueryGrammar $queryGrammar;

    /**
     * The schema grammar implementation.
     */
    protected ?Schema\Grammars\Grammar $schemaGrammar = null;

    /**
     * The query post processor implementation.
     */
    protected Processor $postProcessor;

    /**
     * The event dispatcher instance.
     */
    protected ?Dispatcher $events = null;

    /**
     * The number of active transactions.
     */
    protected int $transactions = 0;

    /**
     * The depth of the active foreign key constraint suppression scope.
     */
    protected int $foreignKeyConstraintSuppressionDepth = 0;

    /**
     * The transaction manager instance.
     */
    protected ?DatabaseTransactionsManager $transactionsManager = null;

    public const string READ_WRITE_TYPE_CONFIG_KEY = 'read_write_type';

    /**
     * Indicates if changes have been made to the database.
     */
    protected bool $recordsModified = false;

    /**
     * Indicates if the connection should use the write connection when reading.
     */
    protected bool $readOnWriteConnection = false;

    /**
     * The configured read / write type for derived single-role connections.
     *
     * @var null|'read'|'write'
     */
    protected ?string $readWriteType = null;

    /**
     * The last retrieved read / write type.
     *
     * @var null|'read'|'write'
     */
    protected ?string $latestReadWriteTypeRetrieved = null;

    /**
     * All of the queries run against the connection.
     *
     * @var array{query: string, bindings: array, time: null|float}[]
     */
    protected array $queryLog = [];

    /**
     * Indicates whether queries are being logged.
     */
    protected bool $loggingQueries = false;

    /**
     * The duration of all executed queries in milliseconds.
     */
    protected float $totalQueryDuration = 0.0;

    /**
     * All of the registered query duration handlers.
     *
     * @var array{threshold: float|int, handler: callable, has_run: bool}[]
     */
    protected array $queryDurationHandlers = [];

    /**
     * Indicates if the connection is in a "dry run".
     */
    protected bool $pretending = false;

    /**
     * All of the callbacks that should be invoked before a transaction is started.
     *
     * @var Closure[]
     */
    protected array $beforeStartingTransaction = [];

    /**
     * All of the callbacks that should be invoked before a query is executed.
     *
     * @var Closure[]
     */
    protected array $beforeExecutingCallbacks = [];

    /**
     * The number of SQL execution errors on this connection.
     *
     * Used by connection pooling to detect and remove stale connections.
     */
    protected int $errorCount = 0;

    /**
     * The connection resolvers.
     *
     * @var array<string, Closure>
     */
    protected static array $resolvers = [];

    /**
     * Create a new database connection instance.
     */
    public function __construct(string $database = '', string $tablePrefix = '', array $config = [])
    {
        // First we will setup the default properties. We keep track of the DB
        // name we are connected to since it is needed when some reflective
        // type commands are run such as checking whether a table exists.
        $this->database = $database;
        $this->configuredDatabase = $database;

        $this->tablePrefix = $tablePrefix;
        $this->configuredTablePrefix = $tablePrefix;

        $this->config = $config;

        $this->readWriteType = $config[self::READ_WRITE_TYPE_CONFIG_KEY] ?? null;

        // We need to initialize a query grammar and the query post processors
        // which are both very important parts of the database abstractions
        // so we initialize these to their default values while starting.
        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }

    /**
     * Set the query grammar to the default implementation.
     */
    public function useDefaultQueryGrammar(): void
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this);
    }

    /**
     * Set the schema grammar to the default implementation.
     */
    public function useDefaultSchemaGrammar(): void
    {
        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): ?Schema\Grammars\Grammar
    {
        return null;
    }

    /**
     * Set the query post processor to the default implementation.
     */
    public function useDefaultPostProcessor(): void
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): Processor
    {
        return new Processor;
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the schema state for the connection.
     *
     * @throws RuntimeException
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): Schema\SchemaState
    {
        throw new RuntimeException('This database driver does not support schema state.');
    }

    /**
     * Begin a fluent query against a database table.
     */
    public function table(Closure|QueryBuilder|UnitEnum|string $table, ?string $as = null): QueryBuilder
    {
        if ($table instanceof UnitEnum) {
            $table = (string) enum_value($table);
        }

        return $this->query()->from($table, $as);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * Run a select statement and return a single result.
     */
    public function selectOne(string $query, array $bindings = [], bool $useReadPdo = true): mixed
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return array_shift($records);
    }

    /**
     * Run a select statement and return the first column of the first row.
     *
     * @throws \Hypervel\Database\MultipleColumnsSelectedException
     */
    public function scalar(string $query, array $bindings = [], bool $useReadPdo = true): mixed
    {
        $record = $this->selectOne($query, $bindings, $useReadPdo);

        if (is_null($record)) {
            return null;
        }

        $record = (array) $record;

        if (count($record) > 1) {
            throw new MultipleColumnsSelectedException;
        }

        return array_first($record);
    }

    /**
     * Run a select statement against the database.
     */
    public function selectFromWriteConnection(string $query, array $bindings = []): array
    {
        return $this->select($query, $bindings, false);
    }

    /**
     * Run a select statement against the database.
     */
    abstract public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array;

    /**
     * Run a select statement against the database and return all of the result sets.
     */
    public function selectResultSets(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        throw new LogicException(sprintf(
            'Database driver [%s] does not support multiple result sets.',
            $this->getDriverName(),
        ));
    }

    /**
     * Run a select statement against the database and return a generator.
     *
     * @return Generator<int, mixed>
     */
    abstract public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator;

    /**
     * Run an insert statement against the database.
     */
    public function insert(string $query, array $bindings = []): bool
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Get the last insert ID.
     */
    public function getLastInsertId(?string $sequence = null): int|string
    {
        throw new LogicException(sprintf(
            'Database driver [%s] does not support retrieving last insert IDs.',
            $this->getDriverName(),
        ));
    }

    /**
     * Run an update statement against the database.
     */
    public function update(string $query, array $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     */
    public function delete(string $query, array $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     */
    abstract public function statement(string $query, array $bindings = []): bool;

    /**
     * Run an SQL statement and get the number of rows affected.
     */
    abstract public function affectingStatement(string $query, array $bindings = []): int;

    /**
     * Run a raw, unprepared query against the connection.
     */
    abstract public function unprepared(string $query): bool;

    /**
     * Get the number of open connections for the database.
     */
    public function threadCount(): ?int
    {
        $query = $this->getQueryGrammar()->compileThreadCount();

        return $query ? (int) $this->scalar($query) : null;
    }

    /**
     * Execute the given callback in "dry run" mode.
     *
     * @param (Closure(\Hypervel\Database\Connection): mixed) $callback
     * @return array{query: string, bindings: array, time: null|float}[]
     */
    public function pretend(Closure $callback): array
    {
        return $this->withFreshQueryLog(function () use ($callback) {
            $this->pretending = true;

            try {
                // Basically to make the database connection "pretend", we will just return
                // the default values for all the query methods, then we will return an
                // array of queries that were "executed" within the Closure callback.
                $callback($this);

                return $this->queryLog;
            } finally {
                $this->pretending = false;
            }
        });
    }

    /**
     * Execute the given callback without "pretending".
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function withoutPretending(Closure $callback): mixed
    {
        if (! $this->pretending) {
            return $callback();
        }

        $this->pretending = false;

        try {
            return $callback();
        } finally {
            $this->pretending = true;
        }
    }

    /**
     * Execute the given callback in "dry run" mode.
     *
     * @return array{query: string, bindings: array, time: null|float}[]
     */
    protected function withFreshQueryLog(Closure $callback): array
    {
        $loggingQueries = $this->loggingQueries;

        // First we will back up the value of the logging queries property and then
        // we'll be ready to run callbacks. This query log will also get cleared
        // so we will have a new log of all the queries that are executed now.
        $this->enableQueryLog();

        $this->queryLog = [];

        // Now we'll execute this callback and capture the result. Once it has been
        // executed we will restore the value of query logging and give back the
        // value of the callback so the original callers can have the results.
        try {
            return $callback();
        } finally {
            $this->loggingQueries = $loggingQueries;
        }
    }

    /**
     * Prepare the query bindings for execution.
     */
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            // We need to transform all instances of DateTimeInterface into the actual
            // date string. Each query grammar maintains its own date string format
            // so we'll just ask the grammar for the format to get from the date.
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = (int) $value;
            }
        }

        return $bindings;
    }

    /**
     * Run a SQL statement and log its execution context.
     *
     * @throws CanceledException
     * @throws QueryException
     */
    protected function run(string $query, array $bindings, Closure $callback): mixed
    {
        foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
            $beforeExecutingCallback($query, $bindings, $this);
        }

        $this->reconnectIfMissingConnection();

        $start = hrtime(true) / 1e9;

        // Here we will run this query. If an exception occurs we'll determine if it was
        // caused by a connection that has been lost. If that is the cause, we'll try
        // to re-establish connection and re-run the query with a fresh connection.
        try {
            try {
                $result = $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $e) {
                $result = $this->handleQueryException(
                    $e,
                    $query,
                    $bindings,
                    $callback
                );
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $events = $this->events;

            if ($events?->hasListeners(QueryFailed::class)) {
                $events->dispatch(new QueryFailed(
                    $query,
                    $bindings,
                    $this->getElapsedTime($start),
                    $this,
                    $exception,
                    $this->latestReadWriteTypeUsed(),
                ));
            }

            throw $exception;
        }

        // Once we have run the query we will calculate the time that it took to run and
        // then log the query, bindings, and execution time so we will report them on
        // the event that the developer needs them. We'll log time in milliseconds.
        $this->logQuery(
            $query,
            $bindings,
            $this->getElapsedTime($start)
        );

        return $result;
    }

    /**
     * Run a SQL statement.
     *
     * @throws CanceledException
     * @throws QueryException
     */
    protected function runQueryCallback(string $query, array $bindings, Closure $callback): mixed
    {
        // To execute the statement, we'll simply call the callback, which will actually
        // run the SQL against the database connection. Then we can calculate the time it
        // took to execute and log the query SQL, bindings and time in our memory.
        try {
            return $callback($query, $bindings);
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with SQL, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (CanceledException $exception) {
            throw $exception;
        } catch (Exception $e) {
            ++$this->errorCount;

            $exceptionType = ($isUniqueConstraintError = $this->isUniqueConstraintError($e))
                ? UniqueConstraintViolationException::class
                : QueryException::class;

            $queryException = new $exceptionType(
                $this->getName(),
                $query,
                $this->prepareBindings($bindings),
                $e,
                $this->getConnectionDetails(),
                $this->latestReadWriteTypeUsed(),
            );

            if ($isUniqueConstraintError && $queryException instanceof UniqueConstraintViolationException) {
                ['index' => $index, 'columns' => $columns] = $this->parseUniqueConstraintViolation($e);

                $queryException->setIndex($index)->setColumns($columns);
            }

            throw $queryException;
        }
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return false;
    }

    /**
     * Extract the index and columns that caused a unique constraint violation.
     *
     * @return array{index: null|string, columns: list<string>}
     */
    protected function parseUniqueConstraintViolation(Exception $exception): array
    {
        return ['index' => null, 'columns' => []];
    }

    /**
     * Log a query in the connection's query log.
     */
    public function logQuery(string $query, array $bindings, ?float $time = null): void
    {
        $this->totalQueryDuration += $time ?? 0.0;

        $readWriteType = $this->latestReadWriteTypeUsed();
        $events = $this->events;
        $hasQueryExecutedListeners = $events?->hasListeners(QueryExecuted::class) ?? false;

        if ($hasQueryExecutedListeners || $this->queryDurationHandlers !== []) {
            $event = new QueryExecuted($query, $bindings, $time, $this, $readWriteType);

            if ($hasQueryExecutedListeners && $events !== null) {
                $events->dispatch($event);
            }

            $handlers = [];

            foreach ($this->queryDurationHandlers as $key => $config) {
                if (! $config['has_run'] && $this->totalQueryDuration() > $config['threshold']) {
                    $this->queryDurationHandlers[$key]['has_run'] = true;
                    $handlers[] = $config['handler'];
                }
            }

            foreach ($handlers as $handler) {
                $handler($this, $event);
            }
        }

        $query = $this->pretending === true
            ? $this->queryGrammar->substituteBindingsIntoRawSql($query, $bindings)
            : $query;

        if ($this->loggingQueries) {
            $this->queryLog[] = compact('query', 'bindings', 'time', 'readWriteType');
        }
    }

    /**
     * Get the elapsed time in milliseconds since a given starting point.
     */
    protected function getElapsedTime(float $start): float
    {
        return round((hrtime(true) / 1e9 - $start) * 1000, 2);
    }

    /**
     * Register a callback to be invoked when the connection queries for longer than a given amount of time.
     */
    public function whenQueryingForLongerThan(DateTimeInterface|CarbonInterval|float|int $threshold, callable $handler): void
    {
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->queryDurationHandlers[] = [
            'threshold' => $threshold,
            'handler' => $handler,
            'has_run' => false,
        ];
    }

    /**
     * Allow all the query duration handlers to run again, even if they have already run.
     */
    public function allowQueryDurationHandlersToRunAgain(): void
    {
        foreach ($this->queryDurationHandlers as $key => $queryDurationHandler) {
            $this->queryDurationHandlers[$key]['has_run'] = false;
        }
    }

    /**
     * Get the duration of all run queries in milliseconds.
     */
    public function totalQueryDuration(): float
    {
        return $this->totalQueryDuration;
    }

    /**
     * Reset the duration of all run queries.
     */
    public function resetTotalQueryDuration(): void
    {
        $this->totalQueryDuration = 0.0;
    }

    /**
     * Handle a query exception.
     *
     * @throws CanceledException
     * @throws QueryException
     */
    protected function handleQueryException(QueryException $e, string $query, array $bindings, Closure $callback): mixed
    {
        if ($this->transactions >= 1) {
            throw $e;
        }

        return $this->tryAgainIfCausedByLostConnection(
            $e,
            $query,
            $bindings,
            $callback
        );
    }

    /**
     * Handle a query exception that occurred during query execution.
     *
     * @throws CanceledException
     * @throws QueryException
     */
    protected function tryAgainIfCausedByLostConnection(QueryException $e, string $query, array $bindings, Closure $callback): mixed
    {
        if ($this->causedByLostConnection($e->getPrevious())) {
            $this->reconnect();

            return $this->runQueryCallback($query, $bindings, $callback);
        }

        throw $e;
    }

    /**
     * Reconnect to the database.
     *
     * @throws LostConnectionException
     */
    public function reconnect(): mixed
    {
        if (is_callable($this->reconnector)) {
            return call_user_func($this->reconnector, $this);
        }

        throw new LostConnectionException('Lost connection and no reconnector available.');
    }

    /**
     * Reconnect to the database if the driver resources are missing.
     */
    public function reconnectIfMissingConnection(): void
    {
        if (! $this->hasDriverResources()) {
            $this->reconnect();
        }
    }

    /**
     * Refresh the driver resources from a fresh connection.
     *
     * @internal
     */
    final public function refreshFrom(Connection $fresh): void
    {
        if ($fresh::class !== static::class || $fresh->getName() !== $this->getName()) {
            throw new LogicException(sprintf(
                'Cannot refresh connection [%s] of type [%s] from connection [%s] of type [%s].',
                $this->getName() ?? '',
                static::class,
                $fresh->getName() ?? '',
                $fresh::class,
            ));
        }

        $this->replaceDriverResources($fresh);
    }

    /**
     * Disconnect from the underlying driver resources.
     */
    public function disconnect(): void
    {
        $exception = null;

        try {
            $this->disconnectDriverResources();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            $this->resetTransactionState();
        } catch (Throwable $throwable) {
            if ($exception === null
                || ($throwable instanceof CanceledException && ! $exception instanceof CanceledException)
            ) {
                $exception = $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Reset the logical transaction state.
     */
    protected function resetTransactionState(): void
    {
        $this->transactions = 0;

        $this->transactionsManager?->rollback($this->getName() ?? '', 0);
    }

    /**
     * Determine whether the connection has driver resources.
     */
    abstract protected function hasDriverResources(): bool;

    /**
     * Disconnect the driver resources.
     *
     * Implementations must forget the current resources through
     * forgetDriverResources() in a finally block so cleanup failure cannot
     * leave stale resources attached.
     */
    abstract protected function disconnectDriverResources(): void;

    /**
     * Forget the driver resources without performing physical cleanup.
     */
    abstract protected function forgetDriverResources(): void;

    /**
     * Refresh the driver resources from a fresh connection.
     *
     * Validate and capture a complete replacement set of driver resources and
     * resource-associated metadata, including the configured database and table
     * prefix baselines. Adopt it in a finally block around teardown. The original
     * teardown throwable must propagate unchanged.
     */
    abstract protected function replaceDriverResources(Connection $fresh): void;

    /**
     * Determine whether the connection is responsive.
     *
     * @internal
     */
    abstract public function ping(): bool;

    /**
     * Determine whether the connection may be reused.
     *
     * @internal
     */
    public function isReusable(): bool
    {
        return true;
    }

    /**
     * Register a hook to be run just before a database transaction is started.
     */
    public function beforeStartingTransaction(Closure $callback): static
    {
        $this->beforeStartingTransaction[] = $callback;

        return $this;
    }

    /**
     * Register a hook to be run just before a database query is executed.
     */
    public function beforeExecuting(Closure $callback): static
    {
        $this->beforeExecutingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Clear all hooks registered to run before a database query.
     *
     * Used by connection pooling to prevent callback leaks between requests.
     */
    public function clearBeforeExecutingCallbacks(): void
    {
        $this->beforeExecutingCallbacks = [];
    }

    /**
     * Begin a foreign key constraint suppression scope.
     *
     * @internal
     */
    public function beginForeignKeyConstraintSuppression(): bool
    {
        return ++$this->foreignKeyConstraintSuppressionDepth === 1;
    }

    /**
     * End a foreign key constraint suppression scope.
     *
     * @internal
     */
    public function endForeignKeyConstraintSuppression(): void
    {
        if ($this->foreignKeyConstraintSuppressionDepth === 0) {
            throw new LogicException('No foreign key constraint suppression scope is active.');
        }

        --$this->foreignKeyConstraintSuppressionDepth;
    }

    /**
     * Reset all wrapper state for pool release.
     *
     * Mutable connection metadata is restored to its configured values.
     * Trustworthy physical session state is preserved and synchronized against
     * the next coroutine's desired state when the connection is handed out again.
     */
    public function resetForPool(): void
    {
        if ($this->foreignKeyConstraintSuppressionDepth > 0) {
            $this->markCurrentSessionStateUnknown();
            $this->foreignKeyConstraintSuppressionDepth = 0;
        }

        // Clear registered callbacks
        $this->beforeExecutingCallbacks = [];
        $this->beforeStartingTransaction = [];

        // Reset query logging
        $this->queryLog = [];
        $this->loggingQueries = false;

        // Reset query duration tracking
        $this->totalQueryDuration = 0.0;
        $this->queryDurationHandlers = [];

        // Reset connection metadata and routing
        $this->database = $this->configuredDatabase;
        $this->tablePrefix = $this->configuredTablePrefix;
        $this->latestReadWriteTypeRetrieved = null;
        $this->readOnWriteConnection = false;

        // Reset pretend mode (defensive - normally reset by finally block)
        $this->pretending = false;

        // Reset record modification state
        $this->recordsModified = false;

        // Reset execution errors for the next borrow window.
        $this->errorCount = 0;
    }

    /**
     * Get the number of SQL execution errors on this connection.
     *
     * Used by connection pooling to detect stale connections.
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * Register a database query listener with the connection.
     *
     * Boot-only. Registers a listener on the worker-global event dispatcher;
     * per-request registration persists and affects subsequent requests.
     *
     * @param Closure(QueryExecuted): mixed $callback
     */
    public function listen(Closure $callback): void
    {
        $this->events?->listen(QueryExecuted::class, $callback);
    }

    /**
     * Fire an event for this connection.
     */
    protected function fireConnectionEvent(string $event): void
    {
        switch ($event) {
            case 'beganTransaction':
                if ($this->events?->hasListeners(TransactionBeginning::class)) {
                    $this->events->dispatch(new TransactionBeginning($this));
                }
                break;
            case 'committed':
                if ($this->events?->hasListeners(TransactionCommitted::class)) {
                    $this->events->dispatch(new TransactionCommitted($this));
                }
                break;
            case 'committing':
                if ($this->events?->hasListeners(TransactionCommitting::class)) {
                    $this->events->dispatch(new TransactionCommitting($this));
                }
                break;
            case 'rollingBack':
                if ($this->events?->hasListeners(TransactionRolledBack::class)) {
                    $this->events->dispatch(new TransactionRolledBack($this));
                }
                break;
        }
    }

    /**
     * Fire the given event.
     */
    protected function event(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /**
     * Get a new raw query expression.
     */
    public function raw(mixed $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Escape a value for safe SQL embedding.
     *
     * @throws RuntimeException
     */
    public function escape(mixed $value, bool $binary = false): string
    {
        if ($value instanceof BinaryParameter) {
            return $this->escapeBinary($value->value);
        }

        if ($value === null) {
            return 'null';
        }
        if ($binary) {
            return $this->escapeBinary($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $this->escapeBool($value);
        }
        if (is_array($value)) {
            throw new RuntimeException('The database connection does not support escaping arrays.');
        }
        if (str_contains($value, "\00")) {
            throw new RuntimeException('Strings with null bytes cannot be escaped. Use the binary escape option.');
        }

        if (preg_match('//u', $value) === false) {
            throw new RuntimeException('Strings with invalid UTF-8 byte sequences cannot be escaped.');
        }

        return $this->escapeString($value);
    }

    /**
     * Escape a string value for safe SQL embedding.
     */
    abstract protected function escapeString(string $value): string;

    /**
     * Escape a boolean value for safe SQL embedding.
     */
    protected function escapeBool(bool $value): string
    {
        return $value ? '1' : '0';
    }

    /**
     * Escape a binary value for safe SQL embedding.
     *
     * @throws RuntimeException
     */
    protected function escapeBinary(string $value): string
    {
        throw new RuntimeException('The database connection does not support escaping binary values.');
    }

    /**
     * Determine if the database connection has modified any database records.
     */
    public function hasModifiedRecords(): bool
    {
        return $this->recordsModified;
    }

    /**
     * Indicate if any records have been modified.
     */
    public function recordsHaveBeenModified(bool $value = true): void
    {
        if (! $this->recordsModified) {
            $this->recordsModified = $value;
        }
    }

    /**
     * Set the record modification state.
     */
    public function setRecordModificationState(bool $value): static
    {
        $this->recordsModified = $value;

        return $this;
    }

    /**
     * Reset the record modification state.
     */
    public function forgetRecordModificationState(): void
    {
        $this->recordsModified = false;
    }

    /**
     * Indicate that the connection should use the write connection for reads.
     */
    public function useWriteConnectionWhenReading(bool $value = true): static
    {
        $this->readOnWriteConnection = $value;

        return $this;
    }

    /**
     * Invalidate the state remembered for the current physical session.
     */
    protected function invalidateCurrentSessionState(): void
    {
    }

    /**
     * Mark the current physical session state as unknown.
     *
     * @internal
     */
    public function markCurrentSessionStateUnknown(): void
    {
    }

    /**
     * Execute an internal physical-session statement.
     *
     * @internal
     */
    public function executeSessionStatement(string $sql): void
    {
        throw new LogicException(sprintf(
            'Database driver [%s] does not support physical session statements.',
            $this->getDriverName(),
        ));
    }

    /**
     * Set the reconnect instance on the connection.
     */
    public function setReconnector(callable $reconnector): static
    {
        $this->reconnector = $reconnector;

        return $this;
    }

    /**
     * Get the database connection name.
     */
    public function getName(): ?string
    {
        return $this->getConfig('name');
    }

    /**
     * Get an option from the configuration options.
     *
     * @return ($option is null ? array<string, mixed> : mixed)
     */
    public function getConfig(?string $option = null): mixed
    {
        return Arr::get($this->config, $option);
    }

    /**
     * Get the basic connection information as an array for debugging.
     */
    protected function getConnectionDetails(): array
    {
        $config = $this->latestReadWriteTypeUsed() === 'read' && $this->readConnectionConfig !== []
            ? $this->readConnectionConfig
            : $this->config;

        return [
            'driver' => $this->getDriverName(),
            'name' => $this->getName(),
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'database' => $config['database'] ?? null,
            'unix_socket' => $config['unix_socket'] ?? null,
        ];
    }

    /**
     * Get the database driver name.
     */
    public function getDriverName(): string
    {
        return $this->getConfig('driver') ?? $this->getDefaultDriverName();
    }

    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return $this->getDriverName();
    }

    /**
     * Get the default database driver name.
     */
    abstract protected function getDefaultDriverName(): string;

    /**
     * Get the query grammar used by the connection.
     */
    public function getQueryGrammar(): QueryGrammar
    {
        return $this->queryGrammar;
    }

    /**
     * Set the query grammar used by the connection.
     */
    public function setQueryGrammar(Query\Grammars\Grammar $grammar): static
    {
        $this->queryGrammar = $grammar;

        return $this;
    }

    /**
     * Get the schema grammar used by the connection.
     */
    public function getSchemaGrammar(): ?Schema\Grammars\Grammar
    {
        return $this->schemaGrammar;
    }

    /**
     * Set the schema grammar used by the connection.
     */
    public function setSchemaGrammar(Schema\Grammars\Grammar $grammar): static
    {
        $this->schemaGrammar = $grammar;

        return $this;
    }

    /**
     * Get the query post processor used by the connection.
     */
    public function getPostProcessor(): Processor
    {
        return $this->postProcessor;
    }

    /**
     * Set the query post processor used by the connection.
     */
    public function setPostProcessor(Processor $processor): static
    {
        $this->postProcessor = $processor;

        return $this;
    }

    /**
     * Get the event dispatcher used by the connection.
     */
    public function getEventDispatcher(): ?Dispatcher
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance on the connection.
     */
    public function setEventDispatcher(Dispatcher $events): static
    {
        $this->events = $events;

        return $this;
    }

    /**
     * Unset the event dispatcher for this connection.
     */
    public function unsetEventDispatcher(): void
    {
        $this->events = null;
    }

    /**
     * Run the statement to start a new transaction.
     */
    protected function executeBeginTransactionStatement(): void
    {
        $this->throwUnsupportedTransactionException();
    }

    /**
     * Create a save point within the database.
     *
     * @throws Throwable
     */
    protected function createSavepoint(): void
    {
        $this->throwUnsupportedTransactionException();
    }

    /**
     * Commit the active physical transaction.
     */
    protected function performCommit(): void
    {
        $this->throwUnsupportedTransactionException();
    }

    /**
     * Perform a rollback within the database.
     *
     * @throws Throwable
     */
    protected function performRollBack(int $toLevel): void
    {
        $this->throwUnsupportedTransactionException();
    }

    /**
     * Throw an exception for an unsupported transaction operation.
     */
    private function throwUnsupportedTransactionException(): never
    {
        throw new LogicException(sprintf(
            'Database driver [%s] does not support transactions.',
            $this->getDriverName(),
        ));
    }

    /**
     * Determine whether the connection has an active physical transaction.
     */
    abstract public function inTransaction(): bool;

    /**
     * Set the transaction manager instance on the connection.
     */
    public function setTransactionManager(DatabaseTransactionsManager $manager): static
    {
        $this->transactionsManager = $manager;

        return $this;
    }

    /**
     * Get the transaction manager instance.
     */
    public function getTransactionManager(): ?DatabaseTransactionsManager
    {
        return $this->transactionsManager;
    }

    /**
     * Unset the transaction manager for this connection.
     *
     * Tests only. A pooled connection keeps the null manager after release, so every
     * later coroutine that borrows it fails when scheduling after-commit callbacks.
     */
    public function unsetTransactionManager(): void
    {
        $this->transactionsManager = null;
    }

    /**
     * Determine if the connection is in a "dry run".
     */
    public function pretending(): bool
    {
        return $this->pretending === true;
    }

    /**
     * Get the connection query log.
     *
     * @return array{query: string, bindings: array, time: null|float}[]
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Get the connection query log with embedded bindings.
     */
    public function getRawQueryLog(): array
    {
        return array_map(fn (array $log) => [
            'raw_query' => $this->queryGrammar->substituteBindingsIntoRawSql(
                $log['query'],
                $this->prepareBindings($log['bindings'])
            ),
            'time' => $log['time'],
        ], $this->getQueryLog());
    }

    /**
     * Clear the query log.
     */
    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Enable the query log on the connection.
     */
    public function enableQueryLog(): void
    {
        $this->loggingQueries = true;
    }

    /**
     * Disable the query log on the connection.
     */
    public function disableQueryLog(): void
    {
        $this->loggingQueries = false;
    }

    /**
     * Determine whether we're logging queries.
     */
    public function logging(): bool
    {
        return $this->loggingQueries;
    }

    /**
     * Get the name of the connected database.
     */
    public function getDatabaseName(): string
    {
        return $this->database;
    }

    /**
     * Set the name of the connected database.
     */
    public function setDatabaseName(string $database): static
    {
        $this->database = $database;

        return $this;
    }

    /**
     * Retrieve the latest read / write type used.
     *
     * @return null|'read'|'write'
     */
    protected function latestReadWriteTypeUsed(): ?string
    {
        return $this->readWriteType ?? $this->latestReadWriteTypeRetrieved;
    }

    /**
     * Get the table prefix for the connection.
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Set the table prefix in use by the connection.
     */
    public function setTablePrefix(string $prefix): static
    {
        $this->tablePrefix = $prefix;

        return $this;
    }

    /**
     * Execute the given callback without table prefix.
     *
     * @template TReturn
     *
     * @param Closure($this): TReturn $callback
     * @return TReturn
     */
    public function withoutTablePrefix(Closure $callback): mixed
    {
        $tablePrefix = $this->getTablePrefix();

        $this->setTablePrefix('');

        try {
            return $callback($this);
        } finally {
            $this->setTablePrefix($tablePrefix);
        }
    }

    /**
     * Get the server version for the connection.
     */
    abstract public function getServerVersion(): string;

    /**
     * Register a connection resolver.
     *
     * Boot-only. The resolver persists in a static property for the worker
     * lifetime and runs on every subsequent Connection construction for the
     * given driver across all coroutines.
     */
    public static function resolverFor(string $driver, Closure $callback): void
    {
        static::$resolvers[$driver] = $callback;
    }

    /**
     * Get the connection resolver for the given driver.
     */
    public static function getResolver(string $driver): ?Closure
    {
        return static::$resolvers[$driver] ?? null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$resolvers = [];
        static::flushMacros();
    }

    /**
     * Prepare the instance for cloning.
     */
    public function __clone(): void
    {
        // When cloning, re-initialize grammars to reference cloned connection...
        $this->useDefaultQueryGrammar();

        if (! is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
    }
}
