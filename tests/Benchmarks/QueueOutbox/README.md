# Queue outbox benchmark

This developer-only harness measures ordinary dispatch, transactional outbox staging, relay delivery, and concurrent SQL claims. It uses the real queue drivers, repository and relay worker. It does not execute application jobs, run in PHPUnit, or impose CI throughput thresholds.

Run from the components repository root. Get fresh confirmation that the machine is idle before collecting performance measurements. Tiny invocations may check execution, counts and cleanup while the machine is busy; their timings are not performance evidence.

```shell
timeout 600 php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/QueueOutbox/benchmark.php \
  --case=stage --database-connection=pgsql --jobs=10000 --batch=1000 \
  > /tmp/outbox-staging.json
```

The harness reads `.env` through `tests/bootstrap.php`. Use a disposable database with permission to create and drop tables. `--database-connection` selects an existing configured connection; its pool size is set by `--pool-size`. Omitting it creates a temporary file-backed SQLite database for functional checks only. SQLite timings do not represent a production networked database.

Tables have random `bench_outbox_` / `bench_jobs_` names, and Redis uses a random queue name. Schema comes from the shipped queue migration stubs. The harness removes its own tables and queue keys in `finally`; it never flushes Redis or migrates existing application tables. Resource names are printed to stderr for manual cleanup after a forced kill. Do not run application workers against its queue. Normal exceptions also produce a nonzero exit status.

## Cases

| Case | Timed work |
| --- | --- |
| `direct` | Normal Redis single or bulk publication, without selecting the outbox. No SQL setup or outbox service resolution. |
| `stage` | Single or bulk dispatch through `viaOutbox()`, each call in its own SQL transaction. No Redis calls are needed. |
| `relay` | Actual `OutboxWorker` drain to Redis or a database queue. Seeded jobs are outside timing; claim, publication, settlement and worker lifecycle are inside. |
| `claims` | Concurrent claim → accepted settlement → refill cycles against a mixed backlog. Each claim and settlement/refill phase has its own resource lifetime. |

`--batch=1` uses single dispatch for `direct` and `stage`; larger values use `bulk()`. Compare single, 1,000-job and 10,000-job staging calls using `--batch=1`, `1000`, and `10000`. Claims and relay batches follow the production worker limit of 1,000.

Use `--target=database` for the database relay case; otherwise the target is Redis. `--redis-connection=default` selects the configured Redis connection. Publication uses its real driver batching and the normal database binding limits. Run each case separately:

```shell
timeout 600 php tests/Benchmarks/QueueOutbox/benchmark.php --case=direct --jobs=10000 --batch=100
timeout 600 php tests/Benchmarks/QueueOutbox/benchmark.php --case=stage --database-connection=mysql --jobs=10000 --batch=1000
timeout 600 php tests/Benchmarks/QueueOutbox/benchmark.php --case=relay --database-connection=pgsql --jobs=10000 --batch=100 --concurrency=8 --pool-size=4
timeout 600 php tests/Benchmarks/QueueOutbox/benchmark.php --case=relay --target=database --database-connection=pgsql --jobs=10000 --concurrency=8 --pool-size=4
```

Concurrency applies to relay and claims. Direct and staging workloads are sequential producers. Vary pool size and concurrency together to distinguish pool pressure from transport latency. Existing tiny-pool integration tests prove the release boundaries; this harness checks that no connections remain borrowed when a sample finishes, without adding a sampling timer or per-operation pool instrumentation. The reported `cache_store` is `worker-array`: relay restart checks still run, but these timings exclude the external cache round trips a Redis or database cache would add.

`--payload-bytes=256` controls the job's string field, not total serialized envelope size. `--warmup=100` runs outside timing; every invocation produces one measured sample. Warmup output is removed before direct, staging and relay measurements. Every run checks source remainder, target counts where applicable, remaining claims and cleanup. A partial drain is a failure, not a faster result. Relay and claim work have a five-minute bound; retain the external timeout for setup and transport failures too.

## Claims and query plans

`--backlog=10000` sizes the mixed backlog; delayed and retrying rows each take a quarter. `--leased` separately controls seeded live leases, defaulting to `batch × concurrency`, not a fraction of the backlog. The remaining rows are eligible, increased when necessary to provide at least one batch per concurrent claimant; the report gives the actual distribution. Seeded leases represent other in-flight work, in addition to the short-lived claims made by this process. Each settled batch is replaced with fresh eligible rows. Warmup churn is retained, so measurement is not limited to a freshly inserted table. Background deadlines are one day away, beyond the bounded run.

Claim p50/p95 includes the repository operation, row materialization and child-coroutine cleanup, not just server execution time. Empty claims caused by competing fallback claimants count as attempts and are reported separately. Cycle throughput and WAL include settlement and refill, including UUID generation and payload encoding. Latency samples add common harness overhead and are retained only for this bounded invocation.

```shell
timeout 600 php tests/Benchmarks/QueueOutbox/benchmark.php \
  --case=claims --database-connection=pgsql --jobs=10000 \
  --backlog=100000 --batch=100 --concurrency=8 --pool-size=4 --explain \
  > /tmp/outbox-claims.json
```

`--explain` refreshes table statistics and captures the actual claim SQL once after churn, outside timing. Executing plans run in transactions that are rolled back. PostgreSQL uses `EXPLAIN (ANALYZE, BUFFERS, WAL, FORMAT JSON)`; MySQL uses `EXPLAIN ANALYZE` for selects; MariaDB uses `ANALYZE FORMAT=JSON`; SQLite uses `EXPLAIN QUERY PLAN`. PostgreSQL's WAL option requires 13+, and MySQL's executing explain requires 8.0.18+. Engines lacking these commands fail with their native error; omit `--explain` there. No optimizer plan is asserted as a correctness requirement.

### PostgreSQL claim-design comparison

`--claim-design=availability` uses the production repository. `--claim-design=reservation` adds a benchmark-only, unindexed `reserved_until` column and replaces only the PostgreSQL claim CTE. It leaves indexed `available_at` unchanged and filters live reservations during selection. Both variants use the real accepted-delete settlement and equivalent logical backlogs. The reservation fixture is not a supported alternative production implementation.

Run both variants in alternating fresh processes. Compare claim latency, buffers and rows filtered alongside write volume: avoiding index changes can reduce writes but require scanning live reservations. Compare the default seeded lease count with a larger `--leased` value representing several other relays; reservation scan cost grows with live leases, not simply with total backlog. No fillfactor, autovacuum or checkpoint tuning is performed. HOT updates still depend on available page space; an unindexed deadline does not guarantee them.

The cluster-wide `pg_current_wal_insert_lsn()` delta includes the entire claim/settle/refill workload and unrelated database activity. It is not isolated index-write cost. The executing PostgreSQL plan supplies statement-local WAL and buffer evidence. Checkpoint state and full-page images can affect comparisons; use repeated samples rather than one ratio. See PostgreSQL's [WAL position functions](https://www.postgresql.org/docs/current/functions-admin.html#FUNCTIONS-ADMIN-BACKUP) and [EXPLAIN options](https://www.postgresql.org/docs/current/sql-explain.html).

Once approved measurements settle the comparison, record the findings and remove the temporary reservation fixture and selector. Do not change the production schema based only on WAL bytes or a single small run.

## Comparisons and interpretation

For direct-path regression checks, copy this directory into an independently installed pre-feature worktree. The `direct` case uses only existing queue APIs and does not load feature-only fixtures. Use identical dependency versions, PHP configuration, opcache/JIT settings and services. Record revisions, uncommitted changes and commands with raw JSON outside Git. Alternate baseline/current order for at least ten fresh-process pairs; compare medians and spread, not the fastest sample.

Direct publication and durable staging provide different guarantees; their throughput is not an apples-to-apples regression comparison. The report includes wall time, process CPU, heap/GC data and non-secret backend settings. CPU excludes database and Redis processes; heap growth is not an allocation count. Driver/network latency, payload size and database durability settings remain part of real throughput.

For a quick functional check, use `--jobs=8 --batch=2 --warmup=2 --backlog=12`. Check all four cases, both relay targets and the PostgreSQL comparison when those services are available. Keep these checks separate from idle-machine measurements. There are no recorded performance results yet.
