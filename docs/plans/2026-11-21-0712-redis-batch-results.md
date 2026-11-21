# Redis storage for job batch results

## Goal and evidence

Keep each batch in SQL and let its results use SQL (default) or Redis. Workers finishing different jobs in one batch currently update the same SQL row. Redis should remove that write from ordinary job completions, including jobs delivered through the SQL outbox. Keep accurate pending counts, duplicate-safe processing and the existing batch APIs. Queue delivery and result storage are independent choices.

Cyppe reported that a Laravel batch of 1,000 jobs on 50 workers went from over 100 seconds to 15 seconds. Our comparison uses Hypervel's current duplicate-safe SQL implementation. It measures bookkeeping through the worker's batch lookup and success path, excluding the business job and queue transport. [Laravel discussion](https://github.com/laravel/framework/discussions/49715) · [Counting contract](2026-11-20-1036-batch-success-counting.md) · [Benchmark and raw samples](../../tests/Benchmarks/JobBatches/RedisResults/README.md)

Median aggregate jobs/s over five rotated runs with 50 workers, 1,000 successes and Redis AOF flushing every write:

| SQL engine | Shared batch, SQL | Shared batch, Redis | One-job batches, SQL | One-job batches, Redis |
|---|---:|---:|---:|---:|
| PostgreSQL 17 | 293 | 1,161 | 1,745 | 851 |
| MySQL 9.5 | 90 | 1,341 | 846 | 593 |
| MariaDB 10.11 | 965 | 1,368 | 1,406 | 823 |

The shared workload meets the throughput goal. One-job batches pay initialization and finish costs on every job. Keep SQL the default. The PostgreSQL enrollment control, with 20 jobs per add and synchronous Redis flushing, measured 2.86 ms for SQL alone and 9.93 ms with certificate invalidation. That control invalidated an existing cached total on every add. At initial dispatch an absent field still costs a Redis call, but its removal makes no AOF write. Enrollment is outside the throughput table. The benchmark report includes ranges, failure-heavy and independent batches, progress callbacks, one worker and weaker Redis flush settings.

## Configuration, schema and ownership

- Ship `queue.batching.driver = env('QUEUE_BATCHING_DRIVER', 'database')` in the framework, skeletons and private app configs. Ship `queue.batching.redis_connection` with `env('REDIS_BATCHING_CONNECTION', env('REDIS_QUEUE_CONNECTION', 'queue'))`; document it beside the driver. Keep `queue.batching.database`, `table` and `results_table` as the SQL owner and table settings. Laravel 13.x uses the same driver key. [Laravel queue documentation](https://laravel.com/docs/13.x/queues#dynamodb-configuration)
- SQL owns batch identity, immutable options/metadata, total jobs added, explicit cancellation/finish and the indexed finish time used by pruning. Store the chosen results driver on each `job_batches` row in a string `driver` column defaulting to `database`. Config changes affect new batches only. Changing the Redis connection must preserve the existing keyspace for live Redis-backed batches.
- Keep the SQL result path's composite primary key, failure index where applicable, PostgreSQL CTE and SQL counter columns. For a Redis-backed row, derive pending/failed/outstanding counts from Redis; never interpret the SQL copies as progress. Adding jobs is a single SQL total increase for the whole addition. The existing SQL outbox and database queue can commit their rows on that same owner connection. No outbox schema or relay changes.
- Keep SQL accounting in `Hypervel\Bus\DatabaseBatchRepository`. Add `RedisBatchRepository extends DatabaseBatchRepository` for Redis result recording, lookups, grouped snapshots, enrollment invalidation and cleanup. Keep its atomic scripts with that class. Both use the same SQL row loader; no new result-store interface or driver manager.
- Bind `BatchRepository` in `BusServiceProvider` to a SQL-backed routing entry. Only this instance resolves `DatabaseBatchRepository::class` and `RedisBatchRepository::class` through the container.
- Select by config at creation. In `find()/get()`, pass the row's driver-bound instance to `BatchFactory::make()`. Hydrated batches need no routing query. For driver-sensitive ID-only calls, the entry reads the driver once from the writer, then delegates or returns the existing missing-row result.
- Rebinding a concrete repository customizes that driver's hydrated batches, ID calls and cleanup. A replacement `BatchRepository::class` owns its own hydration and accounting. Preserve custom factory rebinding and counting signatures.
- Prune both drivers regardless of the current default. Use pooled Redis connections and keep operation state outside worker singletons.
- Add a SQL cleanup table, default `job_batch_deletions`, keyed solely by the batch ID in the same type as the parent. No foreign key to the deleted parent. Ship `queue.batching.deletions_table` and wire configured table names into migrations and the generator. Include the testbench and skeleton migrations, both private apps and tenancy's global table list. The private app schemas are recreated; update their create migrations directly.

## Redis results

Use `batches:{<id>}:successes` (set), `batches:{<id>}:failures` (hash) and `batches:{<id>}:state` (hash). Substitute the batch ID inside the braces. Use the existing `xxh128` hex digest of the logical job ID for success members and failure fields; failure values are unmodified retry IDs. State fields are `known_total`, `failed_count`, `finished_at` and `finished_total`. Pass all three logical keys as `KEYS` to `evalWithShaCache()` with serialization/compression disabled on the borrowed connection. PhpRedis applies the configured prefix once; Lua uses `KEYS` directly. Use `HEXISTS` for one failure and `HVALS` for the explicit retry list. Preserve identifiers such as the string `'0'`. Use neither expiries nor an application lock. Results must live as long as the batch. Run Redis with durable persistence appropriate to the workload and no key eviction.

| Previous result | Incoming result | Action |
|---|---|---|
| None | Success | Add the logical ID to successes. |
| None | Failure | Store retry ID and increment cumulative failures. |
| Failure | Failure | Replace the retry ID and increment cumulative failures again. |
| Failure | Success | Remove the failure, add the success. |
| Success | Either | Return null; skip callbacks and counters. |

Derive pending as total minus `SCARD(successes)`, and outstanding failures from `HLEN(failures)`. A first pass completes only on the first observed result for a logical job when successes plus outstanding failures equals total. An allowed-failures batch finishes on that transition. Zero pending finishes even on a recovery that is not the first pass. Preserve the first applicable finish time, including cancellation. Keep the existing callback/event ordering, lazy failed IDs and the distinction between logical job ID and retry UUID.

**Exact totals.** Cache a certified committed total in the scalar hash. Every Redis-backed enrollment, definite enrollment correction and deletion invalidates this field while holding the SQL parent lock, before SQL can commit. A Redis command error aborts the SQL transaction; removing an already absent field is successful. On rollback the absent field costs a recertification. A process dying before or after commit cannot leave a stale certified total. SQL-only batches do no Redis work.

```text
record result:
    run Redis transition using certified total
    if already succeeded: return null
    if certificate missing, or this result could finish the first pass or reach zero:
        return "lock required" without making a change
    otherwise: apply once and return exact counts
on "lock required":
    lock and reread the live SQL row on its writer
    if missing: return null
    atomically certify total, recheck prior outcome, apply result and decide finish in Redis
    if newly finished: set SQL finished_at if absent, then commit
```

Use a short SQL transaction; callbacks run outside it. A retry after a conflict must recheck the outcome. The Redis automatic finish marker contains its timestamp and the total at which it finished. If a process dies after Redis writes the marker and before the SQL projection commits, reads honor the marker only for that total. Preserve a matching marker's original time if a later recovery repairs SQL. Explicit SQL finish/cancel takes precedence; a successful addition clears the SQL finish and changes the total. Keep the same lock order across operations. SQLite's grammar omits `FOR UPDATE`; acquire the write reservation before reading or touching Redis, e.g. a first SQL statement updating the parent total to itself inside the existing transaction, and verify it with two connections in WAL mode. Never publish an uncommitted total.

**Reads**

- Read Redis counters, certified total and finish marker together. Use the certified total when valid, otherwise the SQL total. Respect explicit SQL lifecycle state and the caller's own SQL writes.
- If distinct successes plus outstanding failures exceed that total, or finish information conflicts, reread on the writer under the enrollment lock and take a new Redis snapshot. Return null for a missing row. If outer transaction isolation hides the change, propagate a serialization failure or report a retryable read conflict. Never clamp a count.
- Keep Horizon's SQL name/ID search, descending ID cursor and page size. In `get($limit, $before)`, eagerly fill progress in SQL page order. Pipeline one atomic snapshot per Redis-backed ID in bounded groups where supported; otherwise run the single-slot commands sequentially. Resolve conflicts before returning the page.
- SQL-only pages issue no Redis commands. Serializing a loaded page needs no progress lookups. Failed-ID lists stay lazy.

**Recommendation: load Redis batch progress on first read**

- **Benefit.** Avoid one Redis round trip per job on the handler path. In the paired PostgreSQL AOF `always` control, shared throughput rose from 1,032 to 1,498 jobs/s (+45%) and independent throughput from 1,324 to 1,995 (+51%). The one-job ranges overlapped (822 vs. 863).
- **Behavior.** For `find()`, first access to `totalJobs`, `pendingJobs`, `failedJobs` or `finishedAt` loads the group using the read rule above. A retained object can see later progress on first access. Loaded values remain stable until `fresh()`. Database-backed batches stay eager.
- **Implementation cost.** Nullable initial counters in the factory and constructor, transition fields to seed events, eager list hydration, and an exception if an unloaded object is first read after deletion. Use the existing lazy-property pattern from `Batch::$failedJobIds`; this requires no extra config switch.
- Preserve `batch()` overrides, `withFakeBatch()`, factory rebinding, public writes, serialization and expected-total checks on the delivered callback snapshot. Verify them on the final implementation.

**Factory and snapshot APIs.** Preserve the argument order, names and defaults. In both signatures change only `int $pendingJobs` and `int $failedJobs` to `?int`; pass both null for first-read hydration. The SQL total and explicit finish time at lookup are inputs to the conflict rule. Keep the public counter properties writable integers. The proposed signatures, with existing imports, are:

```php
BatchFactory::make(BatchRepository $repository, string $id, string $name,
    int $totalJobs, ?int $pendingJobs, ?int $failedJobs, ?array $failedJobIds,
    array $options, CarbonImmutable $createdAt, ?CarbonImmutable $cancelledAt,
    ?CarbonImmutable $finishedAt): Batch

Batch::__construct(QueueFactory $queue, BatchRepository $repository,
    string $id, string $name, int $totalJobs, ?int $pendingJobs, ?int $failedJobs,
    ?array $failedJobIds, array $options, CarbonImmutable $createdAt,
    ?CarbonImmutable $cancelledAt = null, ?CarbonImmutable $finishedAt = null)
```

Only `RedisBatchRepository` exposes the internal `loadJobCounts(string $batchId, int $totalAtLookup, ?CarbonImmutable $finishedAtLookup): ?array`. Its named fields are `totalJobs`, `pendingJobs`, `failedJobs`, `outstandingFailedJobs` and `finishedAt`. With null initial counters the factory supplies a driver-bound Redis repository. Call its loader directly with the original SQL values; avoid reading the hooked properties recursively. No snapshot method on the repository contract.

- Before `recordSuccessfulJob()` or `recordFailedJob()` exposes an unloaded batch, seed it from the accepted transition on `UpdatedBatchJobCounts`. Append `public ?int $outstandingFailedJobs = null` and `public ?CarbonImmutable $finishedAt = null` after `$totalJobs`. SQL and custom callers can omit both.
- Redis supplies the outstanding count (including zero) and applicable finish time, retaining a matching allow-failures marker. Null outstanding count means no seed; a supplied seed with null finish time means unfinished. Terminal transitions have the locked SQL finish time.
- Populate the group once when the transition and explicit SQL state agree; otherwise use the read-conflict rule. Preserve loaded values. Seed terminal results before progress callbacks: a callback can delete the batch and `BatchFinished` then uses the retained object. Normal event seeding costs no Redis read.
- If an otherwise-unloaded retained object's loader returns null after deletion, first progress access throws `RuntimeException` identifying the batch. Its `fresh()` returns null.

## Transactions and delivery failures

- Preflight the *whole* addition, including per-job outbox overrides, queue defaults, actual transaction ownership, deferred jobs and chain weights. Enroll all occurrences before any job can run. Two inline jobs or two independently drained SQS chunks must never trigger completion between members of the same add. Keep the same-owner SQL path atomic. Reject an immediate database-queue insert in an outer transaction on a different connection from the batch owner. For a direct external send from a real outer transaction owning the batch row, require `afterCommit()` or `viaOutbox()` before mutating anything: a later business rollback cannot retract a published job. The repository's own transaction does not trigger this rule. Don't silently change `beforeCommit` or route jobs into the outbox.
- Use SQL results from creation for built-in sync queues and failover routes that can execute synchronously, including nested failover configurations. Resolve the configured connection/default, not a pooled proxy's PHP type. Deferred/background drivers are distinct. For Redis-backed batches reject result recording *before mutation* if the owning SQL connection has an applicable open transaction: Redis results cannot roll back with it. Use the transaction manager's connection-specific, testing-aware definition. A normal worker that commits inside `handle()` before returning is unaffected.
- Add internal `Queue::bulkForBatch(array $jobs, Closure $onDeferredRejection, mixed $data = '', UnitEnum|string|null $queue = null): BatchEnqueueResult`. Share enqueue code with `bulk()`. Return original input offsets for accepted, definitely rejected/unattempted and unknown deliveries, plus the original failure. All-success needs no per-job status entries.
- On a later deferred rejection, pass the definite unsent offsets to the callback; `Batch::add()` maps them to logical weights. `QueuePoolProxy` forwards to its borrowed queue and reacquires via its after-commit dispatcher.
- Preserve failover order, transaction deferral and cancellation. Merge outcomes per occurrence; a later rejection cannot erase prior acceptance or uncertainty.
- In inherited first-party `bulkForBatch()`, compare `(new ReflectionMethod($this, 'bulk'))->getDeclaringClass()->getName()` with the paired bulk implementation's owner once per bulk call on the concrete queue. For a subclass overriding only `bulk()`, invoke its override once and treat an exception as unknown. A subclass can override `bulkForBatch()` for precise reporting.
- For contract-only queues lacking the method, `Batch::add()` uses the same fallback. Keep reporting out of ordinary non-batched dispatch.
- Preserve public `Queue::bulk()`, event data and exceptions. Distinguish jobs confirmed accepted (including a sync job that ran or a post-send listener failure), definitely unsent/rejected, and unknown. Redis can confirm an atomic push; SQS has per-entry and per-chunk outcomes, and sequential drivers know which later jobs they never attempted. Payload preparation, SQL writes, deferred dispatch and after-response dispatch need the same rules. A `sendAttempted` flag alone cannot prove acceptance. Preserve cancellation and the originating error. For a custom transport with no finer information, propagate the error and retain unknown weight.
- Release only definite non-delivery weight. For immediate adds, net the correction inside the transaction that enrolled it. Commit confirmed results and counts before rethrowing a post-publication error; don't undo inline results or delete a newly created batch whose deliveries were accepted or are unknown. If an initial add accepts nothing, remove it without running completion. An unacknowledged direct send can leave the batch pending if the queue did not accept it; durable delivery uses the outbox.
- For a definite post-commit rejection, lock the parent. In one Redis operation invalidate its certified total and read success/failure cardinalities plus the finish marker. Persist the corrected SQL total and any resulting finish in the same update. Subsequent terminal outcomes must recertify behind the lock.
- Preserve cancellation and the original timestamp when restoring an earlier finish. Use the actual lifecycle state, and notify only for a new transition after commit.
- Provide `releaseJobs(string $batchId, int $count, ?int $previousTotal = null, ?CarbonImmutable $previousFinishedAt = null): ?UpdatedBatchJobCounts` on the concrete repositories. Capture the pre-add total and immutable finish date under the enrollment lock when restoration needs them. Convert to an integer timestamp at the storage boundary.
- Return counts only for newly reached completion or first-pass transitions. Restoration or a missing parent returns null. The repository contract gains no method. Custom repositories without the capability retain uncertain weight and the original failure.
- On a batched job timeout in `Job::fail()`, unwind the built-in **batch owner's** SQL connection to level zero before the queued job is deleted and its failed result is recorded. Use `getConnection()->rollBack(toLevel: 0)` on built-in repositories at this timeout-only call site; retain the existing `rollBack()` call for custom repositories and the one-level behavior for other public callers. Select by the shared SQL owner, irrespective of the configured results default. Do not rely on the separate failed-job provider being database-backed or on it using the same connection.

## Deletion and pruning

Physically remove the parent and insert a cleanup ID for Redis results in the same SQL transaction. Invalidate the Redis certificate under the lock, but keep outcomes intact until SQL commits. A rollback preserves the live batch. After outermost commit, unlink the three same-slot keys and remove the cleanup ID only after success. The same routine drains leftover IDs in bounded pages on the ordinary `queue:prune-batches` run. Process failure, Redis downtime and a lost SQL commit acknowledgement leave retryable work. Missing Redis state can only be initialized after locking a live parent. Database-backed batches retain the current direct transactional deletion path.

Keep existing retention ages: finished by finish time, optional unfinished and cancelled by creation time. When pruning, examine old SQL-unfinished Redis batches in bounded ID pages even for the default `--hours` run. Recheck under the parent lock, read only the scalar finish marker and repair missing SQL finish projections. A recently Redis-finished batch is eligible by its actual finish time, never just its older creation time. Preserve late adds, cancellations, existing SQL selection predicates and nested-transaction progress. No new scheduled command, Redis scan or global Redis index.

## Verification and release decisions

- Reuse existing Bus, queue, Horizon and outbox tests. Share the meaningful result-transition cases between SQL and Redis. Use real Redis/Valkey/Cluster tests for same-slot updates, prefixes, configured serializers, failure lookup IDs and duplicate deliveries. Test an existing Redis batch after changing the default driver, and a rebound concrete repository overriding a count method on both hydrated and ID-only calls. Check the stale finished-event fallback after a progress callback deletes the batch, and serialize a mixed-driver listing page without a second round of progress lookups. Keep default SQL worker query counts and the PostgreSQL ordinary-success CTE unchanged.
- With independent SQL connections and real Redis, force a concurrent add vs last job, rollback after invalidation, crash after marker before projection, interrupted cleanup, deletion rollback and reopen during prune. Test a correction that releases the final unsent weight and a correction returning to a prior finish. Exercise all supported SQL engines, especially SQLite WAL writer exclusion. Verify public reads, callbacks and Horizon agree, including an allowed failure recovered after finish.
- Exercise two inline jobs, failover-to-sync, a mix of direct/deferred jobs, after-response publication, SQS partial rejection and an exception from `JobQueued` after acceptance. A driver subclass overriding only `bulk()` must see its override invoked once. Extend the existing batched-worker timeout test with the nested-transaction fixture and Redis results. Use a failed-job provider that does not unwind the batch DB; verify failure is recorded once after all job-owned transaction levels close. Verify initial and later adds, an unknown send, `afterCommit` and outbox with a real outer rollback. Use a deterministic fake for the actual delivery response where a real service wouldn't improve the test. Verify migration generation and both fresh monorepo app schemas.
- Document the single driver choice, SQL ownership, Redis persistence/eviction requirements, retention, operational limits and workloads in `src/docs/queues.md`; update outbox guidance and material Laravel compatibility differences in the porting guide and package README. No new Horizon search or pruning contract. The separate risk that a lost SQL commit response releases an outbox dispatch lock needs its own fix decision.
- After implementation, switch the `RedisResults` harness to the shipped repository/factory for both drivers, preserving the handler workload, correctness checks, settings and alternating runs. Remove `RedisResults.php` and prototype-only scaffolding/flags, including the simulated lazy variant. Keep the planning samples identified by commit; with a fresh idle confirmation, run the matched campaign against the final code and update the benchmark README with the production medians/ranges and raw sample file. Investigate any lost shared-batch gain or added SQL work before publishing PR figures.
- **Owner approval before implementation:** in addition to the public hydration changes above, schema additions; the outer-transaction dispatch/owner checks; recording manual Redis results inside an applicable SQL transaction being rejected and built-in sync routing; the narrow read conflict under restrictive transaction isolation; conservative unknown sends, and timeout-only rollback to level zero. No new required repository or queue interface method. Preserve public dispatch signatures. Confirm the opt-in overhead on small/uncontended batches is acceptable. Benchmark acceptance authorizes this design document, not those API changes.
