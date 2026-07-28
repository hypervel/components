<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Collection;
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
     * Indicates if the connection is a Redis Cluster connection.
     */
    protected ?bool $isCluster = null;

    /**
     * Create a new Redis queue instance.
     *
     * @param Redis $redis the Redis factory implementation
     * @param string $default the connection name
     * @param null|string $connection the connection name
     * @param null|int $retryAfter the expiration time of a job
     * @param null|int $blockFor the maximum number of seconds to block for a job
     * @param int $migrationBatchSize The batch size to use when migrating delayed / expired jobs onto the primary queue. Negative values are infinite.
     */
    public function __construct(
        protected Redis $redis,
        protected string $default = 'default',
        protected ?string $connection = null,
        protected ?int $retryAfter = 60,
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
        $queue = $this->getQueueRedisKey($queue);

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
        return $this->getConnection()->llen($this->getQueueRedisKey($queue));
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->getConnection()->zcard($this->getQueueRedisKey($queue) . ':delayed');
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->getConnection()->zcard($this->getQueueRedisKey($queue) . ':reserved');
    }

    /**
     * Get the pending jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return $this->inspectJobs($queue);
    }

    /**
     * Get the delayed jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return $this->inspectJobs($queue, ':delayed');
    }

    /**
     * Get the reserved jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return $this->inspectJobs($queue, ':reserved');
    }

    /**
     * Get all pending jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allPendingJobs(): Collection
    {
        return $this->inspectAllQueues();
    }

    /**
     * Get all delayed jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allDelayedJobs(): Collection
    {
        return $this->inspectAllQueues(':delayed');
    }

    /**
     * Get all reserved jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allReservedJobs(): Collection
    {
        return $this->inspectAllQueues(':reserved');
    }

    /**
     * Inspect jobs from one queue while holding one Redis connection.
     *
     * @return Collection<int, InspectedJob>
     */
    protected function inspectJobs(?string $queue, string $suffix = ''): Collection
    {
        $name = $queue === null || $queue === '' ? $this->default : $queue;

        return $this->getConnection()->withConnection(
            function (RedisConnection $connection) use ($name, $suffix): Collection {
                $this->isCluster ??= $connection->isCluster();

                return $this->inspectJobsUsing($connection, $name, $suffix);
            },
            transform: false,
        );
    }

    /**
     * Inspect jobs across every queue while holding one Redis connection.
     *
     * @return Collection<int, InspectedJob>
     */
    protected function inspectAllQueues(string $suffix = ''): Collection
    {
        return $this->getConnection()->withConnection(
            function (RedisConnection $connection) use ($suffix): Collection {
                $this->isCluster ??= $connection->isCluster();
                $names = [];

                foreach ($connection->safeScan('queues:*') as $key) {
                    $name = substr($key, strlen('queues:'));

                    foreach ([':delayed', ':reserved', ':notify'] as $storageSuffix) {
                        if (str_ends_with($name, $storageSuffix)) {
                            $name = substr($name, 0, -strlen($storageSuffix));
                            break;
                        }
                    }

                    if ($this->isCluster && preg_match('/^\{([^{}]+)\}$/', $name, $matches) === 1) {
                        $name = $matches[1];
                    }

                    $names[$name] = true;
                }

                return Collection::make(array_keys($names))
                    ->flatMap(fn (string $name): Collection => $this->inspectJobsUsing($connection, $name, $suffix));
            },
            transform: false,
        );
    }

    /**
     * Inspect one Redis queue using an already-held raw connection.
     *
     * @return Collection<int, InspectedJob>
     */
    protected function inspectJobsUsing(RedisConnection $connection, string $name, string $suffix): Collection
    {
        $key = $this->getQueueRedisKey($name) . $suffix;
        $payloads = $suffix === ''
            ? $connection->lrange($key, 0, -1)
            : $connection->zRange($key, 0, -1);

        return Collection::make($payloads ?: [])
            ->map(fn (string $payload): InspectedJob => InspectedJob::fromPayload($payload, queue: $name));
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        $payload = $this->getConnection()->lindex($this->getQueueRedisKey($queue), 0);

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
            static function (RedisQueue $owner, string $payload, ?string $queue) {
                return $owner->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $queue = $this->getQueueRedisKey($queue);

        $this->getConnection()->eval(
            LuaScripts::push(),
            2,
            $queue,
            $queue . ':notify',
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
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            static function (
                RedisQueue $owner,
                string $payload,
                ?string $queue,
                DateInterval|DateTimeInterface|int $delay
            ) {
                return $owner->laterRaw($delay, $payload, $queue);
            }
        );
    }

    /**
     * Push a raw job onto the queue after (n) seconds.
     */
    protected function laterRaw(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue = null): mixed
    {
        $queue = $this->getQueueRedisKey($queue);

        $this->getConnection()->eval(
            LuaScripts::later(),
            1,
            $queue . ':delayed',
            $this->availableAt($delay),
            $payload,
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
        $this->migrate($prefixed = $this->getQueueRedisKey($queue));

        $block = ! $this->secondaryQueueHadJob && $index === 0;

        [$job, $reserved, $attempts] = $this->retrieveNextJob($prefixed, $block);

        if ($index === 0) {
            $this->secondaryQueueHadJob = false;
        }

        if ($reserved !== false) {
            if ($index > 0) {
                $this->secondaryQueueHadJob = true;
            }

            return new RedisJob(
                $this->container,
                $this,
                $job,
                $reserved,
                $this->connectionName,
                $queue === null || $queue === '' ? $this->default : $queue,
                $attempts === false ? null : $attempts,
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

        if (! is_null($this->retryAfter)) {
            $this->migrateExpiredJobs($queue . ':reserved', $queue);
        }
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
     *
     * @return array{false|string, false|string, false|int}
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
            return [false, false, false];
        }

        [$job, $reserved, $attempts] = $nextJob;

        if ($job === false && ! is_null($this->blockFor) && $block
            && $this->getConnection()->blpop([$queue . ':notify'], $this->blockFor)
        ) {
            return $this->retrieveNextJob($queue, false);
        }

        return [$job, $reserved, $attempts];
    }

    /**
     * Delete a reserved job from the queue.
     */
    public function deleteReserved(string $queue, RedisJob $job): void
    {
        $this->getConnection()->zrem($this->getQueueRedisKey($queue) . ':reserved', $job->getReservedJob());
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    public function deleteAndRelease(string $queue, RedisJob $job, DateInterval|DateTimeInterface|int $delay): void
    {
        $queue = $this->getQueueRedisKey($queue);

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
    public function clear(?string $queue): int
    {
        $queue = $this->getQueueRedisKey($queue);

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
        return 'queues:' . ($queue === null || $queue === '' ? $this->default : $queue);
    }

    /**
     * Get the cluster-safe Redis key for the given queue.
     *
     * Redis Cluster requires every key passed to a multi-key Lua script to live
     * on the same hash slot. Queue payloads keep the logical queue name via
     * getQueue(); only storage keys are hash-tagged here.
     */
    protected function getQueueRedisKey(?string $queue = null): string
    {
        $queue = $queue === null || $queue === '' ? $this->default : $queue;

        return $this->isClusterConnection() && ! RedisConnection::hasHashTag($queue)
            ? $this->getQueue('{' . $queue . '}')
            : $this->getQueue($queue);
    }

    /**
     * Determine if the queue connection is a Redis Cluster connection.
     */
    protected function isClusterConnection(): bool
    {
        return $this->isCluster ??= $this->getConnection()->isCluster();
    }

    /**
     * Get the connection for the queue.
     */
    public function getConnection(): RedisProxy
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * Get the underlying Redis instance.
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }
}
