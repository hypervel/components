# Count each batch job's success once

## Status and purpose

Implement the approved per-job results design as its own components PR on `0.4`.

Queues deliver jobs at least once. If a worker records success and stops before deleting the job, a second success can consume another job's pending slot and run completion callbacks early. A terminal failure on redelivery can cancel a batch for a job that already succeeded. A replayed chain link can also enqueue its successor twice with different queue UUIDs. Count each logical batch job's success once. [Two-job reproduction](https://github.com/cyppe/laravel-batch-jobs-redis-driver/issues/7) and [overlapping retries](https://github.com/laravel/framework/pull/48000).

Callbacks still run in the worker that records the success. If that worker stops after committing the count but before its callbacks finish, the redelivery does not run them.

## Batch identity and API

The queue keeps its normal UUID per dispatch. Batch accounting uses the stable chain position when present, otherwise the original queue ID from `Batch::jobIdFor()` (including Horizon's `batchJobId` on retry).

- In `Batch::add()`, clear any old `batchPositionId` when enrolling a standalone job or chain head. After `prepareBatchedChain()`, clone each successor occurrence, assign the copy a fresh internal `Batchable::$batchPositionId` UUID, and pass the copies to the head's existing `chain()`. The serialized tail keeps those identities across redelivery, prepended unbatched jobs and `ChainedBatch` handoffs. Reusing a job object at two positions gives each its own ID.
- Include `data.batchPositionId` in the outer queue payload only for positioned jobs. In `CallQueuedHandler`, resolve both normal and missing-model outcomes from that identity and the existing retry lookup ID. Keep the handler's batch lookup, fakes, virtual calls and pre-update `BatchStarted` snapshot.
- `$jobId` identifies the job for counting throughout `Batch` and `BatchRepository`. Success signatures stay as they are. Append `?string $retryId = null` to `Batch::recordFailedJob()` (after `$e`), both `incrementFailedJobs()` APIs and their implementations. Pass the original lookup ID for a positioned failure. Omission means use `$jobId`. Keep existing parameter names for named callers.
- Add `getFailedJobIds(string $batchId): array` (a list of outstanding retry lookup IDs) and `hasFailedJob(string $batchId, string $jobId): bool` (membership by counting identity) to `BatchRepository`. SQL reads use the writer. For membership, match the full primary key and require a non-null retry ID. For a missing batch the results are an empty list and false.
- Keep `Batch::$failedJobIds` a public, writable array. Widen the existing `$failedJobIds` argument on `BatchFactory::make()` and the `Batch` constructor to `?array`. In `toBatch()`, pass `[]` when no failures are outstanding and `null` for an unloaded list. Set an explicitly supplied array in the constructor. A backed PHP 8.4 `&get` hook with implicit set loads and caches the list on first read, including offset and reference access. The first read can see a newer commit than the counters. No insertion order is promised.
- Preserve `Batchable::batching()` for missing, cancelled and unfinished batches. For a finished batch, a job with a known counting ID delegates membership to `Batch::hasFailedJob($jobId)` and the repository. A job without an ID returns false. This lets an already-queued copy do its work after an allow-failures finish. Update `BatchFake` and `BatchRepositoryFake` for the new signatures and reads, using their supplied in-memory IDs.

## Schema, configuration and migrations

| Table | Change |
|---|---|
| `job_batches` | Replace `failed_job_ids` with integer `outstanding_failed_jobs`, initialized to zero in `store()`. Keep `failed_jobs` cumulative. Index `(finished_at, created_at)` for finished and unfinished pruning. |
| `job_batch_results` | Use `string('batch_id')` to match the parent ID, `char('job_id_hash', 32)` and nullable `longText('retry_id')`. MySQL can silently truncate a caller-supplied retry ID in a smaller text column during `INSERT IGNORE`. Primary key `(batch_id, job_id_hash)`. Use the lowercase hex `xxh128` digest of the counting ID so long, case-sensitive and non-UUID IDs remain distinct across collations. |

On PostgreSQL and SQLite only, add `$table->index('batch_id')->whereNotNull('retry_id')` after checking the schema connection's driver. On MySQL and MariaDB the primary key also serves scans for one batch. A secondary index on every success slows writes. Omit the foreign key because its InnoDB parent check would lock the hot batch row during result insertion. Keep results through completion and cancellation, until the batch is deleted.

- Set `queue.batching.results_table` to `job_batch_results` in `src/foundation/config/queue.php`, explain both tables in the section comment and sync both skeleton configs. `BusServiceProvider` passes the required value via the typed string getter. For direct callers, append `string $resultsTable = self::DEFAULT_RESULTS_TABLE` after the repository constructor's connection argument and define the public typed constant.
- Update `batches.stub`, the skeleton and Testbench `000005` migrations and the Bus migration fixture. Match the shipped nullable `mediumText('options')` in fixtures. Add the results table to its own `batch-results.stub`, skeleton and Testbench `000010` migrations and the next Bus fixture migration. Use configured table names in generated migrations.

### Private monorepo consumers

On monorepo `main`, update the batching config and fresh schema in `apps/starter` and `apps/sonicstack`. Copy the new migration and update their batch create-table migrations. Add `job_batch_results` and `job_outbox` to `tenancy.schema.global_tables` in `packages/hypervel/src/tenancy/config/tenancy.php`. Verify both fresh schemas and tenancy classification.

## Generator command

`make:queue-batches-table` creates one migration per table, like `make:cache-table`.

- **Shared helper.** Move `CacheTableCommand::handle()`'s loop into a protected `createMissingMigrations(array $stubs): bool` on `MigrationGeneratorCommand`.
  - `$stubs` holds stub paths keyed by table name.
  - It warns about and skips each table whose migration exists, and creates the others.
  - It returns whether it created any.
- **Outcomes.** Each command keeps its own outcome:
  - `make:cache-table` keeps reporting `Migrations created successfully.` and succeeding.
  - `make:queue-batches-table` keeps failing when every migration already exists, reporting `Migrations already exist.`; otherwise it reports `Migrations created successfully.`
- **Existing detection.** Keep `BatchesTableCommand::migrationExists()`. It also treats Laravel's `0001_01_01_000002_create_jobs_table.php` as the `job_batches` migration, so an application whose batch table lives there gets only the new migration.
- **Description.** Change the command description to `Create migrations for the batch database tables`.

## Recording results

Each accepted transition updates the result row and parent counters in one transaction. An absent result means the job has yet to report. A non-null `retry_id` means an outstanding failure. SQL NULL means terminal success. Use explicit null checks so a lookup ID such as `'0'` survives.

| Stored result | Incoming result | Result row | Change to parent |
|---|---|---|---|
| Absent | Success | Insert success | Pending −1 |
| Absent | Failure | Insert first retry ID | Cumulative failures +1, outstanding +1 |
| Failed | Success | Clear retry ID | Pending −1, outstanding −1 |
| Failed | Failure | Keep first retry ID | Cumulative failures +1 |
| Succeeded | Either | Keep success | Return null and skip all batch callbacks/events |

**Lock order.** Take the result row before the parent. On portable SQL, use `insertOrIgnore()`. On a success conflict, update the matching row to NULL only where `retry_id IS NOT NULL`. Zero affected rows means a duplicate or deleted result. On a failure conflict, re-read the result with `lockForUpdate()` before updating the parent. If missing or succeeded, return null. Otherwise hold the child lock through the parent update. If the parent is missing, remove the result claimed by this transaction and return null.

**Parent update.** The initial locked read needs only the counters and `finished_at`. Apply the transition and return `UpdatedBatchJobCounts`. Set `completedFirstPass` when `pending_jobs - outstanding_failed_jobs` moves from positive to zero. Preserve an existing finish time. Finish at zero pending regardless of the flag. Only when the first pass completes with jobs still pending and no finish time, fetch and decode `options` on the writer under the same lock. Finish if `allowFailures` is true.

**Protected hook.** Remove `DatabaseBatchRepository::updateAtomicValues()` so each counting method owns one child-first transaction. Retaining the Laravel hook on accepted failures adds a nested savepoint, and its old JSON row is gone. Document the removal for subclasses that override or call it.

**PostgreSQL success.** Use one writable CTE for an ordinary first success. Insert with `ON CONFLICT DO NOTHING RETURNING batch_id`, update the matching parent from the inserted row and return the new counters and finish time. Left-join the update to the insert so a conflict (no inserted row) and a missing parent (inserted row without an update) remain distinguishable. Set a zero-pending finish with `coalesce(finished_at, ?)` in the update. Handle a prior failure with the guarded transition and parent `UPDATE … RETURNING`. Keep the conditional options lookup and any finish update in that same transaction. Wrap both configured table names with the grammar for prefix support. Execute the CTE on the writer and mark the connection modified when it writes, following `Builder::insertOrIgnoreReturning()`.

Use `transaction(..., attempts: 3)` for result and deletion transactions. Only the outermost transaction can retry recognized concurrency errors. Keep batch callbacks outside the retry closure. In `Batch::recordSuccessfulJob()`, call `markAsFinished()` at zero pending only when `$counts->completedFirstPass === null`. A null counts object skips all further handling. Keep the existing fallback for an allow-failures finish and preserve event/callback order. Finishing under the lock lets work added in a progress callback reopen the batch.

## Deletion and pruning

1. Keep the current predicates for finished, unfinished and cancelled batches. On the writer, select at most 1,000 candidate IDs and their `total_jobs` per page. Split into chunks targeting 10,000 result rows using `total_jobs` as an upper estimate. A larger individual batch stays in one transaction.
2. Share a deletion helper with `delete($batchId)`. Inside the transaction delete results for the selected IDs, delete parents that still satisfy the original predicate, then delete results again. The second sweep catches rows committed while the parent delete waited.
3. If fewer parents were deleted than selected, throw an internal stale-selection exception to roll back the whole chunk. Catch it outside the transaction, leave surviving batches' results intact, and reselect on the next prune page. Count only committed parent deletions. A missing ID on direct delete is a no-op. Retry concurrency errors independently of stale selection.

## Documentation and upstream sync

In `src/docs/queues.md`, explain counting on redelivery, failures after success, lazy and unordered failure IDs, and pruning both tables. Link there from brief Bus README entries for deliberate public differences. In `src/docs/porting-from-laravel.md`, tell porters to use Hypervel's migrations, configure `results_table` in custom batching arrays, adapt custom repositories and read failure IDs through the batch API. Compatible jobs can still share a queue backend, restart signals and lock keys. Use one framework to maintain a batch's SQL records.

Document counting and atomic finish on `BatchRepository` and `UpdatedBatchJobCounts`. Explain the intentional ID and storage differences at their owning code so an upstream sync preserves them. Ordinary handler and callback changes can still be ported directly. Adapt upstream batch storage, pruning and custom repository changes against the transition table and tests.

## Tests

- **Public batch behavior.** In `BusBatchTest`, use distinct IDs in the ordinary two-job success test. Redeliver one success and then report its failure. Verify progress runs once, the batch stays active, and completion occurs only after the other job succeeds. Cover a last-job progress callback that adds work, and the zero-pending fallback for a repository without first-pass reporting.
- **Chains.** Reproduce A → B → C with the real SQLite queue. Interrupt A after B is queued and before A is counted, expire the reservation, and process both copies. Distinct queue UUIDs for B and C must each consume only one slot per position. Extend existing serialization, missing-model and Horizon retry cases for position identity. Cover a repeated object at two positions, first retry lookup retained after two failures, `queue:retry-batch` resolving it, and an already-queued copy doing its work after an allow-failures finish.
- **Failure reads.** Check that `find()`, paged `get()` and progress-only callbacks do not load the list. One first access when needed loads and caches real retry IDs. Preserve eager arrays, assignment and offset/reference access, including fakes. Check membership for an outstanding position and a succeeded position in a finished batch, including cancellation taking precedence.
- **SQL transitions.** Add a shared `tests/Integration/Bus/Database/DatabaseBatchRepositoryTestCase.php` patterned on `OutboxClaimTestCase`. Use `DatabaseTruncation`, the two Testbench migrations, independent pooled connections, bounded server lock timeouts and file-backed SQLite in its default DEFERRED mode. Exercise the transition table on all four engines. Include `'0'` as a lookup ID, case-distinct and long common-prefix job IDs, missing-parent cleanup, and an injected post-update exception followed by successful redelivery. Directly fail and then succeed the last outstanding job with a previously unset finish time and a false first-pass flag. Check allow-failures completion and preservation of a cancellation timestamp. An ordinary new success costs one accounting statement on PostgreSQL, three elsewhere, excluding `find()` and transaction control.
- **Races and pruning.** Use bounded two-session barriers for competing failure/success in both orders, including a repeat failure. Exercise deletion during an uncommitted result and a result arriving during deletion, including MySQL/MariaDB READ COMMITTED. On PostgreSQL pause after the combined statement rather than between its CTEs. Assert committed counts, no orphan results and no escaping concurrency error. Cover direct delete and all prune predicates, a cancelled batch pruned during a success, and stale candidates reopened or finished between selection and deletion. A survivor keeps its results and ignores a redelivery.
- **Affected fixtures and consumers.** Replace raw JSON assertions in `BatchableTransactionTest` with parent counts and the actual retry ID. Update both Horizon controller test schemas, Bus fixtures/mock rows and explicit migration lists in `CommanderTest` and Redis `OutboxDeliveryTest`. Check generator order, configured names, Laravel's bundled jobs migration and both-present exit codes. Extend `FoundationConfigTest` to match the shipped results table to the constructor constant.

## Performance evidence for the PR

Include the final matched table and its run ranges in the PR body, labelled as batch bookkeeping throughput. Use the [benchmark report](../../../tests/Benchmarks/JobBatches/results.md) for the method, tradeoffs and raw samples.

## Verification

- Run each changed or new test file immediately.
- Run the database suite filtered to the new tests on each engine: `bin/run-database-tests.sh {mysql|mariadb|pgsql|sqlite} --filter=DatabaseBatchRepositoryTest`.
- Run `BusBatchTest`, `CallQueuedHandlerTest`, `SkipIfBatchCancelledTest`, `JobChainingTest`, the Integration/Database/Queue tests, the Horizon batch tests, the generator tests, `CommanderTest` and the Redis `OutboxDeliveryTest`.
- Run `composer sync:skeleton-config` and `composer check:skeleton-config`. After Testbench migration changes, run `composer test:testbench`.
- Finish with `composer analyse`, then `composer lint:fix` before review.
