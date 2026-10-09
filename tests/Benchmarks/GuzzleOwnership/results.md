# Guzzle ownership and AOP measurements

The ownership checks add measurable CPU cost. Shared AOP optimizations reduce total guarded promise-chain CPU time by about 48% compared with the first implementation, and reduce its added overhead above baseline by about 52%. On the measured Guzzle 8 code, synchronous HTTP adds about 39 µs of client CPU per request; coroutine-parallel HTTP at concurrency 32 adds about 38 µs. These are local workloads, not estimates of application response time or remote-service throughput.

## Method

Baseline: unchanged `0.4` at `4f592496a605fe5028c908ae50ee12173428957b`. The measured implementation is committed through `5c1c44850`, apart from the final equivalent loader-creation simplification made after measurements. The benchmark harness is identical for both. Each checkout has independently installed dependencies and optimized, non-authoritative Composer autoloading.

These measurements precede the subsequent class-map override cleanup, repeat-boot and release-local proxy corrections, and the switch to `xxh128` for internal proxy fingerprints and helper names. Those corrections add generated source metadata and bootstrap lookup for registered overrides; they do not change the per-request dispatch or ownership checks. Their startup and retained-memory effects have not been remeasured, so the resource figures below describe the named benchmark revision rather than the later corrections.

Measurements used PHP 8.4.25, Swoole 6.2.2, cURL 8.5.0 with OpenSSL 3.0.13, Linux x86-64, and an Intel Core i7-7700 at 3.60 GHz with six logical processors exposed. CLI OPcache was enabled, JIT disabled, GC enabled, and `opcache.file_update_protection=2`. Proxy files were generated before warm comparisons and allowed to age past that interval. The loopback origin ran in a separate process; its CPU is excluded. No tests or other measurements ran concurrently.

Each workload has three alternating baseline/candidate process pairs, each with a 32-operation warmup followed by three measured samples. Tables show the median of the nine warm samples and their minimum–maximum range. This range is not a confidence interval. Runtime figures exclude application/proxy startup. Wall time divided by operation count measures batch throughput; individual latency is reported separately where useful.

| Workload | Operations per sample | Concurrency |
| --- | ---: | --- |
| Idle loop | 50,000 | 1 |
| Promise chain | 20,000 | 1 |
| Synchronous HTTP, response middleware, cancellation | 1,000 | 1 |
| Coroutine-parallel HTTP | 512 | 1, 8, 32 |
| Async HTTP, Pusher | 128 | 1, 8, 32 |
| Shared credential provider | 128 | 1, 8 |

The credential endpoint adds a controlled 1 ms delay. The baseline provider creates independent operations, so that comparison is safe; the candidate serializes calls to the shared provider. It measures the cost of that correctness guarantee as well as the Guzzle integration. Known unsafe cross-coroutine promise/transport sharing is covered by regression tests, not treated as a valid throughput baseline.

Reproduce with the commands in [README.md](README.md), substituting the operation counts and concurrency above. Raw reports retain exact commands and versions. The complete runtime matrices are `complete-g8-*` and `complete-g7-*`. After the final bootstrap-only corrections, `loader-g8-*` refreshes all resource rows and their seven runtime comparisons; the other runtime rows retain the earlier measurements of unchanged dispatch and ownership code. `loader-g7-idle-*` refreshes Guzzle 7 startup and idle resources. Earlier `matched-g8-*`, `compact-map-*`, and `lazy-ast-*` reports identify optimization stages. Initial `g8-*` and `final-g8-*` resource comparisons had different autoloader settings and are excluded from final conclusions.

## Shared AOP improvements

The dispatcher now reuses a compiled chain containing only aspect class names and continuation closures. It still resolves each aspect from the current container for each invocation. Generated proxies supply their target instance directly, avoiding repeated closure reflection. Invocation objects and request state are never stored in the chain.

The promise probe constructs three mutable promises and intercepts nine calls per operation. The no-op variant uses the same intercepted methods with a pass-through aspect and the stock task queue. It isolates dispatch overhead; it is not a production mode.

| Warm promise probe | Original dispatcher CPU, µs/op | Compiled chain CPU, µs/op | Change |
| --- | ---: | ---: | ---: |
| Pass-through aspect | 31.62 | 15.80 | −50.0% |
| Production ownership checks | 39.52 | 21.23 | −46.3% |

A separate alternating comparison at 100,000 chains per sample isolated direct instance passing: **21.72 → 20.48 µs/op**, a further **5.7% reduction**, with ranges 20.93–22.91 and 20.15–21.30. Each of its three process pairs improved. The first complete matrix measured **20.50 µs/op**, about **48.1% below the original 39.52 µs/op**. Against its unchanged framework baseline of 3.22 µs/op, added overhead fell from **36.30 to 17.28 µs/op**, a **52.4% reduction**. The final bootstrap refresh measured 20.57 µs/op with the same dispatch code.

Bootstrap publishes proxies through a separate, authoritative Composer loader containing only proxy entries. The application loader retains its original paths without copying its complete class map. Exact class rules use direct lookup, and the PHP parser/printer are created only when generating code. The existing source fingerprint and visitor behavior remain intact; native directory enumeration replaces Symfony Finder for the fixed generator directory.

| Cached bootstrap stage | Startup median (range), ms | Retained PHP heap, KiB | RSS, MiB |
| --- | ---: | ---: | ---: |
| Ownership implementation before bootstrap optimization | 181.16 (173.86–185.98) | 6,114.61 | 74.64 |
| Targeted original paths and class lookup | 108.39 (101.21–109.97) | 4,834.70 | 73.27 |
| Lazy parser/printer | 83.72 (81.49–85.49) | 4,510.60 | 71.49 |
| Dedicated proxy loader and native directory enumeration | 78.15 (75.86–82.25) | 3,164.52 | 69.96 |

The first three stages use three cached idle processes each. The final startup row uses all 21 cached candidate processes from the seven refreshed workloads, whose startup is identical and completes before workload setup; its heap/RSS values use the three idle processes. Combined, these changes remove about **2.88 MiB of retained PHP heap** and **57% of cached startup time** from the first ownership implementation. The corresponding 21 baseline processes measured **69.95 ms** (68.07–80.48), versus **78.15 ms** for the candidate: **8.20 ms** added at startup. One fresh candidate process requiring proxy generation took **132.45 ms**; this cold process is excluded from cached timings and resource tables.

## Final Guzzle 8 comparison

Both checkouts use Guzzle 8.2.0, promises 3.0.2, PSR-7 3.1.0 and AWS SDK 3.399.1. CPU and wall columns are **µs per completed operation**. Ranges show baseline / candidate CPU samples.

| Workload | Concurrency | CPU baseline → candidate | Added CPU | CPU sample ranges | Wall baseline → candidate |
| --- | ---: | ---: | ---: | --- | ---: |
| Idle † | 1 | 0.33 → 0.34 | 0.01 (rounding-sensitive) | 0.33–0.37 / 0.33–0.55 | 0.33 → 0.34 |
| Promise chain † | 1 | 3.23 → 20.57 | 17.34 (536.5%) | 3.17–3.37 / 20.08–21.87 | 3.23 → 20.57 |
| Synchronous HTTP † | 1 | 405.37 → 444.67 | 39.30 (9.7%) | 395.04–423.56 / 436.49–458.81 | 1,573.61 → 1,617.44 |
| Async HTTP | 1 | 289.60 → 377.82 | 88.22 (30.5%) | 274.25–307.85 / 370.02–408.18 | 323.25 → 424.67 |
| Async HTTP | 8 | 200.12 → 259.75 | 59.63 (29.8%) | 193.23–222.44 / 254.02–279.17 | 62,754.72 → 62,820.01 |
| Async HTTP † | 32 | 152.42 → 210.28 | 57.86 (38.0%) | 144.83–159.85 / 200.55–233.86 | 15,793.33 → 15,850.79 |
| Coroutine-parallel HTTP | 1 | 466.42 → 519.82 | 53.40 (11.4%) | 444.83–472.85 / 493.32–532.92 | 2,739.11 → 2,800.48 |
| Coroutine-parallel HTTP | 8 | 422.48 → 482.29 | 59.80 (14.2%) | 401.90–442.48 / 465.33–494.56 | 692.99 → 755.78 |
| Coroutine-parallel HTTP † | 32 | 430.56 → 468.59 | 38.03 (8.8%) | 405.40–461.20 / 453.02–487.84 | 502.64 → 535.97 |
| Response middleware/event | 1 | 301.30 → 339.90 | 38.61 (12.8%) | 294.46–308.23 / 332.78–348.05 | 1,459.76 → 1,495.88 |
| Credential provider | 1 | 371.19 → 419.12 | 47.94 (12.9%) | 346.78–391.45 / 402.99–476.49 | 3,742.10 → 3,803.64 |
| Credential provider | 8 | 294.69 → 409.29 | 114.60 (38.9%) | 283.31–321.66 / 394.66–427.19 | 701.72 → 2,827.56 |
| Pusher | 1 | 390.30 → 500.75 | 110.45 (28.3%) | 385.42–418.23 / 494.96–507.88 | 441.51 → 548.78 |
| Pusher | 8 | 299.13 → 374.29 | 75.16 (25.1%) | 293.68–307.20 / 369.04–405.23 | 62,855.13 → 62,938.13 |
| Pusher † | 32 | 267.77 → 330.34 | 62.57 (23.4%) | 254.00–296.39 / 322.06–374.62 | 15,900.80 → 15,968.84 |
| Cancel pending transfer † | 1 | 108.96 → 165.89 | 56.93 (52.3%) | 106.50–124.49 / 157.19–169.16 | 108.99 → 165.90 |

† Refreshed `loader-g8-*` runs after the bootstrap corrections. Unmarked rows are from `complete-g8-*`, using the same dispatch and ownership implementation. Do not treat rows from different runs as a controlled concurrency-scaling comparison.

The high async/Pusher wall times at concurrency 8 and 32 include the known hooked-select readiness stall addressed by [Swoole #6290](https://github.com/swoole/swoole-src/pull/6290). They must not be used to claim negligible AOP overhead. CPU measurements expose the added work separately. A patched older Swoole build confirmed the reproduction, but it is below Hypervel's supported minimum and was not used for these performance tables. The default AWS Guzzle transport also uses `sendAsync`, including for synchronous SDK calls, so the native issue is not limited to explicitly async application code.

| Individual operation latency | Baseline p50 / p95, ms | Candidate p50 / p95, ms |
| --- | ---: | ---: |
| Synchronous HTTP | 1.563 / 1.687 | 1.610 / 1.731 |
| Coroutine-parallel HTTP, concurrency 32 | 10.132 / 12.269 | 10.858 / 12.615 |
| Credential provider, concurrency 1 | 3.720 / 3.899 | 3.779 / 3.963 |
| Credential provider, concurrency 8 | 4.702 / 5.396 | 12.118 / 22.474 |

The contended credential-provider latency includes waiting for the provider lock. This is the deliberate serialization needed for a shared provider that may retain pending work; it is not the cost of aspect dispatch alone. A memoized, already-completed credential result remains reusable.

## Capacity planning

The 38.03 µs added CPU measured for coroutine-parallel HTTP at concurrency 32 is per outgoing request, not per batch. Multiplying it by the outgoing request rate gives the additional CPU capacity across a deployment:

| Outgoing requests per second | Additional CPU cores at 38.03 µs/request |
| ---: | ---: |
| 1,000 | 0.038 |
| 10,000 | 0.380 |
| 50,000 | 1.902 |

These are arithmetic projections from the measured per-operation CPU, not throughput benchmarks at those traffic levels. Other HTTP and Pusher paths in the table add approximately 39–110 µs per outgoing request. Budgeting 40–110 µs corresponds to approximately 0.4–1.1 additional cores at 10,000 outgoing requests per second. Credential-provider contention is listed separately because it deliberately serializes shared provider calls.

Retained integration code and method metadata are bounded per worker; the 124–188 KiB increase below is not added for every request. Temporary ownership tracking follows outstanding promises and native transfers and releases them as work completes or is canceled. Applications should bound outgoing concurrency and queues: the integration does not cap the amount of unfinished work an application can create.

## Investigating the remaining costs

One cached-startup profile and one profile each of async and cancellation used XHProf 2.3.10, loaded only into those diagnostic processes. Instrumented timings are not mixed into the benchmark tables. Profiles and a separate uninstrumented pass-through comparison explain the remaining work:

- Cached bootstrap performs queue installation and aspect registration, then loads and validates the proxy generator and its inputs. In the profile, provider registration added about 2.17 ms and proxy bootstrap about 7.17 ms. The latter includes roughly 4.19 ms for content fingerprints and their dependencies, including visitor metadata and the installed PHP parser revision. This is startup work, not a per-request scan. The full Composer-map copy, repeated class-rule scan, unused parser construction and Finder loading have been removed.
- The async harness completes batches through `Utils::unwrap()`. At concurrency one, that creates nine mutable promises and makes **27 intercepted calls per operation**: nine constructors, nine resolves, six `then` calls, two waits and one transfer admission. It does more promise work than a single `getAsync()->then()->wait()` chain. Cancellation makes **12 intercepted calls**.
- A separate uninstrumented diagnostic, with 1,000 operations and three warm samples at concurrency one, measured async CPU at **283.28 → 347.88 → 381.40 µs/op** for baseline, pass-through AOP and production ownership. Cancellation measured **106.35 → 142.14 → 159.90 µs/op**. Dispatch therefore accounts for about 64.60 µs and 35.79 µs respectively; ownership and coroutine-local queue handling add about 33.52 µs and 17.76 µs. These are diagnostic process medians, not additional samples in the main paired matrix.

The warm profiles show argument reconstruction, join-point/closure calls, current-container resolution, weak ownership lookups and coroutine-local queue delegation. There were no GC runs inside the timed operation loops. A single per-call estimate from the shorter promise probe does not account for the different intercepted arguments and lifecycle work. Further caching of aspect instances or coroutine state would change the binding and ownership guarantees; these measurements do not justify that change.

## Guzzle 7 compatibility comparison

Both independent checkouts use Guzzle 7.15.5, promises 2.5.3, PSR-7 2.13.1 and AWS SDK 3.399.1. The focused workloads use the same counts and three-pair protocol, except async concurrency 8 uses 32 operations per sample. That smaller batch still exceeds timer granularity and reproduces the native select delay. Raw reports are `complete-g7-*`.

| Workload | Concurrency | CPU baseline → candidate, µs/op | Added CPU | CPU sample ranges, µs/op | Wall baseline → candidate, µs/op |
| --- | ---: | ---: | ---: | --- | ---: |
| Idle | 1 | 0.34 → 0.34 | Below displayed precision | 0.33–0.43 / 0.32–0.39 | 0.33 → 0.34 |
| Promise chain | 1 | 3.16 → 20.53 | 17.36 (548.7%) | 3.09–3.61 / 20.14–21.80 | 3.16 → 20.53 |
| Synchronous HTTP | 1 | 377.37 → 420.63 | 43.26 (11.5%) | 367.89–382.38 / 409.46–424.58 | 1,544.25 → 1,586.15 |
| Async HTTP | 1 | 249.80 → 339.58 | 89.77 (35.9%) | 238.15–276.98 / 331.91–404.75 | 284.95 → 390.40 |
| Async HTTP | 8 | 180.75 → 234.09 | 53.34 (29.5%) | 166.13–193.22 / 229.53–250.53 | 62,740.06 → 62,802.61 |
| Cancel pending transfer | 1 | 85.15 → 139.39 | 54.24 (63.7%) | 83.67–91.84 / 135.15–148.98 | 85.14 → 139.39 |

Absolute added CPU closely matches Guzzle 8 on these paths. These runtime samples precede the final bootstrap-only correction. Three refreshed cached-idle process pairs measured startup at **70.59 → 77.85 ms** (70.27–71.63 / 76.86–79.86), retained heap at **3,021.52 → 3,162.02 KiB**, and RSS at **68.94 → 69.62 MiB**. All 36 original runtime processes and six refreshed cached-idle processes returned to six descriptors; warm-sample heap growth was the same 11,312 bytes of report bookkeeping in both versions. No version-specific implementation branch was needed for the AOP optimizations.

## Resources and cleanup

Values below are process medians after the coroutine exits and GC runs. RSS includes shared code pages and must not be read as private memory added to every server worker. PHP heap and RSS are different measurements.

| Guzzle 8 workload | PHP heap baseline → candidate, KiB | RSS baseline → candidate, MiB |
| --- | ---: | ---: |
| Idle | 3,021.33 → 3,164.52 | 68.88 → 69.96 |
| Promise chain | 3,023.77 → 3,175.22 | 68.97 → 70.15 |
| Synchronous HTTP | 4,135.29 → 4,286.07 | 73.18 → 74.27 |
| Async HTTP, concurrency 32 | 3,555.43 → 3,678.95 | 71.77 → 72.80 |
| Coroutine-parallel HTTP, concurrency 32 | 4,155.11 → 4,305.95 | 75.89 → 77.08 |
| Pusher, concurrency 32 | 3,621.02 → 3,809.09 | 72.29 → 73.38 |
| Cancel pending transfer | 3,478.91 → 3,642.44 | 71.07 → 72.01 |

All 42 refreshed Guzzle 8 processes returned to six open descriptors after exit, as did all 96 in the earlier complete matrix. Across their three warm samples, retained PHP heap increased by exactly 11,312 bytes in both baseline and candidate, matching accumulated result records. These measurements show no additional batch-to-batch retained growth; regression tests separately cover ownership release, cancellation and weak-reference cleanup. The final retained increase is about **124–188 KiB of PHP heap**, including loaded integration code and bounded metadata. The separate loader removes Composer's approximately 1.25 MiB full-map copy; dropping Finder removes additional loaded code.

For these rows, allocated PHP memory after exit was **16 → 16 MiB**. Median warm-sample allocated peaks were **16 → 16 MiB** for HTTP, Pusher and cancellation, **18 → 18 MiB** for promise chains, and **20 → 20 MiB** for the 50,000-operation idle loop.

The harness records snapshots before each sample, after its operations, after releasing result/latency arrays and GC, and after coroutine exit. Peak measurements include those arrays and depend on the sample's operation count; they are not application memory forecasts. The measured closure is also released before the GC snapshot so its captured latency array cannot inflate retained-memory results.
