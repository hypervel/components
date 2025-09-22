<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\ProcessRepository;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;

class RedisProcessRepository implements ProcessRepository
{
    /**
     * Create a new repository instance.
     *
     * @param RedisFactory $redis the Redis connection instance
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
        return $this->connection()->hGetAll(
            "{$master}:orphans"
        );
    }

    /**
     * Record the given process IDs as orphaned.
     */
    public function orphaned(string $master, array $processIds): void
    {
        $time = CarbonImmutable::now()->getTimestamp();

        $shouldRemove = array_diff($this->connection()->hKeys(
            $key = "{$master}:orphans"
        ), $processIds);

        if (! empty($shouldRemove)) {
            $this->connection()->hDel($key, ...$shouldRemove);
        }

        $this->connection()->pipeline(function ($pipe) use ($key, $time, $processIds) {
            foreach ($processIds as $processId) {
                $pipe->hSetNx($key, $processId, $time);
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
        $this->connection()->hDel(
            "{$master}:orphans",
            ...$processIds
        );
    }

    /**
     * Get the Redis connection instance.
     */
    protected function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
