# Database lifecycle benchmark

This developer-only harness measures ordinary coroutine-owned database lifecycles: initial connection resolution, queries, transactions, and deferred pool cleanup. File-backed SQLite isolates framework CPU and allocation costs without network latency; it does not predict a database server's throughput. It uses the real pool and resolver with Testbench pooling enabled.

Run only during an owner-confirmed idle window. Run the same harness in independently installed baseline and modified worktrees, using the same dependency versions, PHP configuration and machine. Record each revision and any uncommitted changes with the reports. Alternate the order of baseline and modified runs to reduce ordering bias.

```shell
php tests/Benchmarks/Database/benchmark.php --concurrency=1 --queries=0 > /tmp/db-resolution.json
php tests/Benchmarks/Database/benchmark.php --concurrency=1 --queries=1 > /tmp/db-first-query.json
php tests/Benchmarks/Database/benchmark.php --concurrency=1 --queries=10 > /tmp/db-queries.json
php tests/Benchmarks/Database/benchmark.php --concurrency=32 --queries=10 --wait-us=1000 > /tmp/db-concurrent.json
php tests/Benchmarks/Database/benchmark.php --concurrency=32 --queries=10 --wait-us=1000 --transaction > /tmp/db-transactions.json
```

The short simulated external wait lets requests overlap and hold real pool slots. Without a wait, SQLite may complete each coroutine before the next starts; use the reported peak active requests and borrowed slots rather than treating configured concurrency as observed concurrency. Compare CPU per request as well as wall-clock throughput when waits are enabled.

On a checkout supporting early release, add `--release` to the concurrent scenario. It returns idle sessions before the wait while retaining the logical connection for subsequent queries. Combining it with `--transaction` measures the pinned path. Baselines without the API reject `--release`; ordinary lifecycle scenarios remain directly comparable.

For contention, set `--pool-size` below concurrency and enable `--pool-metrics`:

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable tests/Benchmarks/Database/benchmark.php --requests=128 --samples=1 --concurrency=32 --pool-size=4 --queries=2 --wait-us=50000 --pool-metrics
```

Compare baseline, current, current with `--release`, and current with `--release --transaction`. The optional instrumented pool records checkout and physical hold p50/p95, including reacquisitions, and the harness records complete request p50/p95. Checkout includes creation and checkout bookkeeping as well as queue waits; after warmup, queueing dominates the contended cases. Hold measurements finish before the slot is made available to another coroutine. Keep this instrumentation disabled for allocation and CPU microbenchmarks because its sample arrays add overhead.

To verify automatic release through the real HTTP client, start the separate verified-TLS origin described in `../HttpTransport/README.md`, then replace the simulated wait with:

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable tests/Benchmarks/Database/benchmark.php --requests=128 --samples=1 --concurrency=32 --pool-size=4 --queries=2 --pool-metrics --http-url='https://127.0.0.1:PORT/?chunks=1&bytes=128&pace_us=0&delay_us=50000' --http-ca=/tmp/hypervel-http-benchmark/ca.pem
```

`--http-url` replaces `--wait-us`. Compare the unchanged baseline with the current default and with `--transaction`; do not use `--release` when measuring the automatic boundary. The origin delays its response by 50 ms while the caller either retains or releases its database session. TLS and HTTP processing add cost, so keep these results separate from simulated-wait measurements.

Each scenario warms the pool, then reports repeated samples. Collection runs only between samples, never within the measured request loop. Reports include CPU time, resolution/query/deferred-cleanup timing, peak heap and ending heap growth, GC runs/collected objects/roots/collector time, and pool ownership after completion. GC roots are sampled after each bounded batch; peak heap captures growth between collections. Memory figures describe the worker, not independent per-request peaks. Harness scheduling and measurement overhead are present in both versions.

For comparisons, invoke one measured sample per process and alternate baseline/current order within each scenario for at least ten pairs. Report median and spread. Enable CLI opcache for both, and use identical JIT and GC settings; the report includes those settings. The warmup precedes the measured sample in each process.

Use `--requests`, `--samples`, `--concurrency`, `--queries`, `--wait-us` and `--pool-size` to vary workload. Keep raw reports outside the repository. Do not use throughput thresholds as CI assertions or add production GC calls to improve benchmark results.
