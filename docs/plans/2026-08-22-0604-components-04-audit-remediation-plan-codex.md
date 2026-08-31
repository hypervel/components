# Hypervel Components 0.4 Audit Remediation Plan

Status: Signed off by `claude-fixes` on 2026-08-22; ready for implementation

## Objective

Resolve every genuine issue retained in this master plan against the current 0.4 branch. Hypervel 0.4 is greenfield: backward compatibility with earlier Hypervel releases, churn, and patch size are not constraints. Preserve Laravel's current canonical APIs unless Hypervel's coroutine, pooled-resource, or long-lived-worker architecture requires a better contract, or preserving the surface would impose disproportionate machinery or materially worse code. Do not import names, aliases, shims, or duplicate paths retained solely for Laravel's historical backward compatibility. Prefer fail-fast behavior, explicit lifecycle ownership, and source-level fixes over compatibility shims.

## Final verdict

- Every genuine finding retained in this master plan remains open unless noted below.
- Finding 123 was valid but is already fixed on the current branch.
- Findings 4, 9, 14, 93, 129, and 153 require no change: some are false positives, while the rest propose churn or machinery for deliberate behavior that is already correct.
- Finding 88 is an exact duplicate of finding 32 and must not become a second patch.
- Findings 6 and 26 are only partially correct as written. Their valid portions remain in this plan; their invalid portions are explicitly rejected below.
- Some audit statements about Laravel were stale or incorrect. A defect shared with current Laravel remains a defect, but the plan does not cite false upstream parity as evidence.

## Rejected, duplicate, resolved, and narrowed claims

| ID | Disposition | Reason |
|---:|---|---|
| 4 | False positive | The audit's claimed Laravel divergence does not exist. Hypervel and current Laravel have the same selector grammar, and runtime checks against both confirmed that raw selectors such as { 1 } and [1, 19] do not match. There is no documented Laravel API requiring the proposed whitespace extension. |
| 6 | Partially confirmed | ViewException::render returning only Response or null is too narrow and must become mixed. ViewException::report returning bool or null is the meaningful Laravel exception contract and must not be widened to arbitrary values. |
| 7 | Confirmed with stale wording | The current cache key is already xxh128, not raw Blade source. The unbounded worker cache and missing source-file recreation after view:clear remain real. |
| 9 | False positive | lastFragment is written but never read. It has no observable behavior and therefore cannot leak a fragment between coroutines. Remove it only if a later compiler cleanup naturally touches the code; do not create a standalone remediation. |
| 14 | False positive | Swoole's response fd is a generated connection SessionId, not an immediately reusable OS socket descriptor. Allocation advances session_round, skips occupied session slots, and verified lookup checks both session and connection identity. The stale-close and delayed-handshake fd-reuse races described by the audit are therefore unreachable. Current Swoole master retains the same invariant, and this investigation exposed no Swoole defect. |
| 32 | Confirmed | This is the canonical keyed-resource-collection issue. |
| 88 | Exact duplicate | Same file, cause, behavior, and fix as 32. Cover it with the 32 tests and close both audit IDs together. |
| 93 | Rejected | Moving methods to a preferred class location is style-only churn. Narrowing the concrete path() return to string would needlessly diverge from Laravel's nullable signature and could make existing subclasses incompatible. |
| 123 | Valid, already resolved | RedisConnection::callGet is mixed on the current branch. Retain or extend the serializer regression test, but do not schedule another production change. |
| 129 | Rejected | Framework reset methods are no-throw lifecycle boundaries by design, and the subscriber already preserves the first error across its explicit outer cleanup stages. Wrapping roughly two hundred static resets in per-call fault-isolation machinery optimizes for unsupported throwing reset implementations and weakens the simple at-most-once cleanup contract. |
| 153 | Rejected | Immediate hard termination is deliberate, documented, and pinned by testKillDoesNotWaitForUnrelatedActiveJobs. The timed-out coroutine is not cancellable, so the worker is poisoned; draining siblings delays the hard timeout while the stuck job can continue side effects. |

## Cross-cutting design decisions

1. Shared cache publication follows database transaction visibility. Committed mutations invalidate affected shared entries after commit, and rollback never invalidates them. Where a package must read its own uncommitted cache-affecting writes, that execution bypasses the affected shared entries while its transaction is dirty. Where fills can race with committed mutations, coordinate cache misses and exact invalidations with per-identity locks unless an existing atomic primitive provably orders every competing writer. Cache hits remain lock-free.
2. Worker-lifetime caches require a natural finite keyspace or deterministic invalidation. Cheap input-derived values should not be retained worker-wide merely to avoid parsing or hashing them, and arbitrary caps are not a substitute for correct ownership.
3. Fail loudly for unsupported or ambiguous configuration. Do not silently clamp, coerce, fall back to inaccurate readers, or accept an API surface that cannot work.
4. Every concurrency fix needs a deterministic interleaving test, not only a sequential unit test.

## Architecture, compatibility, and cost guardrails

- Preserve Laravel's current canonical method names, valid-input semantics, contracts, facades, constructor call forms, container aliases, and extension points. Diverge when Hypervel's architecture requires it or when parity would demand disproportionate machinery/workarounds and produce materially worse code; choose the simplest well-adapted contract in that case and document the divergence. Do not inherit aliases, deprecated names, compatibility shims, or implementation residue that current Laravel retains solely for historical backward compatibility; Hypervel 0.4 should expose the modern canonical surface directly. Establish that something is genuinely legacy before removing it—an apparently unused current extension point is not enough. The plan may also reject previously accepted invalid or misleading states with a descriptive exception.
- Apply that rule consistently: Horizon uses `vonage`, never upstream Horizon's stale `nexmo` name.
- Hypervel worker singletons retain only boot-time immutable/baseline state. Request, job, command, and test overrides belong in CoroutineContext and are cleaned at execution boundaries. This is an architectural adaptation, not a port of Laravel's process-per-request mutable-static assumptions.
- Most fixes remove work, bound memory by correct lifetime, preserve a fast path, or add only constant-time state checks.
- Queue timeout behavior remains unchanged because the proposed drain would weaken its safety contract.
- No fix may add periodic polling, arbitrary eviction thresholds, a distributed lock on every request/cache hit, metadata shortcuts with delayed correctness, or a new abstraction whose only purpose is an unsupported failure mode.
- Performance and scalability claims must be measured during implementation for the affected hot paths. A fix does not land if its package benchmark/load test shows a material regression that is not inherent to the required correctness guarantee; revise the design instead.
- Load tests must cover high-cardinality input and long worker lifetimes, not only request latency: retained memory must converge to the natural live/configured keyspace, and temporary request/job state must disappear at execution teardown.
- Compare database queries, remote calls, lock acquisition, bytes copied over IPC, allocations, and coroutine scheduling before and after each affected hot-path fix. Cache hits and ordinary non-strict/read paths must remain lock-free and free of new I/O.
- No synchronous CPU or blocking-I/O work may be moved onto the event loop. Where an existing public API is inherently synchronous, keep its implementation lean and document task-worker/queue offload for heavy workloads rather than hiding an unbounded background mechanism inside the framework.

## Complete remediation ledger

Each row is an implementation requirement. Test names are descriptive; use the repository's established test file for that component or create the narrowly corresponding file.

### Translation, views, and websocket

| ID | Proposed implementation | Required tests |
|---:|---|---|
| 2 | Stop automatically retaining every parsed key on NamespacedItemResolver. Keep setParsedKey and flushParsedKeys exactly as the public explicit cache API, but parse ordinary keys directly; the explode/str_contains work is cheaper and safer than a per-call context lookup or an arbitrary worker cache cap. | Arbitrary validation/translation keys do not grow worker state; explicitly seeded parsed keys still hit and flush; parse output remains identical; a focused microbenchmark confirms the uncached parser is not a material translation regression. |
| 3 | Keep successful translation groups in the worker cache, but store empty/missing locale-group results only in execution-local negative state. This avoids permanent attacker-driven locale growth without repeating filesystem probes inside one request/job. | Thousands of missing locales leave worker loaded state unchanged; one execution probes a missing/legitimate-empty group once; a later execution can discover a newly added translation; positive groups remain worker-cached. |
| 4 | No change; see disposition above. | Preserve existing selector tests. |
| 5 | Extract `Translator::get()`'s lookup body into a protected internal method with separate substitution replacements and missing-key-callback replacements. `get()` passes `$replace` for both; `choice()` passes `[]` for substitution and the caller's `$replace` to the missing callback, then substitutes only after plural segment selection. Keep every public signature unchanged and do not duplicate lookup. | Callback receives locale, key, and exact replacements; a replacement containing a pipe does not alter plural selection; normal translation replacement remains once-only. |
| 6 | Widen ViewException::render to mixed and forward all native Laravel exception render results. Keep report as bool or null. | String, array, View, Responsable, Response, and null render forwarding; bool/null report forwarding; original exception behavior when methods are absent. |
| 7 | Retain the worker cache only for existing named views, whose keyspace is application-defined. For raw inline component source, derive the deterministic xxh128 view name and keep only execution-local reuse; ensure the source file exists on the first use in that execution. Make view:clear clear the execution-local marker so an immediate re-render recreates the source. Do not add an arbitrary eviction cap. | Named views reuse worker state; thousands of unique inline sources do not grow the worker map; repeated inline render in one execution avoids repeated stats; delete/view:clear then render recreates the source; no cross-component collision. |
| 8 | Preserve the public abstract Engines\Engine base, but make getLastRendered return nullable string to match its initialized state. | Anonymous concrete subclass returns null before render and the rendered path afterward. |
| 9 | No standalone change; see disposition above. | None. |
| 14 | No production change; the dependency evidence and Swoole conclusion are recorded above. | No framework regression test is needed for a dependency invariant. Keep the existing handshake/close lifecycle tests. |

### Mail, notifications, collections, and support

| ID | Proposed implementation | Required tests |
|---:|---|---|
| 21 | Widen Mailable metadata values and storage shapes to int\|string\|null, matching Envelope. Cast consistently only where a downstream header API requires a string. | Integer and string metadata through send, render, and assertion helpers; null/absent metadata; strict-types regression. |
| 23 | In hasEnvelopeAttachment, call attachments only when the mailable defines it; otherwise use an empty list. | Envelope-only mailable, mailable that also defines attachments, attachment match/no-match, and no method fatal. |
| 26 | Keep AnonymousNotifiable::getKey returning null for Laravel fake/assertion parity, but make BroadcastNotificationCreated throw a descriptive exception when no explicit broadcast route exists instead of constructing a trailing-dot private channel. The audit's upstream comparison was wrong—Laravel also defines getKey—but the silent malformed-channel behavior remains a defect. | Anonymous broadcast without route fails loudly; explicit broadcast route works; normal model notifiable fallback unchanged; getKey remains null. |
| 30 | Add symfony/polyfill-php86 as a direct collections dependency because SortDirection is used by that split package. | Package metadata assertion and a standalone collections install/autoload smoke test without database. |
| 31 | Cast the single-item Arr::join result to string, matching the multi-item path and native return type. | One integer, float, stringable object, string, and multi-item list. |
| 32 | Use first() when guessing a resource collection class instead of reading items[0]. | keyBy, filtered/gapped keys, ordinary list, empty collection failure, and paginator/resource conversion. Finding 88 closes with these tests. |
| 34 | Port current Laravel's array-capable multibyte Str::substrReplace implementation, including array offset/length/replacement behavior and key preservation. Correct the scalar negative-length calculation as part of the port: the current Str::substrReplace('Hello', 'X', 2, -1) produces HeXello instead of HeXo. | Scalar parity including the explicit negative-length example; arrays with scalar and array replacements; offset/length arrays; associative keys; negative offsets and lengths; multibyte strings; mismatched replacement lengths. |
| 35 | For built-in UUID/ULID codecs, identify binary values by the unambiguous 16-byte storage length and validate textual 36/26-byte forms separately. Leave the generic public BinaryCodec heuristic available to custom codecs. Runtime sampling confirmed that roughly one in sixteen thousand random v4-shaped UUID payloads can be valid UTF-8 and NUL-free, so this is ordinary data loss at scale rather than a purely theoretical collision. | Deterministic valid-UTF-8, NUL-free 16-byte UUID/ULID payloads round-trip through casts and database bindings; a fixed previously misclassified v4 payload; textual forms; invalid lengths; custom codec behavior unchanged. |

### Database, image, collections duplicate, and pagination

| ID | Proposed implementation | Required tests |
|---:|---|---|
| 82 | Apply incrementEach's strict string-column and numeric-amount validation to decrementEach before constructing raw SQL. | Malicious SQL fragment and nonnumeric amount rejected before query; non-string/associative shape failures; valid ints, floats, and numeric strings update correctly. |
| 83 | Add one package-internal MIME buffer helper with a lazily initialized worker-static finfo and use it from Image and InterventionDriver. finfo::buffer is stateless and non-yielding, so no DI service, coroutine state, lock, reset method, or AfterEachTestSubscriber registration is needed; retaining the handle for the worker lifetime is the intended ownership. | Both call sites report identical MIME across repeated processing; invalid data behavior; ordinary image tests remain independent without a reset seam. |
| 84 | For HEIC/HEIF, if the selected driver cannot decode dimensions, throw ImageException with the driver exception as previous. Never fall back to the known-inaccurate native reader. | Driver success; driver failure with previous exception; native reader is not called for HEIC; ordinary image fallback unchanged. |
| 85 | Replace dimension and effect clamps with consistent InvalidArgumentException validation matching the declared ranges. Invalid dimensions are caller input errors, not image-decoding failures. | Zero/negative width and height for cover/contain/crop/resize/scale; blur/sharpen below 0 and above 100; exact valid boundaries. |
| 86 | Detect unsupported driver names before delegating. Let exceptions from registered custom creators propagate unchanged and validate that the creator returned a Driver with a descriptive result-type error. | Unknown driver message; custom creator InvalidArgumentException preserved; wrong return type; valid extension. |
| 87 | Document that decode/transform/encode are synchronous CPU work that block the worker event loop, and direct heavy conversions to task workers or queued jobs. Do not invent unsupported numeric thresholds. | Documentation review against image driver behavior and worker/task terminology. |
| 88 | No second implementation; close with 32. | 32's tests. |
| 89 | Amend pagination README so current_page_url is documented for both simple and length-aware paginator JSON. | Documentation review plus existing serialization snapshots for both paginator types. |
| 90 | Remove CursorPaginator's duplicate hasMore property and retain the abstract declaration. | Reflection confirms one declaration; cursor pagination behavior unchanged. |
| 91 | Pass this paginator's pageName to resolveCurrentPage when direct construction receives a null page. | Custom p resolves p rather than page; default page; explicit page bypasses resolver. |
| 92 | Remove pagination's hard runtime requirements on database and http. Keep them in require-dev and add Composer suggests only if the optional model/resource transformations need explanation. | Standalone pagination Composer install/autoload; metadata has no cycle; model/pivot/resource instanceof paths still work when optional packages are installed. |
| 93 | No change; see disposition above. | Preserve nullable-path and state-flush behavior. |

### Redis, cache, queue, and Horizon

| ID | Proposed implementation | Required tests |
|---:|---|---|
| 123 | No production change; retain the resolved disposition above. | Serializer-enabled get returns array, object, int, float, string, and null mapping without TypeError. |
| 129 | No change; see disposition above. | Preserve reset/subscriber lifecycle tests. |
| 153 | No change; see disposition above. | Preserve immediate-kill, non-drain, timeout event, and idempotency documentation coverage. |
| 160 | Port the current laravel/vonage-notification-channel package into a Hypervel split package, then make Horizon's existing routeSmsNotificationsTo API effective by adding the missing route in SendNotification and channel selection in LongWaitDetected::via, adapted to the current `vonage` channel, `toVonage`, and VonageMessage. Do not copy Horizon upstream's obsolete `nexmo` name or introduce a second/deprecated driver. `Vonage\Client` caches service objects whose `APIResource` mutates `lastRequest`/`lastResponse` around yielding HTTP calls, so framework-created channels build a fresh SDK Client per send while sharing only normalized immutable configuration and Hypervel's coroutine-safe PSR-18 transport. Add per-execution memoization only later if measurement proves construction material. Keep the Vonage facade non-caching and preserve direct construction with a supplied Client and per-message `usingClient` overrides. Add the package README, canonical notification docs, Horizon docs, `HorizonServiceProvider.stub`, and Horizon Boost notification reference using `vonage` only. | Ported package provider/config, route resolution, message construction, channel send/failure, facade, direct constructor, and per-message override; deterministic concurrent sends cannot exchange SDK request/response state; repeated sends get distinct SDK clients but reuse the safe HTTP transport; Horizon's two consumers use `vonage`; absent number adds no channel; mail/Slack composition; public routeSmsNotificationsTo remains unchanged; all docs/stubs/Boost references contain no `nexmo` surface. |

## Commit and dependency structure

Use package-sized commits that remain reviewable and bisectable. The following order avoids building fixes on obsolete primitives:

1. Mail and data representation: 21, 30-32, 34-35, 82.
2. Vonage notification channel port followed by Horizon wiring: 160.
3. Remaining package-local correctness work by package, followed by performance/docs/cleanup.

Do not combine unrelated packages merely because their findings have the same severity.

## Verification protocol

For every changed test file:

1. From the components repository root, run that exact test file immediately with `./vendor/bin/phpunit --no-progress path/to/Test.php`.
2. For deterministic concurrency tests, run the exact file repeatedly and under the parallel runner where supported.
3. Run the complete affected package test directory after its individual files pass.
4. Run integration suites for every affected external system: MySQL/MariaDB and PostgreSQL for database semantics, and Redis for cache.

Additional required checks:

- Split-package metadata changes: run the package metadata tests and a clean standalone Composer install/autoload smoke test.
- Documentation changes: verify every claimed API and difference against the final source; update package README and src/docs together where both describe it.
- Runtime races: use barriers/channels to force the bad ordering. A test that merely starts two coroutines without controlling their interleaving is insufficient.

After all package work is complete:

1. Run `composer fix` once as the repository checkpoint. It owns formatting, static analysis, the parallel suite, Testbench package tests, and dogfood tests; do not duplicate those full checks immediately beforehand.
2. Inspect git diff and git status; ensure generated fixtures, temporary files, environment changes, node output, and unrelated user changes are absent.

## Completion criteria

- Every audit ID retained in this plan has the disposition recorded above.
- Findings 4, 9, 14, 93, 129, and 153 remain unchanged for the stated reasons.
- Finding 88 is closed by 32 rather than implemented twice.
- Finding 123 remains fixed and regression-covered.
- No worker-global mutable state is introduced without an explicit boot-only contract and reset path.
- No cache fill can republish state after a completed revocation/invalidation.
- All exact-file, package, integration, split-package, TypeScript, static-analysis, lint, dogfood, and final composer fix checks pass.
