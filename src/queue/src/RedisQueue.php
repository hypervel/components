<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Str;

class RedisQueue extends Queue implements QueueContract, ClearableQueue
{
    /**
     * Indicates if a secondary queue had a job available between checks of the primary queue.
     *
     * Only applicable when monitoring multiple named queues with a single instance.
     */
    protected bool $secondaryQueueHadJob = false;

    /**
     * Create a new Redis queue instance.
     *
     * @param RedisFactory $redis the Redis factory implementation
     * @param string $default the connection name
     * @param null|string $connection the connection name
     * @param int $retryAfter the expiration time of a job
     * @param null|int $blockFor the maximum number of seconds to block for a job
     * @param int $migrationBatchSize The batch size to use when migrating delayed / expired jobs onto the primary queue. Negative values are infinite.
     */
    public function __construct(
        protected RedisFactory $redis,
        protected string $default = 'default',
        protected ?string $connection = null,
        protected int $retryAfter = 60,
        protected ?int $blockFor = null,
        protected bool $dispatchAfterCommit = false,
        protected int $migrationBatchSize = -1
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        $queue = $this->getQueue($queue);

        return $this->getConnection()->eval(
            LuaScripts::size(),
            3,
            $queue,
            $queue . ':delayed',
            $queue . ':reserved',
        );
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->getConnection()->llen($this->getQueue($queue));
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->getConnection()->zcard($this->getQueue($queue) . ':delayed');
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->getConnection()->zcard($this->getQueue($queue) . ':reserved');
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        $payload = $this->getConnection()->lindex($this->getQueue($queue), 0);

        if (! $payload) {
            return null;
        }

        $data = json_decode($payload, true);

        return $data['createdAt'] ?? null;
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        $this->getConnection()->pipeline(function () use ($jobs, $data, $queue) {
            $this->getConnection()->transaction(function () use ($jobs, $data, $queue) {
                foreach ((array) $jobs as $job) {
                    if (isset($job->delay)) {
                        $this->later($job->delay, $job, $data, $queue);
                    } else {
                        $this->push($job, $data, $queue);
                    }
                }
            });
        });

        return null;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $this->getConnection()->eval(
            LuaScripts::push(),
            2,
            $this->getQueue($queue),
            $this->getQueue($queue) . ':notify',
            $payload,
        );

        return json_decode($payload, true)['id'] ?? null;
    }

    /**
     * Push a new job onto the queue after a delay.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return $this->laterRaw($delay, $payload, $queue);
            }
        );
    }

    /**
     * Push a raw job onto the queue after (n) seconds.
     */
    protected function laterRaw(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue = null): mixed
    {
        $this->getConnection()->zadd(
            $this->getQueue($queue) . ':delayed',
            $this->availableAt($delay),
            $payload
        );

        return json_decode($payload, true)['id'] ?? null;
    }

    /**
     * Create a payload string from the given job and data.
     */
    protected function createPayloadArray(array|object|string $job, ?string $queue, mixed $data = ''): array
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'id' => $this->getRandomId(),
            'attempts' => 0,
        ]);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null, int $index = 0): ?JobContract
    {
        $this->migrate($prefixed = $this->getQueue($queue));

        $block = ! $this->secondaryQueueHadJob && $index == 0;

        [$job, $reserved] = $this->retrieveNextJob($prefixed, $block);

        if ($index == 0) {
            $this->secondaryQueueHadJob = false;
        }

        if ($reserved) {
            if ($index > 0) {
                $this->secondaryQueueHadJob = true;
            }

            return new RedisJob(
                $this->container,
                $this,
                $job,
                $reserved,
                $this->connectionName,
                $queue ?: $this->default
            );
        }

        return null;
    }

    /**
     * Migrate any delayed or expired jobs onto the primary queue.
     */
    protected function migrate(string $queue): void
    {
        $this->migrateExpiredJobs($queue . ':delayed', $queue);
        $this->migrateExpiredJobs($queue . ':reserved', $queue);
    }

    /**
     * Migrate the delayed jobs that are ready to the regular queue.
     */
    public function migrateExpiredJobs(string $from, string $to): array
    {
        return $this->getConnection()->eval(
            LuaScripts::migrateExpiredJobs(),
            3,
            $from,
            $to,
            $to . ':notify',
            $this->currentTime(),
            $this->migrationBatchSize,
        );
    }

    /**
     * Retrieve the next job from the queue.
     */
    protected function retrieveNextJob(string $queue, bool $block = true): array
    {
        $nextJob = $this->getConnection()->eval(
            LuaScripts::pop(),
            3,
            $queue,
            $queue . ':reserved',
            $queue . ':notify',
            $this->availableAt($this->retryAfter),
        );

        if (empty($nextJob)) {
            return [null, null];
        }

        [$job, $reserved] = $nextJob;

        if (! $job && ! is_null($this->blockFor) && $block
            && $this->getConnection()->blpop([$queue . ':notify'], $this->blockFor)
        ) {
            return $this->retrieveNextJob($queue, false);
        }

        return [$job, $reserved];
    }

    /**
     * Delete a reserved job from the queue.
     */
    public function deleteReserved(string $queue, RedisJob $job): void
    {
        $this->getConnection()->zrem($this->getQueue($queue) . ':reserved', $job->getReservedJob());
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    public function deleteAndRelease(string $queue, RedisJob $job, DateInterval|DateTimeInterface|int $delay): void
    {
        $queue = $this->getQueue($queue);

        $this->getConnection()->eval(
            LuaScripts::release(),
            2,
            $queue . ':delayed',
            $queue . ':reserved',
            $job->getReservedJob(),
            $this->availableAt($delay),
        );
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(string $queue): int
    {
        $queue = $this->getQueue($queue);

        return $this->getConnection()
            ->eval(
                LuaScripts::clear(),
                4,
                $queue,
                $queue . ':delayed',
                $queue . ':reserved',
                $queue . ':notify',
            );
    }

    /**
     * Get a random ID string.
     */
    protected function getRandomId(): string
    {
        return Str::random(32);
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        return 'queues:' . ($queue ?: $this->default);
    }

    /**
     * Get the connection for the queue.
     */
    public function getConnection(): RedisProxy
    {
        return $this->redis->get($this->connection);
    }

    /**
     * Get the underlying Redis instance.
     */
    public function getRedis(): RedisFactory
    {
        return $this->redis;
    }
}
