<?php

declare(strict_types=1);

use Hypervel\Bus\Batch;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\UpdatedBatchJobCounts as Counts;
use Hypervel\Database\PostgresConnection;

class OutcomeBatch extends Batch
{
    public function __construct(
        $queue,
        $repository,
        $id,
        $name,
        $totalJobs,
        $pendingJobs,
        $failedJobs,
        $failedJobIds,
        $options,
        $createdAt,
        $cancelledAt,
        $finishedAt
    ) {
        $this->queue = $queue;
        $this->repository = $repository;
        $this->id = $id;
        $this->name = $name;
        $this->totalJobs = $totalJobs;
        $this->pendingJobs = $pendingJobs;
        $this->failedJobs = $failedJobs;
        $this->options = $options;
        $this->createdAt = $createdAt;
        $this->cancelledAt = $cancelledAt;
        $this->finishedAt = $finishedAt;
    }

    public array $failedJobIds {
        &get {
            if (! isset($this->failedJobIds)) {
                $this->failedJobIds = $this->repository->failureIds($this->id);
            }

            return $this->failedJobIds;
        }
    }

    public ?Counts $alreadyCounted = null;

    public function decrementPendingJobs(string $jobId): ?Counts
    {
        if ($this->alreadyCounted !== null) {
            $result = $this->alreadyCounted;
            $this->alreadyCounted = null;
            return $result;
        }

        return parent::decrementPendingJobs($jobId);
    }
}

class CountingPrototype extends DatabaseBatchRepository
{
    public bool $outcomes = false;

    public bool $combined = false;

    public bool $captureBatch = false;

    public ?object $countedBatch = null;

    public function markAsFinished(string $batchId): void
    {
        // B and C persist the finish in the counting transaction.
    }

    protected function toBatch(object $row): Batch
    {
        $lookupIds = [];
        if (! $this->outcomes) {
            $entries = json_decode($row->failed_job_ids, true, flags: JSON_THROW_ON_ERROR);
            foreach ($entries as $entry) {
                $lookupIds[] = is_array($entry) ? $entry[1] : $entry;
            }
        }
        $batch = $this->factory->make(
            $this,
            $row->id,
            $row->name,
            (int) $row->total_jobs,
            (int) $row->pending_jobs,
            (int) $row->failed_jobs,
            $lookupIds,
            $this->unserialize($row->options),
            Hypervel\Support\CarbonImmutable::createFromTimestamp($row->created_at, date_default_timezone_get()),
            $row->cancelled_at ? Hypervel\Support\CarbonImmutable::createFromTimestamp($row->cancelled_at, date_default_timezone_get()) : null,
            $row->finished_at ? Hypervel\Support\CarbonImmutable::createFromTimestamp($row->finished_at, date_default_timezone_get()) : null,
        );
        if ($this->outcomes && $row->outstanding === 0) {
            $batch->failedJobIds = [];
        }

        return $batch;
    }

    public function failureIds(string $batchId): array
    {
        return $this->getConnection()->table('job_outcomes')->useWritePdo()
            ->where('batch_id', $batchId)->whereNotNull('retry_id')->pluck('retry_id')->all();
    }

    public function recordWithoutLookup(string $batchId, string $jobId): void
    {
        $this->countedBatch = null;
        $counts = $this->decrementPendingJobs($batchId, $jobId);
        if ($counts !== null) {
            $batch = $this->toBatch($this->countedBatch);
            $batch->alreadyCounted = $counts;
            $batch->recordSuccessfulJob($jobId);
        }
    }

    public function decrementPendingJobs(string $batchId, string $jobId): ?Counts
    {
        $db = $this->getConnection();
        $key = ['batch_id' => $batchId, 'job_id_hash' => hash('xxh128', $jobId)];
        return $db->transaction(function () use ($db, $batchId, $jobId, $key) {
            $child = $db->table('job_outcomes');
            if ($this->combined) {
                // Separate inserted/updated results distinguish missing parent from duplicate.
                $columns = $this->captureBatch ? 'b.*' : 'b.pending_jobs,b.failed_jobs,b.outstanding,b.finished_at,b.options';
                $row = $db->selectOne(
                    "with ins as (
                    insert into job_outcomes(batch_id,job_id_hash) values (?,?) on conflict do nothing returning batch_id
                ), upd as (
                    update job_batches b set pending_jobs=b.pending_jobs-1,
                    finished_at=case when b.pending_jobs=1 then coalesce(b.finished_at, ?) else b.finished_at end
                    from ins where b.id=ins.batch_id returning {$columns}
                ) select ins.batch_id,upd.* from ins left join upd on true",
                    [$batchId, $key['job_id_hash'], time()],
                    false
                );
                if ($row !== null) {
                    if ($row->pending_jobs === null) {
                        $child->where($key)->delete();
                        return null;
                    }
                    return $this->finishReturned($batchId, $row, false);
                }
                $inserted = false;
            } else {
                $inserted = $child->insertOrIgnore($key) === 1;
            }

            $wasFailed = false;
            if (! $inserted) {
                if (! $this->outcomes || $child->where($key)->whereNotNull('retry_id')->update(['retry_id' => null]) === 0) {
                    return null;
                }
                $wasFailed = true;
            }

            $parent = $db->table('job_batches')->where('id', $batchId);
            if ($db instanceof PostgresConnection) {
                $fields = $this->captureBatch ? '*' : 'pending_jobs,failed_jobs,finished_at,options,' . ($this->outcomes ? 'outstanding' : 'failed_job_ids');
                $adjust = $this->outcomes && $wasFailed ? ', outstanding=outstanding-1' : '';
                $row = $db->selectOne("update job_batches set pending_jobs=pending_jobs-1,
                    finished_at=case when pending_jobs=1 then coalesce(finished_at, ?) else finished_at end
                    {$adjust} where id=? returning {$fields}", [time(), $batchId], false);
                if ($row !== null) {
                    if ($this->outcomes) {
                        return $this->finishReturned($batchId, $row, $wasFailed);
                    }
                    return $this->updateJson($parent, $row, $jobId, true);
                }
            } else {
                $row = $parent->lockForUpdate()->first();
                if ($row !== null) {
                    if (! $this->outcomes) {
                        return $this->updateJson($parent, $row, $jobId, false);
                    }
                    --$row->pending_jobs;
                    $row->outstanding -= (int) $wasFailed;
                    $first = ! $wasFailed && $row->pending_jobs === $row->outstanding;
                    $values = ['pending_jobs' => $row->pending_jobs];
                    if ($wasFailed) {
                        $values['outstanding'] = $row->outstanding;
                    }
                    $values += $this->finishValues($row, $first);
                    $parent->update($values);
                    if ($this->captureBatch) {
                        $this->countedBatch = $row;
                    }
                    return new Counts($row->pending_jobs, $row->failed_jobs, $first);
                }
            }

            $child->where($key)->delete();
            return null;
        }, attempts: 3);
    }

    private function updateJson($parent, object $row, string $jobId, bool $returned): Counts
    {
        if (! $returned) {
            --$row->pending_jobs;
        }
        $before = json_decode($row->failed_job_ids, true, flags: JSON_THROW_ON_ERROR);
        $after = array_values(array_filter($before, static fn ($entry) => (is_array($entry) ? $entry[0] : $entry) !== $jobId));
        $first = $this->completesFirstPass($row->pending_jobs + 1 - count($before), $row->pending_jobs - count($after));
        $values = $returned ? [] : ['pending_jobs' => $row->pending_jobs, 'failed_jobs' => $row->failed_jobs];
        if (! $returned || $before !== $after) {
            $values['failed_job_ids'] = json_encode($after, JSON_THROW_ON_ERROR);
        }
        $values += $this->finishValues($row, $first);
        if ($values !== []) {
            $parent->update($values);
        }
        return new Counts($row->pending_jobs, $row->failed_jobs, $first);
    }

    private function finishReturned(string $batchId, object $row, bool $wasFailed): Counts
    {
        $first = ! $wasFailed && $row->pending_jobs === $row->outstanding;
        if ($values = $this->finishValues($row, $first)) {
            $this->getConnection()->table('job_batches')->where('id', $batchId)->update($values);
        }
        if ($this->captureBatch) {
            $this->countedBatch = $row;
        }
        return new Counts($row->pending_jobs, $row->failed_jobs, $first);
    }

    private function finishValues(object $row, bool $first): array
    {
        if ($row->finished_at === null && ($row->pending_jobs === 0
            || ($first && ($this->unserialize($row->options)['allowFailures'] ?? false)))) {
            return ['finished_at' => $row->finished_at = time()];
        }
        return [];
    }

    public function incrementFailedJobs(string $batchId, string $jobId, ?string $retryId = null): ?Counts
    {
        $db = $this->getConnection();
        $key = ['batch_id' => $batchId, 'job_id_hash' => hash('xxh128', $jobId)];
        return $db->transaction(function () use ($db, $batchId, $jobId, $retryId, $key) {
            $child = $db->table('job_outcomes');
            $new = false;
            if ($this->outcomes) {
                $new = $child->insertOrIgnore($key + ['retry_id' => $retryId ?? $jobId]) === 1;
                if (! $new && $child->where($key)->lockForUpdate()->value('retry_id') === null) {
                    return null;
                }
            }
            $parent = $db->table('job_batches')->where('id', $batchId);
            $row = $parent->lockForUpdate()->first();
            if ($row === null) {
                if ($new) {
                    $child->where($key)->delete();
                }
                return null;
            }
            $values = ['failed_jobs' => ++$row->failed_jobs];
            if ($this->outcomes) {
                $outstanding = $row->outstanding + (int) $new;
                $values['outstanding'] = $outstanding;
            } else {
                if ($child->where($key)->exists()) {
                    return null;
                }
                $entries = json_decode($row->failed_job_ids, true, flags: JSON_THROW_ON_ERROR);
                $identities = array_map(static fn ($entry) => is_array($entry) ? $entry[0] : $entry, $entries);
                $new = ! in_array($jobId, $identities, true);
                if ($new) {
                    $entries[] = $retryId === null ? $jobId : [$jobId, $retryId];
                }
                $outstanding = count($entries);
                $values['failed_job_ids'] = json_encode($entries, JSON_THROW_ON_ERROR);
            }
            $first = $new && $row->pending_jobs === $outstanding;
            $parent->update($values + $this->finishValues($row, $first));
            return new Counts($row->pending_jobs, $row->failed_jobs, $first);
        }, attempts: 3);
    }
}
