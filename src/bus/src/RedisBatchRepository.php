<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use DateTimeInterface;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use LogicException;
use Redis;
use RuntimeException;

class RedisBatchRepository extends DatabaseBatchRepository
{
    protected const string DRIVER = 'redis';

    private const string SNAPSHOT = <<<'LUA'
        local state = redis.call('HMGET', KEYS[3], 'failed_count', 'known_total', 'finished_at', 'finished_total')
        return {redis.call('SCARD', KEYS[1]), redis.call('HLEN', KEYS[2]),
                tonumber(state[1]) or 0, tonumber(state[2]) or -1,
                tonumber(state[3]) or -1, tonumber(state[4]) or -1}
        LUA;

    private const string FINISH_MARKER = <<<'LUA'
        local f = redis.call('HMGET', KEYS[1], 'finished_total', 'finished_at')
        return tonumber(f[1]) == tonumber(ARGV[1]) and tonumber(f[2]) or -1
        LUA;

    private const string INVALIDATE_TOTAL = "return redis.call('HDEL', KEYS[1], 'known_total')";

    private const string RECORD = <<<'LUA'
        local job, success = ARGV[1], ARGV[2] == 'success'
        if redis.call('SISMEMBER', KEYS[1], job) == 1 then return {} end
        local locked = ARGV[4] ~= ''
        local state = redis.call('HMGET', KEYS[3], 'known_total', 'finished_total', 'finished_at', 'failed_count')
        local total = tonumber(ARGV[4]) or tonumber(state[1])
        if not total then return {-1} end

        local previous = redis.call('HEXISTS', KEYS[2], job)
        local successes = redis.call('SCARD', KEYS[1]) + (success and 1 or 0)
        local outstanding = redis.call('HLEN', KEYS[2]) + (success and -previous or 1-previous)
        local pending = total - successes
        local first = previous == 0 and pending == outstanding
        if not locked and (first or pending == 0) then return {-1} end

        if locked then redis.call('HSET', KEYS[3], 'known_total', total) end
        local failed = tonumber(state[4]) or 0
        if success then
            redis.call('SADD', KEYS[1], job)
            if previous == 1 then redis.call('HDEL', KEYS[2], job) end
        else
            redis.call('HSET', KEYS[2], job, ARGV[3])
            failed = redis.call('HINCRBY', KEYS[3], 'failed_count', 1)
        end

        if total > 0 then
            if first then redis.call('HSET', KEYS[3], 'previous_first_pass_total', total) end
            if pending == 0 then redis.call('HSET', KEYS[3], 'previous_success_total', total) end
        end

        local finished = tonumber(ARGV[6])
        if not finished and tonumber(state[2]) == total then finished = tonumber(state[3]) end
        if not finished and (pending == 0 or (first and ARGV[5] == '1')) then
            finished = tonumber(ARGV[7])
            redis.call('HSET', KEYS[3], 'finished_total', total, 'finished_at', finished)
        end
        return {pending, failed, first and 1 or 0, total, outstanding, finished or -1}
        LUA;

    /**
     * Create a repository for SQL-owned batches with Redis job results.
     */
    public function __construct(
        BatchFactory $factory,
        ConnectionResolverInterface $resolver,
        string $table,
        ?string $connection,
        string $resultsTable,
        private RedisFactory $redis,
        private string $redisConnection,
        string $deletionsTable,
    ) {
        parent::__construct($factory, $resolver, $table, $connection, $resultsTable, $deletionsTable);
    }

    /**
     * Store an empty batch with its known initial progress.
     */
    public function store(PendingBatch $batch): Batch
    {
        return parent::toBatch($this->insertBatch($batch));
    }

    /**
     * Defer loading Redis progress until one of its properties is read.
     */
    protected function toBatch(object $batch): Batch
    {
        $batch->pending_jobs = $batch->failed_jobs = $batch->outstanding_failed_jobs = null;

        return parent::toBatch($batch);
    }

    /**
     * Load paginated progress together before Horizon serializes the page.
     */
    protected function toBatches(array $rows): array
    {
        $batches = [];
        foreach (array_chunk(array_column($rows, null, 'id'), 100, true) as $group) {
            $snapshots = $this->loadSnapshots($group);
            foreach ($group as $id => $row) {
                if (! isset($snapshots[$id])) {
                    continue;
                }
                $counts = $snapshots[$id];
                $row->total_jobs = $counts['totalJobs'];
                $row->pending_jobs = $counts['pendingJobs'];
                $row->failed_jobs = $counts['failedJobs'];
                $row->outstanding_failed_jobs = $counts['outstandingFailedJobs'];
                $row->finished_at = $counts['finishedAt']?->getTimestamp();
                $batches[$id] = parent::toBatch($row);
            }
        }

        return $batches;
    }

    /**
     * Run a raw operation on a borrowed connection with its configured key prefix.
     */
    private function withRedis(Closure $callback): mixed
    {
        return $this->redis->connection($this->redisConnection)->withConnection(
            static fn (RedisConnection $connection) => $connection->withoutSerializationOrCompression(
                static fn () => $callback($connection)
            ),
            transform: false,
        );
    }

    /**
     * Keep one batch's keys in the same Redis Cluster slot.
     */
    private function keys(string $batchId): array
    {
        $prefix = "batches:{{$batchId}}:";

        return [$prefix . 'successes', $prefix . 'failures', $prefix . 'state'];
    }

    /**
     * Get the latest retry IDs of the outstanding failures.
     */
    public function getFailedJobIds(string $batchId): array
    {
        return $this->withRedis(fn (RedisConnection $redis) => $redis->hVals($this->keys($batchId)[1]));
    }

    /**
     * Determine whether the logical job currently has a failed delivery.
     */
    public function hasFailedJob(string $batchId, string $jobId): bool
    {
        return $this->withRedis(fn (RedisConnection $redis) => $redis->hExists($this->keys($batchId)[1], hash('xxh128', $jobId)));
    }

    /**
     * Invalidate the certified total before the SQL transaction can release its lock.
     */
    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $connection = $this->getConnection();
        $increment = function () use ($batchId, $amount): void {
            $this->updateEnrollment($batchId, $amount, []);
            $this->invalidateTotal($batchId);
        };

        if ($connection->transactionLevel() > 0) {
            $increment();
        } else {
            $connection->transaction($increment);
        }
    }

    /**
     * Make the next result reread the total from a locked parent.
     */
    private function invalidateTotal(string $batchId): void
    {
        $this->withRedis(fn (RedisConnection $redis) => $redis->evalWithShaCache(
            self::INVALIDATE_TOTAL,
            [$this->keys($batchId)[2]]
        ));
    }

    /**
     * Withdraw enrollment against a Redis snapshot while the parent is locked.
     */
    protected function releaseFromBatch(Builder $parent, object $row, int $count): ?UpdatedBatchJobCounts
    {
        $script = <<<'LUA'
            redis.call('HDEL', KEYS[3], 'known_total')
            local state = redis.call('HMGET', KEYS[3], 'failed_count', 'previous_first_pass_total',
                                     'previous_success_total', 'finished_total', 'finished_at')
            return {redis.call('SCARD', KEYS[1]), redis.call('HLEN', KEYS[2]), tonumber(state[1]) or 0,
                    tonumber(state[2]) or 0, tonumber(state[3]) or 0, tonumber(state[4]) or -1, tonumber(state[5]) or -1}
            LUA;
        [$succeeded, $outstanding, $failed, $firstPass, $successful, $finishTotal, $finishTime] = $this->withRedis(
            fn (RedisConnection $redis) => $redis->evalWithShaCache($script, $this->keys($row->id))
        );
        $row->pending_jobs = (int) $row->total_jobs - $succeeded;
        $row->outstanding_failed_jobs = $outstanding;
        $row->failed_jobs = $failed;
        $row->previous_first_pass_total = max((int) $row->previous_first_pass_total, $firstPass);
        $row->previous_success_total = max((int) $row->previous_success_total, $successful);
        if ($finishTotal === (int) $row->total_jobs - $count && (int) $row->previous_finished_total !== $finishTotal) {
            // A Redis finish may have committed before its SQL projection.
            $row->previous_finished_total = $finishTotal;
            $row->previous_finished_at = $finishTime;
        }

        return parent::releaseFromBatch($parent, $row, $count);
    }

    /**
     * Record ordinary results in Redis, locking the parent only to certify or finish a batch.
     */
    protected function recordJobResult(string $batchId, string $jobId, ?string $retryId): ?UpdatedBatchJobCounts
    {
        if ($this->hasOwnerTransaction()) {
            throw new LogicException("Redis results for batch [{$batchId}] cannot be recorded inside its database transaction.");
        }

        $keys = $this->keys($batchId);
        $arguments = [hash('xxh128', $jobId), $retryId === null ? 'success' : 'failure', $retryId ?? ''];
        $result = $this->withRedis(static fn (RedisConnection $redis) => $redis->evalWithShaCache(
            self::RECORD,
            $keys,
            [...$arguments, '', '', '', '']
        ));

        if ($result === [-1]) {
            $result = $this->getConnection()->transaction(function () use ($keys, $arguments, $batchId) {
                $parent = $this->lockParents([$batchId]);
                $row = (clone $parent)->first(['total_jobs', 'finished_at', 'options']);
                if ($row === null) {
                    return [];
                }
                $allowsFailures = ($this->unserialize($row->options)['allowFailures'] ?? false) === true;
                $result = $this->withRedis(static fn (RedisConnection $redis) => $redis->evalWithShaCache(
                    self::RECORD,
                    $keys,
                    [...$arguments, $row->total_jobs, (int) $allowsFailures, $row->finished_at ?? '', time()]
                ));
                if ($result !== [] && $result[5] !== -1 && $row->finished_at === null) {
                    $parent->update(['finished_at' => $result[5]]);
                }

                return $result;
            });
        }

        return $result === [] ? null : new UpdatedBatchJobCounts(
            $result[0],
            $result[1],
            (bool) $result[2],
            $result[3],
            $result[4],
            $this->finishDate($result[5]),
        );
    }

    /**
     * Determine whether Redis would observe uncommitted SQL results on this connection.
     */
    private function hasOwnerTransaction(): bool
    {
        $connection = $this->getConnection();

        return $connection instanceof Connection ? $connection->hasApplicableTransaction() : $connection->transactionLevel() > 0;
    }

    /**
     * Recover automatic finishes and drain committed deletions during normal retention.
     */
    public function prune(DateTimeInterface $before): int
    {
        $connection = $this->getConnection();
        $this->drainResultDeletions();

        // A worker may have committed the Redis finish marker and lost its SQL projection.
        $connection->table($this->table)->useWritePdo()->where('driver', static::DRIVER)
            ->whereNull('finished_at')->where('created_at', '<', $before->getTimestamp())
            ->select(['id', 'total_jobs'])->chunkById(100, function (Collection $rows) use ($connection) {
                $snapshots = $this->readSnapshots($rows->pluck('id')->all());
                foreach ($rows as $row) {
                    [, , , , $finished, $finishedTotal] = $snapshots[$row->id];
                    if ($finished !== -1 && $finishedTotal === (int) $row->total_jobs) {
                        $connection->transaction(function () use ($row) {
                            $parent = $this->lockParents([$row->id]);
                            $live = (clone $parent)->whereNull('finished_at')->first(['total_jobs']);
                            if ($live !== null && ($finish = $this->finishMarker($row->id, (int) $live->total_jobs)) !== null) {
                                $parent->update(['finished_at' => $finish]);
                            }
                        });
                    }
                }
            });

        return parent::prune($before);
    }

    /**
     * Read the finish only if the marker applies to the current enrollment.
     */
    private function finishMarker(string $batchId, int $total): ?int
    {
        $finish = $this->withRedis(fn (RedisConnection $redis) => $redis->evalWithShaCache(
            self::FINISH_MARKER,
            [$this->keys($batchId)[2]],
            [$total]
        ));

        return $finish === -1 ? null : $finish;
    }

    /**
     * Remove eligible SQL parents and leave a durable record until Redis cleanup succeeds.
     */
    protected function deleteBatches(Builder $query, array $ids, bool $retryIndividually = false): int
    {
        if ($ids === []) {
            return 0;
        }

        $connection = $this->getConnection();
        return $connection->transaction(function () use ($connection, $query, $ids) {
            $parents = $this->lockParents($ids)->get(['id', 'total_jobs', 'finished_at']);
            $markers = [];
            foreach ($parents as $row) {
                if ($row->finished_at === null) {
                    $markers[$row->id] = [[$this->keys($row->id)[2]], [(int) $row->total_jobs]];
                }
            }
            foreach ($this->evalEach(self::FINISH_MARKER, $markers) as $id => $finish) {
                if ($finish !== -1) {
                    $connection->table($this->table)->where('id', $id)->update(['finished_at' => $finish]);
                }
            }

            $eligible = (clone $query)->whereIn('id', $parents->pluck('id'))->lockForUpdate()->pluck('id')->all();
            if ($eligible === []) {
                return 0;
            }
            $totals = [];
            foreach ($eligible as $id) {
                $totals[$id] = [[$this->keys($id)[2]], []];
            }
            $this->evalEach(self::INVALIDATE_TOTAL, $totals);
            $deleted = $connection->table($this->table)->whereIn('id', $eligible)->delete();
            $this->recordResultDeletions($eligible);

            return $deleted;
        });
    }

    /**
     * Unlink each batch's results in its own Redis Cluster slot.
     */
    protected function deleteJobResults(array $ids): void
    {
        $results = [];
        foreach ($ids as $id) {
            $results[$id] = [$this->keys($id), []];
        }
        $this->evalEach("return redis.call('UNLINK', unpack(KEYS))", $results);
    }

    /**
     * Convert a result timestamp into the batch's immutable finish date.
     */
    private function finishDate(int $timestamp): ?CarbonImmutable
    {
        return $timestamp === -1 ? null : CarbonImmutable::createFromTimestamp($timestamp, date_default_timezone_get());
    }

    /**
     * Load the progress of a batch returned by find().
     */
    public function loadJobCounts(string $batchId, int $totalAtLookup, ?CarbonImmutable $finishedAtLookup): ?array
    {
        $row = (object) ['id' => $batchId, 'total_jobs' => $totalAtLookup, 'finished_at' => $finishedAtLookup?->getTimestamp()];

        return $this->loadSnapshots([$batchId => $row])[$batchId] ?? null;
    }

    /**
     * Read the progress of a bounded group without taking a lock on unchanged waiting batches.
     *
     * @param array<string, object> $rows
     */
    protected function loadSnapshots(array $rows): array
    {
        $views = $this->readSnapshots(array_keys($rows));
        $counts = $waiting = $conflicts = [];
        $inTransaction = null;
        foreach ($views as $id => $view) {
            [$successes, $outstanding, $failed, $total, $finished, $finishedTotal] = $view;
            $row = $rows[$id];
            if ($total < 0 && $successes === 0 && $failed === 0 && $finished < 0 && ! ($inTransaction ??= $this->hasOwnerTransaction())) {
                $waiting[] = $id;
            } elseif ($total < 0 || $successes + $outstanding > $total
                || ($row->finished_at !== null && ((int) $row->total_jobs !== $total
                    || ($finishedTotal === $total && (int) $row->finished_at !== $finished)))) {
                $conflicts[] = $id;
            } else {
                $counts[$id] = $this->snapshotCounts($view, $total, $row->finished_at);
            }
        }

        if ($waiting !== []) {
            $live = $this->getConnection()->table($this->table)->useWritePdo()->whereIn('id', $waiting)->get(['id', 'total_jobs', 'finished_at']);
            foreach ($live as $row) {
                $id = $row->id;
                $finishedAt = $row->finished_at === null ? null : (int) $row->finished_at;
                $finishedAtLookup = $rows[$id]->finished_at === null ? null : (int) $rows[$id]->finished_at;
                if ((int) $row->total_jobs === (int) $rows[$id]->total_jobs && $finishedAt === $finishedAtLookup) {
                    $counts[$id] = $this->snapshotCounts($views[$id], (int) $row->total_jobs, $row->finished_at);
                } else {
                    $conflicts[] = $id;
                }
            }
        }

        if ($conflicts !== []) {
            $counts += $this->getConnection()->transaction(function () use ($conflicts) {
                $rows = $this->lockParents($conflicts)->get(['id', 'total_jobs', 'finished_at'])->keyBy('id');
                $counts = [];
                foreach ($this->readSnapshots($rows->keys()->all()) as $id => $view) {
                    $row = $rows[$id];
                    $total = (int) $row->total_jobs;
                    if ($view[0] + $view[1] > $total || ($view[3] !== -1 && $view[3] !== $total)) {
                        throw new RuntimeException("Batch [{$id}] changed outside the visible database snapshot. Retry the transaction.");
                    }
                    $counts[$id] = $this->snapshotCounts($view, $total, $row->finished_at);
                }
                return $counts;
            });
        }

        return $counts;
    }

    /**
     * Read the counters and completion marker together for each batch.
     */
    private function readSnapshots(array $ids): array
    {
        $snapshots = [];
        foreach ($ids as $id) {
            $snapshots[$id] = [$this->keys($id), []];
        }

        return $this->evalEach(self::SNAPSHOT, $snapshots);
    }

    /**
     * Run a script once per batch, sharing one round trip when a single node holds every key.
     *
     * @param array<array-key, array{list<string>, list<int|string>}> $calls the keys and arguments keyed by batch ID
     * @return array<array-key, mixed>
     */
    private function evalEach(string $script, array $calls): array
    {
        if ($calls === []) {
            return [];
        }

        return $this->withRedis(static function (RedisConnection $redis) use ($script, $calls) {
            if (count($calls) === 1 || $redis->isCluster()) {
                $results = [];
                foreach ($calls as $id => [$keys, $arguments]) {
                    $results[$id] = $redis->evalWithShaCache($script, $keys, $arguments);
                }

                return $results;
            }

            // Send EVAL in pipelines so a cold script cache needs no mid-pipeline NOSCRIPT retry.
            $client = $redis->client();
            assert($client instanceof Redis);
            $pipeline = $client->pipeline();
            foreach ($calls as [$keys, $arguments]) {
                $pipeline->eval($script, [...$keys, ...$arguments], count($keys));
            }
            // Execute through the connection so a failover error keeps this client out of the pool.
            $results = $redis->exec();
            if ($results === false || in_array(false, $results, true)) {
                throw new RuntimeException('Unable to run the Redis batch script: ' . $client->getLastError());
            }

            return array_combine(array_keys($calls), $results);
        });
    }

    /**
     * Combine a Redis snapshot with its matching SQL lifecycle state.
     */
    private function snapshotCounts(array $view, int $total, int|string|null $finishedAt): array
    {
        [$successes, $outstanding, $failed, , $finished, $finishedTotal] = $view;

        return [
            'totalJobs' => $total,
            'pendingJobs' => $total - $successes,
            'failedJobs' => $failed,
            'outstandingFailedJobs' => $outstanding,
            'finishedAt' => $this->finishDate($finishedAt !== null ? (int) $finishedAt : ($finishedTotal === $total ? $finished : -1)),
        ];
    }
}
