<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Exception;
use Generator;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\StatementPrepared;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionCommitting;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar as QueryGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\InteractsWithTime;
use Hypervel\Support\Traits\Macroable;
use PDO;
use PDOStatement;
use RuntimeException;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Connection implements ConnectionInterface
{
    use DetectsConcurrencyErrors,
        DetectsLostConnections,
        Concerns\ManagesTransactions,
        InteractsWithTime,
        Macroable;

    /**
     * The active PDO connection.
     *
     * @var PDO|(Closure(): PDO)|null
     */
    protected PDO|Closure|null $pdo;

    /**
     * The active PDO connection used for reads.
     *
     * @var PDO|(Closure(): PDO)|null
     */
    protected PDO|Closure|null $readPdo = null;

    /**
     * The database connection configuration options for reading.
     */
    protected array $readPdoConfig = [];

    /**
     * The name of the connected database.
     */
    protected string $database;

    /**
     * The type of the connection.
     */
    protected ?string $readWriteType = null;

    /**
     * The table prefix for the connection.
     */
    protected string $tablePrefix = '';

    /**
     * The database connection configuration options.
     */
    protected array $config = [];

    /**
     * The reconnector instance for the connection.
     *
     * @var (callable(Connection): mixed)|null
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
     * The default fetch mode of the connection.
     */
    protected int $fetchMode = PDO::FETCH_OBJ;

    /**
     * The number of active transactions.
     */
    protected int $transactions = 0;

    /**
     * The transaction manager instance.
     */
    protected ?DatabaseTransactionsManager $transactionsManager = null;

    /**
     * Indicates if changes have been made to the database.
     */
    protected bool $recordsModified = false;

    /**
     * Indicates if the connection should use the "write" PDO connection.
     */
    protected bool $readOnWriteConnection = false;

    /**
     * All of the queries run against the connection.
     *
     * @var array{query: string, bindings: array, time: float|null}[]
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
     * @var array{has_run: bool, handler: callable}[]
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
     * The last retrieved PDO read / write type.
     *
     * @var 'read'|'write'|null
     */
    protected ?string $latestPdoTypeRetrieved = null;

    /**
     * Create a new database connection instance.
     */
    public function __construct(PDO|Closure $pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        $this->pdo = $pdo;

        // First we will setup the default properties. We keep track of the DB
        // name we are connected to since it is needed when some reflective
        // type commands are run such as checking whether a table exists.
        $this->database = $database;

        $this->tablePrefix = $tablePrefix;

        $this->config = $config;

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
     * @throws \RuntimeException
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
        return $this->query()->from(enum_value($table), $as);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
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

        return Arr::first($record);
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
    public function select(string $query, array $bindings = [], bool $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            // For select statements, we'll simply execute the query and return an array
            // of the database result set. Each element in the array will be a single
            // row from the database table, and will either be an array or objects.
            $statement = $this->prepared(
                $this->getPdoForSelect($useReadPdo)->prepare($query)
            );

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            return $statement->fetchAll();
        });
    }

    /**
     * Run a select statement against the database and returns all of the result sets.
     */
    public function selectResultSets(string $query, array $bindings = [], bool $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->prepared(
                $this->getPdoForSelect($useReadPdo)->prepare($query)
            );

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            $sets = [];

            do {
                $sets[] = $statement->fetchAll();
            } while ($statement->nextRowset());

            return $sets;
        });
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @return \Generator<int, \stdClass>
     */
    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true): Generator
    {
        $statement = $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            // First we will create a statement for the query. Then, we will set the fetch
            // mode and prepare the bindings for the query. Once that's done we will be
            // ready to execute the query against the database and return the cursor.
            $statement = $this->prepared($this->getPdoForSelect($useReadPdo)
                ->prepare($query));

            $this->bindValues(
                $statement, $this->prepareBindings($bindings)
            );

            // Next, we'll execute the query against the database and return the statement
            // so we can return the cursor. The cursor will use a PHP generator to give
            // back one row at a time without using a bunch of memory to render them.
            $statement->execute();

            return $statement;
        });

        while ($record = $statement->fetch()) {
            yield $record;
        }
    }

    /**
     * Configure the PDO prepared statement.
     */
    protected function prepared(PDOStatement $statement): PDOStatement
    {
        $statement->setFetchMode($this->fetchMode);

        $this->event(new StatementPrepared($this, $statement));

        return $statement;
    }

    /**
     * Get the PDO connection to use for a select query.
     */
    protected function getPdoForSelect(bool $useReadPdo = true): PDO
    {
        return $useReadPdo ? $this->getReadPdo() : $this->getPdo();
    }

    /**
     * Run an insert statement against the database.
     */
    public function insert(string $query, array $bindings = []): bool
    {
        return $this->statement($query, $bindings);
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
    public function statement(string $query, array $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            return $statement->execute();
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            // For update or delete statements, we want to get the number of rows affected
            // by the statement and return that back to the developer. We'll first need
            // to execute the statement and then we'll use PDO to fetch the affected.
            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            $this->recordsHaveBeenModified(
                ($count = $statement->rowCount()) > 0
            );

            return $count;
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     */
    public function unprepared(string $query): bool
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $this->recordsHaveBeenModified(
                $change = $this->getPdo()->exec($query) !== false
            );

            return $change;
        });
    }

    /**
     * Get the number of open connections for the database.
     */
    public function threadCount(): ?int
    {
        $query = $this->getQueryGrammar()->compileThreadCount();

        return $query ? $this->scalar($query) : null;
    }

    /**
     * Execute the given callback in "dry run" mode.
     *
     * @param  (\Closure(\Hypervel\Database\Connection): mixed)  $callback
     * @return array{query: string, bindings: array, time: float|null}[]
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
     * @return array{query: string, bindings: array, time: float|null}[]
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
        $result = $callback();

        $this->loggingQueries = $loggingQueries;

        return $result;
    }

    /**
     * Bind values to their parameters in the given statement.
     */
    public function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR
                },
            );
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
     * @throws QueryException
     */
    protected function run(string $query, array $bindings, Closure $callback): mixed
    {
        foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
            $beforeExecutingCallback($query, $bindings, $this);
        }

        $this->reconnectIfMissingConnection();

        $start = microtime(true);

        // Here we will run this query. If an exception occurs we'll determine if it was
        // caused by a connection that has been lost. If that is the cause, we'll try
        // to re-establish connection and re-run the query with a fresh connection.
        try {
            $result = $this->runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            $result = $this->handleQueryException(
                $e, $query, $bindings, $callback
            );
        }

        // Once we have run the query we will calculate the time that it took to run and
        // then log the query, bindings, and execution time so we will report them on
        // the event that the developer needs them. We'll log time in milliseconds.
        $this->logQuery(
            $query, $bindings, $this->getElapsedTime($start)
        );

        return $result;
    }

    /**
     * Run a SQL statement.
     *
     * @throws QueryException
     */
    protected function runQueryCallback(string $query, array $bindings, Closure $callback): mixed
    {
        // To execute the statement, we'll simply call the callback, which will actually
        // run the SQL against the PDO connection. Then we can calculate the time it
        // took to execute and log the query SQL, bindings and time in our memory.
        try {
            return $callback($query, $bindings);
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with SQL, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            ++$this->errorCount;

            $exceptionType = $this->isUniqueConstraintError($e)
                ? UniqueConstraintViolationException::class
                : QueryException::class;

            throw new $exceptionType(
                $this->getNameWithReadWriteType(),
                $query,
                $this->prepareBindings($bindings),
                $e,
                $this->getConnectionDetails(),
                $this->latestReadWriteTypeUsed(),
            );
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
     * Log a query in the connection's query log.
     */
    public function logQuery(string $query, array $bindings, ?float $time = null): void
    {
        $this->totalQueryDuration += $time ?? 0.0;

        $readWriteType = $this->latestReadWriteTypeUsed();

        $this->event(new QueryExecuted($query, $bindings, $time, $this, $readWriteType));

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
        return round((microtime(true) - $start) * 1000, 2);
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
            'has_run' => false,
            'handler' => $handler,
        ];

        $key = count($this->queryDurationHandlers) - 1;

        $this->listen(function ($event) use ($threshold, $handler, $key) {
            if (! $this->queryDurationHandlers[$key]['has_run'] && $this->totalQueryDuration() > $threshold) {
                $handler($this, $event);

                $this->queryDurationHandlers[$key]['has_run'] = true;
            }
        });
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
     * @throws QueryException
     */
    protected function handleQueryException(QueryException $e, string $query, array $bindings, Closure $callback): mixed
    {
        if ($this->transactions >= 1) {
            throw $e;
        }

        return $this->tryAgainIfCausedByLostConnection(
            $e, $query, $bindings, $callback
        );
    }

    /**
     * Handle a query exception that occurred during query execution.
     *
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
     * Reconnect to the database if a PDO connection is missing.
     */
    public function reconnectIfMissingConnection(): void
    {
        if (is_null($this->pdo)) {
            $this->reconnect();
        }
    }

    /**
     * Disconnect from the underlying PDO connection.
     */
    public function disconnect(): void
    {
        $this->setPdo(null)->setReadPdo(null);
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
     * Reset all per-request state for pool release.
     *
     * Called when a connection is returned to the pool to ensure the next
     * coroutine/request gets a clean connection without leaked state.
     */
    public function resetForPool(): void
    {
        // Clear registered callbacks
        $this->beforeExecutingCallbacks = [];
        $this->beforeStartingTransaction = [];

        // Reset query logging
        $this->queryLog = [];
        $this->loggingQueries = false;

        // Reset query duration tracking
        $this->totalQueryDuration = 0.0;
        $this->queryDurationHandlers = [];

        // Reset connection routing
        $this->readOnWriteConnection = false;

        // Reset pretend mode (defensive - normally reset by finally block)
        $this->pretending = false;

        // Reset record modification state
        $this->recordsModified = false;
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
     */
    public function listen(Closure $callback): void
    {
        $this->events?->listen(Events\QueryExecuted::class, $callback);
    }

    /**
     * Fire an event for this connection.
     */
    protected function fireConnectionEvent(string $event): void
    {
        $this->events?->dispatch(match ($event) {
            'beganTransaction' => new TransactionBeginning($this),
            'committed' => new TransactionCommitted($this),
            'committing' => new TransactionCommitting($this),
            'rollingBack' => new TransactionRolledBack($this),
            default => null,
        });
    }

    /**
     * Fire the given event if possible.
     */
    protected function event(mixed $event): void
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
    public function escape(string|float|int|bool|null $value, bool $binary = false): string
    {
        if ($value === null) {
            return 'null';
        } elseif ($binary) {
            return $this->escapeBinary($value);
        } elseif (is_int($value) || is_float($value)) {
            return (string) $value;
        } elseif (is_bool($value)) {
            return $this->escapeBool($value);
        } else {
            if (str_contains($value, "\00")) {
                throw new RuntimeException('Strings with null bytes cannot be escaped. Use the binary escape option.');
            }

            if (preg_match('//u', $value) === false) {
                throw new RuntimeException('Strings with invalid UTF-8 byte sequences cannot be escaped.');
            }

            return $this->escapeString($value);
        }
    }

    /**
     * Escape a string value for safe SQL embedding.
     */
    protected function escapeString(string $value): string
    {
        return $this->getReadPdo()->quote($value);
    }

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
     *
     * @param  bool  $value
     * @return $this
     */
    public function setRecordModificationState(bool $value)
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
     * Indicate that the connection should use the write PDO connection for reads.
     */
    public function useWriteConnectionWhenReading(bool $value = true): static
    {
        $this->readOnWriteConnection = $value;

        return $this;
    }

    /**
     * Get the current PDO connection.
     */
    public function getPdo(): PDO
    {
        $this->latestPdoTypeRetrieved = 'write';

        if ($this->pdo instanceof Closure) {
            return $this->pdo = call_user_func($this->pdo);
        }

        return $this->pdo;
    }

    /**
     * Get the current PDO connection parameter without executing any reconnect logic.
     */
    public function getRawPdo(): PDO|Closure|null
    {
        return $this->pdo;
    }

    /**
     * Get the current PDO connection used for reading.
     */
    public function getReadPdo(): PDO
    {
        if ($this->transactions > 0) {
            return $this->getPdo();
        }

        if ($this->readOnWriteConnection ||
            ($this->recordsModified && $this->getConfig('sticky'))) {
            return $this->getPdo();
        }

        $this->latestPdoTypeRetrieved = 'read';

        if ($this->readPdo instanceof Closure) {
            return $this->readPdo = call_user_func($this->readPdo);
        }

        return $this->readPdo ?: $this->getPdo();
    }

    /**
     * Get the current read PDO connection parameter without executing any reconnect logic.
     */
    public function getRawReadPdo(): PDO|Closure|null
    {
        return $this->readPdo;
    }

    /**
     * Set the PDO connection.
     */
    public function setPdo(PDO|Closure|null $pdo): static
    {
        $this->transactions = 0;

        $this->pdo = $pdo;

        return $this;
    }

    /**
     * Set the PDO connection used for reading.
     */
    public function setReadPdo(PDO|Closure|null $pdo): static
    {
        $this->readPdo = $pdo;

        return $this;
    }

    /**
     * Set the read PDO connection configuration.
     */
    public function setReadPdoConfig(array $config): static
    {
        $this->readPdoConfig = $config;

        return $this;
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
     * Get the database connection with its read / write type.
     */
    public function getNameWithReadWriteType(): ?string
    {
        $name = $this->getName().($this->readWriteType ? '::'.$this->readWriteType : '');

        return empty($name) ? null : $name;
    }

    /**
     * Get an option from the configuration options.
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
        $config = $this->latestReadWriteTypeUsed() === 'read'
            ? $this->readPdoConfig
            : $this->config;

        return [
            'driver' => $this->getDriverName(),
            'name' => $this->getNameWithReadWriteType(),
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'database' => $config['database'] ?? null,
            'unix_socket' => $config['unix_socket'] ?? null,
        ];
    }

    /**
     * Get the PDO driver name.
     */
    public function getDriverName(): string
    {
        return $this->getConfig('driver');
    }

    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return $this->getDriverName();
    }

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
        $this->getPdo()->beginTransaction();
    }

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
     * @return array{query: string, bindings: array, time: float|null}[]
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
     * Set the read / write type of the connection.
     */
    public function setReadWriteType(?string $readWriteType): static
    {
        $this->readWriteType = $readWriteType;

        return $this;
    }

    /**
     * Retrieve the latest read / write type used.
     *
     * @return 'read'|'write'|null
     */
    protected function latestReadWriteTypeUsed(): ?string
    {
        return $this->readWriteType ?? $this->latestPdoTypeRetrieved;
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
     * @param  \Closure  $callback
     * @return mixed
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
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        return $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Register a connection resolver.
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
