<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use DateTimeInterface;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;
use InvalidArgumentException;

use function Hypervel\Support\enum_value;

class RoutingBatchRepository extends DatabaseBatchRepository
{
    /**
     * Create a repository that routes each batch to its stored driver.
     */
    public function __construct(
        BatchFactory $factory,
        ConnectionResolverInterface $resolver,
        string $table,
        ?string $connection,
        string $resultsTable,
        private Container $container,
        private Repository $config,
    ) {
        parent::__construct($factory, $resolver, $table, $connection, $resultsTable);
    }

    /**
     * Resolve the concrete binding so custom repositories receive their batches.
     */
    private function driver(string $driver): DatabaseBatchRepository
    {
        return $this->container->make(match ($driver) {
            'database' => DatabaseBatchRepository::class,
            'redis' => RedisBatchRepository::class,
            default => throw new InvalidArgumentException("Unsupported job batching driver [{$driver}]."),
        });
    }

    /**
     * Resolve an ID-only operation on the SQL writer.
     */
    private function repositoryFor(string $batchId): ?DatabaseBatchRepository
    {
        $driver = $this->getConnection()->table($this->table)->useWritePdo()->where('id', $batchId)->value('driver');

        return $driver === null ? null : $this->driver($driver);
    }

    /**
     * Store sync batches in the same transaction as their inline results.
     */
    public function store(PendingBatch $batch): Batch
    {
        $driver = $this->config->string('queue.batching.driver');
        if ($driver === 'redis' && $this->runsInline($batch->connection())) {
            $driver = 'database';
        }

        return $this->driver($driver)->store($batch);
    }

    /**
     * Follow failover routes that can execute a job before the enrollment commits.
     */
    private function runsInline(?string $connection, array $visited = []): bool
    {
        $connection = $connection === null || $connection === '' ? $this->config->string('queue.default') : $connection;
        $config = $this->config->nullableArray("queue.connections.{$connection}", null);
        if (($config['driver'] ?? null) === 'sync') {
            return true;
        }
        if (($config['driver'] ?? null) !== 'failover' || isset($visited[$connection])) {
            return false;
        }

        $visited[$connection] = true;

        return array_any($config['connections'], fn ($name) => $this->runsInline(enum_value($name), $visited));
    }

    /**
     * Bind lookups to the row's repository without a second routing query.
     */
    protected function toBatch(object $batch): Batch
    {
        return $this->driver($batch->driver)->toBatch($batch);
    }

    /**
     * Hydrate each driver's rows together and restore SQL cursor order.
     */
    protected function toBatches(array $rows): array
    {
        $groups = $batches = [];
        foreach ($rows as $row) {
            $groups[$row->driver][] = $row;
        }
        foreach ($groups as $driver => $group) {
            $batches += $this->driver($driver)->toBatches($group);
        }

        $ordered = [];
        foreach ($rows as $row) {
            if (isset($batches[$row->id])) {
                $ordered[$row->id] = $batches[$row->id];
            }
        }

        return $ordered;
    }

    /**
     * Look up the outstanding delivery IDs using the batch's driver.
     */
    public function getFailedJobIds(string $batchId): array
    {
        return $this->repositoryFor($batchId)?->getFailedJobIds($batchId) ?? [];
    }

    /**
     * Check for an outstanding failure using the batch's driver.
     */
    public function hasFailedJob(string $batchId, string $jobId): bool
    {
        return $this->repositoryFor($batchId)?->hasFailedJob($batchId, $jobId) ?? false;
    }

    /**
     * Enroll jobs using the batch's original driver.
     */
    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $repository = $this->repositoryFor($batchId);
        if ($repository === null && $amount > 0) {
            throw new BatchNotFoundException("Batch [{$batchId}] no longer exists.");
        }
        $repository?->incrementTotalJobs($batchId, $amount);
    }

    /**
     * Withdraw definitely rejected jobs using the stored results driver.
     */
    public function releaseJobs(string $batchId, int $count): ?UpdatedBatchJobCounts
    {
        return $this->repositoryFor($batchId)?->releaseJobs($batchId, $count);
    }

    /**
     * Record success using the batch's original driver.
     */
    public function decrementPendingJobs(string $batchId, string $jobId): ?UpdatedBatchJobCounts
    {
        return $this->repositoryFor($batchId)?->decrementPendingJobs($batchId, $jobId);
    }

    /**
     * Record failure using the batch's original driver.
     */
    public function incrementFailedJobs(string $batchId, string $jobId, ?string $retryId = null): ?UpdatedBatchJobCounts
    {
        return $this->repositoryFor($batchId)?->incrementFailedJobs($batchId, $jobId, $retryId);
    }

    /**
     * Delete the parent and its driver's results.
     */
    public function delete(string $batchId): void
    {
        $this->repositoryFor($batchId)?->delete($batchId);
    }

    /**
     * Delete a failed dispatch through the driver's ordinary cleanup path.
     */
    public function deleteIfEmpty(string $batchId): void
    {
        $this->repositoryFor($batchId)?->deleteIfEmpty($batchId);
    }

    /**
     * Prune finished batches for both drivers.
     */
    public function prune(DateTimeInterface $before): int
    {
        return $this->driver('database')->prune($before) + $this->driver('redis')->prune($before);
    }

    /**
     * Prune unfinished batches for both drivers.
     */
    public function pruneUnfinished(DateTimeInterface $before): int
    {
        return $this->driver('database')->pruneUnfinished($before) + $this->driver('redis')->pruneUnfinished($before);
    }

    /**
     * Prune cancelled batches for both drivers.
     */
    public function pruneCancelled(DateTimeInterface $before): int
    {
        return $this->driver('database')->pruneCancelled($before) + $this->driver('redis')->pruneCancelled($before);
    }
}
