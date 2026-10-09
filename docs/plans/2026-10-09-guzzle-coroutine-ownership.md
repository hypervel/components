# Guzzle coroutine ownership

## Objective

Prevent Guzzle callbacks and native transfers from running in another request's coroutine, without replacing Guzzle's scheduler or maintaining a dependency fork. Preserve normal framework concurrency, request context, tenant isolation, transaction ownership, and bounded resource lifetimes.

## Why this change is needed

Guzzle's process-wide task queue can execute one request's promise callbacks when an unrelated coroutine drains it. This includes middleware and event listeners belonging to a synchronous HTTP operation, not only application-created async callbacks. Request-scoped services can then resolve another request's state or use the wrong database transaction.

A shared `CurlMultiHandler` also allows one coroutine to drive native transfers belonging to another. This can crash a Swoole worker. Separately pooled SDK clients do not prevent a shared custom credential-provider object from reusing its own in-flight HTTP work. The cached default Pusher/Reverb client has a similar problem through its public async methods.

Use Guzzle's supported `Utils::queue()` setter for queue isolation. Guzzle supplies no equivalent global hook for construction and operations on its mutable promises or admission and driving of its multi-handler. Client subclasses and outer middleware cannot cover promises and middleware created inside dependencies. Narrow AOP interception is therefore needed at those public method boundaries.

Do not use a maintained fork, Composer/vendor patch, private-member access, copied promise implementation, custom scheduler, context impersonation, or framework-wide wait pumping. Copying request context into a new coroutine does not reproduce ownership of transaction and other native resources.

## Ownership contract

- A mutable `GuzzleHttp\Promise\Promise` belongs to the native coroutine that constructs it, including the non-coroutine context outside Swoole coroutines.
- Pending work is created, driven, settled, and canceled by its owner. Another coroutine is refused before it can execute foreign callbacks, drive native work, or mutate pending state.
- Completed results remain reusable across coroutines. A promise reported as fulfilled because it adopted another pending promise is not yet a completed result.
- Each coroutine has its own stock Guzzle task queue. Queue and transfer state implement `NonCopyableContext`, so `fork()`, `forkOwned()`, and `parallel(copyContext: true)` cannot inherit them. Outside coroutines, retain normal Guzzle queue behavior, including shutdown draining.
- At coroutine exit, cancel unfinished native transfers and drain the owner's stock queue so cancellations reach dependent promises and reset memoized state. Repeat for transfers created by callbacks until both are empty. Callbacks stay in their owner; do not wait for abandoned HTTP work to succeed. A retained pending promise cannot later be driven by another request; its owner must finish the work before exiting.
- Framework concurrency remains available through native coroutines and `parallel()`: create and finish each operation in the child that owns it, then share completed results. Preserve ordinary sequential reuse of pooled clients and completed credential promises.
- These guarantees cover Guzzle's mutable promises and cURL multi transport. Do not claim arbitrary third-party `PromiseInterface` implementations or custom transports acquire ownership automatically.

## Production structure and lifecycle

### Shared Guzzle integration

Place the integration under `Hypervel\Http\Client\Guzzle`, with aspects in its `Aspects` directory and a focused ownership exception under the HTTP package's exceptions. One `NonCopyableContext` holder owns the coroutine queue and active transfers, with one exit hook registered on first use of either. Use `Hypervel\Coroutine\Coroutine::defer()` so an escaping task failure cannot abort earlier resource defers; cancel remaining transfers in `finally`. Keep queue delegation and weak ownership bookkeeping separate.

Register the aspects and queue integration through `HttpServiceProvider`, already part of `DefaultProviders` and package discovery. This must work independently of Sentry and Telescope, in HTTP requests, queue workers, and console commands. Add `hypervel/di` to the HTTP package's direct dependencies for its aspects; do not put Guzzle dependencies into Engine or generic coroutine primitives.

Register aspect rules before `GenerateProxies`, which runs between provider registration and boot. Install the queue before framework code can schedule Guzzle work. An already-loaded vendor class cannot be replaced, so bootstrap ordering must guarantee interception or fail with an actionable error. Cover cold boot, cached proxies, repeated Testbench applications, and provider boot-time use.

At `GenerateProxies`, reject a target already loaded from its original source before installing the generated class map. Accept an already-loaded generated proxy only when its rewritten methods cover the current rules. Apply this to all actual aspect targets, including the existing Sentry/Telescope Client aspects. PHP cannot replace a loaded class. The error must identify the class and explain lazy registration: bind a client factory in `register()` and instantiate after proxy generation, rather than eagerly loading the client there. Document this existing AOP loading requirement where the new built-in integration makes it relevant. Application-defined aspects have the same requirement in tests: avoid loading their targets in app-less tests before registration, or exercise them through application tests with the aspect registered before first use. Test that the early-load error names the class and provides this guidance, normal lazy registration works, and repeated compatible proxy boots remain valid.

Have `ProxyCallVisitor` mark each method it actually rewrites with a framework-owned method attribute, such as `ProxyMethod`. At bootstrap, inspect this metadata on the loaded class without instantiating attributes; verify the concrete source methods selected by the current rules are covered. Exclude generated original-body helpers from that comparison. This records the loaded code's capabilities directly, without a separate mutable registry or using the file's process-wide fingerprint as a substitute. No per-call checks are needed. Preserve class-wide and wildcard behavior, and combine explicit constructor rules with class-wide rules so an application aspect cannot remove the ownership constructor interception. Test same/subset method requirements, additional required methods failing loudly, unrelated aspect changes, and mixed class/explicit-constructor rules.

Use the same class-only method exclusions for rewriting and runtime aspect selection. An explicit constructor rule makes the constructor interceptable without opting unrelated whole-class aspects into it. Exact constructor rules and explicit method wildcards keep their existing behavior.

Queue installation is idempotent: if `Utils::queue()` already returns the integration queue, retain it. Otherwise wrap its current queue as the non-coroutine delegate, preserving existing tasks and shutdown behavior. Do not create another shutdown-enabled `TaskQueue` on every application boot: its permanent shutdown closure retains it. Coroutine queues disable shutdown draining. Test repeated application boots preserve the same integration queue and outside delegate.

Use existing context, cancellation, and test-reset primitives. Aspects are worker-safe services; all invocation state belongs in local variables or the owning coroutine. Ownership metadata is weakly keyed, with strong retention only for currently active transfers. Reset behavior must preserve already-generated proxies while clearing test-owned state; it must not erase live production ownership.

Provide `flushState()` for static ownership bookkeeping, registered in `AfterEachTestSubscriber`'s framework cleanup. Cancel the current holder's remaining test-owned non-coroutine transfers before clearing ownership, aspect, or container state; do not execute abandoned outside-coroutine application callbacks during test reset. The existing context flush releases the holder. Keep queue installation idempotent across resets. Tests must join their coroutines and finish their queue work before teardown rather than rely on cleanup to run application code.

Cancellation itself queues rejection handlers, so the test reset must also discard remaining outside-coroutine tasks. At the start of `AfterEachTestExtension::bootstrap()`, before test-state registrar discovery or integration installation, seed `Utils::queue()` with a shutdown-disabled stock `TaskQueue`; the integration retains this as its outside delegate. Use this shared extension, not components' `tests/bootstrap.php`, so application and split-package suites get the same behavior. At test reset, replace that delegate with a fresh shutdown-disabled queue after cancellation. No old shutdown closure then retains its callbacks. Do not merely replace a shutdown-enabled delegate: its original shutdown callback still retains and drains the old queue. Production continues to wrap the existing queue unchanged; test normal process-shutdown behavior in isolated processes using production bootstrap.

Also use `AfterEachTestExtension::bootstrap()` to generate the relevant Guzzle proxy files before test discovery can load their classes. Store them in a per-worker directory from `ParallelTesting::tempDir()`, retained for that process and cleaned at shutdown. Cover only framework-owned aspects whose packages are installed: HTTP's Promise and multi-handler aspects, plus Sentry/Telescope's Client aspects when present. Generate from the union of their declared method rules without enabling optional observability at runtime or adding dependencies on optional packages. Restore pre-existing aspect rules afterward, and clear the visitor registry if it was empty before generation, so the first test has no extra registrations. Share aspect-default extraction with provider registration. Later application boots accept these already-loaded generated classes when their required methods are covered; test applications with and without optional instrumentation, verifying each aspect runs only when registered. Testbench providers activate their aspects normally; guard-dependent tests verify both proxy generation and active ownership interception. Keep clean-process bootstrap/error tests isolated. Adapt existing manual aspect tests to call the generated proxy directly where appropriate, avoiding double interception. Verify the shared extension through both components and package-mode tests; do not accept suite-order-dependent unproxied execution as validation.

### HTTP connection options

Named HTTP connections must retain their handler options on async requests. Add `Factory::newConnectionHandler()` as the uncached counterpart of `getConnectionHandler()`, sharing option derivation and the existing protected construction method. Async requests use a fresh handler with registered transport settings; sync requests retain the cached handler. Per-call preset replacement changes request options, not registered transport policy. Preserve explicit client/handler bypasses. Extend existing connection tests for async option forwarding, isolation and preset replacement, and clarify this in the HTTP client guide.

### Promise interception

Intercept only `Promise::__construct`, `then`, `wait`, `cancel`, `resolve`, and `reject`.

- Record ownership after successful construction.
- Before an operation, check the caller against the owner only where pending work or an unresolved adoption remains. Keep Guzzle's native behavior and errors for completed operations.
- Before accepting a pending adopted promise, verify its ownership. Record adoption only after the underlying settlement succeeds; a rejected self-resolution must not leave a self-reference or stale bookkeeping.
- Determine completion through adopted promises, including chains. Preserve fulfillment, rejection, callback ordering, cancellation, `otherwise()`, and `wait(false)` behavior within the owner.
- Release transfer accounting when its native transfer promise settles or cancels. Do not retain every completed promise for the worker lifetime.

### Transport interception and retention

Intercept `CurlMultiHandler::__invoke`, `tick`, `execute`, and `close` where provided by the installed supported version.

- Reserve the handler for the current owner **before** invoking request preparation, because reading an upload body can yield. Same-owner overlapping transfers share the owner and an active count.
- Convert a successful pending transfer into active-transfer accounting. Release the reservation on a preparation exception or an immediately settled result.
- Reject a different owner attempting admission, driving, or closing while reservations or transfers remain. Release ownership after the final transfer completes, allowing sequential reuse.
- Use one release path so success, rejection, cancellation, and preparation failure cannot diverge in their accounting.
- Keep each coroutine's active transfer promises alive until settlement or exit cancellation. This prevents another coroutine's garbage collection from destroying a native multi handle while its owner is still using it.
- Retain non-coroutine transfers until settlement or process shutdown. Do not allow a request's garbage collection to destroy native handles created outside coroutines.
- Bound retention by outstanding work, not total requests served. Do not introduce a worker-wide strong registry of every promise, client, or callback.

### Shared AWS credential providers

Use one shared wrapper under `Hypervel\Support\Aws` for user-supplied callable credential providers in SQS, S3, and SES v2. These packages already consume Support; do not make filesystem or mail depend on queue. Declare direct coroutine/engine dependencies used by the wrapper in the split-package metadata.

- Serialize provider invocation **and its promise wait** with the existing `Locker`, using the provider's underlying callable identity across separate wrappers and consumers. Bound-object methods share their object's lock; invokable-object and `[$object, '__invoke']` forms converge. Normalize named function/static callable identities without retaining an unbounded lookup registry.
- Each caller invokes the provider in its own context and gets its own completed result or rejection. No extra credential cache, failure cache, leader-result broadcasting, or tenant-result sharing.
- Acquire until `Locker::lock()` grants ownership; always unlock in `finally`. Preserve the original cancellation exception. Where AWS translates a canceled fetch into its own exception, restore cancellation only when the coroutine is actually canceled, preserving the SDK exception as the cause.
- Preserve explicit SDK memoization and SQS `AwsCredentialCache` behavior. Framework-built per-client provider chains remain per-client. Wrap effective custom providers at the client construction boundary after configuration precedence is resolved; preserve arrays, anonymous credentials, and supported provider selectors.
- Preserve pool identity: derive pool definitions and fingerprints from the original construction config before wrapping the provider. For S3, wrap in `createS3Client()`, not `s3ClientConfig()`, which also supplies the fingerprint. Verify equivalent SQS/S3/SES configurations with the same provider and explicit `pool.fingerprint` continue to share a pool.
- Do not infer aliases between distinct closures hiding the same provider. Do not change default pooling or broadly replace SDK handlers.

### Pusher and Reverb

Update the default client constructed by `BroadcastManager::pusher()` so synchronous requests retain reusable low-level transport and asynchronous requests share a handler only within the same client and native coroutine. Reverb uses the same construction path. Preserve same-coroutine promise fan-out and connection reuse; per-transfer handlers silently serialize that supported work.

- Build a normal Guzzle handler stack around this dispatch choice; preserve middleware behavior.
- Lazily store async handlers in one broadcasting-owned `NonCopyableContext` holder containing a `WeakMap`, keyed by the client's stable shared-handler closure. Use a fixed coroutine-context key, not a worker-wide registry. Children copying context get their own holder; weak keys release handlers with their clients, and coroutine exit releases the holder. Transfer cancellation must run before context destruction. Outside-coroutine storage retains ordinary reuse and the existing context test reset.
- In the default path, move `transport_sharing` from client options to handler construction because Hypervel now supplies the handler. Pass `multiplex` to handler construction and preserve its request default.
- Preserve timeouts and caller callbacks such as `on_stats`. When the user supplies a custom handler, bypass the default-handler adaptation and pass its options unchanged to Guzzle, preserving Guzzle's own validation. Custom-handler ownership remains the caller's responsibility under the global guard.
- In the default-handler path, reject `max_host_connections` and `max_total_connections` with an actionable broadcasting-specific explanation: bound coroutine concurrency or rate limit instead of sharing one multi-handler across requests. Do not import HTTP's entire reserved-options policy; its unrelated cookie/handler/pool restrictions do not apply here.

## Formatter correctness

Disable PHP-CS-Fixer's `return_assignment` rule: it removes assignments captured by reference in nested closures, breaking the normal Guzzle wait-callback pattern. Keep valid code intact and disable the unsafe transformation explicitly; do not add dummy annotations or a custom fixer.

## Correctness validation

Use deterministic synchronization where practical to force the failing interleaving. Tests must assert behavior, callback context, resources, and errors rather than print results. Use isolated subprocesses for real proxy loading, dependency variants, native crash reproductions, and non-coroutine shutdown behavior. Give local servers dynamic ports and unconditional cleanup.

Demonstrate the representative regressions fail on unchanged baseline code, using the same test/probe body and compatible bootstrap. Distinguish a reproduced defect from a new-API missing-class failure. For crash cases, assert subprocess exit/output rather than crashing the suite runner. Keep the original failure evidence and exact commands.

Required coverage:

| Area | Assertions |
| --- | --- |
| Queue/context isolation | A yielding queue drain cannot run another request's sync middleware, async callback, or event listener; request/tenant values and a real database transaction remain with the correct coroutine. Children do not inherit queues through either fork API or context-copying parallel execution. Concurrent synchronous requests through one shared default Guzzle client, including redirects and caller `on_stats`, still succeed in their respective contexts. |
| Promise operations | Owner operations work; foreign pending registration/wait/cancel/settlement/adoption fail before side effects; completed fulfillment and rejection remain reusable; adopted pending chains remain protected; failed self-settlement leaves no stale state; ordinary callback ordering and exception semantics hold. |
| Transport admission | Preparation yields, throws, or completes immediately; foreign admission is refused before native work; same-owner transfers and later sequential reuse work; successful uploads finish on both dependency families. |
| Cleanup | Fulfillment, rejection, cancellation, owner exit with abandoned transfers, and later GC release ownership exactly once; unrelated requests cannot execute abandoned callbacks; non-coroutine shutdown retains its queue behavior and native handle lifetime; repeated batches do not accumulate completed work. |
| AWS consumers | Exercise SQS, S3, and SES through their actual manager/client construction and pooling, not bare SDK clients misrepresented as defaults. Reused provider objects and supported callable forms serialize, callers receive their own context-specific credentials, memoized completed credentials remain reusable, and lock owners/waiters clean up on failure/cancellation. |
| Broadcasting | Concurrent public `triggerAsync()`/`triggerBatchAsync()` via Pusher and Reverb, sync requests, custom handler behavior, option forwarding, cap errors, and caller callbacks. |
| Framework integration | HTTP client sync/async and coroutine concurrency, lazy streaming bodies read after `send()` returns (including supported sequential coroutine handoff), relevant Saloon paths, queue/filesystem/mail/broadcasting consumers, and existing Sentry/Telescope callbacks with the integration active or observability disabled. Verify `CurlStreamingBody`'s read-time promise completion without extending the pending-promise restriction to its caller-owned stream. |
| Bootstrap | HTTP and console boot, cached proxy reuse, repeated Testbench applications, no Sentry/Telescope installed/enabled, and installed split-package requirements. No silent unproxied target classes. |

Run upstream `guzzlehttp/promises` tests with production integration enabled for both supported dependency families, preserving applicable upstream assertions. Also test ordinary non-coroutine behavior. Use independently installed dependency environments. Preserve current supported Composer ranges rather than dropping a major to simplify the fix.

Make dependency drift visible through ordinary CI tests. Keep the existing full-suite jobs' unlocked dependency resolution unchanged; they test what an ordinary framework install resolves, currently Guzzle 8/promises 3. Add only a focused Guzzle 7/promises 2 compatibility job, constraining PSR-7 to its compatible 2.x family and running the ownership and affected AWS/broadcasting tests. Composer's `update --with` supplies temporary constraints without changing manifests. Verify real proxy interception of every targeted method, constructor ownership, and the behavioral regression suite; signature checks alone cannot detect changed scheduling semantics. Reuse PHPUnit and Composer, without a custom source-drift analyzer or another full-suite matrix. Keep upstream-suite validation reproducible with the same integration bootstrap.

PHPUnit setup/teardown runs outside the test coroutine, and Foundation setup can create temporary coroutines. Create and finish pending promise work in the coroutine that drives it; do not create pending fixtures in setup and pass them into the test coroutine. Full-suite validation must catch these lifecycle mismatches. Correct fixture ownership without weakening production checks or changing the behavior a test protects. Validate package-mode bootstrap as well as the components suite.

## Documentation and future maintenance

Keep the user-facing ownership explanation and native-coroutine example in `src/docs/http-client.md`; link from relevant consumer documentation rather than duplicating the explanation. Explain where to create and finish async work, safe reuse of completed results, exit cancellation, custom-handler responsibilities, and deliberate unpooled/direct mode. Show a supported replacement for transferring pending work between requests.

Update broadcasting, queue, filesystem, and mail documentation where their consumer changes require it. Record deliberate public restrictions in the relevant README differences sections and the Laravel porting guide; do not turn internal bookkeeping or bug fixes into a divergence inventory. Keep AOP rationale and supported-version maintenance details with the owning implementation/tests and this plan.

## Benchmark and optimization protocol

Run measurements on an idle machine without concurrent tests, builds, or other benchmarks.

Keep the harness and reproducible commands under `tests/Benchmarks/GuzzleOwnership`, with a results summary beside them. Keep raw reports outside the repository. No production benchmark command or timing assertions in CI.

1. Compare the unchanged baseline with the implementation, using independently installed dependencies and the exact same harness, PHP/Swoole/Guzzle versions, settings, origin, payloads, concurrency, and operation counts. Record commit IDs, runtime/extension versions, CPU/VM details, OPcache/JIT/GC, and commands in measurement output.
2. Separate cold proxy generation/startup, cached startup, and warm runtime. AOP still constructs join points and dispatches aspects per intercepted call; startup generation does not establish that runtime overhead is negligible.
3. Measure promise construction/chains and owner checks, synchronous local HTTP, owner-driven async HTTP, coroutine-parallel HTTP, middleware/listeners, representative credential-provider contention, and public Pusher async use. Include success, bounded cancellation/cleanup, and idle/no-Guzzle controls. Keep correctness checks outside timed loops where they would distort costs.
4. Use controlled baseline/no-op-aspect/full-guard variants in isolated benchmark processes to separate AOP dispatch cost from ownership bookkeeping where useful. These are harness variants, not production bypass flags. End-to-end comparisons use the complete production integration.
5. Measure wall time, process CPU, throughput and latency distribution at bounded increasing concurrency. Measure PHP allocated/peak memory, RSS, open descriptors, active transfer cleanup, and memory after repeated batches/GC. Account for harness bookkeeping and separate origin CPU; PHP heap alone does not describe native cURL resource costs.
6. Use short warmups and a small fixed set of alternating paired runs; report median and spread, absolute differences, and percentages. Increase repetitions only to resolve a specific remaining uncertainty. Do not blindly repeat large matrices, introduce CPU-pinning machinery, or treat noise as a regression or improvement.
7. Never compare an unsafe/crashing baseline with successful work as if it delivered equivalent throughput. Use safe matching workloads for overhead deltas and separately report that the corrected supported scenario completes reliably. Benchmark both dependency families on the focused promise/transport workloads; run the wider consumer matrix on the primary version and investigate material version differences.
8. Measure shared AOP dispatch and resource costs separately from Guzzle-specific costs. Inspect generated calls, chain construction, container resolution and allocation; consider worker-startup preparation or immutable caching where measurements justify it. Preserve aspect ordering, argument/reference semantics, short-circuiting, exceptions, dynamic container bindings, scoped/transient aspects, coroutine reentrancy and cleanup. Do not cache invocation state or aspect instances merely to save resolution work. Prefer direct simplification and removal of repeated work, and measure material changes.

### Shared AOP optimization

Replace the per-call generic AOP pipeline with a compiled chain of static closures cached by class and method in `AspectManager`. Each closure captures only its aspect class and next closure; resolve the current container and aspect on invocation. Publish the complete chain after priority resolution, retaining the existing ordering and cache-reset boundary. Never retain a join point, intercepted instance, application container or aspect instance in the cache. The method set bounds worker-lifetime storage.

Remove the unused AOP-specific `Pipeline`; keep the general Laravel-style pipeline unchanged. Registered aspects use `process()`, including classes that do not extend `AbstractAspect`. Consolidate the removed pipeline tests into dispatcher behavior tests. Verify short-circuiting, original-method bypass, exception identity, dynamic container replacement/rebinding, yielding concurrent and nested calls, argument/reference semantics, and collection of invocation objects without cyclic GC. Use a scoped aspect and assert target identity in the concurrent-dispatch case rather than adding separate lifetime and instance-concurrency matrices. Existing generated-proxy and bootstrap coverage must continue to pass.

Pass the intercepted instance from generated proxies to `ProceedingJoinPoint` instead of reflecting its closure on every `getInstance()` call. Pass null for static methods and update the manual aspect test helper and direct construction sites. Verify identity across multiple objects, constructors, static calls and concurrent invocations. Measure this separately from compiled dispatch. The existing generator fingerprint invalidates cached proxy files. Do not cache intercepted objects for the worker lifetime.

Publish generated proxies through one dedicated Composer ClassLoader owned by GenerateProxies. Make it class-map-authoritative, give it no vendor directory, and prepend it only after proxies exist and generation plus loaded-class validation succeed. Reuse it across boots; its small map contains only proxy entries. Keep the application loader and original source paths unchanged, avoiding Composer's full-map copy when adding replacements. Preserve PSR-4 lookup, source overrides, regeneration, wildcard discovery and atomic publication on failure.

Resolve the application loader through the first entry of ClassLoader::getRegisteredLoaders(), preserving the existing no-loader error and explicit test loader injection. This excludes the vendor-directory-less proxy loader even after Composer's cached loader is reset. GenerateProxies::flushState() unregisters and clears its loader, but must not be called by global per-test cleanup: pregenerated Guzzle proxies must survive test discovery and subsequent applications. Generator unit tests use an isolated subclass with its own static loader, and unregister only that loader during teardown. Retain real production bootstrap subprocess coverage and add the auxiliary-loader discovery regression.

Select exact class rules through a direct map lookup after stripping the method suffix. For wildcard class rules, build the pattern once and use `preg_grep` on the class names, allocating that local name list only when a wildcard is encountered. Keep selection order and deduplication. Verify application-map preservation and mixed exact/wildcard selection through existing bootstrap tests; measure startup and memory separately from runtime dispatch.

Initialize Ast's parser only in `parse()` and its printer only when printing generated code; `proxy()` delegates parsing to `parse()`. Keep Ast local to each generation call. Cached proxy boot must not construct or load the unused parser and printer. Extend the existing cold/cached bootstrap subprocess case to verify these classes load on cold generation and remain unloaded on cached boot, while ownership still works. Preserve visitor reflection and all generation semantics; measure the startup and memory effect separately.

Fingerprint the generator's PHP source files with a sorted `glob` and the existing filesystem reads. Avoid loading Symfony Finder solely to enumerate this fixed directory.

The result table must include workload, versions/concurrency, baseline and final time or throughput, absolute/percentage delta, memory/RSS and cleanup differences, sample spread, and relevant limitations. Rerun affected comparisons after an optimization. Never describe unmeasured overhead as noise or invent acceptable thresholds.

## Measurements

The [benchmark report](../../tests/Benchmarks/GuzzleOwnership/results.md) records the shared AOP improvements, ownership overhead, retained memory, cleanup behavior, sample ranges, and dependency versions. It also translates measured per-request CPU into example deployment capacity costs, distinguishing those projections from measured throughput.
