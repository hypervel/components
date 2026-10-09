# Guzzle ownership benchmark

This developer harness compares the unchanged branch base with the reviewed ownership implementation. It reports startup separately from warm operations, using the production HTTP provider and generated proxies. It is not a CI test or a prediction of remote service throughput.

**Do not run measurements until the owner confirms the machine is idle and authorizes them.** Correctness checks and syntax validation can run beforehand.

Use independent baseline and modified worktrees with identical installed dependency versions. The runner's `--root` selects that checkout and its autoloader, so the same harness can test both without changing baseline source. Record the Git revisions and keep the checkouts clean. Run the focused workloads with both Guzzle 8/promises 3/PSR-7 3 and Guzzle 7/promises 2/PSR-7 2; the broader consumer workloads need only the primary family unless results show a material version difference.

Start the separate origin after the idle-machine gate:

```sh
php tests/Benchmarks/GuzzleOwnership/Fixtures/server.php
```

It prints `READY <port>`. Use that port in another terminal:

```sh
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/GuzzleOwnership/benchmark.php \
  --root=/absolute/path/to/checkout --endpoint=http://127.0.0.1:PORT \
  --scenario=async --operations=1000 --concurrency=8 --samples=5 \
  --storage=/tmp/guzzle-benchmark-current > /tmp/guzzle-current-async.json
```

Use separate storage directories for each checkout, dependency family and variant. The first process with an empty directory measures cold proxy generation; the next uses its cached files. Runtime samples include one explicitly labeled warmup. Startup measures autoloading, application construction, provider registration and proxy bootstrap, not a complete application boot. Stop the origin and remove the temporary worktrees, storage directories and dependency projects when finished.

| Scenario | Work |
| --- | --- |
| `idle` | Same measurement loop without Guzzle work; identifies harness costs. |
| `promise` | Construct, chain, resolve and wait on mutable promises. |
| `sync` | Sequential public Hypervel HTTP client requests using a named connection. |
| `async` | Guzzle async fan-out, created and completed in one coroutine. |
| `parallel` | Public HTTP requests through the same named connection in bounded child coroutines. |
| `middleware` | A response middleware callback dispatching an event. |
| `credentials` | Concurrent invocations of a shared callable that fetches and resolves credentials, using the serialization wrapper when installed. |
| `pusher` | Same-coroutine fan-out through the public client built by `BroadcastManager`. |
| `cancel` | Create and cancel pending native transfers, then drain their rejection callbacks. |

`concurrency` controls fan-out for `async`, `parallel`, `credentials` and `pusher`. Other scenarios execute sequentially. Use bounded levels such as 1, 8 and 32, with operation counts sufficient to exceed timer granularity. Keep inputs identical within each comparison. Adding `?delay_us=1000` to the endpoint supplies a controlled fetch delay for credential contention; the public Pusher endpoint uses its own URL and does not inherit that query.

The credential provider creates independent fetches on the baseline, so the comparison is safe. Use `--concurrency=1` to measure per-call wrapper and ownership overhead. At higher concurrency, its serialized version queues callers; with `delay_us` set, compare p95 operation latency with concurrency times the uncontended fetch time. The harness records total latency, not separate I/O and lock-wait timings. Do not attribute all added latency to AOP, or benchmark the known unsafe shared pending-provider or cross-coroutine Pusher cases on the baseline as equivalent successful work; regression tests cover their correction.

On the modified checkout, `--variant=noop` replaces the registered ownership aspects with one pass-through aspect targeting the same methods and uses the stock task queue. This diagnostic separates ordinary AOP dispatch from the combined ownership and queue bookkeeping. It is not a production mode, and end-to-end before/after tables use `production` on both checkouts. Never use the diagnostic to evaluate correctness or cross-coroutine misuse.

Reports include wall time, process CPU, throughput, p50/p95 operation latency, PHP used/allocated/peak memory, Linux RSS, descriptor counts and GC statistics before/after each sample and after coroutine exit. The separately running origin's CPU is excluded. Results and latency arrays consume memory proportional to the operation count; they are released before each `after_gc` snapshot. Final cleanup includes coroutine-owned handlers and queues. Report both retained warm resources and resources after exit; PHP heap alone does not account for native cURL memory.

Use a small fixed set of alternating baseline/current process pairs, initially three pairs per scenario. Report medians and spread, absolute changes and percentages; add repetitions only to resolve observed uncertainty. Compare cold startup, cached startup and warm runtime independently. Keep raw JSON outside the repository and include exact commands, versions, CPU/VM details, OPcache/JIT/GC settings and limitations with the result tables. Measurements that reveal meaningful overhead require investigation before adding an optimization. Do not introduce timing assertions or assume a difference is noise.
