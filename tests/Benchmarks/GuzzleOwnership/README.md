# Guzzle ownership benchmark

This developer harness compares the branch base with the Guzzle forks integration. It reports startup separately from warm operations using the production HTTP provider. Proxy bootstrap remains in the harness so it can also measure the AOP implementation on older revisions. It is not a CI test or a prediction of remote service throughput.

See [the measured results](results.md) for operation costs and resource comparisons.

Run measurements on an idle machine, without concurrent tests, builds, or other benchmarks. Use `--smoke` for a small correctness check; its timings are not performance evidence. Measured runs require `--idle-confirmed`.

Use independent baseline and modified worktrees. Keep unchanged dependencies at identical versions; compare upstream Guzzle 8.2.0 / promises 3.0.2 with their Hypervel forks. The runner's `--root` selects that checkout and its autoloader, so the same harness can test both without changing baseline source. Record the Git revisions and any uncommitted changes.

Run `composer dump-autoload -o` in both checkouts before measuring. Installing an optimized baseline while using a previously unoptimized main checkout gives misleading startup and memory differences. Reports include class-map size and mode; exact entry counts may differ with the source changes. Pre-generate the proxies before warm comparisons and allow PHP's `opcache.file_update_protection` interval to pass, so newly written proxy files receive the same OPcache treatment as existing files. Measure cold startup separately.

Start the separate origin:

```sh
php tests/Benchmarks/GuzzleOwnership/Fixtures/server.php
```

It prints `READY <port>`. Use that port in another terminal:

```sh
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/GuzzleOwnership/benchmark.php \
  --root=/absolute/path/to/checkout --endpoint=http://127.0.0.1:PORT \
  --scenario=async --operations=1000 --concurrency=8 --samples=5 \
  --idle-confirmed \
  --storage=/tmp/guzzle-benchmark-current > /tmp/guzzle-current-async.json
```

Use separate storage directories for each checkout. On AOP revisions, the first process with an empty directory measures cold proxy generation; the next uses its cached files. Runtime samples include one explicitly labeled warmup. Startup measures autoloading, application construction, provider registration and proxy bootstrap, not a complete application boot. Stop the origin and remove the temporary worktrees, storage directories and dependency projects when finished.

| Scenario | Work |
| --- | --- |
| `idle` | Same measurement loop without Guzzle work; identifies harness costs. |
| `promise` | Construct, chain, resolve and wait on mutable promises. |
| `sync` | Sequential public Hypervel HTTP client requests using a named connection. |
| `direct-sync` | Sequential requests through a directly constructed Guzzle client; the control for global middleware. |
| `async` | Guzzle async fan-out, created and completed in one coroutine. |
| `parallel` | Public HTTP requests through the same named connection in bounded child coroutines. |
| `direct-parallel` | Synchronous requests through one directly constructed Guzzle client from child coroutines; records distinct local connection ports per sample. |
| `middleware` | A response middleware callback dispatching an event. |
| `global-middleware` | Direct synchronous requests wrapped by two pass-through global middleware (fork only). Compare absolute CPU per request with `direct-sync`. |
| `credentials` | Concurrent invocations of a shared callable that fetches and resolves credentials, using the serialization wrapper when installed. |
| `pusher` | Same-coroutine fan-out through the public client built by `BroadcastManager`. |
| `cancel` | Create and cancel pending native transfers, then drain their rejection callbacks. |
| `cancel-retention` | Cancel bare multi-handler transfers without draining per operation; report memory before and after the sample's queue drain and after owner exit. |

`concurrency` controls fan-out for `async`, `parallel`, `direct-parallel`, `credentials` and `pusher`. Other scenarios execute sequentially. Measure `direct-parallel` at 8 and 32 to check connection reuse and descriptors across repeated bursts. Add `--outside` to compare execution outside coroutines; `parallel`, `direct-parallel` and `credentials` require child coroutines and do not support that mode. Use operation counts sufficient to exceed timer granularity and keep inputs identical within each comparison. Adding `?delay_us=1000` to the endpoint supplies a controlled fetch delay for credential contention; the public Pusher endpoint uses its own URL and does not inherit that query.

The credential provider creates independent fetches, and both compared implementations serialize access to it. Use `--concurrency=1` to measure per-call wrapper and ownership overhead. At higher concurrency, callers queue; with `delay_us` set, compare p95 operation latency with concurrency times the uncontended fetch time. The harness records total latency, not separate I/O and lock-wait timings.

Reports include wall time, process CPU, throughput, p50/p95 operation latency, PHP used/allocated/peak memory, Linux RSS, descriptor counts and GC statistics before/after each sample and after coroutine exit. The separately running origin's CPU is excluded. Results and latency arrays consume memory proportional to the operation count; they are released before each `after_gc` snapshot. Final cleanup includes coroutine-owned handlers and queues. Report both retained warm resources and resources after exit; PHP heap alone does not account for native cURL memory.

Use a small fixed set of alternating baseline/current process pairs, initially three pairs per scenario. Report medians and spread, absolute changes and percentages; add repetitions only to resolve observed uncertainty. Compare cold startup, cached startup and warm runtime independently. Keep raw JSON outside the repository and include exact commands, versions, CPU/VM details, OPcache/JIT/GC settings and limitations with the result tables. Measurements that reveal meaningful overhead require investigation before adding an optimization. Do not introduce timing assertions or assume a difference is noise.
