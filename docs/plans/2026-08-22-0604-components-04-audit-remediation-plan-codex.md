# Hypervel Components 0.4 Audit Remediation Plan

Status: Findings #21, #23, #26, #30–32, #34–35, and #82 are implemented in a separate slice; #160 (Vonage channel port plus Horizon wiring) is the only remaining work

## Objective

Resolve every genuine issue retained in this master plan against the current 0.4 branch. Hypervel 0.4 is greenfield: backward compatibility with earlier Hypervel releases, churn, and patch size are not constraints. Preserve Laravel's current canonical APIs unless Hypervel's coroutine, pooled-resource, or long-lived-worker architecture requires a better contract, or preserving the surface would impose disproportionate machinery or materially worse code. Do not import names, aliases, shims, or duplicate paths retained solely for Laravel's historical backward compatibility. Prefer fail-fast behavior, explicit lifecycle ownership, and source-level fixes over compatibility shims.

## Cross-cutting design decisions

1. Worker-lifetime caches require a natural finite keyspace or deterministic invalidation. Cheap input-derived values should not be retained worker-wide merely to avoid parsing or hashing them, and arbitrary caps are not a substitute for correct ownership.
2. Fail loudly for unsupported or ambiguous configuration. Do not silently clamp, coerce, fall back to inaccurate readers, or accept an API surface that cannot work.
3. Every concurrency fix needs a deterministic interleaving test, not only a sequential unit test.

## Architecture, compatibility, and cost guardrails

- Preserve Laravel's current canonical method names, valid-input semantics, contracts, facades, constructor call forms, container aliases, and extension points. Diverge when Hypervel's architecture requires it or when parity would demand disproportionate machinery/workarounds and produce materially worse code; choose the simplest well-adapted contract in that case and document the divergence. Do not inherit aliases, deprecated names, compatibility shims, or implementation residue that current Laravel retains solely for historical backward compatibility; Hypervel 0.4 should expose the modern canonical surface directly. Establish that something is genuinely legacy before removing it—an apparently unused current extension point is not enough. Reject invalid or misleading states with a descriptive exception.
- Apply that rule consistently: Horizon uses `vonage`, never upstream Horizon's stale `nexmo` name.
- Hypervel worker singletons retain only boot-time immutable/baseline state. Request, job, command, and test overrides belong in CoroutineContext and are cleaned at execution boundaries. This is an architectural adaptation, not a port of Laravel's process-per-request mutable-static assumptions.
- Most fixes remove work, bound memory by correct lifetime, preserve a fast path, or add only constant-time state checks.
- No fix may add periodic polling, arbitrary eviction thresholds, a distributed lock on every request/cache hit, metadata shortcuts with delayed correctness, or a new abstraction whose only purpose is an unsupported failure mode.
- Performance and scalability claims must be measured during implementation for the affected hot paths. A fix does not land if its package benchmark/load test shows a material regression that is not inherent to the required correctness guarantee; revise the design instead.
- Load tests must cover high-cardinality input and long worker lifetimes, not only request latency: retained memory must converge to the natural live/configured keyspace, and temporary request/job state must disappear at execution teardown.
- Compare database queries, remote calls, lock acquisition, bytes copied over IPC, allocations, and coroutine scheduling before and after each affected hot-path fix. Cache hits and ordinary non-strict/read paths must remain lock-free and free of new I/O.
- No synchronous CPU or blocking-I/O work may be moved onto the event loop. Where an existing public API is inherently synchronous, keep its implementation lean and document task-worker/queue offload for heavy workloads rather than hiding an unbounded background mechanism inside the framework.

## Complete remediation ledger

Each row is an implementation requirement. Test names are descriptive; use the repository's established test file for that component or create the narrowly corresponding file.

### Vonage notifications and Horizon

| ID | Proposed implementation | Required tests |
|---:|---|---|
| 160 | Port the current laravel/vonage-notification-channel package into a Hypervel split package, then make Horizon's existing routeSmsNotificationsTo API effective by adding the missing route in SendNotification and channel selection in LongWaitDetected::via, adapted to the current `vonage` channel, `toVonage`, and VonageMessage. Do not copy Horizon upstream's obsolete `nexmo` name or introduce a second/deprecated driver. `Vonage\Client` caches service objects whose `APIResource` mutates `lastRequest`/`lastResponse` around yielding HTTP calls, so framework-created channels build a fresh SDK Client per send while sharing only normalized immutable configuration and Hypervel's coroutine-safe PSR-18 transport. Add per-execution memoization only later if measurement proves construction material. Keep the Vonage facade non-caching and preserve direct construction with a supplied Client and per-message `usingClient` overrides. Add the package README, canonical notification docs, Horizon docs, `HorizonServiceProvider.stub`, and Horizon Boost notification reference using `vonage` only. | Ported package provider/config, route resolution, message construction, channel send/failure, facade, direct constructor, and per-message override; deterministic concurrent sends cannot exchange SDK request/response state; repeated sends get distinct SDK clients but reuse the safe HTTP transport; Horizon's two consumers use `vonage`; absent number adds no channel; mail/Slack composition; public routeSmsNotificationsTo remains unchanged; all docs/stubs/Boost references contain no `nexmo` surface. |

## Commit and dependency structure

Use package-sized commits that remain reviewable and bisectable:

1. Vonage notification channel port followed by Horizon wiring: 160.

Do not combine unrelated packages merely because their findings have the same severity.

## Verification protocol

For every changed test file:

1. From the components repository root, run that exact test file immediately with `./vendor/bin/phpunit --no-progress path/to/Test.php`.
2. For deterministic concurrency tests, run the exact file repeatedly and under the parallel runner where supported.
3. Run the complete affected package test directory after its individual files pass.
4. Run integration suites for every affected external system, including MySQL/MariaDB and PostgreSQL for database semantics.

Additional required checks:

- Split-package metadata changes: run the package metadata tests and a clean standalone Composer install/autoload smoke test.
- Documentation changes: verify every claimed API and difference against the final source; update package README and src/docs together where both describe it.
- Runtime races: use barriers/channels to force the bad ordering. A test that merely starts two coroutines without controlling their interleaving is insufficient.

After all package work is complete:

1. Run `composer fix` once as the repository checkpoint. It owns formatting, static analysis, the parallel suite, Testbench package tests, and dogfood tests; do not duplicate those full checks immediately beforehand.
2. Inspect git diff and git status; ensure generated fixtures, temporary files, environment changes, node output, and unrelated user changes are absent.

## Completion criteria

- Every finding retained in this plan is implemented and verified.
- No worker-global mutable state is introduced without an explicit boot-only contract and reset path.
- All exact-file, package, integration, split-package, static-analysis, lint, dogfood, and final composer fix checks pass.
