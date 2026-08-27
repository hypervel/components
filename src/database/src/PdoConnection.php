<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Closure;
use Generator;
use Hypervel\Database\Events\StatementPrepared;
use PDO;
use PDOStatement;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;
use WeakMap;

class PdoConnection extends Connection
{
    /**
     * The active PDO connection.
     *
     * @var null|(Closure(): PDO)|PDO
     */
    protected PDO|Closure|null $pdo;

    /**
     * The active PDO connection used for reads.
     *
     * @var null|(Closure(): PDO)|PDO
     */
    protected PDO|Closure|null $readPdo = null;

    /**
     * The default fetch mode of the connection.
     */
    protected int $fetchMode = PDO::FETCH_OBJ;

    /**
     * The registered database session configurators.
     *
     * @var list<SessionConfigurator>
     */
    protected static array $sessionConfigurators = [];

    /**
     * The state known for each live physical database session.
     *
     * @var null|WeakMap<PDO, PhysicalSessionState>
     */
    protected static ?WeakMap $physicalSessionStates = null;

    /**
     * Create a new PDO database connection instance.
     *
     * @param (Closure(): PDO)|PDO $pdo
     */
    public function __construct(PDO|Closure $pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        $this->pdo = $pdo;

        parent::__construct($database, $tablePrefix, $config);
    }

    /**
     * Run a select statement against the database.
     */
    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo, $fetchUsing) {
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

            return $statement->fetchAll(...$fetchUsing);
        });
    }

    /**
     * Run a select statement against the database and return all of the result sets.
     */
    public function selectResultSets(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo, $fetchUsing) {
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
                $sets[] = $statement->fetchAll(...$fetchUsing);
            } while ($statement->nextRowset());

            return $sets;
        });
    }

    /**
     * Run a select statement against the database and return a generator.
     *
     * @return Generator<int, mixed>
     */
    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        $statement = $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return null;
            }

            // First we will create a statement for the query. Then, we will set the fetch
            // mode and prepare the bindings for the query. Once that's done we will be
            // ready to execute the query against the database and return the cursor.
            $statement = $this->prepared($this->getPdoForSelect($useReadPdo)
                ->prepare($query));

            $this->bindValues(
                $statement,
                $this->prepareBindings($bindings)
            );

            // Next, we'll execute the query against the database and return the statement
            // so we can return the cursor. The cursor will use a PHP generator to give
            // back one row at a time without using a bunch of memory to render them.
            $statement->execute();

            return $statement;
        });

        if ($statement === null) {
            return;
        }

        if ($fetchUsing !== []) {
            // fetchAll() supplies default column and class arguments that setFetchMode()
            // demands explicitly, so a mode-only call keeps the same meaning when streamed.
            if (count($fetchUsing) === 1) {
                $mode = $fetchUsing[0] & ~(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE | PDO::FETCH_CLASSTYPE | PDO::FETCH_PROPS_LATE);

                if ($mode === PDO::FETCH_COLUMN) {
                    $fetchUsing[] = 0;
                } elseif ($mode === PDO::FETCH_CLASS && ($fetchUsing[0] & PDO::FETCH_CLASSTYPE) === 0) {
                    $fetchUsing[] = stdClass::class;
                }
            }

            $statement->setFetchMode(...$fetchUsing);
        }

        foreach ($statement as $record) {
            yield $record;
        }
    }

    /**
     * Configure the PDO prepared statement.
     */
    protected function prepared(PDOStatement $statement): PDOStatement
    {
        $statement->setFetchMode($this->fetchMode);

        $this->event(StatementPrepared::class, fn () => new StatementPrepared($this, $statement));

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
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'pdo';
    }

    /**
     * Escape a string value for safe SQL embedding.
     */
    protected function escapeString(string $value): string
    {
        // Quote through the session that executed the last query because quoting may depend
        // on its configuration, and resolving the other endpoint may open or reconfigure it.
        $pdo = $this->latestReadWriteTypeUsed() === 'write'
            ? $this->getPdo()
            : $this->getReadPdo();

        $escaped = $pdo->quote($value);

        if ($escaped === false) {
            throw new RuntimeException('The database connection could not escape the given value.');
        }

        return $escaped;
    }

    /**
     * Get the last inserted ID.
     */
    public function getLastInsertId(?string $sequence = null): int|string
    {
        return $this->getLastInsertIdFrom($this->getPdo(), $sequence);
    }

    /**
     * Get the last inserted ID from the given PDO connection.
     */
    protected function getLastInsertIdFrom(PDO $pdo, ?string $sequence = null): int|string
    {
        $lastInsertId = $pdo->lastInsertId($sequence);

        if ($lastInsertId === false) {
            throw new RuntimeException('The database driver could not retrieve the last insert ID.');
        }

        return $lastInsertId;
    }

    /**
     * Get the current synchronized PDO connection.
     */
    public function getPdo(): PDO
    {
        $this->latestReadWriteTypeRetrieved = 'write';
        $pdo = $this->resolvePdo();

        return static::$sessionConfigurators === []
            ? $pdo
            : $this->synchronizeSession($pdo, read: false);
    }

    /**
     * Get the current PDO parameter without resolving, reconnecting, or synchronizing session state.
     */
    public function getRawPdo(): PDO|Closure|null
    {
        return $this->pdo;
    }

    /**
     * Get the current synchronized PDO connection used for reading.
     */
    public function getReadPdo(): PDO
    {
        if ($this->transactions > 0) {
            return $this->getPdo();
        }

        if ($this->readOnWriteConnection
            || ($this->recordsModified && $this->getConfig('sticky'))) {
            return $this->getPdo();
        }

        $this->latestReadWriteTypeRetrieved = 'read';
        $pdo = $this->resolveReadPdo();

        return static::$sessionConfigurators === []
            ? $pdo
            : $this->synchronizeSession($pdo, read: true);
    }

    /**
     * Get the current read PDO parameter without resolving, reconnecting, or synchronizing session state.
     */
    public function getRawReadPdo(): PDO|Closure|null
    {
        return $this->readPdo;
    }

    /**
     * Resolve the current write PDO without synchronizing session state.
     */
    protected function resolvePdo(): PDO
    {
        if ($this->pdo instanceof Closure) {
            return $this->pdo = call_user_func($this->pdo);
        }

        return $this->pdo;
    }

    /**
     * Resolve the current read PDO without synchronizing session state.
     */
    protected function resolveReadPdo(): PDO
    {
        if ($this->readPdo instanceof Closure) {
            return $this->readPdo = call_user_func($this->readPdo);
        }

        if ($this->readPdo instanceof PDO) {
            return $this->readPdo;
        }

        $this->latestReadWriteTypeRetrieved = 'write';

        return $this->resolvePdo();
    }

    /**
     * Synchronize the desired state for a physical database session.
     */
    protected function synchronizeSession(PDO $pdo, bool $read): PDO
    {
        $sessionState = static::physicalSessionState($pdo);

        if ($sessionState->configuring) {
            $this->markSessionStateUnknown($pdo);

            throw new RuntimeException('Reentrant database session configuration is not allowed.');
        }

        if ($sessionState->unknown) {
            $sessionState->configuring = true;

            try {
                $pdo = $this->replaceUnknownSession($read);
            } finally {
                $sessionState->configuring = false;
            }

            $sessionState = static::physicalSessionState($pdo);

            if ($sessionState->configuring) {
                $this->markSessionStateUnknown($pdo);

                throw new RuntimeException('Reentrant database session configuration is not allowed.');
            }
        }

        $sessionState->configuring = true;

        try {
            foreach (static::$sessionConfigurators as $index => $configurator) {
                $desiredState = $configurator->state($this);

                if ($desiredState === null
                    || ($sessionState->appliedStates[$index] ?? null) === $desiredState) {
                    continue;
                }

                try {
                    $configurator->apply($pdo, $desiredState, $this);

                    if ($sessionState->unknown) {
                        throw new RuntimeException('Database session state became unknown during configuration.');
                    }
                } catch (Throwable $exception) {
                    $sessionState->appliedStates = [];
                    $sessionState->unknown = true;

                    throw $exception;
                }

                $sessionState->appliedStates[$index] = $desiredState;
            }

            if ($sessionState->unknown) {
                throw new RuntimeException('Database session state became unknown during configuration.');
            }
        } finally {
            $sessionState->configuring = false;
        }

        return $pdo;
    }

    /**
     * Replace a physical session whose state can no longer be trusted.
     */
    protected function replaceUnknownSession(bool $read): PDO
    {
        if ($this->transactions > 0) {
            throw new RuntimeException('Database session state is unknown within an active transaction.');
        }

        $this->reconnect();

        $replacement = $read
            ? $this->resolveReadPdo()
            : $this->resolvePdo();

        if (static::sessionStateIsUnknown($replacement)) {
            throw new RuntimeException('Database session state remains unknown after reconnecting.');
        }

        return $replacement;
    }

    /**
     * Get the state holder for a physical database session.
     */
    protected static function physicalSessionState(PDO $pdo): PhysicalSessionState
    {
        $states = static::$physicalSessionStates ??= new WeakMap;

        return $states[$pdo] ??= new PhysicalSessionState;
    }

    /**
     * Determine whether a physical database session has unknown state.
     */
    protected static function sessionStateIsUnknown(PDO $pdo): bool
    {
        return static::$physicalSessionStates !== null
            && isset(static::$physicalSessionStates[$pdo])
            && static::$physicalSessionStates[$pdo]->unknown;
    }

    /**
     * Invalidate the states remembered for a physical database session.
     */
    protected function invalidateSessionState(PDO $pdo): void
    {
        if (static::$physicalSessionStates !== null
            && isset(static::$physicalSessionStates[$pdo])) {
            static::$physicalSessionStates[$pdo]->appliedStates = [];
        }
    }

    /**
     * Mark a physical database session's state as unknown.
     */
    protected function markSessionStateUnknown(PDO $pdo): void
    {
        $sessionState = static::physicalSessionState($pdo);
        $sessionState->appliedStates = [];
        $sessionState->unknown = true;
    }

    /**
     * Invalidate the state remembered for the current physical session.
     */
    protected function invalidateCurrentSessionState(): void
    {
        $this->invalidateSessionState($this->resolvePdo());
    }

    /**
     * Mark the current write session's state as unknown.
     *
     * @internal
     */
    public function markCurrentSessionStateUnknown(): void
    {
        $pdo = $this->getRawPdo();

        if (! $pdo instanceof PDO) {
            // Cleanup must not resolve a lazy connection merely to invalidate a session that does not yet exist.
            return;
        }

        $this->markSessionStateUnknown($pdo);
    }

    /**
     * Execute an internal physical-session statement.
     *
     * @internal
     */
    public function executeSessionStatement(string $sql): void
    {
        $pdo = $this->getPdo();

        try {
            if ($pdo->exec($sql) === false) {
                throw new RuntimeException("Failed to execute schema statement [{$sql}].");
            }
        } catch (Throwable $exception) {
            $this->markSessionStateUnknown($pdo);

            throw $exception;
        }
    }

    /**
     * Determine whether an open PDO has unknown session state.
     */
    private function hasUnknownSessionState(): bool
    {
        if (static::$physicalSessionStates === null) {
            return false;
        }

        $writePdo = $this->getRawPdo();

        if ($writePdo instanceof PDO
            && static::sessionStateIsUnknown($writePdo)) {
            return true;
        }

        $readPdo = $this->getRawReadPdo();

        return $readPdo instanceof PDO
            && static::sessionStateIsUnknown($readPdo);
    }

    /**
     * Determine whether the connection may be reused.
     *
     * @internal
     */
    public function isReusable(): bool
    {
        return ! $this->hasUnknownSessionState();
    }

    /**
     * Determine whether the connection has driver resources.
     */
    protected function hasDriverResources(): bool
    {
        return $this->pdo instanceof PDO || $this->pdo instanceof Closure;
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
        $this->readConnectionConfig = $config;

        return $this;
    }

    /**
     * Forget the driver resources without performing physical cleanup.
     */
    protected function forgetDriverResources(): void
    {
        $this->setPdo(null)->setReadPdo(null);
    }

    /**
     * Disconnect the driver resources.
     */
    protected function disconnectDriverResources(): void
    {
        $pdo = $this->getRawPdo();
        $exception = null;

        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
                $this->invalidateSessionState($pdo);
            }
        } catch (Throwable $throwable) {
            $this->markSessionStateUnknown($pdo);

            if (! $this->causedByLostConnection($throwable)) {
                $exception = $throwable;
            }
        } finally {
            $this->forgetDriverResources();
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Refresh the PDO resources from a fresh connection.
     */
    protected function replaceDriverResources(Connection $fresh): void
    {
        /** @var self $fresh */
        $fresh->getPdo();
        $fresh->getReadPdo();

        $pdo = $fresh->getRawPdo();
        $readPdo = $fresh->getRawReadPdo();
        $database = $fresh->database;
        $configuredDatabase = $fresh->configuredDatabase;
        $tablePrefix = $fresh->tablePrefix;
        $configuredTablePrefix = $fresh->configuredTablePrefix;
        $config = $fresh->config;
        $readConnectionConfig = $fresh->readConnectionConfig;
        $readWriteType = $fresh->readWriteType;

        // Keep the current generation intact until both replacement handles
        // are ready so a failed refresh cannot leave a partial connection.
        try {
            $this->disconnect();
        } finally {
            // Disconnect always forgets the old handles, even when cleanup throws.
            $this->setPdo($pdo)->setReadPdo($readPdo);
            $this->database = $database;
            $this->configuredDatabase = $configuredDatabase;
            $this->tablePrefix = $tablePrefix;
            $this->configuredTablePrefix = $configuredTablePrefix;
            $this->config = $config;
            $this->readConnectionConfig = $readConnectionConfig;
            $this->readWriteType = $readWriteType;
            $this->latestReadWriteTypeRetrieved = null;
        }
    }

    /**
     * Determine whether the already-open PDO resources are responsive.
     *
     * @internal
     */
    public function ping(): bool
    {
        // Known session configuration is memoized by physical PDO across clean
        // releases. Pool maintenance must remain session-state-neutral.
        $writePdo = $this->getRawPdo();
        $readPdo = $this->getRawReadPdo();
        $pdos = [];

        if ($writePdo instanceof PDO) {
            $pdos[] = $writePdo;
        }

        if ($readPdo instanceof PDO && $readPdo !== $writePdo) {
            $pdos[] = $readPdo;
        }

        try {
            foreach ($pdos as $pdo) {
                $statement = $pdo->query('SELECT 1');

                if ($statement === false) {
                    return false;
                }

                $statement->closeCursor();
            }

            return true;
        } catch (Throwable $exception) {
            if ($exception instanceof CanceledException) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * Determine whether the connection has an active physical transaction.
     */
    public function inTransaction(): bool
    {
        return $this->pdo instanceof PDO && $this->pdo->inTransaction();
    }

    /**
     * Run the statement to start a new transaction.
     */
    protected function executeBeginTransactionStatement(): void
    {
        $this->getPdo()->beginTransaction();
    }

    /**
     * Create a save point within the database.
     *
     * @throws Throwable
     */
    protected function createSavepoint(): void
    {
        $this->resolvePdo()->exec(
            $this->queryGrammar->compileSavepoint('trans' . ($this->transactions + 1))
        );
    }

    /**
     * Commit the active physical transaction.
     */
    protected function performCommit(): void
    {
        $pdo = $this->resolvePdo();

        try {
            $pdo->commit();
        } catch (Throwable $exception) {
            $this->invalidateSessionState($pdo);

            if (! $this->causedByLostConnection($exception)
                && ! $this->causedByConcurrencyError($exception)) {
                $this->markSessionStateUnknown($pdo);
            }

            throw $exception;
        }
    }

    /**
     * Perform a rollback within the database.
     *
     * @throws Throwable
     */
    protected function performRollBack(int $toLevel): void
    {
        $pdo = $this->resolvePdo();

        try {
            if ($toLevel === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($this->queryGrammar->supportsSavepoints()) {
                $pdo->exec(
                    $this->queryGrammar->compileSavepointRollBack('trans' . ($toLevel + 1))
                );
            }
        } catch (Throwable $exception) {
            if (! $this->causedByLostConnection($exception)) {
                $this->markSessionStateUnknown($pdo);
            }

            throw $exception;
        } finally {
            $this->invalidateSessionState($pdo);
        }
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        return $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Register a database session configurator.
     *
     * Boot-only. The configurator persists in a static property for the worker
     * lifetime and runs on every subsequent synchronized PDO hand-out across all
     * coroutines.
     */
    public static function configureSessionUsing(SessionConfigurator $configurator): void
    {
        static::$sessionConfigurators[] = $configurator;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        parent::flushState();

        static::$sessionConfigurators = [];
        static::$physicalSessionStates = null;
    }
}
