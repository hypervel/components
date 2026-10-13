# Sentry performance measurements

Measured on October 13, 2026, using the [benchmark harness](README.md). The baseline is merged `0.4` at `8a8b9fd99`. The reconciled package before this optimization pass is `03d28a00b`; the final source and corrected harness are `c7b9b2a1b`. The branch comparison includes Sentry's supporting framework corrections, such as proxy-source mapping and fork-time logging cleanup; startup-memory differences therefore describe the branch, not just Sentry classes. The separate optimization comparison isolates this pass's Sentry changes.

These timings use the fixture at `c7b9b2a1b`. Subsequent harness review replaced its controller's service facades/helpers with injected contracts and added a delivery check after saturation. Those corrections were smoke-tested, not rebenchmarked; they do not change the production optimizations or retroactively change these measurements.

The machine was idle for measurements: Linux x86-64 KVM, six vCPUs reporting Intel Core i7-7700 at 3.60 GHz, PHP 8.4.25 and Swoole 6.3.0RC1. Both sides used Sentry SDK 4.34.0, Guzzle 8.2.0, Promises 3.0.2, Nyholm PSR-7 1.8.2 and Symfony HttpFoundation 8.1.8. Applications had independent production-only installations with matching dependency versions. OPcache was enabled and JIT disabled. HTTP runs disabled OPcache file-update protection for newly generated immutable caches. Server processes used CPUs 1–2, the sink CPU 3 and load generators CPUs 4–5; component runs used CPU 1.

Tables use medians of three alternating baseline/current process pairs. Component cases also repeat within each process. These are measurements of the stated fixtures, not production capacity estimates. The initial HTTP run was discarded after discovering that an unquoted `null` logging-channel name selected PHP null and emergency file logging. No timings from that run are included.

## HTTP workloads

All 36 normal-load launches passed response, delivery and cleanup checks: no telemetry for successful C0–C3 requests, and exactly one transaction per request for C4/C5. Each launch measured 20,000 plain requests at concurrency 32, 10,000 mixed requests at concurrency 32 and 3,000 mixed requests at concurrency one, after warmup.

Median total server CPU, including work that finishes after the response, in microseconds per request:

| Configuration | Plain, c32 original | Final | Mixed, c32 original | Final | Mixed, c1 original | Final |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| C0: package excluded | 504 | 496 | 1,259 | 1,285 | 1,460 | 1,523 |
| C1: no DSN | 494 | 505 | 1,280 | 1,259 | 1,633 | 1,580 |
| C2: errors only | 596 | 591 | 1,591 | 1,552 | 1,827 | 1,840 |
| C3: unsampled tracing | 684 | 670 | 1,728 | 1,703 | 1,993 | 1,903 |
| C4: fully sampled defaults | 1,342 | 1,323 | 2,815 | 2,769 | 3,090 | 3,047 |
| C5: fully sampled, optional instrumentation off | 1,353 | 1,332 | 2,234 | 2,188 | 2,587 | 2,497 |

Whole-request differences are modest. Even C0, which excludes Sentry, varies between revisions and repetitions: its mixed c32 CPU ranges were 1,242–1,287 µs original and 1,258–1,294 µs final. Fully sampled mixed c32 ranges were 2,786–2,878 µs original and 2,759–2,783 µs final, with a 1.6% median reduction. Do not treat small changes in the other rows as established improvements or regressions.

Fully sampled defaults, with original → final medians:

| Workload | Requests/sec | p50 latency, ms | p99 latency, ms | Sampled peak worker RSS, MiB |
| --- | ---: | ---: | ---: | ---: |
| Plain, c32 | 782 → 797 | 40.10 → 39.49 | 52.66 → 50.02 | 67.71 → 67.89 |
| Mixed, c32 | 393 → 400 | 80.74 → 79.38 | 98.58 → 95.12 | 78.59 → 79.47 |
| Mixed, c1 | 322 → 327 | 3.02 → 2.96 | 4.76 → 4.79 | 85.74 → 86.62 |

Concurrency-32 latency includes queueing in a single worker driven to capacity. These are not unloaded endpoint latency figures. RSS includes native allocations and shared pages, so it is not interchangeable with PHP's live heap.

Fully sampled tracing still has a real absolute cost. In the final mixed c32 fixture, C4 uses approximately 1,484 µs more CPU per request than C0; C2 adds about 267 µs. At 1,000 requests/sec those differences amount to approximately 1.48 and 0.27 CPU-seconds per second, respectively, before any extrapolation to different application workloads. The small before/after reduction does not make capturing and delivering every transaction free.

## Request collection and scope access

CPU sampling identified repeated cookie-pattern matching, PSR-7 request clones for each header, and repeated Hub context lookups. The changes use fixed-name comparisons for fixed cookie names, construct valid headers together, and read the coroutine-local scope stack directly. They add no worker-lifetime cache or shared request state. Invalid headers retain their fallback path; numeric header names now survive both paths.

Request fetching, microseconds per call:

| Case | Original package | Before optimization | Final | Optimization gain |
| --- | ---: | ---: | ---: | ---: |
| Legacy GET | 28.7 | 28.5 | 20.7 | 27% |
| Legacy JSON POST, approximately 2 KB | 46.6 | 53.7 | 45.4 | 15% |
| `data_collection` GET | 29.1 | 27.8 | 19.4 | 30% |
| `data_collection` JSON POST, approximately 2 KB | 47.8 | 32.0 | 23.7 | 26% |

The original-package and final columns come from the final paired run; the pre-optimization column comes from the earlier three-pair component run. The complete transaction request-data processor measured 45.1 → 36.3 µs for legacy GET, 63.4 → 61.7 µs for legacy JSON, 56.4 → 46.2 µs for `data_collection` GET and 76.3 → 51.5 µs for `data_collection` JSON.

A focused comparison of the old and new Hub lookup, in five alternating pairs of 100,000 calls inside a coroutine, measured 0.446 → 0.210 µs per `getSpan()` call. This is the lookup's cost, not a claim that whole requests became twice as fast.

Copying a populated scope into a child coroutine measured 5.49 → 2.96 µs per child between the original and final packages. With propagation inactive, the measurements were 1.43 → 1.42 µs, within variation.

## Outgoing HTTP instrumentation

The MockHandler cases isolate instrumentation and promise handling from network I/O. Times include the underlying Guzzle operation. Ranges are the minimum and maximum process medians, in microseconds per request.

| Case | Original median (range) | Final median (range) |
| --- | ---: | ---: |
| Explicit `no_sentry_aspect` opt-out | 77.4 (76.6–82.4) | 77.4 (75.2–78.3) |
| Sentry inactive | 76.9 (74.4–77.1) | 76.0 (73.9–77.7) |
| Recording off, trace-header propagation enabled | 103.2 (101.3–103.3) | 102.1 (96.5–102.9) |
| Breadcrumbs, no transaction | 125.4 (122.0–125.5) | 129.2 (128.5–133.5) |
| Unsampled transaction | 121.8 (118.8–124.0) | 131.9 (131.5–132.5) |
| Sampled synchronous request | 141.4 (141.4–148.3) | 153.6 (149.4–155.6) |
| Sampled asynchronous request | 143.5 (139.0–146.0) | 155.8 (152.5–157.0) |

Enabled instrumentation has an added cost: it observes promise completion, including failures and redirects, and collects the final prepared request and response under the SDK's data-collection policy. Returning to entry-only observation would lose that behavior. The synchronous origin lookup remains threshold-gated; forcing its threshold to zero measured 177.9 µs, versus 153.6 µs at the default. Async origin capture happens at entry because the caller's stack no longer exists at completion; disabling it measured 151.8 µs in this fixture.

Pending asynchronous request memory, bytes per request:

| Case | Original | Final |
| --- | ---: | ---: |
| Sentry inactive | 8,962 | 8,962 |
| Sampled, origin disabled | Not available | 15,231 |
| Sampled, default origin capture | 11,471 | 18,991 |

These are live pending callbacks, not memory retained per completed request. The default increase is about 7.34 KiB per outstanding sampled request in this fixture, or 7.17 MiB for 1,000 such requests. About 3.67 KiB of the final per-request allocation is the captured origin stack here. Capture is limited to 64 frames and excludes arguments; actual memory depends on stack depth and names. Disabling origin capture retains completion correctness while avoiding that allocation.

The approximately 12 µs measured sampled-call increase corresponds to 0.012, 0.12 and 0.60 CPU-seconds per second at 1,000, 10,000 and 50,000 instrumented calls per second, respectively. This is arithmetic from the isolated measurement, not a throughput benchmark at those rates; it excludes error capture, transaction serialization and delivery.

## Disabled instrumentation and retained memory

The registration inventory confirms that inactive Sentry installs no event listeners, middleware, Guzzle aspect, child-coroutine propagation hook or Blade decorator. Turning off individual features removes their recording work; shared listeners remain where another enabled feature needs them. Turning off all optional tracing and breadcrumb toggles still leaves request transactions, trace-header propagation and lifecycle handling. It is not equivalent to disabling Sentry.

Median PHP heap at idle startup and after the complete normal workload followed by garbage collection, in MiB:

| Configuration | Startup original | Final | Post-workload original | Final |
| --- | ---: | ---: | ---: | ---: |
| C0 | 8.29 | 8.31 | 10.05 | 10.08 |
| C1 | 8.81 | 8.83 | 10.58 | 10.61 |
| C2 | 8.89 | 8.92 | 10.78 | 10.81 |
| C3 | 8.97 | 8.99 | 10.93 | 10.96 |
| C4 | 8.97 | 8.99 | 11.15 | 11.17 |
| C5 | 8.90 | 8.92 | 11.06 | 11.09 |

Post-workload figures include warmed framework services and fixture session data. The roughly 0.03 MiB branch difference also appears with Sentry excluded. Fully sampled post-workload worker RSS was 85.32 → 86.20 MiB.

A separate six-wave retention check used the fully sampled mixed workload with exception reporting, 1,000 requests per wave, concurrency 64 and a delayed sink. After collection, each wave returned to ten idle transport connections, no borrowed connections, no waiting borrowers and no pending sends. The remaining PHP-heap increase matched the fixture's 64 new browser sessions per wave. Excluding Sentry gave the same session growth. Those application-owned session records are not a telemetry leak.

The investigation also isolated an uncached-template effect: repeatedly including a PHP file containing a closure added 256 KiB per 1,000 includes in standalone PHP, without Hypervel or Swoole. The benchmark's immediate build/start sequence left its newly compiled view outside OPcache. With the view cached, that growth disappeared with and without Sentry. Final HTTP measurements use cached views on both sides; they do not remove the closure or alter the workload to hide its cost.

## Saturation and recovery

All six saturation launches passed, each with two 5,000-request waves at concurrency 64. The sink delayed every envelope by 200 ms; each request attempted one error event and one transaction. The shipped pool limit remained ten connections. Medians across three launches:

| Revision / wave | Requests/sec | Received errors / 5,000 | Received transactions / 5,000 | Drain after responses, ms | Sampled peak RSS, MiB |
| --- | ---: | ---: | ---: | ---: | ---: |
| Original, first | 206 | 448 | 684 | 186 | 84.46 |
| Original, second | 212 | 441 | 652 | 235 | 84.66 |
| Final, first | 210 | 458 | 641 | 236 | 85.47 |
| Final, second | 215 | 484 | 596 | 225 | 86.59 |

Every application request succeeded. Both revisions failed to deliver roughly 89% of attempted telemetry under this artificial overload. The sink counts arrivals, not the reason an item was not delivered. These runs demonstrate the pool's bounded behavior and draining, not a lossless queue or a delivery guarantee at arbitrary load. They did not include the later low-load delivery check after saturation.

After every wave, both revisions returned to ten idle connections, zero borrowed connections, zero waiting borrowers, zero pending sends and the original idle coroutine count. Final post-GC PHP heap was 11.37 MiB original and 11.34 MiB current. The separate six-wave check above distinguishes fixture-session growth from telemetry retention.

Follow-up smoke checks of the revised harness passed on both revisions, including exact delivery of one error and one transaction after removing the sink delay. Deliberately closing the current transport pool immediately before that request made the check fail with zero delivered items, while its earlier waves and drained counters still passed. This verifies the recovery assertion without treating smoke timings as new performance evidence.

## Design choices

The retained optimizations remove repeated work without changing public APIs, adding cache state or weakening filtering. Request conversion stays on demand so it observes the request's current values. Invalid headers remain isolated rather than causing an event to disappear.

The completion observer remains despite its measured cost: removing it loses final response, redirect and rejection information. Async origin capture also remains configurable; a completion-time backtrace cannot recover the caller that has already returned. The report exposes both its time and pending-memory cost instead of treating it as free.

Validation covered the complete Sentry suite (542 tests, 1,866 assertions), formatting and source/type-fixture static analysis. Numeric-header regressions failed against the prior implementation and passed after the correction. Benchmark cases additionally assert delivered telemetry, finished spans, breadcrumbs, scope propagation and cleanup.
