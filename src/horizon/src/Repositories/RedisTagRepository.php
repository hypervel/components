<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Horizon\Contracts\TagRepository;

class RedisTagRepository implements TagRepository
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
     * Get the currently monitored tags.
     */
    public function monitoring(): array
    {
        return (array) $this->connection()->smembers('monitoring');
    }

    /**
     * Return the tags which are being monitored.
     */
    public function monitored(array $tags): array
    {
        return array_intersect($tags, $this->monitoring());
    }

    /**
     * Start monitoring the given tag.
     */
    public function monitor(string $tag): void
    {
        $this->connection()->sadd('monitoring', $tag);
    }

    /**
     * Stop monitoring the given tag.
     */
    public function stopMonitoring(string $tag): void
    {
        $this->connection()->srem('monitoring', $tag);
    }

    /**
     * Store the tags for the given job.
     */
    public function add(string $id, array $tags): void
    {
        $this->connection()->pipeline(function ($pipe) use ($id, $tags) {
            foreach ($tags as $tag) {
                $pipe->zadd($tag, str_replace(',', '.', microtime(true)), $id);
            }
        });
    }

    /**
     * Store the tags for the given job temporarily.
     */
    public function addTemporary(int $minutes, string $id, array $tags): void
    {
        $this->connection()->pipeline(function ($pipe) use ($minutes, $id, $tags) {
            foreach ($tags as $tag) {
                $pipe->zadd($tag, str_replace(',', '.', microtime(true)), $id);

                $pipe->expire($tag, $minutes * 60);
            }
        });
    }

    /**
     * Get the number of jobs matching a given tag.
     */
    public function count(string $tag): int
    {
        return $this->connection()->zcard($tag);
    }

    /**
     * Get all of the job IDs for a given tag.
     */
    public function jobs(string $tag): array
    {
        return (array) $this->connection()->zrange($tag, 0, -1);
    }

    /**
     * Paginate the job IDs for a given tag.
     */
    public function paginate(string $tag, int $startingAt = 0, int $limit = 25): array
    {
        $tags = (array) $this->connection()->zrevrange(
            $tag,
            $startingAt,
            $startingAt + $limit - 1
        );

        return collect($tags)->values()->mapWithKeys(function ($tag, $index) use ($startingAt) {
            return [$index + $startingAt => $tag];
        })->all();
    }

    /**
     * Remove the given job IDs from the given tag.
     */
    public function forgetJobs(array|string $tags, array|string $ids): void
    {
        $this->connection()->pipeline(function ($pipe) use ($tags, $ids) {
            foreach ((array) $tags as $tag) {
                foreach ((array) $ids as $id) {
                    $pipe->zrem($tag, $id);
                }
            }
        });
    }

    /**
     * Delete the given tag from storage.
     */
    public function forget(string $tag): void
    {
        $this->connection()->del($tag);
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
