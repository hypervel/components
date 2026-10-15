# AI performance measurements

Measured on October 15, 2026, from the AI branch at `b15a346d2` with the streaming candidates described below. PHP 8.4.25 and Swoole 6.3.0RC1 used CLI OPcache with JIT disabled. The [README](README.md) describes the fixtures and reproduction commands. These are local measurements, not real model latency or a Laravel comparison. Experimental results do not imply production support.

The host was Linux x86-64 on an Intel Core i7-7700 at 3.60 GHz, with six logical CPUs exposed. Installed libraries included Guzzle 8.2.0, PSR-7 3.1.0 and AWS SDK 3.401.2; native cURL was 8.5.0 with OpenSSL 3.0.13. CPU excludes separate service processes. Repeated streaming and broadcasting comparisons used ten alternating process pairs; the Scout transaction experiment used five pairs with three measured samples per input mode. Tables show medians rather than selected fastest runs.

## Streaming and import findings

- Eliminating response-reference cycles and repeated receive-buffer copying preserves replay and incremental delivery while reducing CPU and retained heap. The combined measurements are below; the framework-only receive-buffer comparison is recorded separately in the HTTP transport benchmark results.
- The default concurrency 50/pool 10 precomputed-vector import failed after 4,500 of 5,000 updates. The proposed database-vector admission change must use the actual imported models' pool and execute inline when only the parent's slot is available. Lower simultaneous embedding-request overlap is a tradeoff to retain when evaluating the fix. Parent/child idle-session release is handled separately by the framework changes; the AI admission change was not measured here.
- HTTP retention is measured before batching: immediate-broadcast CPU at 5 ms receiver delay falls 379.19 → 311.82 ms per 576 events; new warm connections fall 44 → 0. At 50 ms delay CPU falls 350.42 → 325.26 ms, but wall-time ranges overlap. Retaining idle handles keeps resources available for reuse; the handle bound is not a strict socket cap. Framework release and retention changes are documented in [PR #669](https://github.com/hypervel/components/pull/669).
- A 50 ms batching window cuts CPU 52–58% and sends/jobs 58–61% after fixing retention, but adds 36 ms to fast immediate-event p95 latency. A 0 ms window captures most of the slow-immediate benefit without deliberate waiting, but adds about 2.6% CPU in the fast case and does not help queued throughput. This supports an opt-in recommendation, not a default change. The measured helper is benchmark-only; production ownership, cancellation, reasoning-event identities and byte bounds remain separate implementation requirements.

Zero-window coalescing gives essentially the same slow-immediate latency benefit as 50 ms, but starts sender work for every enabled stream, merges wire event IDs/counts under backpressure and costs about 14 µs per original event in the fast case. All ten paired fast-case CPU differences are positive. Preserving text alone does not make wire-event changes invisible.

## Existing mechanisms that earn their place

- Skill caching removes repeated directory reads/parsing without a new per-request watcher. Cached discovery takes 24.8 µs versus 4,150 µs for 20 skills, and 103 µs versus 20,348 µs for 100 skills.
- Existing opt-in concurrent tool execution/title generation shortens independent waits. Four controlled 20 ms tools take 81.7 → 21.2 ms; a main response and title take 44.6 → 23.3 ms including SQLite persistence. These are controlled local waits, not promises about provider speed.
- Atomic conversation turns with one parent update reduce serial PostgreSQL wall time from 12.60 → 5.89 ms and CPU from 1.88 → 1.45 ms. MySQL wall time falls from 24.30 → 14.13 ms and CPU from 1.96 → 1.55 ms, compared with separate public message writes. This is a comparison within Hypervel, not a direct Laravel benchmark. Per-tool approval-result commits retain their durability tradeoff; five results take 28.4 ms on PostgreSQL and 63.8 ms on MySQL. This does not guarantee exactly-once external tool effects.

## Experiments to reject

- Automatic 100-row Scout write transactions: generated import time falls from 22.65 → 18.65 s, but overlapping writer p99 rises from 170 → 1,455 ms. Precomputed import time falls from 19.48 → 15.76 s, while writer p99 rises from 33 → 1,217 ms. Keep per-row writes. The test deliberately targets imported rows; storage is nearly full and slow, and group-commit/durability behavior affects gains. Smaller arbitrary/configurable groups would add tuning and maintenance; these results do not establish that batching regresses every remote database.
- Shared Bedrock SDK clients: fresh → reused CPU is 3.98 → 0.60 ms for buffered steps and 4.99 → 1.47 ms for streaming with inherited AWS configuration. Without that configuration the saving is 0.8–0.9 ms. The saving is real, but request/credential ownership, concurrent promises and extension compatibility do not justify shared-client machinery. Keep fresh clients. Repeated shared-config parsing contributes to the cost; whether that freshness is intentional and how safe caching would work were not established, so this is not a confirmed dependency defect.
- AI-specific embedding compression: keep JSON and the application's Redis compression. With 100 independent 1,536-dimensional vectors, LZ4 reduces stored values from 1.75 → 1.17 MB, while cache-hit client CPU rises from 12.21 → 12.86 ms. Compression saves space, not loopback CPU; no provider-specific binary codec or maintenance matrix is justified.
- Larger idle-connection bounds for AI streams: resource counts stabilized during account churn, so keep the existing bound. Native bulk reads show potential, but the supported fallback also needs incremental delivery. [Guzzle #3936](https://github.com/guzzle/guzzle/pull/3936) tracks that change; these measurements do not establish its current release status or justify changing parser behavior before dependency support is available.

## Measured streaming changes

| Workload / metric | Before | Candidate |
| --- | ---: | ---: |
| Small paced agent, CPU/request | 6.88 ms | 5.66 ms |
| 1 MiB generated text through agent, CPU/request | 1,260 ms | 687 ms |
| Same large case, end-of-run PHP heap | 49.82 MiB | 9.17 MiB |
| Sustained concurrent account churn, client CPU/request | 9.63–9.66 ms | 5.56–5.60 ms |
| Same churn, completed requests/second | 103.5–103.8 | 178.4–179.8 |
| Same churn, cyclic GC runs per sample | 149–154 | 0 |

These are local synthetic-provider workloads, not real model latency or a Laravel comparison. Large generated text includes the protocol's terminal complete-response payload. End heap is an end-of-run measurement, not per-request memory.

In the separate large direct-HTTP diagnostic, candidate bytewise reading used 662.48 ms CPU/request versus 14.55 ms with native bulk reading. This is evidence of parser overhead, not a deployable cross-transport optimization: incremental delivery on the PHP-stream fallback must also be preserved before changing the AI reader.

## Broadcasting comparisons after connection retention

Ten alternating fresh-process pairs, with five warm samples per case, completed successfully. Values below are medians of process medians; p95 is each sample's original-event acceptance percentile, not an aggregate across every event. Local receiver delay is 5 or 50 ms; sixteen streams emit 576 original events over about 640 ms. Client CPU excludes Redis and the receiver. Queue producers/workers share one process, so queue latency is not a forecast for separately deployed workers. Constant receiver delay produced zero reorderings but proves no general ordering guarantee.

| Delivery / receiver delay | Window | Sends | Client CPU ms | Event p95 ms | Call returned ms | Last accepted ms |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Immediate / 5 ms | off | 576 | 311.82 | 22.51 | 663.98 | 663.44 |
| Immediate / 5 ms | 0 ms | 574 | 319.94 | 23.38 | 663.49 | 663.04 |
| Immediate / 5 ms | 50 ms | 240 | 149.27 | 58.41 | 663.31 | 662.93 |
| Immediate / 50 ms | off | 576 | 325.26 | 1,263.91 | 1,990.76 | 1,989.36 |
| Immediate / 50 ms | 0 ms | 240 | 150.29 | 156.77 | 807.83 | 807.00 |
| Immediate / 50 ms | 50 ms | 224 | 145.16 | 156.63 | 818.14 | 817.64 |
| Queued / 5 ms | off | 576 | 945.65 | 495.22 | 640.66 | 1,174.05 |
| Queued / 5 ms | 50 ms | 240 | 411.44 | 76.11 | 640.65 | 725.68 |
| Queued / 50 ms | off | 576 | 1,028.39 | 1,264.32 | 640.37 | 1,986.13 |
| Queued / 50 ms | 50 ms | 240 | 428.73 | 171.97 | 640.45 | 850.12 |

The 0 ms queued cases leave 576 jobs and CPU effectively unchanged (949.40/1,035.41 ms). Timed-batch peak PHP heap is similar or lower than the matched controls; these numbers exclude native cURL allocations and are not a complete retained-socket memory measurement. A batching window limits intentional waiting, not end-to-end delivery time when the sender is busy.

The separate backlog case drains 539 jobs/s with the real worker configured for sixteen concurrent jobs, using 1.41 ms worker CPU/job and at most four sends in flight. That is below this workload's approximately 902 events/s, explaining the queued backlog and why reducing jobs helps. It is a local workload result, not a universal worker capacity limit.

## Limits

- One MySQL conversation process hit the normal three-second pool checkout deadline during a storage/lock stall. Its entire process is excluded from medians. No timeout was increased to make it pass.
- The Docker volume was 99% full during the database measurements. Those timings describe that storage environment.
- No paid providers were used. These measurements do not verify live-provider behavior, Sentry AI integration or optional MCP integration.
