# Batch accounting benchmark

This PHP 8.4 prototype compares the SQL work for recording batched jobs. It uses the checked-out Hypervel database connections, per-job batch lookup, batch factory, and success callback path. Queue delivery, the business job, and HTTP request handling are outside the timer.

| Variant | What runs |
| --- | --- |
| A | The checkout's DatabaseBatchRepository, at the pinned baseline revision, including its first-pass fixes and duplicate-success defect. |
| B | The planned per-job success table, with an indexed hash key, the pruning index, and outstanding failures stored on the batch as JSON. The PHP path handles both ordinary IDs and chain entries. PostgreSQL updates with RETURNING and rewrites JSON only when a corresponding failure is removed. |
| C | One row per observed job identity. A null retry ID is terminal success. A non-null ID identifies an outstanding failure. Parent counters track pending jobs, cumulative failures, and outstanding failed jobs. The failed-ID property loads once on access when outstanding failures exist. |
| C-noindex | C without the secondary index for looking up outstanding failures. |
| C-reuse | C with the parent row from the accounting transaction used to populate the batch snapshot, saving the handler's initial find. Measures normal SQL-backed batches without listeners or callbacks. |
| C-cte | PostgreSQL C with a data-modifying CTE for the first success insert and parent update, in a transaction. Conflict and exceptional finish paths use subsequent statements in that transaction. |
| C-reuse-cte | Both PostgreSQL optimizations. |

B and C are benchmark implementations, with the public framework methods used where possible. On B and C the Batch class's separate finish call does no SQL. The optimized variants do not prove the behavior of custom batch loaders, factories, event listeners, deletion/pruning races or failover. Implementation tests must cover them before releasing the change.

## Reproduce

Run on an idle machine from a components worktree at `568424974`, the revision containing the harness and its baseline batch repository. The samples record `061e6b009`, the checkout used with the then-uncommitted harness first archived at `568424974`. Install Composer dependencies in that worktree first. The PHP CLI needs pcntl, PDO MySQL, PostgreSQL and SQLite. Docker must have the three server images listed in run.py locally. Each engine runs in its own new loopback-only container and volume. The runner removes them afterward. The recorded servers were MySQL 9.5.0, MariaDB 10.11.19 and PostgreSQL 17.11. The samples include the image IDs.

```sh
python3 tests/Benchmarks/JobBatches/run.py --phase final > final.jsonl &&
python3 tests/Benchmarks/JobBatches/run.py --phase core > core.jsonl &&
python3 tests/Benchmarks/JobBatches/run.py --phase savings > savings.jsonl &&
python3 tests/Benchmarks/JobBatches/summarize.py final.jsonl core.jsonl savings.jsonl
```

The final comparison uses C-noindex on MySQL and MariaDB and C-cte on PostgreSQL, retaining the normal handler lookup on all three. The original core phase uses indexed C and a separate PostgreSQL insert and update.

Each engine starts with tests for accounting transitions. B and C check duplicate successes, stale and repeated failures, the first-pass rule, preserving finish time, rollback, and missing-parent cleanup. C also checks success and failure in both orders on separate server connections. The scheduling signal does not establish that a database lock wait occurred. PostgreSQL's combined variant exercises the same transitions as the two-statement path. SQLite is checked for correctness only.

Every timed sample starts from a fresh schema. Each fork opens its own connection and warms the batch lookup before a start barrier. Workers process distinct, preassigned successful job IDs. The parent measures elapsed time until the last worker finishes; process creation, seeding and verification are outside timing. The runner rotates variant order over five repetitions and records every sample. Jobs/s is aggregate throughput across all workers.

| Case | Timed work |
| --- | --- |
| clean | One batch, 1,000 fresh successes, 50 workers, zero outstanding failures. |
| heavy | As above, starting with 5,000 distinct outstanding failures. The last fresh success finishes the first pass of an allow-failures batch. |
| independent | 50 workers each processing 20 jobs in their own batch. |
| progress | As heavy, B and C with a progress callback that reads progress on the freshly loaded batch. |
| readids | 100 fresh successes, 10 workers, 5,000 outstanding failures. The callback explicitly reads all failed IDs on every success. Compare B and C within this control only. |

For each timed sample the harness verifies the final parent counters, finish time, failure and success cardinality, callback count, and number of IDs explicitly read. SQL/job counts executed statements, excluding transaction control. Transaction duration includes lock waits. The reported parent-lock interval starts when the locking statement returns to the client and ends at commit. It excludes work before the statement returns and may include time after the server releases the lock while the client awaits commit acknowledgment. Despite the historical raw field name parent_lock_lower_bound_ms_per_job, it is only a client-side interval, not a bound on server lock hold time. MySQL log bytes use the summed binary log file sizes, PostgreSQL uses WAL positions. Both bracket only the timed workload on a dedicated server. MariaDB's default container configuration has binary logging disabled.

The child-table hydration index is partial on PostgreSQL and SQLite. On MySQL and MariaDB its second column is a one-character prefix of the nullable text retry ID, allowing long lookup IDs and indexing the non-null predicate. The capture includes actual optimizer plans and durability settings. Payloads contain realistic 36-character IDs and minimal options. The core and local optimization runs have no injected network delay.

## Diagnostic controls

```sh
python3 tests/Benchmarks/JobBatches/run.py --phase index --engine mysql > index.jsonl
python3 tests/Benchmarks/JobBatches/run.py --phase sparse --engine mysql > sparse.jsonl
python3 tests/Benchmarks/JobBatches/run.py --phase latency --engine pgsql --repeats 3 > latency.jsonl
```

The MySQL index control processes 5,000 jobs per repetition, split across 50 independent batches. The sparse control then compares forced access paths when listing the single outstanding failure in 100,001 rows of one batch (20 warm samples of each). The local PostgreSQL latency control applies a requested 1.4 ms delay per server response chunk through a loopback proxy. Use the recorded SELECT 1 medians to interpret that control; the requested delay is not a measured RTT.

The servers in this campaign ran on the same host as PHP, with six CPUs available to the benchmark process (Intel Core i7-7700). All variants used real durable commits. See [measurements](measurements.md) for aggregate medians and ranges, and [samples](samples/) for individual runs and engine settings.
