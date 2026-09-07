# Hypervel OpenTelemetry Benchmark Report

## Purpose

This report records the performance evidence gathered for the first-party OpenTelemetry package. It is a development artifact for review and the pull-request summary, not a user-facing performance guarantee or a timing-sensitive CI gate.

The one-off harness remained outside the repository, as required by the implementation plan. The method and final measurements are retained here so the conclusions do not depend on temporary files.

## Environment

- Date: 2026-09-01 UTC.
- Host: x86-64 QEMU virtual machine, 6 single-threaded virtual CPU cores, 13 GiB memory.
- Runtime: PHP 8.4.23 and Swoole 6.2.2.
- Protobuf comparison: portable `google/protobuf` PHP implementation versus the official `protobuf` 5.36.0 extension.
- Server: one Hypervel event worker. This isolates per-worker cost and avoids hiding it behind multi-worker throughput.
- Sink: local OTLP/HTTP protobuf receiver on loopback. Separate runs delayed the receiver by 500 ms or made it unavailable.

The host can fluctuate under external load. Short modes therefore used warmed repeated samples and median results. A/B tests were also run in both orders where order could bias the result.

## Representative workload

Each request performs three deliberately small operations:

1. Hypervel HTTP request and response handling.
2. One SQLite `SELECT 1` query.
3. One synchronous queue job.

The application does almost no other work. This makes telemetry a large share of total request time and is intentionally harsher than a normal application route.

Short-run modes used an eight-second warm-up followed by five four-second samples at concurrency four. Comparisons used median throughput, p50/p99 latency, worker CPU, RSS, PHP allocated memory, live coroutine count, and export request/byte counts.

Long-run modes used concurrency 32 across many export intervals. Additional deterministic package tests exercised nested and concurrent HTTP, queue, scheduler, View, Scout, gRPC, WebSocket, context, deferred-handle, metric-view, and flush-ownership behavior.

## Baseline and enabled modes

All throughput values are requests per second.

| Mode | Throughput | p50 | p99 | Relative throughput |
| --- | ---: | ---: | ---: | ---: |
| Package absent | 1,335 | 2.95 ms | 3.99 ms | baseline |
| `OTEL_SDK_DISABLED=true` | 1,349 | 2.93 ms | 4.04 ms | +1.0% (noise) |
| All three signal exporters `none` | 1,333 | 2.96 ms | 4.13 ms | -0.2% (noise) |
| Full graph, always-off sampler | 1,092 | 3.61 ms | 4.93 ms | -18.3% |
| Full graph, always-on sampler, discard exporter | 1,009 | 3.90 ms | 5.48 ms | -24.5% |
| 10% sampling, OTLP with protobuf extension | 1,050 | 3.73 ms | 5.21 ms | -21.4% |
| Always-on sampling, OTLP with protobuf extension | 928 | 4.06 ms | 15.39 ms | -30.5% |
| Metrics only, OTLP with protobuf extension | 1,175 | 3.35 ms | 4.92 ms | -12.0% |

The inactive modes had identical PHP allocated memory, the same five live coroutines as the package-absent baseline, no exports, and no request failures. Their throughput and latency differences changed direction and remained within host noise.

An enabled provider adds one scheduler coroutine. Every enabled run returned to six live coroutines after load and kept bounded worker memory.

The active percentage differences are real for this fixture, but the absolute p50 increase for all three instrumented operations is about 0.7-1.1 ms. A normal route with meaningful application work will not have the same relative percentage.

## Per-domain recording cost

These runs used an always-on sampler and a discard exporter to isolate span creation and recording from serialization and network export.

| Enabled domain | Throughput | p50 | p99 | Relative throughput |
| --- | ---: | ---: | ---: | ---: |
| HTTP | 1,146 | 3.41 ms | 5.05 ms | -14.2% |
| Database | 1,218 | 3.21 ms | 4.50 ms | -8.8% |
| Queue | 1,201 | 3.29 ms | 4.68 ms | -10.1% |
| HTTP, database, and queue | 994 | 3.94 ms | 5.63 ms | -25.5% |

All samples completed without request failures, retained six live coroutines, performed no export, and kept bounded memory.

## OTLP encoding comparison

### Protobuf extension versus PHP encoder

| Mode | PHP encoder | Extension | Change |
| --- | ---: | ---: | ---: |
| Always-on throughput | 510 req/s | 928 req/s | +81.9% |
| Always-on p99 | 240.28 ms | 15.39 ms | -93.6% |
| 10% sampling throughput | 922 req/s | 1,050 req/s | +13.9% |
| Metrics-only throughput | 1,163 req/s | 1,175 req/s | +1.0% (noise) |

A second always-on comparison used enlarged bounded queues so ordinary queue pressure did not explain the result. It measured 418 req/s with the PHP encoder and 919 req/s with the extension, with the same roughly 240 ms versus 16 ms p99 split. The extension also used about 4 MiB less PHP allocated memory in that run.

The pure-PHP encoder's large p99 spikes come from protobuf serialization consuming worker CPU in the scheduler coroutine. Export I/O still remains off the application coroutine, but CPU-heavy encoding competes with request work on the same worker.

### Optimized protobuf versus JSON

The JSON and optimized-protobuf matrix was run in both protocol orders. Each protocol therefore contributed ten warmed samples per signal mode.

| Mode | JSON | Protobuf extension | Protobuf throughput change |
| --- | ---: | ---: | ---: |
| Always-on throughput | 542 req/s | 936 req/s | +72.6% |
| Always-on p99 | 193.00 ms | 15.32 ms | -92.1% latency |
| 10% sampling throughput | 986 req/s | 1,039 req/s | +5.4% |
| 10% sampling p99 | 5.07 ms | 5.37 ms | +6.0% latency |
| Metrics-only throughput | 1,164 req/s | 1,167 req/s | +0.3% (noise) |
| Metrics-only p99 | 4.76 ms | 4.71 ms | -1.0% (noise) |

JSON did not provide a high-volume encoding advantage. Under always-on tracing it behaved much more like the pure-PHP protobuf encoder than the protobuf extension and produced the same kind of scheduler-CPU latency spikes. At 10% sampling, JSON had slightly lower median/tail latency in these short samples, while protobuf completed about 5% more requests. Metrics-only application performance was indistinguishable.

Protobuf also used substantially less network bandwidth. Median exported bytes per four-second sample were about 2.75 MB versus 4.26 MB under always-on tracing, 0.41 MB versus 0.84 MB at 10% sampling, and 0.033 MB versus 0.065 MB for metrics only. The protobuf always-on run completed about 71% more application requests while still sending fewer bytes, so the direction is not explained by lower telemetry volume.

The enlarged-queue always-on comparison confirmed the result with ordinary queue pressure removed:

| Protocol | Throughput | p50 | p99 | PHP allocated memory |
| --- | ---: | ---: | ---: | ---: |
| JSON | 503 req/s | 3.94 ms | 193.85 ms | 50.5 MiB |
| Protobuf extension | 923 req/s | 3.99 ms | 16.14 ms | 46.5 MiB |

Optimized protobuf was 83.7% faster in that run, eliminated most of the export-cycle tail spike, used about 4 MiB less PHP allocated memory, and exported fewer total bytes despite completing about 81% more requests.

## SDK internal metrics

The same OTLP workload was run with internal metrics off and on in both orders:

| Mode | Throughput | p50 | p99 | PHP allocated memory |
| --- | ---: | ---: | ---: | ---: |
| Internal metrics off | 952 req/s | 4.13 ms | 5.93 ms | 42.5 MiB |
| Internal metrics on | 874 req/s | 4.50 ms | 6.27 ms | 42.5 MiB |

Enabling the SDK's self-observability reduced throughput by about 8.2% in this span-heavy fixture. Keeping it disabled by default is correct. It remains useful when its processor queue and loss counters justify the measured cost.

## Comparison with Hypervel Sentry

The same one-worker fixture was compared with `hypervel/sentry`. OpenTelemetry used OTLP/HTTP protobuf with the official extension. Sentry used its normal compressed envelope transport to the same loopback receiver. Each mode ran after a five-second warm-up, then contributed five three-second samples in each A/B order at concurrency four.

The matched Sentry modes disabled breadcrumbs, unrelated integrations, and `continue_after_response`. Separate runs retained Sentry's normal defaults. Receiver totals were collected only after graceful worker shutdown so late batches were included. The OTLP receiver accepted bodies up to 64 MiB; this matters for a full batch of large exception stack records. No application request or receiver parse failed.

### Traces

| Mode | Throughput | Change | p50 | p99 | CPU / 1,000 requests | RSS | Export requests / 1,000 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| No telemetry | 1,351 req/s | baseline | 2.91 ms | 4.02 ms | 0.79 s | 63.5 MiB | 0 |
| OpenTelemetry, 10% | 1,046 req/s | -22.6% | 3.75 ms | 5.31 ms | 1.03 s | 74.5 MiB | 1.3 |
| Sentry matched, 10% | 873 req/s | -35.4% | 4.37 ms | 6.87 ms | 1.21 s | 73.3 MiB | 97.5 |
| OpenTelemetry, 100%, default queue | 963 req/s | -28.7% | 3.94 ms | 15.09 ms | 1.10 s | 79.2 MiB | 4.8 |
| OpenTelemetry, 100%, queue 16,384 | 944 req/s | -30.2% | 3.99 ms | 15.45 ms | 1.14 s | 80.2 MiB | 6.6 |
| Sentry matched, 100% | 515 req/s | -61.9% | 7.61 ms | 10.32 ms | 2.02 s | 72.9 MiB | 1,001 |
| Sentry defaults, 100% | 534 req/s | -60.5% | 7.33 ms | 10.34 ms | 1.96 s | 72.7 MiB | 1,001 |

OpenTelemetry's 10% run delivered 6,231 spans for 20,938 workload requests, consistent with three spans on about 10% of requests. The matched Sentry run delivered 1,699 transactions and 8,494 child spans for 17,423 requests, or six trace records per sampled request. Sentry therefore captured a broader trace in this fixture; the throughput comparison does not pretend the record counts are equal.

At 100% sampling, the default OpenTelemetry trace queue delivered 73.1% of expected spans. The fixture produced about 2,850 spans per second, while the default queue holds 2,048 records between the comparison's one-second drains. Raising `OTEL_BSP_MAX_QUEUE_SIZE` to 16,384 delivered every expected span, cost about 2% throughput and 2 MiB of PHP allocated memory relative to the dropping run, and is the fair full-delivery comparison above. Sentry also delivered every trace record.

OpenTelemetry's batched export reduced full-sampling backend request count by about 150 times: 6.6 requests per 1,000 application requests versus about 1,001 for Sentry. Its scheduler-owned batch encoding caused the higher full-sampling p99 spike, but request throughput and p50 remained substantially better.

### Logs

The log baseline performed the same HTTP/database/queue work and sent one structured Monolog record to a `NullHandler`. The combined modes added 10% tracing.

| Mode | Throughput | Change | p50 | p99 | CPU / 1,000 requests | RSS | Export requests / 1,000 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Local log baseline | 1,290 req/s | baseline | 3.05 ms | 4.24 ms | 0.83 s | 63.7 MiB | 0 |
| OpenTelemetry logs | 1,232 req/s | -4.5% | 3.16 ms | 4.87 ms | 0.87 s | 71.6 MiB | 2.5 |
| Sentry logs | 683 req/s | -47.1% | 5.70 ms | 8.39 ms | 1.53 s | 75.8 MiB | 1,000 |
| OpenTelemetry logs + 10% traces | 993 req/s | -23.0% | 3.91 ms | 6.75 ms | 1.07 s | 76.6 MiB | 3.6 |
| Sentry logs + 10% traces | 576 req/s | -55.4% | 6.72 ms | 9.76 ms | 1.82 s | 73.7 MiB | 1,100 |

Every generated log reached the receiver. OpenTelemetry used about 106 uncompressed wire bytes per application request; Sentry used about 502 compressed bytes. This validates Sentry's transport cost and delivery count only. Hypervel currently documents Sentry log capture as unsafe under concurrent requests because the upstream SDK log aggregator is shared across executions; the benchmark does not claim to fix or validate that correctness issue.

### Reported exceptions

The exception endpoint throws and renders one exception on every request. The baseline uses a null application logger, so the table measures direct error-capture overhead rather than ordinary exception rendering alone.

| Mode | Throughput | Change | p50 | p99 | CPU / 1,000 requests | RSS | Export requests / 1,000 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Exception baseline | 667 req/s | baseline | 5.86 ms | 8.40 ms | 1.69 s | 64.7 MiB | 0 |
| OpenTelemetry exception logs | 596 req/s | -10.6% | 6.43 ms | 10.28 ms | 1.88 s | 91.4 MiB | 2.7 |
| Sentry matched | 132 req/s | -80.2% | 30.04 ms | 41.45 ms | 10.60 s | 73.6 MiB | 1,000 |
| Sentry defaults | 131 req/s | -80.4% | 30.34 ms | 41.59 ms | 10.82 s | 73.8 MiB | 1,000 |

Every generated exception reached the receiver. OpenTelemetry averaged about 5.2 KiB of uncompressed protobuf per exception and Sentry about 7.7 KiB of compressed envelope data. OpenTelemetry's higher RSS comes from bounded batches of large stack records; Sentry instead spent much more CPU and latency sending one event per exception. Raising the OpenTelemetry log queue to 16,384 did not improve delivery or throughput and increased RSS, so the default remains preferable for this stress. This all-requests-throw case is deliberately pathological rather than a normal production error rate.

## Sustained load, backpressure, and lifecycle

The normal sustained run used 10% sampling, the protobuf extension, concurrency 32, and a 60-second load:

- 1,063 req/s, 29.50 ms p50, and 42.28 ms p99.
- No request failures.
- Six live coroutines after the run.
- 44 bounded export requests carrying about 5.96 MB.
- PHP allocated memory remained at 42.5 MiB; RSS remained bounded at about 86 MiB after sustained allocation and export activity.

With the OTLP receiver delaying every response by 500 ms, the same run produced 1,064 req/s, 29.51 ms p50, and 41.64 ms p99, with no failures and the same live coroutine count. Slow exporter I/O therefore did not move onto the application path.

During a 30-second complete receiver outage followed by 30 seconds of recovery:

- Outage throughput was 1,063 req/s and recovery throughput was 1,062 req/s.
- There were no request failures in either phase.
- PHP allocated memory and live coroutine count remained fixed.
- Export resumed after recovery.

Across a worker reload under load:

- Throughput was 1,069 req/s before and 1,078 req/s after reload.
- p99 remained about 40 ms with no request failures.
- The replacement worker began with fresh bounded memory and six live coroutines.
- The retiring worker performed its final shutdown export.

## Structural stress checks

The complete OpenTelemetry package test suite was run after the measurements. Its deterministic checks cover the behavior that a single synthetic HTTP fixture cannot measure meaningfully:

- nested and concurrent context/scope isolation;
- queue producer/consumer state ownership and cleanup;
- nested and concurrent scheduler, Console, View, Scout, gRPC, and WebSocket operations;
- bounded weak maps, stacks, deferred callbacks, and observable registrations;
- periodic/manual flush exclusion and shutdown waiting for an in-flight flush;
- independently disabled instruments and inactive hook registration;
- controlled metric attribute sets across dynamic namespace, destination, index, and method values;
- typed metric-view registration for deployment-specific attribute filtering;
- failure isolation without false completion telemetry.

The suite completed successfully. No cross-coroutine leak, unfinished state, overlapping export, or unbounded package-owned collection was found.

## Conclusions and recommendations

1. **Keep the current architecture.** Request paths only build and aggregate telemetry in memory. One worker scheduler owns serialization and export. A separate process would not remove the measured span/instrumentation cost and is not needed to keep slow or failed network I/O off application paths.
2. **Keep OTLP/HTTP protobuf as the default and require `ext-protobuf` whenever an active built-in OTLP signal selects it.** The portable fallback is not suitable for Hypervel's performance target. Because protocol selection is per signal at runtime, enforce this at exporter construction with a clear boot error rather than globally requiring the extension for JSON, console, `none`, custom-exporter, or complete-provider configurations. Keep the Composer suggestion so the conditional requirement is visible before runtime.
3. **Keep SDK internal metrics off by default.** Their operational data is useful, but their hot-path cost is measurable at high span volume.
4. **Document sampling and queue sizing as production tuning.** Sampling materially reduces encoding/export work, while bounded SDK queues prevent memory growth during bursts and outages. At this fixture's extreme 100% trace rate, the default queue dropped records exactly as designed; a larger bounded trace queue restored full delivery with a small throughput and memory cost. The default log queue already delivered the exception storm fully and should not be enlarged blindly.
5. **Do not introduce an in-package relay or source-side cross-worker aggregator for performance.** A Collector or private relay remains useful for backend connection consolidation, durable buffering, authentication, or product-specific behavior, not as a fix for request-path export blocking.
6. **Prefer OpenTelemetry over the current Sentry package when low overhead and backend request count matter.** In this fixture OpenTelemetry was materially faster for traces, logs, and direct exception capture, while batching reduced export request count by two to three orders of magnitude. Retain the trace-breadth and Sentry-log-correctness caveats above when presenting the comparison.
7. **Do not turn these measurements into fixed CI thresholds.** The host fluctuates, and application work changes relative overhead. Keep deterministic ownership and zero-cost-path assertions in CI; use repeatable profiling for performance reviews.
