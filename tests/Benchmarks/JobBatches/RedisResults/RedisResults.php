<?php

declare(strict_types=1);

use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\UpdatedBatchJobCounts;
use Hypervel\Support\CarbonImmutable;

function benchRedis(): Redis
{
    $client = new Redis;
    $client->connect('127.0.0.1', (int) getenv('BENCH_REDIS_PORT'), 5);
    return $client;
}

class ResultBatch extends Batch
{
    public function decrementPendingJobs(string $jobId): ?UpdatedBatchJobCounts
    {
        return $this->repository->success($this->id, $jobId, $this->totalJobs, $this->allowsFailures());
    }
}

class ResultFactory extends BatchFactory
{
    public function make(
        BatchRepository $repository,
        string $id,
        string $name,
        int $totalJobs,
        int $pendingJobs,
        int $failedJobs,
        ?array $failedJobIds,
        array $options,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $cancelledAt,
        ?CarbonImmutable $finishedAt
    ): Batch {
        return new ResultBatch($this->queue, ...func_get_args());
    }
}

/** Benchmark prototype for the success, first-pass and recovery paths. */
class RedisResultsRepository extends DatabaseBatchRepository
{
    private Redis $client;

    protected const VIEW = <<<'LUA'
        return {redis.call('SCARD',KEYS[2]),redis.call('HLEN',KEYS[3]),
                redis.call('HGET',KEYS[1],'failed_count') or 0,
                redis.call('HGET',KEYS[1],'finished_total') or -1,
                redis.call('HGET',KEYS[1],'finished_at') or 0}
        LUA;

    private const SUCCESS = <<<'LUA'
        local job = ARGV[1]
        if redis.call('SISMEMBER',KEYS[2],job) == 1 then return {} end
        local locked = ARGV[3] == '1'
        local known = tonumber(redis.call('HGET',KEYS[1],'known_total'))
        local reported = tonumber(ARGV[2])
        local successes = redis.call('SCARD',KEYS[2]) + 1
        local recovering = redis.call('HEXISTS',KEYS[3],job)
        local outstanding = redis.call('HLEN',KEYS[3]) - recovering
        if not locked and (not known or successes >= math.min(known,reported)
            or (recovering == 0 and successes + outstanding >= math.min(known,reported))) then
            return {-1}
        end
        if locked then redis.call('HSET',KEYS[1],'known_total',reported) end
        redis.call('SADD',KEYS[2],job)
        if recovering == 1 then redis.call('HDEL',KEYS[3],job) end
        local pending = reported - successes
        local first = locked and recovering == 0 and pending == outstanding
        local finished = 0
        if locked and ARGV[5] == '0' and (pending == 0 or (first and ARGV[4] == '1')) then
            if tonumber(redis.call('HGET',KEYS[1],'finished_total')) == reported then
                finished = tonumber(redis.call('HGET',KEYS[1],'finished_at'))
            else
                finished = tonumber(ARGV[6])
                redis.call('HSET',KEYS[1],'finished_total',reported,'finished_at',finished)
            end
        end
        return {pending,tonumber(redis.call('HGET',KEYS[1],'failed_count')) or 0,first and 1 or 0,finished}
        LUA;

    public static function loadScripts(Redis $client): void
    {
        foreach ([self::VIEW, self::SUCCESS] as $script) {
            $client->script('load', $script);
        }
    }

    protected function runScript(string $script, string $id, array $args = []): array
    {
        $this->client ??= benchRedis();
        ++Metrics::$redisCalls;
        $prefix = "batch:{{$id}}:";
        $response = $this->client->evalSha(sha1($script), [$prefix . 'state', $prefix . 'successes', $prefix . 'failures', ...$args], 3);
        if (! is_array($response)) {
            throw new RuntimeException('Redis script failed: ' . $this->client->getLastError());
        }
        return $response;
    }

    protected function toBatch(object $row): Batch
    {
        [$successes, $outstanding, $failed, $finishedTotal, $finishedAt] = $this->runScript(self::VIEW, $row->id);
        $row->pending_jobs = (int) $row->total_jobs - $successes;
        $row->failed_jobs = $failed;
        $row->outstanding_failed_jobs = $outstanding;
        if ($row->finished_at === null && (int) $finishedTotal === (int) $row->total_jobs) {
            $row->finished_at = $finishedAt;
        }
        return parent::toBatch($row);
    }

    public function success(string $id, string $job, int $total, bool $allowsFailures): ?UpdatedBatchJobCounts
    {
        $hash = hash('xxh128', $job);
        $counts = $this->runScript(self::SUCCESS, $id, [$hash, $total, 0, (int) $allowsFailures, 0, time()]);
        if ($counts === [-1]) {
            ++Metrics::$escalations;
            $counts = $this->getConnection()->transaction(function () use ($id, $hash, $allowsFailures) {
                $query = $this->getConnection()->table('job_batches')->where('id', $id)->whereNull('deleted_at');
                $row = (clone $query)->lockForUpdate()->first(['total_jobs', 'finished_at']);
                if ($row === null) {
                    return [];
                }
                $counts = $this->runScript(self::SUCCESS, $id, [$hash, $row->total_jobs, 1,
                    (int) $allowsFailures, $row->finished_at ?? 0, time()]);
                if (($counts[3] ?? 0) > 0) {
                    $query->whereNull('finished_at')->update(['finished_at' => $counts[3]]);
                }
                if ($counts !== []) {
                    $counts[4] = (int) $row->total_jobs;
                }
                return $counts;
            });
        }
        return $counts === [] ? null : new UpdatedBatchJobCounts($counts[0], $counts[1], (bool) $counts[2], $counts[4] ?? $total);
    }

    public function getFailedJobIds(string $batchId): array
    {
        $this->client ??= benchRedis();
        return $this->client->hVals("batch:{{$batchId}}:failures");
    }

    public function successes(string $batchId): int
    {
        return $this->client->sCard("batch:{{$batchId}}:successes");
    }
}

/** Read the Redis snapshot once, on the first access to a progress property. */
class LazyResultBatch extends ResultBatch
{
    public bool $hasResultSnapshot = false;

    public int $pendingJobs {
        get {
            $this->loadResultSnapshot();
            return $this->pendingJobs;
        }
    }

    public int $failedJobs {
        get {
            $this->loadResultSnapshot();
            return $this->failedJobs;
        }
    }

    public ?CarbonImmutable $finishedAt {
        get {
            $this->loadResultSnapshot();
            return $this->finishedAt;
        }
    }

    private function loadResultSnapshot(): void
    {
        if ($this->hasResultSnapshot) {
            return;
        }
        [$successes, , $failed, $finishedTotal, $finishedAt] = $this->repository->snapshot($this->id);
        $this->hasResultSnapshot = true;
        $this->pendingJobs = $this->totalJobs - $successes;
        $this->failedJobs = (int) $failed;
        if ($this->finishedAt === null && (int) $finishedTotal === $this->totalJobs) {
            $this->finishedAt = CarbonImmutable::createFromTimestamp((int) $finishedAt, date_default_timezone_get());
        }
    }
}

class LazyResultFactory extends BatchFactory
{
    public function make(
        BatchRepository $repository,
        string $id,
        string $name,
        int $totalJobs,
        int $pendingJobs,
        int $failedJobs,
        ?array $failedJobIds,
        array $options,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $cancelledAt,
        ?CarbonImmutable $finishedAt
    ): Batch {
        return new LazyResultBatch(
            $this->queue,
            $repository,
            $id,
            $name,
            $totalJobs,
            $pendingJobs,
            $failedJobs,
            null,
            $options,
            $createdAt,
            $cancelledAt,
            $finishedAt
        );
    }
}

class LazyResultsRepository extends RedisResultsRepository
{
    public function snapshot(string $id): array
    {
        return $this->runScript(self::VIEW, $id);
    }

    protected function toBatch(object $row): Batch
    {
        return $this->factory->make(
            $this,
            $row->id,
            $row->name,
            (int) $row->total_jobs,
            0,
            0,
            null,
            $this->unserialize($row->options),
            CarbonImmutable::createFromTimestamp($row->created_at, date_default_timezone_get()),
            $row->cancelled_at === null ? null : CarbonImmutable::createFromTimestamp($row->cancelled_at),
            $row->finished_at === null ? null : CarbonImmutable::createFromTimestamp($row->finished_at)
        );
    }
}
