<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;

class RedisTagRepository implements TagRepository
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
     * Get the currently monitored tags.
     */
    public function monitoring(): array
    {
        return (array) $this->connection()->sMembers('monitoring');
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
        $this->connection()->sAdd('monitoring', $tag);
    }

    /**
     * Stop monitoring the given tag.
     */
    public function stopMonitoring(string $tag): void
    {
        $this->connection()->sRem('monitoring', $tag);
    }

    /**
     * Store the tags for the given job.
     */
    public function add(string $id, array $tags): void
    {
        $this->connection()->pipeline(function ($pipe) use ($id, $tags) {
            foreach ($tags as $tag) {
                $pipe->zAdd($tag, str_replace(',', '.', microtime(true)), $id);
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
                $pipe->zAdd($tag, str_replace(',', '.', microtime(true)), $id);

                $pipe->expire($tag, $minutes * 60);
            }
        });
    }

    /**
     * Get the number of jobs matching a given tag.
     */
    public function count(string $tag): int
    {
        return $this->connection()->zCard($tag);
    }

    /**
     * Get all of the job IDs for a given tag.
     */
    public function jobs(string $tag): array
    {
        return (array) $this->connection()->zRange($tag, 0, -1);
    }

    /**
     * Paginate the job IDs for a given tag.
     */
    public function paginate(string $tag, int $startingAt = 0, int $limit = 25): array
    {
        $tags = (array) $this->connection()->zRevRange(
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
                    $pipe->zRem($tag, $id);
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
     */
    protected function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
