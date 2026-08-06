# Framework Coroutine, State, and Lifecycle Audit

## Scope

Audit every package under `src/` for coroutine safety, worker-lifetime state correctness, resource ownership, liveness, deterministic cleanup, test isolation, API correctness, and hot-path performance. Work package by package, but fix shared defects at their lowest owning boundary and update every affected consumer in the same coherent change.

This is the reusable operating plan and compact routing index. The stable sections define how to run this audit now and in the future, while the package checklist tracks completion. Detailed package findings, changes, validation, and reviews live in the companion [`2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`](2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md).

The superseded `docs/ai/package-coroutine-safety-review.md` is removed after its useful bug taxonomy, remediation patterns, and conventions are incorporated here. This plan and its companion ledger together become the only repository source of truth for the audit.

## Goal

Finish with a framework that reads as if it was designed for a long-lived Swoole worker from the start:

- request and operation state is isolated from sibling coroutines;
- immutable and worker-lifetime state is intentionally shared;
- every coroutine, timer, channel, process, signal, socket, stream, connection, client, lease, listener, and callback has a clear owner;
- partial creation rolls back every reservation it made;
- cleanup is exhaustive and preserves the earliest failure;
- waits for external progress are bounded when the peer or owner can disappear;
- native false/error results cannot violate declared PHP contracts;
- Laravel public APIs, configuration, documented behavior, and conventional extension patterns remain compatible by default;
- hot paths do not gain unnecessary allocations, container lookups, locks, retries, logging, or yields;
- tests reproduce real failure modes deterministically and release everything they own;
- no stale compatibility layer, workaround, dead branch, obsolete helper, misleading comment, or superseded documentation remains after a correction.

Hypervel 0.4 is greenfield. Backward compatibility, churn, and blast radius do not justify retaining a flawed Hypervel-specific API or internal design. This freedom also covers surfaces directly deprecated or removed by the package's actual upstream; it does not make Laravel's current public API, configuration keys and structure, documented behavior, or conventional extension patterns disposable.

Any intentional divergence from that Laravel-facing contract is exceptional and requires a concrete, meaningful Hypervel benefit plus explicit owner approval. Present the reasoning, compatibility impact, alternatives, performance effect, complexity cost, and ongoing upstream-comparison burden before implementation. Code-style preference, subjective tidiness, theoretical flexibility without real consumers, or benchmark noise is not a meaningful benefit. The correct result is the simplest design that fixes the verified problem while preserving useful upstream parity.

## What this audit is not

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

## Backing research and current runtime facts

### Long-lived container semantics

`Container::resolve()` in `src/container/src/Container.php` has three relevant lifetimes:

- scoped bindings are stored in `CoroutineContext`;
- explicit singletons are stored in the process-global container instance;
- unbound concrete classes are auto-singletoned in `$autoSingletons` unless the resolution is contextual, parameterized, explicitly bound, or self-building.

The audit must therefore treat an unbound concrete as worker-lifetime until source proves otherwise. A constructor that captures request data, a mutable builder, or a resolver callback that expected a fresh instance can be wrong even without an explicit `singleton()` registration.

Relevant current control flow:

```php
if ($this->isScoped($abstract) && ! $needsContextualBuild) {
    $contextKey = self::SCOPED_CONTEXT_PREFIX . $abstract;

    if (CoroutineContext::has($contextKey)) {
        return CoroutineContext::get($contextKey);
    }
}

if (isset($this->instances[$abstract]) && ! $needsContextualBuild) {
    return $this->instances[$abstract];
}

if (isset($this->autoSingletons[$abstract]) && ! $needsContextualBuild) {
    return $this->autoSingletons[$abstract];
}
```

The later cache-write path stores safe unbound concretes in `$autoSingletons`. Provider bindings, aliases, contextual builds, resolving callbacks, and constructor dependencies are therefore part of every package audit.

### Coroutine context and fork semantics

`CoroutineContext` stores coroutine values in the native coroutine context and non-coroutine values in a process-global fallback. `Coroutine::fork()` snapshots the parent's context before spawning and installs that snapshot before running the child.

Copying is value-sensitive:

- objects implementing `ReplicableContext` are replicated;
- other object values remain shared references;
- child writes to the copied context map do not update the parent map;
- selecting no keys copies all keys.

Current public helpers document that behavior:

```php
function go(callable $callable, bool|array $copyContext = false): int
{
    return $copyContext === false
        ? Coroutine::create($callable)
        : Coroutine::fork($callable, is_array($copyContext) ? $copyContext : []);
}
```

The audit must catch code that expects child-to-parent context propagation and code that copies a mutable object expecting isolation.

### Test cleanup has two kinds of work

`AfterEachTestSubscriber::flushStateAfterTest()` currently preserves the earliest failure while running package callbacks, Mockery verification, terminal database wrapper cleanup, and the framework static reset list.

The database resolver call is deliberately outside `flushFrameworkState()`: discarding a borrowed wrapper is throwable resource cleanup, while framework `flushState()` methods form the no-throw static reset boundary. Do not mechanically turn every cleanup operation into `flushState()` or put throwable I/O in the static reset list.

```php
try {
    DatabaseConnectionResolver::flushCachedConnections();
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

try {
    $this->flushFrameworkState();
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}
```

Container singleton instance state is discarded when the test container is reset. Static/process-global state survives and needs an explicit reset when it can contaminate later tests. Live resources remain owned by the test or component that acquired them and must be closed, canceled, joined, released, or discarded through exception-safe cleanup.

### Optional event behavior includes fakes

The event dispatcher caches listener lookups. `EventFake::hasListeners()` also returns true for an unrestricted fake and for explicitly faked event classes. Optional observational events can therefore use `hasListeners()` without making normal `Event::fake()` assertions disappear.

Guard optional framework events before construction and dispatch. Do not guard actions where dispatch is itself the requested side effect, such as jobs, broadcasts, commands, webhooks, or other explicit dispatch APIs.

### Validation gates

The root `composer.json` defines `composer fix` as:

1. PHP CS Fixer;
2. both PHPStan configurations;
3. the full parallel components suite;
4. the Testbench package contract suite;
5. the Testbench dogfood package suite.

Any package cycle that changes source or tests ends with this full gate. Audit-only ledger changes do not rerun the runtime suite because no executable code changed.

### Local upstream references

Use local sources rather than online lookup when available. In particular, inspect the source package Hypervel was ported from when parity or inherited behavior matters:

- Laravel framework: `examples/laravel/framework/` relative to the monorepo root;
- Hyperf framework: `examples/hyperf/hyperf/` relative to the monorepo root;
- other Laravel first-party packages: `examples/laravel/`;
- other Hyperf low-level packages, including Engine: `examples/hyperf/`;
- Friendsofhyperf packages: `examples/friendsofhyperf/`;
- installed Symfony and SDK sources: `vendor/`;
- previous Hypervel behavior when useful: `examples/hypervel/components/`.

Inspect a package README only for an upstream reference, a `Differences From Laravel` section, architecture notes, or package-specific constraints. Do not spend audit time reading badges, installation boilerplate, license text, or empty descriptions.

When the audit finds an upstream feature that Hypervel has not yet ported, follow the **Incremental upstream updates** workflow in `AGENTS.md`. Record the originating implementation and documentation pull-request surface and the current-branch result in the package investigation and companion ledger so the completeness check remains auditable.

### Inherited upstream internals

When a Hypervel class changes behavior inherited from Symfony or another dependency, verify the dependency's real dispatch and visibility rules before proposing an override:

- a dependency's `private` method is not overridden through subclass dispatch;
- a dependency's `private const` is not visible to the subclass;
- a method that reads `self::$property` directly does not become late-bound merely because the subclass redeclares the property;
- bypassing or restating an upstream method requires a source comment explaining the necessary invariant and focused tests for every upstream setup step Hypervel still relies on.

Prefer the smallest necessary divergence. Do not duplicate an upstream method just to avoid one harmless line, but do not retain process-global mutation that races in a Swoole worker merely for source parity.

### Verified Swoole primitive behavior

The following behavior was reproduced on Swoole 6.2.2 while fixing the test-suite lifecycle defects that preceded this audit. Treat it as the current runtime contract instead of re-probing it package by package. Revalidate it after a Swoole major/minor upgrade or a change to the relevant Hypervel wrapper:

- `Channel::pop(0.0)` blocks when the channel is empty; it is not a non-blocking poll. `WaitGroup::wait(0.0)` has the same behavior when its count is positive because it delegates to a channel pop. Use `Channel::getLength()` or `WaitGroup::count()` when only a state observation is required.
- Default coroutine cancellation interrupts one `System::waitSignal()` call but does not terminate an unconditional waiter loop. A waiter that must be terminally canceled uses exception-injecting cancellation and treats `CanceledException` as expected ownership cleanup.
- `Coroutine::yield()` parks the coroutine until another path explicitly resumes it. It is not a substitute for a scheduling yield such as the production runtime's hooked `usleep(0)`.
- Native Swoole handles can already be torn down when PHP destructors run. Native channel/resource closure therefore belongs to an explicit deterministic lifecycle path, not `__destruct()`. A destructor may perform only PHP-local best-effort cleanup whose full call chain is proven not to reach a native handle.

### Verified PHP compiler behavior

PHP 8.4 changed `__FUNCTION__` and `__METHOD__` inside closures and arrow functions from the historical bare `{closure}` value to a descriptor containing the lexical parent and closure definition line, such as `{closure:App\Service::method():42}`. Nested closures compose that descriptor. A source compiler that moves a method body must preserve the original lexical name and line rather than exposing its generated helper. Revalidate this descriptor format after every PHP major or minor upgrade; a native-runtime canary must fail if PHP changes the format assumed by the generator.

## Audit principles

### 1. Verify before changing

A suspicious pattern is not an actionable finding until the audit establishes:

- the exact file and symbol;
- every relevant caller and callee across `src/` and `tests/`;
- the state or resource owner;
- the initialization, commit, use, and cleanup boundaries;
- a realistic production or test failure schedule;
- why current guards and tests do not prevent it;
- sibling implementations and same-family sites;
- relevant upstream behavior;
- the lowest correct fix boundary;
- a regression strategy;
- the performance and complexity effect of the proposed fix.

Use a focused probe when source reasoning cannot settle native or scheduler behavior. Do not repeatedly run the full suite hoping to reproduce a rare flake.

### 2. Fix the lowest inconsistent contract

Do not add local compensation when a shared lower-level contract is wrong. A caller catch is not enough when a typed filesystem method can return `false`; a per-consumer spawn catch is not enough when Engine exposes an ambiguous spawn contract; a proxy workaround is not enough when pool ownership is undefined.

After changing a lower-level contract, re-audit every affected caller and revisit completed packages that depend on it. Record cross-references in both the owning package and each affected package ledger entry.

### 3. Make ownership explicit

The component that acquires or registers a resource records the exact handle and releases that exact handle. Cleanup must not reconstruct identity from mutable state when the original handle can be retained.

Examples include coroutine IDs, timer IDs, process IDs plus incarnation checks, listener callbacks, pool leases, subscriber objects, stream handles, temporary filenames, signal watcher IDs, and channel tokens.

### 4. Make creation transactional

If code reserves capacity or publishes state before a later operation can fail, it must either finish creation or roll back every earlier change. Do not expose half-initialized objects, registered-but-dead pools, leaked wait-group counts, or published runtime paths without their cleanup owner.

### 5. Make cleanup exhaustive

Independent cleanup steps run even when an earlier step fails. The earliest operation or cleanup failure remains primary. Cleanup failures must not corrupt bookkeeping, skip unrelated cleanup, or turn a successful ownership transfer into a reported failure.

### 6. Bound only external progress

Use deadlines where progress depends on a process, socket peer, lock owner, IPC child, or external service that can disappear. Do not add arbitrary timeouts to ordinary internal coroutine joins once successful creation and ownership guarantee completion.

### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Finding classification

### Category

- **Defect:** current behavior can be wrong, unsafe, leaky, stuck, or observably inconsistent.
- **Improvement:** no current defect, but an evidence-backed, meaningful practical simplification, performance gain, flexibility gain, or general capability exists; style-only churn and negligible micro-optimizations do not qualify.
- **Intentional difference:** behavior differs from upstream for a verified Hypervel reason and is safe.
- **Userland footgun:** an API is correct within its intended boot/test boundary but can be misused without clear guidance.
- **Rejected concern:** source tracing disproves the suspected failure or the state is not realistic enough to justify machinery.

### Severity

- **Critical:** plausible security-boundary failure, silent data corruption/loss, wrong-process signaling, or worker-wide catastrophic failure.
- **Major:** realistic hang, request failure, cross-request state leak, resource/capacity leak, race, or persistent wrong behavior.
- **Minor:** bounded edge-case correctness, diagnostics, test isolation/flakiness, or maintainability issue with concrete impact.
- **Improvement:** no current defect; use only with the category and approval rules above.

### Confidence

- **High:** directly reproduced or proven by a complete source and control-flow trace.
- **Medium:** the complete trace establishes the vulnerable schedule but reproduction depends on environment or timing.
- **Low:** a plausible concern still lacks evidence. Do not implement it until resolved.

Severity and confidence are independent. Avoid inflated labels. A rare catastrophic failure can be Critical with Medium confidence; a directly reproduced typo can be Minor with High confidence.

## Package audit procedure

Run every stage for every package. Scale the amount of text to the package, not the care. A package with three pure value objects needs a short audit; a framework manager with drivers and native resources needs a long one.

### Stage 0 — Restore context after compaction

Before resuming this plan after any compaction:

1. read `AGENTS.md` in full;
2. read this plan in full, including the audit routing and cross-package dependency indexes;
3. read only the companion-ledger entry for the active package and entries explicitly named by the routing/dependency indexes; do not reread the full accumulated ledger;
4. inspect the current package README only for upstream references, differences, architecture notes, or package-specific constraints;
5. re-open the current package source and relevant repository callers;
6. treat compacted summaries as navigation aids, never as evidence.

### Stage 1 — Discover the package shape

Use progressive disclosure before content searches:

```bash
tree -L 1 src/{package}
tree -L 1 src/{package}/src
tree -L 1 tests/{Package}
```

Continue shallow exploration until the relevant subdirectories are known. Use a full tree only for a narrowed, reasonably small package directory.

Inventory:

- `composer.json`, provider metadata, and upstream reference;
- service providers and config files;
- contracts, facades, managers, factories, registries, and repositories;
- middleware, listeners, observers, commands, jobs, and events;
- drivers, transports, adapters, connectors, pools, clients, and external SDK wrappers;
- compilers, generated code, proxies, AOP aspects, and class-map overrides;
- traits and inherited state;
- unit, integration, process, coroutine, and external-service tests.

Build a `{package-test-paths...}` list from the package's real unit, integration, fixture, and subprocess tests. Names are usually related to the package but are not guaranteed to match it exactly; for example, a transport package can own tests named after the underlying client. Use those discovered paths in the generic scans below. Whole-repository caller searches happen in Stage 11 after concrete symbols are known, avoiding a repeated dump of every unrelated test that happens to use a channel or coroutine.

Read every service provider in full before classifying the package. Read the central state and execution flows in full. For a large file, read consecutive chunks so initialization, mutation, cleanup, and exceptional exits remain connected.

### Stage 2 — Map container and service lifetimes

Classify every binding and implicit resolution:

```bash
grep -RInE --include='*.php' -- '->(bind|singleton|scoped|instance|alias|resolving|afterResolving)\(' src/{package}/src
grep -RInE --include='*.php' -- '(Container::getInstance|->make\(|->get\(|app\(|resolve\()' src/{package}/src
```

For each result, answer:

- Is the resolved object fresh, scoped, explicitly shared, or auto-singletoned?
- Does its constructor capture request, session, auth, locale, config, clock, event, or transport state?
- Does a lazy binding closure mutate global SDK or PHP state on first resolution?
- Does a resolving callback write request data onto a long-lived object and therefore run only for the first resolution?
- Does an explicit binding key match the canonical alias used by `resolve()`?
- Is a transient dependency frozen inside a singleton closure?
- Does a mutable object genuinely need a fresh `bind()`, a coroutine `scoped()` binding, a per-call factory, or no container caching?

Search all callers before changing a binding. `build()` bypasses top-level bindings and swaps; it is not a generic “fresh make” replacement.

### Stage 3 — Map static, inherited, and cached state

Search declarations and writes separately:

```bash
grep -RInE --include='*.php' -- '(public|protected|private)[[:space:]]+static' src/{package}/src \
    | grep -vE 'static[[:space:]]+function'
grep -RInE --include='*.php' -- 'flushState\(|flushMacros\(|reset[A-Z]|forget[A-Z]|clear[A-Z]' \
    src/{package}/src src/testing/src/PHPUnit/AfterEachTestSubscriber.php {package-test-paths...}
grep -RInE --include='*.php' -- '^[[:space:]]+use[[:space:]]+[A-Z_\\]' src/{package}/src
```

The first command deliberately excludes only `static function`, not every line containing `function`; a property such as `$functionCache` must remain visible. The trait scan targets indented class-body `use` statements rather than top-level imports. Read compound trait declarations and adaptations instead of assuming one trait per line.

Do not trust a declaration scan alone. Read every trait used by a central class when that trait can carry state. `Macroable`, model/event traits, relation traits, and package-specific concerns can introduce static or mutable instance state that is invisible in the using class.

Classify each value:

- immutable lookup metadata;
- lazy immutable worker cache;
- boot-time mutable configuration;
- test-only override;
- manager/driver registry;
- per-request or per-operation state;
- consume-once state;
- external resource handle.

Verify reset behavior from the actual lifecycle:

- process-global mutable statics that can affect later tests need a pure `flushState()` and the correct test cleanup registration;
- nullable lazy caches use `null` as their structural reset sentinel;
- container singleton instance state is normally discarded with the container and should not be converted to static state for test convenience;
- coroutine context is cleared with the coroutine and must not be “cleaned” from a later static reset hook;
- resource release that can throw or perform I/O belongs in separately captured teardown, not the no-throw static reset list.

Audit every public mutator on a long-lived object. If it is genuinely boot/test-only, require the standard warning and a concrete failure sentence. If callers reasonably need it per request, documentation is not a fix: redesign the state boundary.

### Stage 4 — Map per-call mutation and handoff

Look for state written during render, send, handle, dispatch, execute, process, or request flow:

```bash
grep -RInE --include='*.php' -- '(\$this->[A-Za-z0-9_]+[[:space:]]*=|static::\$|self::\$)' src/{package}/src
grep -RInE --include='*.php' -- '(set[A-Z]|always[A-Z]|resolve[A-Z].*Using|build[A-Z].*Using|using\()' src/{package}/src
grep -RInE --include='*.php' -- '(listen\(|observe\(|forget\(|subscribe\(|register\()' src/{package}/src
```

Trace these high-signal shapes:

- chain mutation followed by a read from the same shared object;
- save/mutate/restore on a shared property, even with `finally`;
- middleware/listener/decorator handoff through singleton state;
- temporary event listener cleanup using `forget(EventClass)`, which removes listeners owned by other code;
- derived constructor state whose input setters do not recompute it;
- shallow cloning of object-valued mutable properties;
- a public mutator on a driver, adapter, transport, channel, or handler held by a manager cache;
- registration inside a per-item or per-driver loop;
- transformations that append a prefix, suffix, namespace, or token repeatedly without normalizing prior output;
- boot-provided state consumed and cleared on the first runtime use;
- compiled output that resolves a global factory/container instead of the active context-aware or cloned environment.

For every temporary mutation, ask what sibling coroutines can observe between assignment and restoration. `finally` repairs eventual state; it does not provide isolation.

### Stage 5 — Map coroutine creation and scheduling

```bash
grep -RInE --include='*.php' -- '(Coroutine::create|Coroutine::fork|(^|[^A-Za-z0-9_])(go|co)\(|parallel\(|WaitGroup|Concurrent|Waiter)' src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(Coroutine::defer|defer\(|cancel\(|yield\(|resume\(|sleep\(|usleep\()' src/{package}/src {package-test-paths...}
```

For each spawn:

1. list bookkeeping committed before creation: capacity, token, wait-group count, timer registry, callback registry, published handle, or state flag;
2. trace native/framework spawn failure and rollback;
3. determine where child exceptions go;
4. determine who joins, cancels, or observes completion;
5. verify cancellation actually wakes the blocked primitive;
6. verify the parent cannot return while child-owned state is still required;
7. inspect copied context and mutable object references;
8. inspect test doubles for removed yields or different scheduling.

Do not catch a spawn exception after ownership was already committed and report the operation as failed if the state change succeeded. Either roll back before the commit point or make the later notification best-effort and ensure consumers perform a final state check.

### Stage 6 — Map channels, timers, locks, and pools

```bash
grep -RInE --include='*.php' -- '(new Channel|Swoole\\Table|new (Swoole)?Table|->column\(|->push\(|->pop\(|->close\(|->isFull\(|->isEmpty\()' src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(Timer::|->tick\(|->after\(|->clear\(|Mutex|Locker|Atomic|flock\(|lock\(|unlock\()' src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(Pool|Lease|borrow|release\(|discard\(|destroy\(|recycle|heartbeat|keepalive)' src/{package}/src {package-test-paths...}
```

Verify:

- one canonical state store across coroutine and non-coroutine execution;
- closed channels reject new ownership and wake blocked waiters;
- zero timeout semantics match the native primitive rather than an assumption;
- waiter counts and signals cannot be stranded;
- timer registration rolls back on spawn failure and timer IDs are cleared on every exit;
- recurring callback failures are visible without stopping intended recurrence;
- lock acquisition backs off and has a justified deadline when an owner can die;
- no yielding/logging/network work occurs inside a critical section unless the lock is designed for it;
- pool objects have one owner, one borrow, and one terminal release/discard;
- health-check and destroy failures cannot create ghost capacity;
- idle/lifetime maintenance uses monotonic time and does not refresh idle clocks accidentally;
- a lazy result, stream, iterator, promise, paginator, or job cannot outlive the lease that created it.

### Stage 7 — Map processes, signals, sockets, streams, and native boundaries

```bash
grep -RInE --include='*.php' -- '(proc_open|new Process|Swoole\\Process|waitpid|->wait\(|->start\(|->stop\(|->kill\()' src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(pcntl_|posix_|SIG[A-Z]+|waitSignal|register_shutdown_function|WORKER_EXIT)' src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(fopen|fread|fwrite|stream_|socket|Socket|recv\(|send\(|accept\(|connect\()' src/{package}/src {package-test-paths...}
```

Trace:

- process creation and exact PID ownership;
- PID reuse and positive incarnation checks before signaling;
- signal masks before fork and restoration on every parent failure path;
- child handler installation before control signals can arrive;
- bounded stop/kill/reap behavior;
- every pipe and process handle on success and failure;
- forked children inheriting parent shutdown handlers;
- partial reads/writes, EOF, empty non-EOF reads, native `false`, interruption, and closed peers;
- fixed ports and shared paths under parallel tests;
- exceptions leaving native Swoole callbacks without completing the protocol response;
- shell interpolation versus argv-array execution and escaped unavoidable shell boundaries.

Treat metadata-then-use as a named race shape. An `exists()` or `isFile()` check does not make the later read/open safe. The native operation remains the checked boundary and its `false` result must be compatible with the declared PHP type and exception contract.

### Stage 8 — Map process-global runtime mutation

```bash
grep -RInE --include='*.php' \
    -e 'Config::set' \
    -e 'config\(\)->set' \
    -e 'config\(\[[^]]' \
    -e 'putenv\(' \
    -e 'date_default_timezone_set' \
    -e 'setlocale' \
    -e 'mb_internal_encoding' \
    -e 'ini_set' \
    -e 'error_reporting' \
    -e 'set_error_handler' \
    -e 'set_exception_handler' \
    -e 'restore_error_handler' \
    -e 'restore_exception_handler' \
    src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(Carbon::|new Carbon\(|->(add|sub|modify|setDate|setTime)[A-Za-z0-9_]*\()' src/{package}/src {package-test-paths...}
grep -RInE --include='*.php' -- '(createRandomStringsUsing|serializeUsing|setTestNow|setHttpClient|set[A-Z].*Resolver)' src/{package}/src {package-test-paths...}
```

Runtime `Config::set()` is process-global and races across requests. Process-global environment, timezone, locale, encoding, INI, error/exception handlers, random factories, clocks, and SDK globals need a boot-only owner or an isolated alternative. Saving and restoring a global around yielding work still races.

Use `CarbonImmutable` for shared or stored timestamps. A mutable `Carbon` object retained on a singleton, manager, job coordinator, or copied context can be changed by another path holding the same reference.

### Stage 9 — Audit tests as lifecycle code

Tests are part of the concurrency surface. Inspect:

- fake workers whose `sleep()` or I/O method only records and no longer yields;
- detached coroutines, timers, subscribers, listeners, signals, and child processes;
- unbounded joins, pipe reads, lock waits, and child reaping;
- shutdown handlers inherited after fork;
- process-global Swoole settings without process isolation;
- fixed ports, predictable temp files, shared SQLite paths, and committed fixture mutation;
- container, facade, config, clock, environment, signal, EventFake, and Mockery swaps restored only on success;
- test cleanup that can skip later cleanup after one exception;
- assertions that accidentally test scheduling luck rather than the intended invariant;
- external-service tests missing the repository’s per-worker isolation traits.

Use the correct test base. Tests writing files need Testbench or `ParallelTesting::tempDir()`. Tests that change process-global Swoole settings or would hard-spin under the old implementation need process isolation or a purpose-built subprocess.

### Stage 10 — Audit API, types, parity, and performance

Inspect package facades and contracts as part of the public surface. Verify:

- native and docblock types cover every reachable value;
- callbacks and proxies do not leak borrowed internals;
- fluent/static return types return the advertised receiver;
- Laravel's current public APIs, configuration keys and structure, documented behavior, and conventional extension patterns remain compatible by default;
- any proposed Laravel-facing divergence has a concrete, meaningful Hypervel benefit and is presented to the owner as a stop gate before implementation;
- Hypervel-specific differences are necessary for Swoole or provide another owner-approved meaningful benefit and are documented where the repository rules require it;
- before removing a deprecated API, verify that the package's direct upstream explicitly deprecates it; a deprecation in an underlying dependency does not count when the direct upstream retains the wrapper;
- a bug is fixed even when upstream shares it;
- deliberate lifecycle bypasses and unsupported use are not treated as defects unless the public contract promises behavior through them;
- optional observational events use `hasListeners()`;
- dynamic container results are narrowed so static analysis checks method calls;
- caches are keyed by every input that changes construction and by nothing that only changes presentation;
- worker caches have bounded or intentional retention;
- new correctness logic adds no unjustified hot-path overhead.

For a possible optimization, measure or establish the cost and usage pattern before proposing it. Surface every meaningful evidence-backed optimization or broader improvement to the owner, including the upstream-maintenance cost and viable alternatives, then stop for approval before implementation. Do not propose style-only divergence or performance changes whose effect is merely noise.

### Stage 11 — Complete the repository trace

For every candidate finding, search the whole repository for:

- the class, interface, trait, method, property, event, config key, and exception;
- all implementers and sibling drivers;
- facades and magic annotations;
- service providers and bindings;
- tests, fixtures, fakes, and mocks;
- docs and comments describing the behavior;
- local Laravel, Hyperf, Symfony, SDK, or prior Hypervel sources when relevant.

Read only relevant hits after the broad search. “Trace across the repository” does not mean reading unrelated packages without a connection.

## Established remediation vocabulary

These examples show accepted shapes. They are not automatic answers. The audit must first prove the owner and lifetime.

### Boot-only shared mutation

When a public mutator genuinely configures worker-wide behavior at boot or in tests, keep the API and add the standard warning with the concrete risk:

```php
/**
 * Configure the resolver used by the worker.
 *
 * Boot-only. The callback persists for the worker lifetime and affects every
 * subsequent request.
 */
public static function resolveUsing(Closure $resolver): void
{
    static::$resolver = $resolver;
}
```

Do not use this warning to excuse an API that callers reasonably need per request. That requires redesign.

### Pure static reset

Use `flushState()` only for worker-static state that can be reset without I/O or resource ownership work:

```php
protected const DEFAULT_LIMIT = 100;

protected static int $limit = self::DEFAULT_LIMIT;

/**
 * Flush all static state.
 */
public static function flushState(): void
{
    static::$limit = self::DEFAULT_LIMIT;
    static::$resolver = null;
}
```

Register framework-owned resets in `AfterEachTestSubscriber` when the state can contaminate later tests. Optional/private packages use the supported test-state registrar rather than hardcoding themselves into framework cleanup.

Do not call `CoroutineContext::forget()` from `flushState()`. The test coroutine has already ended, and the authoritative context flush handles coroutine/non-coroutine context. Do not hide throwable resource release inside this no-throw boundary.

### Per-operation state

Prefer a per-call argument when the state belongs naturally to one operation:

```php
public function render(Message $message, Theme $theme): string
{
    return $this->renderer->render($message, $theme);
}
```

Use a scoped binding when a full service instance belongs to one request/coroutine. Use `CoroutineContext` when separate framework paths must share one invocation-scoped value and a parameter cannot be threaded cleanly.

Context keys follow the current convention:

```php
protected const THEME_CONTEXT_KEY = '__mail.theme';
protected const CHANNEL_CONTEXT_KEY_PREFIX = '__log.channel_context.';
```

Constants are protected for class-internal use and public only when another class or test must reference them.

For nested temporary context, save whether a value existed and restore that exact state:

```php
$hadPrevious = CoroutineContext::has(self::THEME_CONTEXT_KEY);
$previous = CoroutineContext::get(self::THEME_CONTEXT_KEY);
CoroutineContext::set(self::THEME_CONTEXT_KEY, $theme);

try {
    return $callback();
} finally {
    if ($hadPrevious) {
        CoroutineContext::set(self::THEME_CONTEXT_KEY, $previous);
    } else {
        CoroutineContext::forget(self::THEME_CONTEXT_KEY);
    }
}
```

### Isolated cloning

When a long-lived configured prototype must be customized per operation, clone it and deep-clone every mutable object whose state must not bleed back:

```php
public function __clone(): void
{
    $this->finder = clone $this->finder;
    $this->environment = clone $this->environment;
}
```

The caller mutates and uses the clone. Do not save/mutate/restore the singleton prototype around yielding work.

### Transactional creation

Keep unpublished state local until every creation step succeeds. Roll back the partial resource without replacing the creation failure:

```php
$resource = null;

try {
    $resource = $this->openResource();
    $registration = $this->registerResource($resource);
} catch (Throwable $exception) {
    try {
        $resource?->close();
    } catch (Throwable) {
        // Preserve the creation failure.
    }

    throw $exception;
}

$this->resource = $resource;
$this->registration = $registration;
```

If cleanup failure reporting is required, use an existing no-throw reporter. Do not mask the primary failure.

### Spawn rollback

Bookkeeping reserved before coroutine creation is rolled back on the typed creation exception:

```php
$this->waitGroup->add();

try {
    Coroutine::create(function (): void {
        try {
            $this->runTask();
        } finally {
            $this->waitGroup->done();
        }
    });
} catch (CoroutineCreateException $exception) {
    $this->waitGroup->done();

    throw $exception;
}
```

The rollback must mirror exactly what happened before spawn. Do not decrement state that was not reserved and do not catch after a successful ownership commit merely to pretend the commit failed.

### Exhaustive cleanup and primary failure

Run independent cleanup actions even after one fails:

```php
$exception = $operationFailure;

foreach ($cleanupActions as $cleanup) {
    try {
        $cleanup();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
}

if ($exception !== null) {
    throw $exception;
}
```

Do not allocate a large closure list on a hot path merely to use this shape. Direct `try/catch` blocks are clearer for a small fixed teardown.

### Resource lease and deferred result

A resource is released only after the operation and every lazy/deferred result are finished. If a method returns a live stream, iterator, promise, paginator, response callback, or job retaining the backend client, either:

- wrap the result so it owns and releases the lease at its real terminal event;
- complete/materialize the result before release when semantics allow it;
- redesign the pooled boundary around the real connection-owning object;
- do not pool that stateless or already-shared layer.

Generic magic proxying is not safe when the proxy cannot know which return values remain live.

### Checked native boundary

Metadata checks do not replace validation of the native operation:

```php
if (! $this->isFile($path)) {
    throw new FileNotFoundException("File does not exist at path {$path}.");
}

$contents = @file_get_contents($path);

if ($contents === false) {
    throw new FileNotFoundException("Unable to read file at path {$path}.");
}

return $contents;
```

Suppress a native warning only at a boundary whose return value is immediately checked and converted into the framework’s named failure contract.

### Optional event dispatch

```php
if ($this->events->hasListeners(RequestHandled::class)) {
    $this->events->dispatch(new RequestHandled($request, $response));
}
```

Resolve/narrow a dynamic dispatcher to its contract so PHPStan checks both calls. Do not add this guard when dispatch is the requested side effect.

### Temporary listener ownership

Do not register a listener for one operation and later call `forget(EventClass)`. That removes other owners’ listeners. Prefer one boot-time listener that reads a per-coroutine callback, or retain and remove the exact callback when the dispatcher supports exact removal.

### Manager cache ownership

Keep driver/engine caches as instance state on the manager unless the cache genuinely needs class-level lifetime beyond the container. An instance cache already has worker lifetime in production and is discarded with the test container. Static state adds cleanup burden without improving runtime behavior.

### Idempotent derivation

When a value may already contain the framework marker, normalize before appending:

```php
$basePrefix = $this->stripTestingSuffix($configuredPrefix);

return $basePrefix . "test_{$token}_";
```

Do not repeatedly derive from the previous derived result.

### Process-global mutation

Do not mutate config, environment, timezone, locale, encoding, PHP INI, exception handlers, terminal settings, or SDK globals during request/programmatic work. Move true worker configuration to boot. For per-request behavior, pass explicit values or use coroutine-local state. Save/restore is unsafe when the operation can yield.

## Regression-test design

### Test the old failure, not the implementation detail

Each defect receives a focused regression that fails against the old behavior for the intended reason. Assert externally meaningful behavior and ownership invariants rather than private line-by-line mechanics.

Examples:

- state isolation: sibling coroutines observe only their own value;
- spawn rollback: capacity, wait-group count, token, or timer registration returns to its original state;
- cleanup: later independent cleanup runs and the first exception remains primary;
- process lifecycle: a child that exits early is detected, killed if owned, and reaped within a bound;
- TOCTOU: the native operation fails after metadata says “present,” and the framework returns/throws its documented result rather than `TypeError`;
- lease lifetime: the resource remains borrowed until a returned stream/promise/iterator actually finishes;
- optional event: no construction/dispatch without listeners and normal `Event::fake()` coverage still sees the event;
- dead owner: lock acquisition fails descriptively within the configured external-progress bound rather than spinning.

### Deterministic coroutine interleaving

Use an explicit channel/barrier/atomic handshake when exact ordering matters:

```php
$ready = new Channel(1);
$continue = new Channel(1);

$results = parallel([
    'first' => function () use ($ready, $continue, $service): string {
        $service->setValue('first');
        $ready->push(true);
        $continue->pop();

        return $service->value();
    },
    'second' => function () use ($ready, $continue, $service): string {
        $ready->pop();
        $service->setValue('second');
        $continue->push(true);

        return $service->value();
    },
]);
```

Use `usleep()` to force interleaving only when the production operation itself yields on time and exact handshaking would distort the tested behavior. Allow enough deadline margin for parallel CI; keep the assertion that detects the unwanted extra work tight.

### Failure injection

Prefer existing seams: injected timers, factories, filesystem subclasses, process wrappers, connection factories, or protected boundary overrides in test fixtures. Do not add a public production hook solely for a test.

Use process isolation when changing Swoole process-global settings such as coroutine limits or hook flags. Use a purpose-built subprocess when the old behavior can hard-spin or terminate the PHP process before PHPUnit can report it.

### Files, ports, and external services

- Use `ParallelTesting::tempDir()` for scratch paths.
- Use Testbench when a test mutates an application skeleton.
- Bind to port `0` or use the repository server traits rather than a fixed port.
- Use per-worker Redis/database isolation traits for external services.
- Restore exact global/container/facade/environment state in `finally`.
- Close every stream, socket, process, subscriber, and timer in `finally`.

### Test cadence

After changing or adding a test file, run that file immediately. After the package implementation is complete:

1. run every affected test file;
2. run relevant integration groups when their services are available;
3. run `composer fix` for every source/test change;
4. inspect all skipped tests and failures normally reported by the gate; do not weaken assertions to obtain green output.

An audit-only package ledger update skips `composer fix` because executable code did not change. A documentation-only correction uses the relevant documentation checks; run the runtime suite only when the documentation generation/validation surface executes framework code.

## Package completion workflow

A package checkbox means the entire cycle below is finished, not merely that files were read.

### Branch and authorization model

This audit targets the repository's `0.4` integration branch. Start an audit branch from the latest owner-approved `0.4` state and do not perform audit work directly on `0.4`. The owner decides pull-request boundaries: one audit branch may accumulate one or several completed packages or cross-package work units before the owner determines that it contains enough coherent work for a pull request.

The owner has explicitly authorized coherent commits after each completed package or cross-package work unit for this audit, following the owner pre-commit checkpoint below. Continue the next package on the same active audit branch after those commits unless the owner directs otherwise. Do not push, open, merge, or close a pull request until the owner explicitly requests it, and do not infer that a completed package should become its own pull request.

A resumed session verifies the active branch, the last completed package commits, and whether an owner-requested base update affects audited assumptions before editing. Never infer a different target branch from a hosting service's default branch.

### 1. Audit

- restore context per Stage 0;
- discover package structure;
- perform every relevant audit stage;
- trace candidate findings across all callers, siblings, tests, docs, and upstream sources;
- prepare a complete investigation report with architecture, verified findings, rejected concerns, proposed boundaries, tests, performance, and complexity.

Do not edit source during the investigation.

### 2. Pre-implementation second opinion

Send the complete package report and proposed fixes for an independent second opinion. Work one message at a time and wait for each reply. Continue until every finding, rejection, fix boundary, test, and overengineering concern reaches consensus.

The second-opinion review thread holds proposals and discarded ideas. Add only the final consensus to the companion ledger.

### 3. Owner review

After second-opinion consensus:

- a verified defect fix may proceed to implementation and later code review without another owner checkpoint when it preserves the Laravel-facing contract, adds no measured or source-proven hot-path regression, and has a settled design;
- every Improvement-category finding must be surfaced to the owner with its meaningful practical benefit, cost, alternatives, and upstream-parity effect, and requires explicit approval before implementation;
- changing a Laravel public API, configuration key or structure, documented behavior, or conventional extension pattern is always an exceptional stop gate requiring explicit owner approval, even when motivated by a defect or broader architectural improvement;
- any change with a measured or source-proven hot-path performance regression requires explicit owner approval before implementation, even when it fixes a defect;
- any choice the evidence cannot settle returns to the owner rather than being guessed.

### 4. Update the ledger

Add the compact post-consensus package block to the companion ledger. Record final findings and important rejected concerns, not conversational history. Update this plan's routing index with the active package and every ledger entry that must be reread for it.

### 5. Implement

Implement every accepted correction at the lowest correct boundary. Update every affected package, contract, provider, facade, test, comment, and documentation surface. Remove superseded code completely.

If implementation exposes an unexpected bug, edge case, lower-level defect, same-family omission, performance problem, or design contradiction:

1. stop editing that path;
2. investigate the full cause;
3. send the cause and proposed solution through a focused second-opinion loop;
4. amend the final ledger decision;
5. implement the consensus.

### 6. Validate

- run changed test files as they are completed;
- run all focused package/cross-package tests;
- run `composer fix` for source/test changes;
- use proportionate docs checks for documentation-only changes;
- skip the full runtime gate only for a true audit-only ledger update.

### 7. Fresh self-review

Review the entire package work without trusting the plan or prior discussion. Trace:

- every changed caller and callee;
- state lifetime and mutation;
- resource acquisition, commit, and every cleanup path;
- spawn/cancel/timeout/native failure;
- test scheduling and teardown;
- API/config/documentation parity;
- hot-path cost and retained memory;
- stale/dead code and comments;
- whether multiple local changes indicate a simpler lower-level design;
- whether any new complexity has no real job.

Fix straightforward omissions. Unexpected design issues return to the focused second-opinion workflow.

### 8. Post-implementation code review

Request an independent review of the complete diff and validation. Continue until sign-off. Pre-implementation review and code review remain separate even for a small behavioral change: one verifies the diagnosis and boundary; the other verifies the actual diff.

### 9. Prepare final audit records

Update the companion-ledger work-unit block with implemented changes, cross-package revalidation, tests/gates, and review sign-off. Give it a concise work-unit heading; multiple ledger work units may later be included in one owner-selected pull request. Prepare the routing index, cross-package dependency index, and package-checklist changes in this plan. Remove wording that describes abandoned designs and do not duplicate branch, pull-request, or commit references in the audit documents.

Record the Laravel-facing result explicitly as one of: public API and configuration unchanged; only directly upstream-deprecated surface removed; or intentional divergence approved by the owner with the meaningful reason preserved.

### 10. Owner pre-commit checkpoint

After every code change is complete, all gates are green, the fresh self-review is complete, and the independent code review is signed off:

1. provide the owner with a concise, self-contained summary of the audited package or work unit;
2. cover the verified findings, implemented changes, important rejected concerns, regression coverage, validation, public/API impact, hot-path impact, and final complexity/overengineering assessment;
3. notify the owner that the work is ready for pre-commit review;
4. stop and wait for the owner to review the summary, inspect the work if desired, ask questions, request changes, or explicitly approve committing.

Do not create any source, test, documentation, ledger, or bookkeeping commit before that explicit approval. If the owner requests changes, implement them, rerun proportionate validation and review, update the summary, notify again, and wait for approval.

### 11. Commit

Commit source, tests, and documentation in as many coherent commits as useful, with detailed bodies. Make one final audit-bookkeeping commit containing the ledger entry and this plan's checklist/index updates. Do not duplicate branch, pull-request, commit, or merge references in the audit documents; repository history already owns that information. A clean audit with no executable or documentation correction has only this bookkeeping commit.

The checked box becomes authoritative when the final bookkeeping commit succeeds. Continue on the same audit branch after the owner approves the next work unit. Push only when the owner requests it, normally when the owner decides the accumulated work is ready for a pull request.

## Cross-package work units

When a finding belongs to a lower-level package or spans packages:

- record one owning finding ID and cross-reference it from every affected package;
- expand the implementation to the owner and every affected consumer/sibling;
- do not add a consumer-local workaround;
- review and implement the change as one coherent transaction;
- run focused tests for every affected package plus the full gate;
- revalidate already-completed packages whose assumptions changed;
- record bidirectional owner/consumer references in the companion ledger;
- mark a package complete only when its own full audit is complete, even if it received a cross-package fix earlier.

An exceptionally large shared work unit may receive its own linked detail plan when a compact ledger entry cannot safely explain the final design. Do not create a separate file for ordinary package findings.

## Audit routing index

This compact index routes the completed-work history that must be consulted with the full plan after compaction. Detailed history remains in the [companion ledger](2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md).

- **Active package or work unit:** None. `pagination` is complete; detail plan `2026-08-06-0928-pagination-correctness-current-parity-and-query-contracts.md`.
- **Ledger entries required for the active work:** None. The completed Pagination work is recorded under `Complete Pagination correctness, current parity, and query contracts`, with its cross-package findings also recorded at their owning package entries.
- **Pending revalidation carried into the active work:** None. The completed View audit owns `ComponentAttributeBag::data()`; Translation's `__()` conditional type remains routed to its active owner audit.

Update these three lines when a package starts, completes, or gains a cross-package dependency. Name exact work-unit headings or shared finding IDs from the companion ledger; never use “see recent entries” or require a full-ledger reread.

### Cross-package dependency index

Add one row only for a shared finding or changed lower-level assumption that another package must reread or revalidate. Ordinary package-local findings stay out of this index. Remove the `None` row when adding the first real entry.

| Finding | Owning package | Affected or revalidation packages | Ledger entry |
|---|---|---|---|
| `validation-01` | `validation` | `contracts` and `validation` (revalidation complete) | `Harden framework contracts and request-scoped state`; shared finding `validation-01` |
| `view-01` | `view` | `contracts`, `foundation`, and `view` (revalidation complete) | `Harden framework contracts and request-scoped state`; shared finding `view-01` |
| `filesystem-01` | `filesystem` | `contracts` and `filesystem` (revalidation complete) | `Harden framework contracts and request-scoped state`; shared finding `filesystem-01` |
| `queue-01` | `queue` | `contracts` and `queue` (revalidation complete) | `Harden framework contracts and request-scoped state`; shared finding `queue-01` |
| `contracts-05` | `contracts` | `http`, `foundation`, `console`, `database`, and `routing` (revalidation complete) | `Harden framework contracts and request-scoped state`; finding `contracts-05` |
| `testbench-01` | `testbench` | `foundation` (revalidation complete); later full `testbench` audit | `Restore Conditionable proxy truthiness`; shared finding `testbench-01` |
| `http-01` | `http` | `macroable` and `http` (revalidation complete), `testing`; later full `testing` audit | `Complete Macroable callable and test-state handling`; shared finding `http-01` |
| `console-01` | `console` | `contracts` and `console` (revalidation complete) | `Preserve typed console contracts during Composer scripts`; shared finding `console-01` |
| `reflection-01` | `reflection` | `events` and `foundation` (revalidation complete) | `Consolidate reflection metadata and correct callable inference`; finding `reflection-01` |
| `reflection-02` | `reflection` | `foundation`, `console`, `routing`, and `view` (revalidation complete) | `Consolidate reflection metadata and correct callable inference`; finding `reflection-02` |
| `reflection-04` | `reflection` | `di` and `queue` (revalidation complete), `support`, `testing`; later full consumer audits | `Consolidate reflection metadata and correct callable inference`; finding `reflection-04` |
| `config-01` | `config` | `foundation` (revalidation complete) | `Preserve configuration identity across worker reloads`; finding `config-01` |
| `config-02` | `foundation` | `reverb` (revalidation complete), `testing`; later full `testing` audit | `Preserve configuration identity across worker reloads`; finding `config-02` |
| `container-05` | `container` | `context` (revalidation complete) | `Coordinate shared container construction and complete current contextual resolution`; finding `container-05` |
| `container-06` | `container` | `context` (revalidation complete) | `Coordinate shared container construction and complete current contextual resolution`; finding `container-06` |
| `container-08` | `container` | `auth`, `cache`, `log`, `support`; `routing` (revalidation complete); later full consumer audits | `Coordinate shared container construction and complete current contextual resolution`; finding `container-08` |
| `container-09` | `auth`, `cache`, `log` | `container`, `auth`, `cache`, and `log` (revalidation complete) | `Coordinate shared container construction and complete current contextual resolution`; finding `container-09` |
| `container-10` | `log` | `container` and `log` (revalidation complete) | `Coordinate shared container construction and complete current contextual resolution`; finding `container-10` |
| `context-01` | `context` | `container` and `foundation` (revalidation complete) | `Correct explicit coroutine context targeting`; finding `context-01` |
| `context-04` | `context` | `foundation` and `database` (revalidation complete) | `Correct explicit coroutine context targeting`; finding `context-04` |
| `coroutine-05` | `coroutine`, `filesystem` | `filesystem` (revalidation complete) | `Make coroutine creation and copied context failure-safe`; finding `coroutine-05` |
| `coroutine-06` | `context`, `coroutine` | `concurrency` and `foundation` (revalidation complete) | `Make coroutine creation and copied context failure-safe`; finding `coroutine-06` |
| `foundation-02` | `foundation` | `coroutine` and `foundation` (revalidation complete) | `Make coroutine creation and copied context failure-safe`; finding `foundation-02` |
| `websocket-server-01` | `websocket-server` | `websocket-server` (revalidation complete) | `Make coroutine creation and copied context failure-safe`; finding `websocket-server-01` |
| `concurrency-01` | `concurrency`, `foundation`, `testbench` | `foundation` (revalidation complete); later full `testbench` audit | `Make process concurrency transport lossless and reconstruct failures safely`; finding `concurrency-01` |
| `concurrency-02` | `concurrency`, `testbench` | later full `testbench` audit | `Make process concurrency transport lossless and reconstruct failures safely`; finding `concurrency-02` |
| `concurrency-03` | `concurrency`, `foundation`, `testbench` | `foundation` (revalidation complete); later full `testbench` audit | `Make process concurrency transport lossless and reconstruct failures safely`; finding `concurrency-03` |
| `pool-01` | `pool` | `coordinator` and `pool` (revalidation complete) | `Release cleared coordinator timers deterministically`; finding `pool-01` |
| `pool-02` | `pool` | `pool` (revalidation complete) | `Release cleared coordinator timers deterministically`; finding `pool-02` |
| `pool-04` | `pool`, `database`, `redis` | `database` and `redis` (revalidation complete) | `Bound pool resources and connection progress deterministically`; finding `pool-04` |
| `pool-05` | `pool` | `database` and `redis` (revalidation complete) | `Bound pool resources and connection progress deterministically`; finding `pool-05` |
| `database-02` | `database` | `pool` and `database` (revalidation complete) | `Bound pool resources and connection progress deterministically`; finding `database-02` |
| `redis-02` | `redis` | `pool` and `redis` (revalidation complete) | `Bound pool resources and connection progress deterministically`; finding `redis-02` |
| `pool-08` | `pool`, `redis` | `redis` (revalidation complete) | `Bound pool resources and connection progress deterministically`; finding `pool-08` |
| `database-01` | `database` | `database` (revalidation complete) | `Release cleared coordinator timers deterministically`; finding `database-01` |
| `redis-01` | `redis` | `redis` (revalidation complete) | `Release cleared coordinator timers deterministically`; finding `redis-01` |
| `di-02` | `di` | `foundation` (revalidation complete); later full `sentry` and `telescope` audits | `Correct AOP proxy generation and publication`; finding `di-02` |
| `filesystem-02` | `filesystem` | `di` and `filesystem` (revalidation complete) | `Correct AOP proxy generation and publication`; finding `filesystem-02` |
| `filesystem-03` | `filesystem` | `encryption`, `support`, and `filesystem` (revalidation complete) | `Harden encryption rotation, key publication, and global lifecycle state`; finding `filesystem-03` |
| `filesystem-04` | `filesystem` | `cache` (revalidation complete) | `Harden filesystem I/O, streaming, and response teardown`; finding `filesystem-04` |
| `http-02` | `http` | `filesystem`, `foundation`, `http-server`, and `http` (revalidation complete) | `Harden filesystem I/O, streaming, and response teardown`; finding `http-02` |
| `filesystem-07` | `filesystem`, `foundation`, `http-server` | `filesystem`, `foundation`, `http-server`, and `http` (revalidation complete) | `Harden filesystem I/O, streaming, and response teardown`; finding `filesystem-07` |
| `foundation-04` | `foundation` | `filesystem`, `foundation`, and `http-server` (revalidation complete) | `Harden filesystem I/O, streaming, and response teardown`; finding `foundation-04` |
| `events-01` | `foundation` | `events` and `foundation` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `events-01` |
| `events-03` | `events`, `queue` | `queue` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `events-03` |
| `events-04` | `events`, `foundation` | `foundation` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `events-04` |
| `events-05` | `events`, `broadcasting` | `broadcasting` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `events-05` |
| `events-06` | `events`, `foundation` | `foundation` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `events-06` |
| `queue-11` | `queue` | `events`, `queue`, and `broadcasting` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `queue-11` |
| `queue-12` | `bus`, `queue` | `events`, `bus`, `queue`, and `broadcasting` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `queue-12` |
| `foundation-01` | `foundation` | `support` and `foundation` (revalidation complete) | `Correct event dispatch, queued-consumer isolation, and queue interoperability`; finding `foundation-01` |
| `support-02` | `support` | `auth` (revalidation complete), `broadcasting` (revalidation complete), `bus` (revalidation complete), `cache` (revalidation complete), `concurrency`, `console` (revalidation complete), `container`, `contracts`, `cookie`, `database` (revalidation complete), `events`, `filesystem` (revalidation complete), `foundation` (revalidation complete), `hashing` (revalidation complete), `horizon` (revalidation complete), `inertia`, `jwt`, `log`, `mail`, `notifications` (revalidation complete), `permission`, `pipeline`, `queue` (revalidation complete), `redis` (revalidation complete), `reverb` (revalidation complete), `routing` (revalidation complete), `sanctum`, `scout`, `session` (revalidation complete), `socialite`, `telescope`, `testbench`, `translation`; later full consumer audits | `Normalize framework enum identifiers at string boundaries`; finding `support-02`; sibling findings `translation-01` and `reverb-03`; linked detail plan `2026-07-15-0920-framework-enum-identifier-contracts.md` |
| `macroable-03` | `macroable` | `cookie`, `log`, and `notifications` (revalidation complete); later full `jwt` audit | `Complete Macroable callable and test-state handling`; finding `macroable-03` |
| `auth-01` | `support`, `auth` | `auth` (revalidation complete) | `Correct Support utility boundaries and authentication timing isolation`; finding `auth-01` |
| `encryption-03` | `encryption` | `contracts`, `support`, `filesystem`, and `foundation` (revalidation complete) | `Harden encryption rotation, key publication, and global lifecycle state`; finding `encryption-03` |
| `sanctum-01` | `sanctum` | `encryption`; later full `sanctum` audit | `Harden encryption rotation, key publication, and global lifecycle state`; finding `sanctum-01` |
| `process-02` | `process` | `concurrency` (revalidation complete) | `Make Process callbacks and pools failure-safe`; finding `process-02` |
| `server-process-10` | `server-process` | `foundation` (revalidation complete) | `Make custom server processes failure-safe`; finding `server-process-10` |
| `signal-05` | `contracts`, `signal` | `server-process` (revalidation complete) | `Complete Signal handler reliability, public APIs, and deployment guidance`; finding `signal-05` |
| `server-11` | `foundation`, `server` | `server-process` and `reverb` (revalidation complete) | `Complete Signal handler reliability, public APIs, and deployment guidance`; finding `server-11` |
| `bus-03` | `bus`, `contracts`, `foundation` | `foundation` and `queue` (revalidation complete) | `Make Bus dispatch, batches, and unique payloads lifecycle-safe`; finding `bus-03` |
| `bus-10` | `bus`, `queue` | `queue` (revalidation complete) | `Make Bus dispatch, batches, and unique payloads lifecycle-safe`; finding `bus-10` |
| `bus-17` | `bus`, `foundation`, `queue`, `testing` | `log`, `foundation`, and `queue` (revalidation complete); later full `testing` audit | `Make Bus dispatch, batches, and unique payloads lifecycle-safe`; finding `bus-17` |
| `bus-18` | `foundation`, `queue` | `foundation` and `queue` (revalidation complete) | `Make Bus dispatch, batches, and unique payloads lifecycle-safe`; finding `bus-18` |
| `core-01` | `core`, `foundation` | `foundation` (revalidation complete) | `Harden Core lifecycle callbacks and stdout logging`; finding `core-01` |
| `core-05` | `core`, `foundation` | `foundation` (revalidation complete) | `Harden Core lifecycle callbacks and stdout logging`; finding `core-05` |
| `core-06` | `core`, `server` | `server` (revalidation complete) | `Harden Core lifecycle callbacks and stdout logging`; finding `core-06` |
| `http-server-03` | `http-server`, `filesystem`, `http`, `foundation` | `context`, `contracts`, `engine`, `http`, and `testing` (revalidation complete); later full `testing` audit | `Unify HTTP response emission and harden native server boundaries`; finding `http-server-03` |
| `http-server-05` | `testing` | `http-server` (revalidation complete); later full `testing` audit | `Unify HTTP response emission and harden native server boundaries`; finding `http-server-05` |
| `http-server-06` | `http-server` | `reverb`, `websocket-server`, and `grpc` (revalidation complete) | `Unify HTTP response emission and harden native server boundaries`; finding `http-server-06` |
| `http-server-07` | `http-server` | `grpc` (revalidation complete) | `Unify HTTP response emission and harden native server boundaries`; finding `http-server-07` |
| `http-server-08` | `http-server`, `foundation` | `grpc` (revalidation complete) | `Unify HTTP response emission and harden native server boundaries`; finding `http-server-08` |
| `foundation-06` | `foundation`, `testbench` | `foundation` (revalidation complete); later full `testbench` audit | `Complete Foundation runtime lifecycles and safe publication`; finding `foundation-06` |
| `console-02` | `console` | `foundation` and `console` (revalidation complete) | `Complete Foundation runtime lifecycles and safe publication`; finding `console-02` |
| `queue-14` | `foundation`, `queue` | `foundation` and `queue` (revalidation complete) | `Complete Foundation runtime lifecycles and safe publication`; finding `queue-14` |
| `http-03` | `http`, `foundation` | `contracts`, `foundation`, and `http` (revalidation complete) | `Complete Foundation runtime lifecycles and safe publication`; finding `http-03` |
| `auth-02` | `auth` | `foundation` and `auth` (revalidation complete) | `Complete Foundation runtime lifecycles and safe publication`; finding `auth-02` |
| `auth-12` | `auth` | `fortify` (revalidation complete); later full `fortify` audit | `Complete Auth correctness, lifecycle, and current parity`; finding `auth-12` |
| `database-03` | `database` | `foundation` and `database` (revalidation complete); later full `testbench` audit | `Complete Foundation runtime lifecycles and safe publication`; finding `database-03` |
| `foundation-17` | `foundation` | `foundation` and `scout` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; finding `foundation-17` |
| `foundation-18` | `foundation` | `foundation` and `scout` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; finding `foundation-18` |
| `database-04` | `database` | `console` and `database` (revalidation complete) | `Complete Console command, scheduling, and generator lifecycles`; finding `database-04` |
| `reverb-04` | `reverb` | `reverb` (revalidation complete) | `Complete Console command, scheduling, and generator lifecycles`; finding `reverb-04` |
| `watcher-10` | `support` | `watcher`, `foundation`, and `horizon` (revalidation complete) | `Make Watcher drivers and managed processes lifecycle-safe`; finding `watcher-10` |
| `database-05` | `core`, `database` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `database-05`; sibling finding `redis-03` |
| `database-06` | `core`, `server`, `database` | `server` and `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `database-06`; sibling finding `redis-05` |
| `database-08` | `database` | `foundation`, `testing`, and `testbench` (revalidation complete); later full `testing` and `testbench` audits | `Complete Database persistence lifecycles and current Laravel parity`; finding `database-08` |
| `database-10` | `database` | `scout` and `nested-set` (revalidation complete); later full consumer audits | `Complete Database persistence lifecycles and current Laravel parity`; finding `database-10` |
| `database-14` | `database` | `queue` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `database-14` |
| `redis-03` | `redis` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `redis-03` |
| `redis-04` | `redis` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `redis-04` |
| `redis-05` | `redis`, `core`, `server` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `redis-05` |
| `redis-06` | `redis` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `redis-06` |
| `redis-07` | `redis` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `redis-07` |
| `redis-08` | `redis`, `pool` | `redis` (revalidation complete) | `Complete Database persistence lifecycles and current Laravel parity`; finding `redis-08` |
| `redis-09` | `redis` | `cache` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `redis-09` |
| `redis-10` | `redis` | `reverb` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `redis-10` |
| `redis-11` | `redis` | `reverb` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `redis-11` |
| `redis-12` | `redis`, `cache` | `redis` and `cache` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `redis-12` |
| `redis-13` | `redis` | `horizon`, `cache`, `queue`, `session`, and `broadcasting` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `redis-13` |
| `redis-21` | `redis` | `queue` (revalidation complete) | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `redis-21` |
| `redis-22` | `redis` | `queue` and `support` (revalidation complete) | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `redis-22` |
| `reverb-05` | `reverb` | `redis` and `reverb` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `reverb-05` |
| `redis-15` | `redis` | `telescope` and `sentry` (revalidation complete); later full consumer audits | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `redis-15` |
| `horizon-01` | `horizon` | `redis` and `horizon` (revalidation complete) | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `horizon-01` |
| `telescope-01` | `telescope` | `redis` (revalidation complete); later full `telescope` audit | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `telescope-01` |
| `telescope-02` | `telescope` | `redis` (revalidation complete); later full `telescope` audit | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `telescope-02` |
| `sentry-01` | `sentry` | `redis` (revalidation complete); later full `sentry` audit | `Complete Redis pooling, subscriber transport, topology, parity, and lifecycle safety`; finding `sentry-01` |
| `cache-04` | `cache` | `auth` (full-audit revalidation complete), `sanctum` and `testbench` (revalidation complete); later full remaining consumer audits | `Complete Cache parity, cleanup, permanence, and tagged ownership`; finding `cache-04` |
| `filesystem-12` | `filesystem` | `session` (revalidation complete) | `Complete Session lifecycles, persistence, and current Laravel parity`; finding `filesystem-12` |
| `session-23` | `cache` | `session` (revalidation complete) | `Complete Session lifecycles, persistence, and current Laravel parity`; finding `session-23` |
| `contracts-09` | `contracts` | `foundation` and `broadcasting` (revalidation complete) | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `contracts-09` |
| `notifications-07` | `contracts` | `notifications` (revalidation complete) | `Harden framework contracts and request-scoped state`; finding `notifications-07` |
| `notifications-12` | `notifications` | `horizon` and `notifications` (revalidation complete) | `Complete Notifications correctness, Slack parity, and reentrant failure ownership`; finding `notifications-12` |
| `queue-22` | `queue` | `horizon` (revalidation complete); later full `telescope` audit | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `queue-22` |
| `queue-29` | `queue` | `foundation` (revalidation complete) | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `queue-29` |
| `queue-36` | `queue`, `support` | `support` (revalidation complete) | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `queue-36` |
| `queue-37` | `queue`, `support` | `support` (revalidation complete) | `Complete Queue pooling, payload durability, and current Laravel parity`; finding `queue-37` |
| `queue-40` | `queue` | `queue` and `horizon` (revalidation complete) | `Complete Horizon cluster, process, publication, and current Laravel parity`; finding `queue-40` |
| `redis-23` | `redis` | `redis` and `horizon` (revalidation complete) | `Complete Horizon cluster, process, publication, and current Laravel parity`; finding `redis-23` |
| `telescope-03` | `telescope` | `telescope` (targeted correction complete); later full `telescope` audit | `Complete Horizon cluster, process, publication, and current Laravel parity`; finding `telescope-03` |
| `fortify-01` | `fortify` | `fortify` (targeted correction complete); later full `fortify` audit | `Complete Horizon cluster, process, publication, and current Laravel parity`; finding `fortify-01` |
| `reverb-06` | `reverb` | `reverb` (revalidation complete) | `Complete Horizon cluster, process, publication, and current Laravel parity`; finding `reverb-06` |
| `cache-11` | `cache` | `cache` and `reverb` (revalidation complete) | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `cache-11` |
| `cache-20` | `cache` | `cache` and `reverb` (revalidation complete) | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `cache-20` |
| `reverb-24` | `reverb` | `foundation` and `reverb` (revalidation complete) | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `reverb-24` |
| `server-10` | `server` | `server` and `reverb` (revalidation complete) | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `server-10` |
| `grpc-01` | `grpc` | `reverb` and `grpc` (revalidation complete) | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `grpc-01` |
| `boost-01` | `boost` | `grpc` (revalidation complete); later full `boost` audit | `Fix gRPC terminal and response boundaries`; finding `boost-01` |
| `websocket-server-13` | `websocket-server` | `websocket-server` (revalidation complete); Reverb path confirmed unaffected | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `websocket-server-13` |
| `testbench-02` | `testbench` | `testbench` (targeted correction complete); later full `testbench` audit | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `testbench-02` |
| `support-27` | `support` | `support` and `websocket-server` (revalidation complete); Reverb path confirmed unaffected | `Complete Reverb connection, shared-state, and current Laravel parity lifecycles`; finding `support-27` |
| `nested-set-13` | `nested-set` | `testing` (revalidation complete); later full `testing` audit | `Complete Nested Set invariants, performance, and modern APIs`; finding `nested-set-13` |
| `database-15` | `database` | `database` and `testing` (targeted correction complete); later full `testing` audit | `Harden Eloquent identity and partial-projection safety`; finding `database-15` |
| `database-16` | `database` | `database` (targeted correction complete) | `Harden Eloquent identity and partial-projection safety`; finding `database-16` |
| `database-17` | `database` | `database` (targeted correction complete) | `Harden Eloquent identity and partial-projection safety`; finding `database-17` |
| `database-18` | `database` | `database` and `queue` (targeted correction complete) | `Harden Eloquent identity and partial-projection safety`; finding `database-18` |
| `database-19` | `database` | `database` (targeted correction complete) | `Harden Eloquent identity and partial-projection safety`; finding `database-19` |
| `database-20` | `database` | `database` (targeted correction complete) | `Harden Eloquent identity and partial-projection safety`; finding `database-20` |
| `permission-01` | `permission` | `permission` (targeted correction complete); later full `permission` audit | `Harden Eloquent identity and partial-projection safety`; finding `permission-01` |
| `permission-02` | `permission` | `permission` (targeted correction complete); later full `permission` audit | `Harden Eloquent identity and partial-projection safety`; finding `permission-02` |
| `permission-03` | `permission` | `permission` (targeted correction complete); later full `permission` audit | `Harden Eloquent identity and partial-projection safety`; finding `permission-03` |
| `permission-04` | `permission` | `permission` (targeted correction complete); later full `permission` audit | `Harden Eloquent identity and partial-projection safety`; finding `permission-04` |
| `permission-05` | `permission` | `permission` (targeted correction complete); later full `permission` audit | `Harden Eloquent identity and partial-projection safety`; finding `permission-05` |
| `fortify-02` | `fortify` | `fortify` (targeted correction complete); later full `fortify` audit | `Harden Eloquent identity and partial-projection safety`; finding `fortify-02` |
| `pagination-01` | `pagination` | `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `pagination-01` |
| `pagination-02` | `pagination` | `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `pagination-02` |
| `collections-15` | `collections` | `collections` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `collections-15` |
| `support-32` | `support` | `support` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `support-32` |
| `support-33` | `support` | `support` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `support-33` |
| `sanctum-02` | `sanctum` | `sanctum` (targeted correction complete); later full `sanctum` audit | `Complete Pagination correctness, current parity, and query contracts`; finding `sanctum-02` |
| `api-client-01` | `api-client` | `api-client` (targeted correction complete); later full `api-client` audit | `Complete Pagination correctness, current parity, and query contracts`; finding `api-client-01` |
| `database-24` | `database` | `database` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `database-24` |
| `database-25` | `database` | `database` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `database-25` |
| `scout-41` | `scout` | `scout` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `scout-41` |
| `routing-25` | `routing` | `routing` and `pagination` (revalidation complete) | `Complete Pagination correctness, current parity, and query contracts`; finding `routing-25` |
| `queue-41` | `database`, `queue` | `database`, `queue`, and `notifications` (revalidation complete) | `Harden Eloquent identity and partial-projection safety`; finding `queue-41` |
| `scout-01` | `scout` | `scout` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; carried finding `scout-01` |
| `scout-02` | `scout` | `scout` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; carried finding `scout-02` |
| `notifications-08` | `notifications` | `notifications` (revalidation complete) | `Harden Eloquent identity and partial-projection safety`; finding `notifications-08` |
| `http-04` | `http` | `http` (revalidation complete) | `Harden Eloquent identity and partial-projection safety`; finding `http-04` |
| `http-05` | `http` | `http` (revalidation complete) | `Harden Eloquent identity and partial-projection safety`; finding `http-05` |
| `http-06` | `http` | `http` (revalidation complete) | `Harden Eloquent identity and partial-projection safety`; finding `http-06` |
| `testing-01` | `testing` | `testing` (targeted correction complete); later full `testing` audit | `Harden Eloquent identity and partial-projection safety`; finding `testing-01` |
| `testing-02` | `testing` | `testing` (targeted correction complete); later full `testing` audit | `Harden Eloquent identity and partial-projection safety`; finding `testing-02` |
| `routing-01` | `contracts`, `foundation`, `routing`, `support` | `contracts`, `foundation`, `routing`, `support`, and `http` (revalidation complete) | `Complete HTTP correctness, JSON:API, and current Laravel parity`; finding `routing-01` |
| `testbench-03` | `testbench` | `http` (revalidation complete); later full `testbench` audit | `Complete HTTP correctness, JSON:API, and current Laravel parity`; finding `testbench-03` |
| `database-21` | `database` | `database` and `scout` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; finding `database-21` |
| `database-22` | `database` | `database` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; finding `database-22` |
| `database-23` | `database` | `database` and `scout` (revalidation complete) | `Complete Scout current parity, queue, and search lifecycles`; finding `database-23` |
| `mail-17` | `mail` | `mail` and `support` (revalidation complete) | `Complete Mail correctness, current parity, and package boundaries`; finding `mail-17` |
| `support-28` | `support` | `support` and `mail` (revalidation complete) | `Complete Mail correctness, current parity, and package boundaries`; finding `support-28` |
| `support-29` | `support` | `support`, `mail`, and `validation` (revalidation complete) | `Complete Mail correctness, current parity, and package boundaries`; finding `support-29` |
| `contracts-10` | `contracts` | `contracts` and `mail` (revalidation complete) | `Complete Mail correctness, current parity, and package boundaries`; finding `contracts-10` |
| `contracts-11` | `contracts` | `contracts`, `mail`, and `console` (revalidation complete) | `Complete Mail correctness, current parity, and package boundaries`; finding `contracts-11` |
| `filesystem-14` | `filesystem` | `filesystem` and `mail` (revalidation complete) | `Complete Mail correctness, current parity, and package boundaries`; finding `filesystem-14` |
| `routing-04` | `routing` | `wayfinder` (targeted correction complete); later full `wayfinder` audit | `Complete Routing correctness, current parity, and cache lifecycles`; finding `routing-04` |
| `redis-24` | `redis` | `redis` and `routing` (revalidation complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `redis-24` |
| `routing-12` | `routing` | `encryption` (targeted correction complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `routing-12` |
| `routing-18` | `routing` | `auth` (revalidation complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `routing-18` |
| `collections-14` | `collections` | `collections` and `routing` (revalidation complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `collections-14` |
| `validation-18` | `validation` | `validation` and `support` (revalidation complete) | `Complete Validation correctness, parity, and compiled lifecycles`; finding `validation-18` |
| `view-09` | `foundation` | `foundation` and `view` (revalidation complete) | `Complete View correctness, lifecycle, and current parity`; finding `view-09` |
| `view-24` | `foundation` | `foundation` and `view` (revalidation complete) | `Complete View correctness, lifecycle, and current parity`; finding `view-24` |
| `view-37` | `view` | `view` (revalidation complete), `foundation`, `testbench`, and `testing` (targeted corrections complete); later full `testbench` and `testing` audits | `Complete View correctness, lifecycle, and current parity`; finding `view-37` |
| `view-38` | `view` | `view` (revalidation complete), `boost` (targeted correction complete); later full `boost` audit | `Complete View correctness, lifecycle, and current parity`; finding `view-38` |
| `translation-10` | `translation` | `view` (sibling revalidation complete); later full `translation` audit | `Complete View correctness, lifecycle, and current parity`; finding `translation-10` |

## Package checklist

The checklist mirrors every first-level directory under `src/`. Before plan sign-off and whenever packages are added or removed, generate the sorted directory set from `src/`, extract the sorted package names below, and compare both sets. The counts and names must match, with no duplicates.

```bash
for package_dir in src/*; do test -d "$package_dir" && basename "$package_dir"; done \
    | sort > /tmp/hypervel-src-packages
grep '^- \[[ x]\] `' docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md \
    | cut -d'`' -f2 \
    | sort > /tmp/hypervel-plan-packages
wc -l /tmp/hypervel-src-packages /tmp/hypervel-plan-packages
comm -3 /tmp/hypervel-src-packages /tmp/hypervel-plan-packages
uniq -d /tmp/hypervel-plan-packages
```

The current expected result is `72` lines in each file, with no output from `comm` or `uniq`. Update the expected count when the package set changes.

The order is lower-level first where practical. Hypervel has cross-cutting dependencies and facades, so this is not claimed to be a perfect dependency DAG. The cross-package revalidation rule handles remaining inversions.

### Core semantics and long-lived state

- [x] `contracts`
- [x] `conditionable`
- [x] `macroable`
- [x] `collections`
- [x] `reflection`
- [x] `config`
- [x] `container`
- [x] `context`
- [x] `di`
- [x] `events`
- [x] `log`
- [x] `support`

### Security and request primitives

- [x] `encryption`
- [x] `hashing`
- [x] `cookie`

### Coroutine and resource infrastructure

- [x] `engine`
- [x] `coroutine`
- [x] `concurrency`
- [x] `coordinator`
- [x] `signal`
- [x] `pool`
- [x] `object-pool`
- [x] `process`
- [x] `server-process`
- [x] `filesystem`

### Framework dispatch and runtime

- [x] `pipeline`
- [x] `bus`
- [x] `core`
- [x] `foundation`
- [x] `console`
- [x] `server`
- [x] `http-server`
- [x] `websocket-server`
- [x] `watcher`

### Persistence, transport, and background execution

- [x] `database`
- [x] `redis`
- [x] `cache`
- [x] `session`
- [x] `queue`
- [x] `horizon`
- [x] `reverb`
- [x] `http`
- [ ] `api-client`
- [x] `grpc`
- [x] `broadcasting`
- [x] `mail`
- [x] `notifications`

### Application and domain packages

- [x] `auth`
- [x] `validation`
- [x] `routing`
- [x] `view`
- [ ] `translation`
- [x] `pagination`
- [ ] `socialite`
- [ ] `sanctum`
- [ ] `fortify`
- [ ] `passkeys`
- [ ] `permission`
- [ ] `jwt`
- [x] `scout`
- [ ] `telescope`
- [ ] `sentry`
- [ ] `inertia`
- [x] `nested-set`
- [ ] `json-schema`

### Tooling and developer surfaces

- [ ] `testing`
- [ ] `testbench`
- [ ] `prompts`
- [ ] `tinker`
- [ ] `boost`
- [ ] `facade-documenter`
- [ ] `wayfinder`

## Plan maintenance and audit completion

### Before each package

- confirm the previous work unit is committed and the owner has authorized continuing on the active audit branch;
- set the active-package routing fields and list every exact ledger entry required for the work;
- read the current package's existing companion-ledger entry, if any, plus only the cross-referenced entries named by the routing/dependency indexes;
- check whether a completed lower-level package changed assumptions used here;
- inspect relevant upstream/differences notes, not README boilerplate;
- create a detailed working checklist for the package’s actual files and audit stages.

### Before checking a package off

- every package source area and central flow was read;
- all broad repository searches and relevant hits were traced;
- candidate findings were verified or rejected;
- second-opinion consensus was reached before code changes;
- final consensus was recorded in the companion ledger;
- accepted changes and same-family corrections are complete;
- obsolete code, tests, comments, config, and docs are removed;
- changed tests passed immediately;
- required focused and full gates are green;
- fresh self-review found no unresolved issue;
- code review is signed off;
- every accepted hot-path regression, if any, received explicit owner approval before implementation;
- the owner reviewed the post-sign-off summary and explicitly approved committing;
- the final bookkeeping commit succeeded;
- routing and dependency indexes reflect the next active work and every pending revalidation;
- any affected completed package was revalidated and its companion-ledger entry amended.

### Framework-wide completion

The audit is complete only when:

- all package checkboxes are checked;
- the generated `src/*` package set still matches the checklist exactly;
- every cross-package revalidation is closed;
- no companion-ledger entry contains an unresolved accepted defect;
- all plan-linked detail work is complete and merged into final decisions;
- the final codebase and documentation contain no stale description of replaced behavior;
- a final framework-wide review checks recurring patterns across all package results;
- `composer fix` passes at the final repository state.
