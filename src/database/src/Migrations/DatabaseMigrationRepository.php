<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface as Resolver;
use Hypervel\Database\Query\Builder;
use WeakReference;

class DatabaseMigrationRepository implements MigrationRepositoryInterface
{
    protected const string CONNECTION_CONTEXT_KEY_PREFIX = '__database.migration_repository.connection.';

    /**
     * The name of the database connection to use.
     */
    protected ?string $connection {
        get {
            if (! Coroutine::inCoroutine()) {
                return $this->nonCoroutineConnection;
            }

            $state = CoroutineContext::get(self::CONNECTION_CONTEXT_KEY_PREFIX . spl_object_id($this));

            // A released object's ID may be reused while its context entry survives.
            return $state !== null && $state[0]->get() === $this ? $state[1] : null;
        }
        set {
            if (Coroutine::inCoroutine()) {
                CoroutineContext::set(self::CONNECTION_CONTEXT_KEY_PREFIX . spl_object_id($this), [
                    WeakReference::create($this), $value,
                ]);
            } else {
                $this->nonCoroutineConnection = $value;
            }
        }
    }

    private ?string $nonCoroutineConnection = null;

    /**
     * Create a new database migration repository instance.
     */
    public function __construct(
        protected Resolver $resolver,
        protected string $table
    ) {
    }

    /**
     * Get the completed migrations.
     *
     * @return string[]
     */
    public function getRan(): array
    {
        return $this->table()
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->pluck('migration')->all();
    }

    /**
     * Get the list of migrations.
     *
     * Driver-owned migration schemas may omit id and return numeric-string batches.
     *
     * @return object{migration: string, batch: int|numeric-string}[]
     */
    public function getMigrations(int $steps): array
    {
        $query = $this->table()->where('batch', '>=', '1');

        return $query->orderBy('batch', 'desc')
            ->orderBy('migration', 'desc')
            ->limit($steps)
            ->get()
            ->all();
    }

    /**
     * Get the list of the migrations by batch number.
     *
     * @return object{migration: string, batch: int|numeric-string}[]
     */
    public function getMigrationsByBatch(int $batch): array
    {
        return $this->table()
            ->where('batch', $batch)
            ->orderBy('migration', 'desc')
            ->get()
            ->all();
    }

    /**
     * Get the last migration batch.
     *
     * @return object{migration: string, batch: int|numeric-string}[]
     */
    public function getLast(): array
    {
        $query = $this->table()->where('batch', $this->getLastBatchNumber());

        return $query->orderBy('migration', 'desc')->get()->all();
    }

    /**
     * Get the completed migrations with their batch numbers.
     *
     * @return array<string, int|numeric-string>
     */
    public function getMigrationBatches(): array
    {
        return $this->table()
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->pluck('batch', 'migration')->all();
    }

    /**
     * Log that a migration was run.
     */
    public function log(string $file, int $batch): void
    {
        $record = ['migration' => $file, 'batch' => $batch];

        $this->table()->insert($record);
    }

    /**
     * Remove a migration from the log.
     *
     * @param object{migration: string} $migration
     */
    public function delete(object $migration): void
    {
        $this->table()->where('migration', $migration->migration)->delete();
    }

    /**
     * Get the next migration batch number.
     */
    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Get the last migration batch number.
     */
    public function getLastBatchNumber(): int
    {
        return (int) ($this->table()->max('batch') ?? 0);
    }

    /**
     * Create the migration repository data store.
     */
    public function createRepository(): void
    {
        $schema = $this->getConnection()->getSchemaBuilder();

        $schema->createMigrationRepositoryTable($this->table);
    }

    /**
     * Determine if the migration repository exists.
     */
    public function repositoryExists(): bool
    {
        $schema = $this->getConnection()->getSchemaBuilder();

        return $schema->hasTable($this->table);
    }

    /**
     * Delete the migration repository data store.
     */
    public function deleteRepository(): void
    {
        $schema = $this->getConnection()->getSchemaBuilder();

        $schema->drop($this->table);
    }

    /**
     * Get a query builder for the migration table.
     */
    protected function table(): Builder
    {
        return $this->getConnection()->table($this->table)->useWritePdo();
    }

    /**
     * Get the connection resolver instance.
     */
    public function getConnectionResolver(): Resolver
    {
        return $this->resolver;
    }

    /**
     * Resolve the database connection instance.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    /**
     * Set the information source to gather data.
     */
    public function setSource(?string $name): void
    {
        $this->connection = $name;
    }
}
