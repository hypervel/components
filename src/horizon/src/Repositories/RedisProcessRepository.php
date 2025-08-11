<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Horizon\Contracts\ProcessRepository;

class RedisProcessRepository implements ProcessRepository
{
    /**
     * Create a new repository instance.
     *
     * @param RedisFactory $redis The Redis connection instance.
     */
    public function __construct(
        public RedisFactory $redis
    ) {
    }

    /**
     * Get all of the orphan process IDs and the times they were observed.
     */
    public function allOrphans(string $master): array
    {
        return $this->connection()->hgetall(
            "{$master}:orphans"
        );
    }

    /**
     * Record the given process IDs as orphaned.
     */
    public function orphaned(string $master, array $processIds): void
    {
        $time = CarbonImmutable::now()->getTimestamp();

        $shouldRemove = array_diff($this->connection()->hkeys(
            $key = "{$master}:orphans"
        ), $processIds);

        if (! empty($shouldRemove)) {
            $this->connection()->hdel($key, ...$shouldRemove);
        }

        $this->connection()->pipeline(function ($pipe) use ($key, $time, $processIds) {
            foreach ($processIds as $processId) {
                $pipe->hsetnx($key, $processId, $time);
            }
        });
    }

    /**
     * Get the process IDs orphaned for at least the given number of seconds.
     */
    public function orphanedFor(string $master, int $seconds): array
    {
        $expiresAt = CarbonImmutable::now()->getTimestamp() - $seconds;

        return collect($this->allOrphans($master))->filter(function ($recordedAt, $_) use ($expiresAt) {
            return $expiresAt > $recordedAt;
        })->keys()->all();
    }

    /**
     * Remove the given process IDs from the orphan list.
     */
    public function forgetOrphans(string $master, array $processIds): void
    {
        $this->connection()->hdel(
            "{$master}:orphans",
            ...$processIds
        );
    }

    /**
     * Get the Redis connection instance.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function connection()
    {
        return $this->redis->connection('horizon');
    }
}
