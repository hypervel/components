# Guzzle fork integration measurements

The native forks reduce CPU use on all five measured paths. Promise-chain CPU falls by 78%, asynchronous HTTP by 19%, and direct synchronous requests at concurrency 32 by 36%. Sentry's sampled MockHandler calls take 34% less time. These are local before/after comparisons, not production capacity forecasts.

## Method

Measured October 16, 2026, on an idle Linux x86-64 machine with six logical processors reporting Intel Core i7-7700 at 3.60 GHz. PHP 8.4.25, Swoole 6.3.0RC1, cURL 8.5.0 and OpenSSL 3.0.13 were unchanged. CLI OPcache was enabled, JIT disabled, GC enabled and `opcache.file_update_protection=2`. Processes were not CPU-pinned.

- Before: components `f2ebe434da7de52a6591649e1ddef93653783306`, using Guzzle 8.2.0 and promises 3.0.2 with AOP ownership checks.
- After: components `1419afbc5b7c61f81c331c6faa531fb49b6f9d21`, using hypervel/guzzle 8.2.0.1 and hypervel/guzzle-promises 3.0.2.2. Uncommitted changes were documentation only.
- Both worktrees had independent, freshly optimized, non-authoritative autoloaders and matching 67-character checkout paths. Storage-path lengths also matched. Every unchanged dependency had the same version, including PSR-7 3.1.0. The baseline manifest pinned the three upstream Guzzle packages to those versions.

Each workload used three process pairs, reversing order in the second pair. Each process ran a 32-operation warmup and three measured samples. Tables show the median of nine samples; ranges are their minimum and maximum, not confidence intervals. Runtime excludes startup. The origin ran in a separate process whose CPU is excluded. Baseline proxies were already generated and older than the OPcache protection interval.

| Workload | Scenario | Operations per sample | Concurrency |
| --- | --- | ---: | ---: |
| Promise chain | `promise` | 20,000 | 1 |
| Framework synchronous HTTP | `sync` | 1,000 | 1 |
| Guzzle asynchronous HTTP | `async` | 128 | 8 |
| Direct Guzzle synchronous HTTP | `direct-parallel` | 512 | 32 |
| Cancel without per-operation queue drains | `cancel-retention` | 1,000 | 1 |

## Performance

CPU and wall figures are microseconds per operation. Wall time divided by operation count describes batch throughput; it is not individual latency at concurrency greater than one.

| Workload | CPU before → after | CPU reduction | CPU ranges before / after | Wall before → after | Operations/sec before → after |
| --- | ---: | ---: | --- | ---: | ---: |
| Promise chain | 21.45 → 4.81 | 77.6% | 20.96–21.79 / 4.68–5.08 | 21.46 → 4.81 | 46,606 → 207,881 |
| Framework synchronous HTTP | 463.87 → 430.35 | 7.2% | 448.92–499.87 / 423.28–454.53 | 1,650.56 → 1,605.34 | 606 → 623 |
| Guzzle asynchronous HTTP, c8 | 226.46 → 183.06 | 19.2% | 220.84–241.95 / 168.60–210.66 | 226.43 → 184.14 | 4,416 → 5,431 |
| Direct Guzzle synchronous HTTP, c32 | 345.17 → 220.34 | 36.2% | 328.41–372.66 / 213.70–244.35 | 411.49 → 286.78 | 2,430 → 3,487 |
| Cancel pending transfer | 86.83 → 73.17 | 15.7% | 85.06–93.23 / 71.95–87.50 | 86.82 → 73.17 | 11,517 → 13,667 |

Cancellation timing stops before the sample's queue drain. It measures admission and cancellation, not the complete callback-cleanup cost.

| HTTP workload | Median p50 before → after, ms | Median p95 before → after, ms |
| --- | ---: | ---: |
| Framework synchronous HTTP | 1.632 → 1.588 | 1.742 → 1.708 |
| Guzzle asynchronous HTTP, c8 | 1.247 → 1.029 | 1.721 → 1.419 |
| Direct Guzzle synchronous HTTP, c32 | 8.924 → 6.278 | 10.865 → 7.013 |

Synchronous wall time and latency include the extra 1 ms timer used by Swoole's native cURL hook for immediate work, addressed by [swoole-src#6299](https://github.com/swoole/swoole-src/pull/6299). Both revisions use the same affected runtime. Compare CPU to assess the framework change; these figures are not a general sync-versus-async transport comparison.

The direct-client comparison includes the fork's larger idle-handle default. Across each 512-request sample, distinct local ports fell from 467 to 32. This demonstrates reuse across bursts; port counts are not a count of newly opened connections in every warm sample.

## Resources and cleanup

Medians after the owner coroutine exits and garbage collection runs:

| Workload | Live PHP heap before → after, KiB | RSS before → after, MiB |
| --- | ---: | ---: |
| Promise chain | 3,316.30 → 3,167.25 | 73.93 → 73.38 |
| Framework synchronous HTTP | 4,364.98 → 4,282.78 | 78.41 → 78.01 |
| Guzzle asynchronous HTTP, c8 | 3,791.70 → 3,627.46 | 76.61 → 76.00 |
| Direct Guzzle synchronous HTTP, c32 | 3,794.30 → 3,637.23 | 79.23 → 77.69 |
| Cancel pending transfer | 3,586.63 → 3,492.19 | 75.45 → 75.11 |

Live PHP heap is 82–164 KiB lower after exit, and median RSS is 0.34–1.54 MiB lower. Allocated PHP memory is 18 MiB on both revisions in every process. The measured runs did not call `gc_mem_caches()`. These process measurements include loaded code and allocator capacity; they are not per-request allocations.

Every process returned to six open descriptors after exit. During direct-client concurrency, the idle descriptor count was 18 → 105: nine runtime descriptors plus three per cached synchronous handle in this Linux/Swoole cURL setup. The hook retains a multi handle for each easy handle, in addition to its connection. The larger cache retained 32 handles rather than three. At the default limit of 256 handles, the same setup can retain about 768 descriptors per handler after a sufficiently concurrent burst; it does not allocate them all upfront. The other workloads had the same warm descriptor counts on both revisions.

Canceling 1,000 bare-handler transfers without draining the queue temporarily added a median 43,392 → 4,955,392 bytes of live heap. The scoped queue retains canceled transfers until their completion callbacks run. On the fork, the first measured sample also left a one-time 116,864-byte increase after drain and GC, from tracking/queue capacity and PHP object-store growth. Later samples added no further capacity. After draining those later samples, both revisions were 44,920 bytes above the pre-sample snapshot; after dropping results and collecting cycles, both were 5,656 bytes above it. Owner tracking and queue storage are released at owner exit; PHP can reuse its object-store capacity.

From the first measured sample's post-GC snapshot to the third's, heap growth was identical on both revisions: 11,312 bytes for ordinary workloads and 14,368 bytes for cancellation, matching the retained sample-report structures. This excludes the first-sample capacity increase above. This bounded run does not replace a sustained-load memory study.

## Sentry

The [Sentry results](../Sentry/results.md#outgoing-http-instrumentation) contain the matching component comparison: sampled requests 154.51 → 102.17 µs, recording disabled with trace propagation 101.78 → 68.56 µs, and explicit opt-out 77.68 → 49.82 µs. These include the underlying MockHandler operation and promise handling; they are not isolated middleware costs.

## Reproduction

Raw reports retain exact commands, settings and versions. Use the [README](README.md) to prepare independent worktrees and start the origin, then run each workload using the counts above:

```sh
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/GuzzleOwnership/benchmark.php \
  --root=/path/to/checkout --endpoint=http://127.0.0.1:PORT \
  --scenario=async --operations=128 --concurrency=8 --samples=3 \
  --storage=/tmp/checkout-specific-storage
```

Run the same current harness against both worktrees. Alternate before/after, after/before, before/after. No profiler was enabled and no production code was changed during measurement. Broader workloads, startup comparisons and profiling are tracked separately in `docs/todo.md`.
