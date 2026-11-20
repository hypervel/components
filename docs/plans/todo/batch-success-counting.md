# Count each batch job's success once

## Status and purpose

Agreed design; the owner approved the new table. Implement it as its own components PR on `0.4`. Before starting, check the current code against this plan.

Queues deliver jobs at least once. A worker can record a batched job's success and stop before the queue deletes the job, so the job runs again and is recorded again. `DatabaseBatchRepository::decrementPendingJobs()` subtracts once per call, so the second delivery decrements another job's pending slot and `then` / `finally` can run while work is still pending. A redelivery that throws after its success was counted also fails the batch: a batch that does not allow failures is cancelled and its `catch` callbacks run for a job that succeeded. Laravel `master` has the same code. Reproductions: [the two-job case](https://github.com/cyppe/laravel-batch-jobs-redis-driver/issues/7) and [overlapping retries](https://github.com/laravel/framework/pull/48000).

`Batch::jobIdFor()` already gives a retried job its original ID. After this change:
- each job's success counts once;
- a failure after the job's recorded success is ignored;
- failures before a success still increment `failed_jobs` each time, as today.

Callbacks still run in the worker that records the success. If that worker stops after committing the count but before its callbacks finish, the redelivery does not run them.

## Owner approval

The owner approved the new table. These Laravel differences still need the owner's approval:

- **Stale failures.** A failure after a job's recorded success is ignored. Laravel counts it, and the first such failure cancels a batch that does not allow failures and runs its `catch` callbacks.
- **Required configuration.** `queue.batching.successes_table` is a new required config key. An application that defines its own `queue.batching` array must add it, because framework defaults merge only at the top level.
- **Protected extension point.** `decrementPendingJobs()` no longer goes through the protected `updateAtomicValues()`, which keeps its signature and still serves failures.
- **Uncontended cost.** The record costs up to about a quarter of bookkeeping throughput when batches do not contend (see the measurements).

## Design

1. **Success records.** A new `job_batch_successes` table with primary key `(batch_id, job_id_hash)`. Recording a success inserts the record first with `insertOrIgnore()`; a duplicate returns `null` and changes nothing.
2. **Insert before locking.** The insert runs in the decrement's transaction before the batch row is locked, so it adds no work while the hot row is locked.
3. **Missing batch.** When the locked read finds no batch, the transaction deletes the record it inserted and returns `null`.
4. **Finish in the locked update**, following the completion rule under Repository contract. `Batch` no longer calls `markAsFinished()` afterwards, so a job added just after the last success reopens the batch instead of being stranded in a finished batch.
5. **PostgreSQL.** One `UPDATE … RETURNING` statement replaces the locking `SELECT` and `UPDATE`, so the row is locked only from that statement until `COMMIT`. Removing the job from `failed_job_ids`, or finishing a batch with only failures left, needs a second `UPDATE` in the same transaction; both are rare.
6. **Stale failures.** `incrementFailedJobs()` locks the batch row as today, then makes a non-locking existence read for the job's success record and returns `null` when it exists. `Batch::recordFailedJob()` already returns early for `null`.
7. **No foreign key.** InnoDB checks a foreign key by share-locking the parent row, which is the hot batch row, so every success insert would wait behind the batch lock. `delete()` and the prune methods delete the records explicitly.
8. **Keep records until pruning.** A duplicate can arrive after its batch finished, once the queue's retry or visibility timeout passes.
9. **Deletion and pruning.** Only a batch that is actually deleted loses its records. Each deletion transaction deletes the records, deletes the batches that still match, and deletes the records again. If fewer batches were deleted than selected, the whole transaction rolls back and pruning selects again.
10. **Retry concurrency errors.** The success, failure and deletion transactions pass `attempts: 3`, as `DatabaseOutboxRepository` and the database rate limiter do. `transaction()` retries only errors its concurrency detector recognizes (deadlocks, serialization failures, lock timeouts and SQLite busy errors), and only at the outermost level.
    - This matters because `CallQueuedHandler` records a success after the job ran and before the queue deletes it. A bookkeeping error that escapes would make the queue run the work again.
    - Each transaction closure is database work only, so it is safe to repeat.

Lock order: a success takes its record, then the batch row. A failure or `incrementTotalJobs()` takes only the batch row, plus a non-locking read for a failure. Deletion takes the records, then the batch rows, then any records committed meanwhile. So no transaction holds a batch row while it waits for a success record, except under MySQL READ COMMITTED (below).

The isolation levels below were checked with two connections each on MySQL 9.5, MariaDB 10.11 and PostgreSQL 17, in two orders. In one, the success inserts its record after the deletion's first record delete; in the other, the success already holds the batch row. No case left a batch or record behind:

| Engine and isolation level | Behavior |
|---|---|
| MySQL and MariaDB, REPEATABLE READ (the default) | No errors. The first record delete waits for an in-flight success's uncommitted record and gap-locks the range against new ones |
| MySQL and MariaDB, READ COMMITTED | Can deadlock: with no gap locks, a success inserts its record after the first delete, then waits for the batch row while the second delete waits for that record. InnoDB chose the success in the check, but either transaction can be rolled back. A success's retry finds the batch gone and records nothing; a prune's retry repeats its chunk |
| PostgreSQL, READ COMMITTED (the default) | No errors. A delete never waits for another transaction's uncommitted record, and each statement takes a new snapshot, so the second record delete removes a record committed while the batch delete waited |
| PostgreSQL, REPEATABLE READ | The losing transaction fails with a serialization failure, both for a late success and for a prune waiting on a success; the retry handles it |

SERIALIZABLE was not checked. Like REPEATABLE READ, it reports conflicts as serialization failures, which the retry covers. SQLite serializes writers, and the success transaction begins with a write; the earlier SQLite interleaving checks covered this design.

A failure that commits before a competing success of the same job is counted as a failure, and the success is counted too. Both deliveries happened, and closing that window would need a reverse lock order or a longer hot lock.

Rejected alternatives:

| Alternative | Why not |
|---|---|
| Foreign key with cascade, locking the batch before inserting | Puts the insert's round trips inside the hot lock. Estimated about −18% on MySQL at 1.4 ms RTT, and it gives back most of PostgreSQL's gain |
| ID array or bitmap on the batch row | An O(N) rewrite under the lock |
| Per-job rows at enqueue | More writes, and finishing still needs the counter |
| Acknowledging the job first | Loses completions, and SQL cannot acknowledge the queue atomically |
| Skipping by attempt number | A later attempt cannot tell whether an earlier one committed |
| MySQL `LAST_INSERT_ID(expr)` or triggers | Returns only one value, or moves logic into migrations |
| Sharded counters | Change what `pending_jobs` means and need a separate claim to finish exactly once |
| PostgreSQL single-statement CTE | Finishing an allow-failures batch would need a second transaction, and a crash between them leaves the batch unfinished forever |
| Deleting batches first, then sweeping records in separate transactions | A crash between them leaves records nothing can find |

## Repository contract

Document these semantics on `BatchRepository` and `UpdatedBatchJobCounts` so every repository, including any future Redis repository, implements the same counting:

- `decrementPendingJobs()` counts each job's success once. It returns `null` when the batch does not exist or the job's success was already recorded.
- `incrementFailedJobs()` returns `null` when the batch does not exist or the job already succeeded.
- A repository that reports `completedFirstPass` finishes the batch in the same atomic update: once no jobs remain pending, or once only failed jobs remain in a batch that allows failures. It keeps an existing finish time.
- Repositories that report `null` keep Laravel's behavior: `Batch` calls `markAsFinished()` itself.
- Deleting or pruning a batch removes its success records. A batch that survives keeps them.

`BatchRepositoryFake` and `BatchFake` need no change.

## Schema, configuration and migrations

New table, identical in the stub, the skeleton and the Testbench migrations:

```php
Schema::create('job_batch_successes', function (Blueprint $table) {
    $table->string('batch_id');
    // Hashing gives job IDs of any length a fixed-width key that stays exact on every database.
    $table->char('job_id_hash', 32);

    $table->primary(['batch_id', 'job_id_hash']);
});
```

- **`batch_id`** has the same type as `job_batches.id`, whose values it holds.
- **`job_id_hash`** is the lowercase hex `xxh128` digest of the job ID.
  - **Why a digest.** `Batch::recordSuccessfulJob(string $jobId)` and `recordFailedJob()` accept any string and enforce no length. Framework job IDs are 36-character UUIDs, but nothing else limits them.
  - **Why not the raw ID.** A `string()` column has the configured default length, normally 255. MySQL's `INSERT IGNORE` truncates longer values even in strict mode, so two IDs sharing their first 255 bytes would count as one success.
  - **Exact.** The digest compares exactly under every collation, as `failed_job_ids` compares raw IDs in PHP today.
  - **Unseeded.** It must be identical in every worker, and job IDs are framework-generated, not untrusted input.
  - **Collisions.** At 128 bits, a collision between two job IDs is negligible.
  - `uuid()` columns would also reject the non-UUID IDs that `BusBatchTest` and the public methods accept, on PostgreSQL and MariaDB.
- **Benchmarks.** They used `string('batch_id')` and `string('job_id')` holding 36-character UUIDs. The planned key is 32 characters, and each success also computes one `xxh128` digest in PHP. Throughput was not re-measured.
- **Configuration.**
  - Add `'successes_table' => 'job_batch_successes'` to `queue.batching` in `src/foundation/config/queue.php`, with no code fallback.
  - Update the section comment to say the tables also record each job's success.
  - Run `composer sync:skeleton-config` to regenerate both skeleton configs.
- **`BusServiceProvider`.** Pass `config->string('queue.batching.successes_table')` to the repository.
- **Skeleton migration.** Add `skeletons/hypervel/database/migrations/0001_01_01_000010_create_job_batch_successes_table.php`. It goes after the outbox migration (`000009`) so earlier files keep their names; without a foreign key, order does not matter.
- **Testbench migration.** Add `src/testbench/hypervel/migrations/0001_01_01_000010_testbench_create_job_batch_successes_table.php`.
- **Bus test fixture.** Add `tests/Bus/Fixtures/migrations/2024_11_20_000001_create_job_batch_successes_table.php`.
- **Align the test schemas.** The Testbench `job_batches` migration uses `longText('failed_job_ids')`, and the Bus fixture uses `text` for both `failed_job_ids` and `options`. Change them to the shipped `json('failed_job_ids')` and `mediumText('options')`, so the database tests run against the real column types.

## Generator command

`make:queue-batches-table` creates one migration per table, like `make:cache-table`.

- **Stubs.** Add `src/queue/src/Console/stubs/batch-successes.stub`. Both table names come from configuration.
- **Shared helper.** Move `CacheTableCommand::handle()`'s loop into a protected `createMissingMigrations(array $stubs): bool` on `MigrationGeneratorCommand`.
  - `$stubs` holds stub paths keyed by table name.
  - It warns about and skips each table whose migration exists, and creates the others.
  - It returns whether it created any.
- **Outcomes.** Each command keeps its own outcome:
  - `make:cache-table` keeps reporting `Migrations created successfully.` and succeeding.
  - `make:queue-batches-table` keeps failing when every migration already exists, reporting `Migrations already exist.`; otherwise it reports `Migrations created successfully.`
- **Existing detection.** Keep `BatchesTableCommand::migrationExists()`. It also treats Laravel's `0001_01_01_000002_create_jobs_table.php` as the `job_batches` migration, so an application whose batch table lives there gets only the new migration.
- **Description.** Change the command description to `Create migrations for the batch database tables`.

## DatabaseBatchRepository

**Constructor.** Append `string $successesTable = self::DEFAULT_SUCCESSES_TABLE` after `?string $connection = null`, so existing four-argument calls keep naming the connection.
- Add `public const string DEFAULT_SUCCESSES_TABLE = 'job_batch_successes'`, as `DatabaseStore::DEFAULT_LOCK_TABLE` does.
- `BusServiceProvider` passes the configured value explicitly, so a missing key still fails.

**`jobIdHash(string $jobId): string`** returns `hash('xxh128', $jobId)`. Its docblock gives the reason, which is under Schema. The success insert, the missing-batch cleanup and the stale-failure read all use it.

**`decrementPendingJobs()`** owns its transaction, because the record must be inserted before the batch is locked. Calling `updateAtomicValues()` inside it would add savepoint round trips while the row is locked.

```php
public function decrementPendingJobs(string $batchId, string $jobId): ?UpdatedBatchJobCounts
{
    $connection = $this->getConnection();

    $success = ['batch_id' => $batchId, 'job_id_hash' => $this->jobIdHash($jobId)];

    return $connection->transaction(function () use ($connection, $batchId, $jobId, $success) {
        // A job the batch already counted, such as one the queue delivered again, changes nothing.
        if ($connection->table($this->successesTable)->insertOrIgnore($success) === 0) {
            return null;
        }

        $counts = $connection instanceof PostgresConnection
            ? $this->decrementPendingJobsWithReturning($batchId, $jobId)
            : $this->decrementPendingJobsWithLock($batchId, $jobId);

        // The batch was deleted after the job loaded it, so its success must not outlive it.
        if ($counts === null) {
            $connection->table($this->successesTable)->where($success)->delete();
        }

        return $counts;
    }, attempts: 3);
}
```

**`decrementPendingJobsWithLock()`** holds today's `decrementPendingJobs()` body. It calls `lockAndUpdate()` instead of `updateAtomicValues()`, and uses `finishWhenComplete()`.

**`decrementPendingJobsWithReturning()`** handles PostgreSQL:

```php
$connection = $this->getConnection();
$table = $connection->getQueryGrammar()->wrapTable($this->table);

// The right-hand side reads the old row, so pending_jobs = 1 means this success leaves none pending.
$batch = $connection->selectOne(
    "update {$table} set pending_jobs = pending_jobs - 1, "
    . 'finished_at = case when pending_jobs = 1 then coalesce(finished_at, ?) else finished_at end '
    . 'where id = ? returning pending_jobs, failed_jobs, failed_job_ids, finished_at, options',
    [time(), $batchId],
    useReadPdo: false,
);

if ($batch === null) {
    return null;
}

$failedJobIds = (array) json_decode($batch->failed_job_ids, true);
$remainingFailedJobIds = array_values(array_diff($failedJobIds, [$jobId]));
$completedFirstPass = $this->completesFirstPass(
    $batch->pending_jobs + 1 - count($failedJobIds),
    $batch->pending_jobs - count($remainingFailedJobIds),
);

$values = ($remainingFailedJobIds === $failedJobIds ? [] : ['failed_job_ids' => json_encode($remainingFailedJobIds)])
    + $this->finishWhenComplete($batch, $batch->pending_jobs, $completedFirstPass);

if ($values !== []) {
    $connection->table($this->table)->where('id', $batchId)->update($values);
}

return new UpdatedBatchJobCounts($batch->pending_jobs, $batch->failed_jobs, $completedFirstPass);
```

`wrapTable()` applies the connection's table prefix. The returned row has `finished_at` set whenever no jobs are pending, so `finishWhenComplete()` only adds the finish for an allow-failures batch.

**`lockAndUpdate(string $batchId, Closure $callback): ?array`** is the body of today's `updateAtomicValues()`: lock the row, call the callback and update with its values. Its docblock says the caller owns the transaction.
- A callback that returns `null` leaves the batch unchanged, and `lockAndUpdate()` returns `null`.
- `updateAtomicValues()` keeps its Laravel signature and becomes `transaction(fn () => $this->lockAndUpdate($batchId, $callback), attempts: 3)`.
- `incrementFailedJobs()` still calls `updateAtomicValues()`. Its callback first returns `null` when `exists()` finds the job's success record by `batch_id` and `jobIdHash()`, and otherwise keeps today's body. Comment that the read does not lock, so a failure never waits for a success's record.

**`finishWhenComplete(object $batch, int $pendingJobs, bool $completedFirstPass): array`** replaces `finishWhenOnlyFailuresRemain()` and applies the completion rule. It keeps that method's docblock, extended to the zero case. It unserializes the options only when it checks for an allow-failures finish.

**Deletion and pruning.**

```php
public function delete(string $batchId): void
{
    $this->deleteBatches([$batchId], fn (Builder $query) => $query);
}

public function prune(DateTimeInterface $before): int
{
    return $this->pruneBatches(fn (Builder $query) => $query
        ->whereNotNull('finished_at')
        ->where('finished_at', '<', $before->getTimestamp()));
}

// pruneUnfinished() and pruneCancelled() keep their current constraints the same way.

protected function pruneBatches(Closure $constraints): int
{
    $totalDeleted = 0;

    do {
        // Select from the writer: a lagging replica would keep returning batches that are already gone.
        $batches = $constraints($this->getConnection()->table($this->table)->useWritePdo())
            ->limit(1000)
            ->pluck('total_jobs', 'id');

        // Aim for about 10,000 success records per transaction; a single larger batch still takes one transaction.
        $ids = [];
        $records = 0;

        foreach ($batches as $id => $totalJobs) {
            if ($ids !== [] && $records + $totalJobs > 10000) {
                $totalDeleted += $this->deleteBatches($ids, $constraints) ?? 0;
                [$ids, $records] = [[], 0];
            }

            $ids[] = $id;
            $records += $totalJobs;
        }

        if ($ids !== []) {
            $totalDeleted += $this->deleteBatches($ids, $constraints) ?? 0;
        }
    } while ($batches->isNotEmpty());

    return $totalDeleted;
}

protected function deleteBatches(array $ids, Closure $constraints): ?int
{
    $connection = $this->getConnection();

    try {
        return $connection->transaction(function () use ($connection, $ids, $constraints) {
            $connection->table($this->successesTable)->whereIn('batch_id', $ids)->delete();

            $deleted = $constraints($connection->table($this->table))->whereIn('id', $ids)->delete();

            if ($deleted !== count($ids)) {
                throw new StaleBatchSelectionException;
            }

            $connection->table($this->successesTable)->whereIn('batch_id', $ids)->delete();

            return $deleted;
        }, attempts: 3);
    } catch (StaleBatchSelectionException) {
        return null;
    }
}
```

**`deleteBatches()` docblock.** Explain three things:
- the lock order;
- that the second delete removes records committed while the batch rows were being deleted;
- why a batch that was reopened, finished or deleted after it was selected rolls back the chunk: otherwise a redelivered job could be counted again.

**Prune loop.** It stops because a deleted batch is never selected again, and a changed one no longer matches. The `total_jobs` target is soft: it groups many small batches into one transaction, and a batch larger than the target still gets one transaction of its own.

**`StaleBatchSelectionException`.** Add it as an `@internal` `RuntimeException` in `src/bus/src/`, with a one-line class docblock explaining that it rolls back a deletion transaction. Throwing from the transaction closure is how `transaction()` rolls back. Hypervel rejects a manual rollback inside that closure.

Imports: `Hypervel\Database\Query\Builder` for the constraint closures. `PostgresConnection` is already imported.

## Batch and counts

**`Batch::recordSuccessfulJob()`.** When `$counts->pendingJobs === 0`, call `markAsFinished()` only when `$counts->completedFirstPass === null`, with the same comment as `markAsFinishedIfOnlyFailuresRemain()`. Dispatching `BatchFinished` and running `then` / `finally` stay unchanged.

**The `UpdatedBatchJobCounts` docblock for `$completedFirstPass`.** State the completion rule there. It ties both finishes to this one Hypervel-specific signal rather than adding a second flag: only `DatabaseBatchRepository` reports it, and repositories that report `null` keep Laravel's behavior.

**`BatchRepository` docblocks.** Add the contract above to `decrementPendingJobs()`, `incrementFailedJobs()` and `delete()`.

## Documentation

- **`src/docs/queues.md`, Job Batching introduction.**
  - Say the command creates migrations for the batch tables, including the record of each job's success.
  - Add one sentence: a job the queue [delivers again](#at-least-once-delivery) after it completed is counted once, so it does not change the batch's progress or run its callbacks again.
- **Batch Failures.** Add one sentence: a failure of a job that already completed successfully does not count as a batch failure.
- **Pruning Batches.** Name both tables in the opening sentence.

The owner is adding general porting guidance on using Hypervel's framework migrations, so this plan adds no batch-specific porting entry or README difference.

Horizon's batch screens and Telescope's batch watcher only read or retry batches; they need no change.

## Tests

**`tests/Bus/BusBatchTest.php`**, which runs on the default SQLite connection:

- **`testSuccessfulJobsCanBeRecorded`.** The upstream test records `'test-id'` twice and expects both to count. Record two distinct IDs, as `testBatchStartedEventIsDispatched` already does.
- **New `testRedeliveredSuccessIsCountedOnce`.** Record one job twice in a two-job batch. Pending stays 1, progress runs once, and `then` / `finally` do not run. The second job then finishes the batch once.
- **New `testFailureOfAJobThatAlreadySucceededDoesNotFailTheBatch`.** The batch does not allow failures. Afterwards it is not cancelled, `failedJobs` is 0, `catch` has not run, and `failedJobIds` is empty.
- **New `testJobAddedAsTheLastJobSucceedsReopensTheBatch`.** A `progress` callback adds a job on the last success, mirroring `testWorkAddedWhileOnlyFailuresRemainReopensTheBatch`. The batch ends unfinished with one pending job; today's code marks it finished.
- **New `testRepositoriesWithoutFirstPassReportingFinishBatchesWhenNoJobsRemain`.** It sits beside the failure fallback test: a repository double returns `new UpdatedBatchJobCounts(0, 0)`, and `markAsFinished` is expected.

**New `tests/Integration/Bus/Database/DatabaseBatchRepositoryTestCase.php`.**
- **Setup.** It is abstract and follows `OutboxClaimTestCase`:
  - `DatabaseTruncation`;
  - pooled testing connections with `max_connections` 4;
  - a bounded `lock_timeout` on the server engines;
  - the Testbench `job_batches` and success migrations loaded by path;
  - plain string job IDs such as `job-a`, so the non-UUID path runs on every engine.
- **Subclasses.**
  - `DatabaseBatchRepositoryTest` runs on MySQL, MariaDB and PostgreSQL.
  - `Sqlite/DatabaseBatchRepositoryTest` runs on a file-backed database, as `Sqlite/OutboxClaimTest` does, under the shipped DEFERRED transaction mode.

Sequential cases:
- **Counting.** In a five-job batch:
  - `job-a` fails, then succeeds, which empties `failed_job_ids` (PostgreSQL's second update);
  - its second success returns `null` with the counts unchanged;
  - its later failure returns `null`;
  - `JOB-A` counts as a separate job, and so do two 300-character IDs that share their first 255 characters;
  - `job-b` leaves nothing pending and sets `finished_at` in that update.
- **Finishing.**
  - A success that leaves only failures finishes an allow-failures batch, through PostgreSQL's second update.
  - A cancelled batch keeps its cancellation's finish time when its last job succeeds.
- **Rolled-back success.** A listener throws once, right after the decrement statement. Nothing is recorded and the counts are unchanged; redelivering the job then counts it once. This verifies that the record and the decrement commit together.
- **Missing batch.** A success for the missing batch ID `missing-batch` returns `null` and leaves no record.
- **Statement count.** The success path issues 2 statements on PostgreSQL (insert, then `update … returning`), the same number as today's locking select and update, and 3 elsewhere. This guards the locked-section shape.
- **Deletion.**
  - `delete()` removes the batch and its records.
  - Each prune method (data provider) returns the number of batches deleted, removes their records, and leaves other batches and their records alone.
- **Changed prune candidates.** A `QueryExecuted` listener changes one candidate once, right after the first candidate select (data provider):
  - for `prune`, the candidate is reopened through `incrementTotalJobs()`;
  - for `pruneUnfinished`, its last job succeeds.

  The changed batch keeps its records, a redelivery of its old job returns `null`, and the other candidates and their records are gone.

Interleavings: pause one coroutine inside its transaction with a `QueryExecuted` listener and a `Channel`, start the other, and release the first after a short sleep.
- **Delete during a success.** A success holds its record (paused after the insert) while `delete()` runs. There is no deadlock, and no batch or record remains.
- **Success arriving during a delete.** A `delete()` is paused after its batch delete while a success inserts its record and waits for the batch row. Then the delete is released.
  - It runs at the default isolation level and, on MySQL and MariaDB, also under READ COMMITTED through a method-level `#[DefineEnvironment]`.
  - Under READ COMMITTED the two deadlock, and the engine may roll back either one.
  - Assert only the outcome: no exception escapes, and no batch or record remains. If the deletion is rolled back, the success can commit before the deletion's retry.
- **Prune during a success.** A success holds the batch row (paused after its update) while a cancelled batch is pruned. The prune waits, then removes the batch and every record.
- **Failure and success of the same job, in both orders** (`TestWith`). Neither deadlocks.
  - Mark this case `#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]`.
  - SQLite serializes writers, and its read-then-write failure transaction is subject to DEFERRED mode's lock upgrade, as it is today.

**Other test changes:**
- **`QueueBatchesTableCommandTest`.**
  - Assert both migrations, in order.
  - When a Laravel `0001_01_01_000002_create_jobs_table.php` exists, assert that only the success records migration is created.
  - When both exist, assert the command fails.
- **`CommanderTest`.** Add the new Testbench migration to the expected list.
- **`FoundationConfigTest`.** Assert that `queue.batching.successes_table` matches `DatabaseBatchRepository::DEFAULT_SUCCESSES_TABLE`, as it does for the cache lock table.
- **`OutboxDeliveryTest`.** Add the new Testbench migration path; it processes batch jobs.

## Measured bookkeeping cost

These are scratch-harness measurements, not end-to-end application timings. End-to-end impact was not measured; [discussion #49715](https://github.com/laravel/framework/discussions/49715)'s 100 s vs 15 s figures were end-to-end production timings.

**Harness.**
- Each success ran `find()`, the decrement, and the finish on the last job, with no callbacks.
- 50 forked workers, each with its own connection.
- Durability was identical across variants: MySQL 9.5 with flush=1 and `sync_binlog=1`; MariaDB 10.11 with flush=1 and no binlog; PostgreSQL 17 with `fsync` and `synchronous_commit`.
- Figures are medians: 5 local runs, or 3 runs with about 1.4 ms added per round trip.

**One batch, 1,000 jobs, 50 workers** (elapsed time; jobs per second in brackets):

| Engine | Today | Success record | Record plus PostgreSQL `RETURNING` |
|---|---|---|---|
| MySQL, local | 10.97 s (91); runs 8.84–11.30 | 11.69 s (86); runs 9.53–31.14 | — |
| MySQL, 1.4 ms RTT | 12.69 s (79) | 12.67 s (79) | — |
| MariaDB, local | 1.21 s (824) | 1.27 s (786) | — |
| PostgreSQL, local | 4.16 s (240) | 4.12 s (243) | 3.51 s (285) |
| PostgreSQL, 1.4 ms RTT | 9.84 s (102); p95 2.5 s | 9.88 s (101); p95 0.66 s | 5.57 s (180); p95 0.68 s |

**Each worker on its own batch** (jobs per second, with the change from today):

| Engine | Today | Success record |
|---|---|---|
| MySQL | 534 | 397 (−26%) |
| MariaDB | 1297 | 1168 (−10%) |
| PostgreSQL | 1130 | 961 (−15%); 1124 (−0.5%) with `RETURNING` |

On PostgreSQL, `RETURNING` recovers today's uncontended rate.

**Per success.**
- **Statements.**
  - On MySQL, MariaDB and SQLite, one more statement runs before the lock: three instead of two. On MySQL that is an `INSERT IGNORE`, which also adds a prepare.
  - PostgreSQL still runs two: the insert, then `update … returning` in place of the locking select and update, with one fewer inside the lock.
- **Writes.**
  - One more row plus its primary-key entry.
  - MySQL redo grew from about 1,250 B to 1,750 B; PostgreSQL WAL from about 185 B to 575 B.
  - fsyncs were roughly unchanged (MySQL under contention: 1.14 → 1.18 per job).
- **Retries.** `attempts: 3` costs nothing unless a deadlock or serialization failure occurs.
- **Storage.** One row per successful batched job until pruning.
- **Finishing.** No `markAsFinished()` UPDATE once per batch.

The hot row is held until the commit flushes, so contention is limited by commit latency, and work before the lock costs little. SQLite was checked for correctness only.

## Verification

- Run each changed or new test file immediately.
- Run the database suite filtered to the new tests on each engine: `bin/run-database-tests.sh {mysql|mariadb|pgsql|sqlite} --filter=DatabaseBatchRepositoryTest`.
- Run `BusBatchTest`, `CallQueuedHandlerTest`, `SkipIfBatchCancelledTest`, `JobChainingTest`, the Integration/Database/Queue tests, the Horizon batch tests, the generator tests, `CommanderTest` and the Redis `OutboxDeliveryTest`.
- Run `composer sync:skeleton-config`, then `composer check:skeleton-config`.
- Finish with `composer analyse`, then `composer lint:fix` before review.
