<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

trait UsesClusterAwarePipeline
{
    /**
     * Execute commands in a pipeline, falling back to a transaction.
     */
    protected function pipeline(callable $callback): array
    {
        $connection = $this->connection();

        // Horizon hash-tags its Cluster prefix, so this remains one atomic
        // single-slot batch. Horizon never uses WATCH, so EXEC cannot abort.
        /** @var array<int, mixed> $result */
        $result = $connection->isCluster()
            ? $connection->transaction($callback)
            : $connection->pipeline($callback);

        return $result;
    }
}
