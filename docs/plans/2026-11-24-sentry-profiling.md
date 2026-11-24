# Sentry profiling in Hypervel workers

## Outcome

Applications can enable transaction profiling to see where a particular request or job ran, or continuous profiling to inspect a worker over time. Preserve Sentry's configuration, sampling, payload format, and application-supplied collectors. Profiling stays opt-in. The Hypervel Hub owns coroutine-local scopes and sampling decisions. The PHP SDK owns continuous recording, transactions, serialization, transport and client reports.

## Transaction profiles

Require sentry/sentry ^4.38.0 in both Composer manifests, matching the current Laravel integration and including SDK reporting for rejected transaction profiles and continuous chunks. Version 4.35 added the per-transaction factory in [SDK PR 2253](https://github.com/getsentry/sentry-php/pull/2253). Version 4.36 handles coalesced event counts and empty-stack idle markers. Set a default factory on the builder only when Excimer is loaded and the user has supplied none. Create a fresh collector when the SDK selects a transaction. Let the stock SDK handle a missing extension.

Capture the runtime context from CoroutineRuntimeContextStorage when recording starts. Match it by object identity so attached child coroutines count as request work while detached work runs independently. Without an active context, match the exact starting coroutine ID, including the main execution outside Swoole. No worker-wide table or per-request registration is needed. Direct SDK clients can supply their own factory.

Use released Excimer's real-time clock, a 0.0101-second period and a stack depth of 128, matching the SDK. Record the wall-clock start time when creating the native profiler, whose timestamps are offsets from construction. Excimer synchronously invokes its flush callback after capturing a sample. Flushing one sample at a time lets the callback decide ownership before extracting the trace. Excimer preserves the original epoch across rotations.

```php
$belongsToTransaction = $context === null
    ? Coroutine::id() === $coroutineId
    : $storage->get() === $context;
```

Keep native timestamp, event count and trace fields on owned samples. After an owned sample, remember the timestamp of the first foreign sample. Emit one empty stack at that timestamp only when another owned sample arrives. Sentry's renderer weights a sample up to its successor and recognizes empty stacks as idle. This preserves gaps and the minimum of two real samples. Keep frame arrays once per profile, keyed by their serialized fields. Release the frame dictionary on stop.

Bound storage for long jobs. The SDK discards profiles when their last formatted sample is over 30 seconds. Its converter may backdate a coalesced sample, but never earlier than its predecessor's raw timestamp. Once the preceding raw entry is itself over 30 seconds, preserve only the two adjacent entries and discard subsequent samples. Either predecessor, including an idle marker, preserves the SDK's own rejection and client report. Free the frame dictionary at that point.

The callback must have no reference back to its native profiler. Excimer 1.2.7 retains callbacks outside PHP's cycle traversal. A bound callback referencing the collector can keep an abandoned timer alive even after cycle collection. Release the timer on stop, pass the samples to the SDK Profile and leave formatting to Sentry. Stop and detach discarded HTTP profilers for both normal termination and cancellation. Clear request state before invoking application code. Follow SDK logging for ordinary failures and propagate coroutine cancellation.

### Measured cost

On PHP 8.4.27, Swoole 6.3.0 RC2, Excimer 1.2.7 and SDK 4.37.1, paired synthetic runs used one pinned CPU, identical work and real SDK payload conversion. With eight overlapping CPU/I/O requests all profiled, the median was 0.834 CPU seconds and 23.70 requests/second for stock Sentry, and 0.833 seconds and 23.59 requests/second for the collector sharing repeated frames. A recognizable foreign stack occurred in 96 of 144 stock profiles and zero of 144 filtered profiles. In a 72-frame CPU workload with two alternating call paths lasting about 3.8 seconds, incremental peak PHP memory was 0.42 MiB for stock Sentry, 11.25 MiB with raw traces, 6.01 MiB reusing the immediately previous trace, and 1.36 MiB sharing frames. These are local transaction-profiler measurements. Production overhead depends on concurrency, depth and sampling settings.

## Continuous profiling

The [Laravel 4.31.0 integration](https://github.com/getsentry/sentry-laravel/blob/4.31.0/src/Sentry/Laravel/ServiceProvider.php#L439) forwards user options and uses the SDK Hub directly. Hypervel's coroutine-aware Hub must perform the corresponding SDK calls. Notify the process profiler when a client is first bound and when rebound. For a sampled root with trace lifecycle, register its span ID, then put the active profiler ID and SDK thread metadata on the transaction. Preserve the upstream warning for trace lifecycle with tracing disabled. The SDK owns stop-on-finish, final runtime-context cleanup, overlapping-root tracking and fork detection. Explicit middleware discard must release the root as well. Expose the SDK lifecycle and session rate in package configuration and explain manual worker profiling, automatic trace lifecycle and the precedence of transaction profiling. The continuous recording contains all work in the worker, including overlapping requests.

```php
$continuousProfiler->startForRootSpan((string) $transaction->getSpanId());
$profilerId = $continuousProfiler->getProfilerId();
```

### SDK delivery ordering

A separate upstream change owns a verified SDK defect. When a stop yields during delivery, a concurrent root can be attached to the stopping session and then cleared. With stock SDK 4.37.1 and 4.38.0, two roots produced one chunk during this interleaving and two chunks with sequential delivery. Complete the outgoing batch and retire its recording state before calling transport code and preserve a newly started session and queued chunks. Keep the usual no-client, manual, fork and failure behavior. Cover stop during a yielding or reentrant transport with an upstream regression. Hypervel uses released APIs throughout. Track the first fixed SDK release for the dependency minimum.

## Verification and delivery

- Reproduce request contamination using the existing native Excimer test with its skip removed. Assert the owning request's stacks and an idle gap across unrelated work. Exercise attached and detached children, missing runtime context, collector cleanup and the duration boundary.
- Verify application factory precedence, failed start and factory cancellation through the Hub, ignored-route cleanup, continuous profile IDs on overlapping roots, final-owner cleanup, and client binding before or after manual profiling starts. Isolate process-wide SDK session sampling between test cases.
- Install Excimer in the existing CI image using its PIE package. Exercise the relevant package with the extension both enabled and absent. Keep any remaining native pre-collection optimization in the todo entry, alongside the upstream release follow-up.
- Run each edited test file immediately. At the review boundary run formatting, full static analysis and targeted suites sequentially. Trace the final diff through provider registration, scope inheritance, middleware, queue and scheduler call sites, cancellation, SDK lifecycle and package installation. Obtain independent code review and commit whole files by concern. Keep both branches local until the owner authorizes publication.
