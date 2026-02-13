<?php

declare(strict_types=1);

namespace Hypervel\Database;

class DatabaseTransactionRecord
{
    /**
     * The name of the database connection.
     */
    public string $connection;

    /**
     * The transaction level.
     */
    public int $level;

    /**
     * The parent instance of this transaction.
     */
    public ?DatabaseTransactionRecord $parent;

    /**
     * The callbacks that should be executed after committing.
     *
     * @var callable[]
     */
    protected array $callbacks = [];

    /**
     * The callbacks that should be executed after rollback.
     *
     * @var callable[]
     */
    protected array $callbacksForRollback = [];

    /**
     * Create a new database transaction record instance.
     */
    public function __construct(string $connection, int $level, ?DatabaseTransactionRecord $parent = null)
    {
        $this->connection = $connection;
        $this->level = $level;
        $this->parent = $parent;
    }

    /**
     * Register a callback to be executed after committing.
     */
    public function addCallback(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Register a callback to be executed after rollback.
     */
    public function addCallbackForRollback(callable $callback): void
    {
        $this->callbacksForRollback[] = $callback;
    }

    /**
     * Execute all of the callbacks.
     */
    public function executeCallbacks(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }

    /**
     * Execute all of the callbacks for rollback.
     */
    public function executeCallbacksForRollback(): void
    {
        foreach ($this->callbacksForRollback as $callback) {
            $callback();
        }
    }

    /**
     * Get all of the callbacks.
     *
     * @return callable[]
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * Get all of the callbacks for rollback.
     *
     * @return callable[]
     */
    public function getCallbacksForRollback(): array
    {
        return $this->callbacksForRollback;
    }
}
