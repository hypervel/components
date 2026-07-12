# Framework Coroutine, State, and Lifecycle Audit Ledger

## Purpose

This companion document stores the durable findings and implementation history produced by the [framework audit plan](2026-07-12-framework-coroutine-state-lifecycle-audit.md). The operating procedure, active-work routing, cross-package dependency index, and 71-package checklist remain in the main plan so they can be reread after compaction without loading this growing history.

Append package entries in checklist order. Keep each entry compact but complete enough to recover the final source-backed decision without chat history.

## Reading and writing rules

- Do not reread this file in full after compaction.
- Read the active package's entry, if one exists, and only the shared/package entries named by the main plan's routing and dependency indexes.
- Do not record proposed findings before second-opinion consensus.
- Do not preserve discussion history, discarded drafts, or superseded designs.
- Record an important rejected concern only when a future auditor could reasonably rediscover it and repeat the same investigation.
- Give every shared finding one owning ID and use that ID in every affected package entry.
- Keep the main plan's routing and dependency indexes sufficient to locate every entry required by active or future work.
- Use the final pull-request title as the work-unit heading. Do not duplicate branch, pull-request, commit, or merge references; repository history already owns that information.

## Entry templates

### Clean package

```md
### `{pull-request title}`

- **Architecture and inspected risk surfaces:** concise package lifetime/ownership model and the high-risk files, bindings, traits, callers, and tests inspected.
- **Result:** no verified defect or approved improvement.
- **Important rejected concerns:** concise source-backed reasons for any non-obvious safe pattern.
- **Cross-package notes:** dependencies or consumers that need later revalidation, or “none”.
- **Validation and review:** audit review sign-off, executable gates omitted because code did not change, and owner pre-commit approval.
- **Assessment:** coroutine safety, worker-state safety, lifecycle, performance, and complexity in one concise statement.
```

### Package with findings

```md
### `{pull-request title}`

- **Architecture and inspected risk surfaces:** concise package lifetime/ownership model and the high-risk files, bindings, traits, callers, and tests inspected.

| ID | Category | Severity | Confidence | Failure and owning boundary | Final decision |
|---|---|---|---|---|---|
| `{package}-01` | Defect | Major | High | Concrete failure schedule; lowest owner | Consensus correction |

- **Important rejected concerns:** concerns whose rejection prevents repeated speculative work.
- **Cross-package implications:** owning and affected package IDs, including required revalidation.
- **Implementation:** final source/test/doc changes and stale design removed.
- **Regression tests:** deterministic old-failure coverage and relevant integration coverage.
- **Performance and complexity:** measured/reasoned overhead and why the result is proportionate.
- **Validation and review:** focused commands, `composer fix`, self-review, code-review sign-off, and owner pre-commit approval.
- **Assessment:** final coroutine safety, worker-state safety, lifecycle, performance, robustness, and overengineering judgment.
```

### Shared work

```md
### Shared finding `{owner-package}-NN`: {title}

- **Owner:** package and exact lower-level contract.
- **Affected packages:** bidirectional list of every consumer/sibling changed or revalidated.
- **Failure:** realistic schedule and visible effect.
- **Decision:** final coherent cross-package design.
- **Implementation and cleanup:** source/tests/docs removed or changed across packages.
- **Validation:** package-focused coverage, full gate, self-review, review sign-off, and owner pre-commit approval.
- **Revalidation:** completed package entries updated by this contract change.
```

## Package findings and changes

### Harden framework contracts and request-scoped state

- **Architecture and inspected risk surfaces:** The package is almost entirely interfaces and immutable value contracts, with no resource ownership of its own. The audit covered every declaration, subtree metadata, direct tests, mutable concrete helpers, all implementers and consumers of candidate contract changes, current local Laravel and Hyperf contract sets, and the concrete validation, view, filesystem, console, server, and process boundaries exposed by the contracts.

| ID | Category | Severity | Confidence | Failure and owning boundary | Final decision |
|---|---|---|---|---|---|
| `contracts-01` | Defect | Major | High | The split package does not declare four external parent-interface dependencies, so affected contracts cannot load standalone | Require PSR Container, PSR Simple Cache, PSR Log, and Monolog; regress every external parent interface through package metadata |
| `validation-01` | Defect | Major | High | Configured Email/File/Password default instances are mutable worker-global prototypes reused by concurrent validations | Clone each configured result and nested executable rule objects, require the correct concrete rule family, and reset Email's callback |
| `view-01` | Defect | Major | High | Request-session errors are written into the worker-singleton View Factory and can be observed by a sibling request | Add a minimal internal coroutine-local request-shared-data owner and merge it with the global baseline in one render-path merge |
| `filesystem-01` | Defect | Major | High | The factory contract returns `mixed`, omits enum names, and permits custom creators to return non-filesystem objects | Type the complete construction boundary as `Filesystem` and reject invalid custom results once at construction |
| `queue-01` | Defect | Major | High | `SerializesModels` converts non-Eloquent queue contracts into identifiers that only Eloquent can restore | Restrict identifier serialization to actual Eloquent models and collections; leave other objects to normal PHP serialization |
| `contracts-02` | Improvement | Improvement | High | Eighteen public methods and four parameters are incompletely typed; Console Kernel `handle()` is incorrectly `mixed` | Add evidence-backed native types and update all implementers/test doubles; do not add a broad reflection style test |
| `contracts-03` | Improvement | Improvement | High | Hypervel lacks Laravel's listener-discovery opt-out contract and filter | Port `ShouldBeDiscovered` and its focused discovery coverage |
| `contracts-04` | Improvement | Improvement | High | Public low-level APIs retain incorrect Hyperf spellings | Rename handshake/process symbols comprehensively with no aliases |
| `contracts-05` | Improvement | Improvement | High | APIs deprecated by Laravel and dead migration-command wiring remain despite supported replacements | Remove the directly deprecated or dead surfaces, migrate each caller to its intended source/replacement, and record intentional upstream omissions; retain Laravel's live `Application::add()` wrapper |
| `contracts-06` | Defect | Minor | High | Two Engine failures emit grammatically broken diagnostics | Replace them with clear messages and assert the exact failures |
| `contracts-07` | Userland footgun | Minor | High | Several concrete worker-singleton mutators lack lifecycle warnings | Add warnings at concrete lifetime boundaries; keep generic contracts implementation-neutral except for universal native/process invariants |
| `contracts-08` | Improvement | Minor | High | The package lacks required upstream references, accurate nullable model documentation, method docs, and several native test/doc return types | Complete the package documentation while preserving concise title-only method docs |

- **Important rejected concerns:** Do not rewrite validation around only `ValidationRule`; Laravel's deprecated contracts remain its live internal execution protocol, userland already receives the modern API, and a rewrite would add permanent synchronization cost without behavior gain. Do not remove `Console\Application::add()`: Symfony deprecated its underlying method, but Laravel deliberately retains, uses, and tests a non-deprecated wrapper over `addCommand()`. Do not add orphan Image contracts before Hypervel has a coherent Image package. Do not relocate the Monolog-specific context contract without a separately approved API redesign. Do not add optional dependencies merely because they appear only in lazy parameter/return types. Do not make public `View::share()` request-dependent or add a public request-sharing API. Do not keep a throwing `Request::get()` tombstone; record the intentional omission so static analysis rejects it.
- **Cross-package implications:** `validation-01` affects `validation`; `view-01` affects `view` and `foundation`; `filesystem-01` affects `filesystem`; `queue-01` affects `queue`; `contracts-04` affects `server`, `server-process`, `websocket-server`, and `reverb`; `contracts-05` affects `http`, `routing`, `foundation`, `console`, and `database`. Those package checkboxes remain open until their own complete audits.
- **Implementation:** Declared every external parent-interface dependency in the split package; added evidence-backed contract and implementation types; ported `ShouldBeDiscovered` with current upstream source, fixtures, tests, and docs; corrected the handshake/process spellings without aliases; removed only APIs directly deprecated by Laravel plus dead migration wiring; retained Laravel's live `Application::add()` wrapper; normalized every statically valid `resolveCommands()` argument shape; corrected Engine diagnostics; completed contract docs and upstream references; and added lifecycle warnings to concrete worker-state mutators. The validation, view, filesystem, and queue corrections below were implemented at their owning boundaries, with superseded state writes, loose construction types, unrestorable identifier conversion, raw morph-alias restoration, dead dependencies, obsolete tests, and stale comments removed.
- **Regression tests:** Added split-package dependency-presence coverage, discovery opt-out fixtures, exact Engine diagnostic coverage inside and outside a coroutine, custom-filesystem result validation, configured-rule clone/isolation coverage, deterministic concurrent view-error isolation, ordinary serialization for non-Eloquent queue contracts, morph-mapped Eloquent collection restoration, and all supported scalar/array `resolveCommands()` forms. Updated every affected contract implementation and test double, including integration-only caster fixtures.
- **Performance and complexity:** Owner approved one clone per configured validation-default resolution and one coroutine-context lookup per rendered View object. The View path retains one array merge. Custom filesystem validation runs only on construction/cache miss; discovery is boot-only; all other accepted changes add no meaningful runtime work. No locks, registries, compatibility shims, or speculative abstractions are introduced.
- **Validation and review:** Pre-implementation second-opinion consensus and owner approval complete. Focused affected-package tests and the complete `composer fix` gate pass. Fresh full-diff self-review completed; its focused second-opinion corrections are implemented and revalidated. Independent code review is signed off; owner pre-commit approval is complete.
- **Assessment:** The result establishes truthful public contracts and isolates the verified shared-state failures without locks, request-scoped manager clones, compatibility aliases, recursive graph machinery, or speculative abstractions. Runtime work is limited to the explicitly approved clone/context operations and constant-time boundary checks.

### Shared finding `validation-01`: configured validation-default prototype isolation

- **Owner:** `validation`, specifically Email/File/Password default-rule construction.
- **Affected packages:** `contracts` discovered and traced the live validation contracts; `validation` owns the source and regression coverage.
- **Failure:** A configured rule instance or callable returning one is reused across requests. Fluent mutations, validator/data injection, and failure messages then alter the prototype or race between sibling validations.
- **Decision:** Keep the worker-global boot configuration as a prototype, return a correctly typed clone for each configured `default()` call, clone nested `Rule|InvokableRule|ValidationRule` objects through normal PHP clone semantics, reject an invalid callback result, and reset Email's callback. Closure captures and pathological cyclic graphs remain the configuring developer's responsibility; no reflection, graph tracker, or general validator cloning policy.
- **Implementation and cleanup:** Added one small shared `ClonesCustomRules` trait to Email, File, and Password. Configured defaults now return a top-level clone, clone nested executable rule objects, reject the wrong rule family, and leave the configured prototype unchanged. Email now clears its default callback during state reset. Existing deprecated validation contracts remain because Laravel still uses them as the live compatibility substrate.
- **Validation:** Deterministic mutation, callable-result, nested-rule, coroutine-interleaving, invalid-result, and Email-reset regressions pass; the work-unit review is signed off and the later full `validation` audit remains pending.
- **Revalidation:** Full `validation` audit remains pending.

### Shared finding `view-01`: request-shared view-data isolation

- **Owner:** `view`, specifically request-only shared data consumed by the worker-singleton Factory; `foundation` owns the session-errors middleware writer.
- **Affected packages:** `contracts`, `view`, and `foundation`.
- **Failure:** Concurrent middleware scopes overwrite `Factory::$shared['errors']`, allowing one request to render another request's validation errors.
- **Decision:** Keep public `share()` as a boot-time worker baseline. Store only the request overlay in a minimal internal coroutine-local state owner with exact nested/exceptional restoration. `Factory::mergeSharedData()` composes global, request, and local data in one merge; `shared()` and `getShared()` observe the overlay. No public Factory-contract expansion or scoped Factory clone.
- **Implementation and cleanup:** Added the minimal coroutine-local `RequestSharedData` owner. Session-error middleware now scopes its overlay for exactly the downstream request lifetime and restores prior state in `finally`. Factory render/shared reads compose global, request, and local data with the intended precedence; the singleton error write and middleware constructor dependency were removed.
- **Validation:** Precedence, nested exceptional restoration, and deterministic two-request interleaving regressions pass; the work-unit review is signed off and the later full `view` and `foundation` audits remain pending.
- **Revalidation:** Full `view` and `foundation` audits remain pending.

### Shared finding `filesystem-01`: truthful filesystem construction boundary

- **Owner:** `filesystem`, from `Contracts\Filesystem\Factory` through manager construction and custom creators.
- **Affected packages:** `contracts` and `filesystem`.
- **Failure:** The public contract accepts fewer valid disk names than the manager, returns `mixed`, and allows a custom creator to publish an arbitrary object as a disk.
- **Decision:** Accept `UnitEnum|string|null`, return `Filesystem` throughout the construction boundary, and validate custom creator output once before publication/caching.
- **Implementation and cleanup:** Typed the contract and manager construction path as `Filesystem`, aligned enum-capable disk names, and reject an invalid custom-creator result once before caching or publication. Existing creator binding behavior remains intact.
- **Validation:** Valid custom creators, manager binding, and invalid-result regressions pass; the work-unit review is signed off and the later full `filesystem` audit remains pending.
- **Revalidation:** Full `filesystem` audit remains pending.

### Shared finding `queue-01`: restorable model-identifier boundary

- **Owner:** `queue`, specifically `SerializesAndRestoresModelIdentifiers`; `contracts` owns the queue entity/collection marker interfaces and `ModelIdentifier` data carrier.
- **Affected packages:** `contracts` and `queue`.
- **Failure:** The serialization trait converts any `QueueableEntity` or `QueueableCollection` into an Eloquent `ModelIdentifier`, but both restoration branches construct Eloquent models and return Eloquent collections. Non-Eloquent implementations therefore serialize into identifiers that cannot be restored. Separately, collection restoration constructs the raw stored class value even though morph-map serialization may replace it with an alias, so morph-mapped model collections fail while single models restore correctly.
- **Decision:** Create model identifiers only for actual Eloquent `Model` and `EloquentCollection` instances. Those classes already implement the queue contracts. Leave non-Eloquent implementations to ordinary PHP object serialization, preserving every working built-in path while making custom objects restorable through their native serialization behavior. Resolve the stored class or morph alias once through `ModelIdentifier::getClass()` before every collection-restoration use. Cover both serialization branches and morph-mapped collection restoration. Explain the intentional Laravel correctness divergence in focused source comments, not the package README because intended public behavior is unchanged. The constant-time type checks replace the existing interface checks without adding hot-path work.
- **Implementation and cleanup:** Replaced the generic queue-contract checks with the Eloquent types the restoration path can actually reconstruct. Collection restoration now resolves the model class once before construction and pivot checks, matching the already-correct single-model path. Removed the now-unused generic imports and retained only focused comments at the two non-obvious boundaries; no user-facing difference entry was added.
- **Validation:** Eloquent relation stripping, non-Eloquent entity/collection round trips, and morph-mapped Eloquent collection restoration pass. Pre-implementation second-opinion consensus, owner approval, and work-unit code review are complete; the later full `queue` audit remains pending.
- **Revalidation:** Full `queue` audit remains pending.

### Restore Conditionable proxy truthiness

- **Architecture and inspected risk surfaces:** Conditionable consists of one stateless trait and one short-lived higher-order proxy under the shared `Hypervel\Support` namespace. The audit covered both source files, both package-owned test files, every framework consumer, all consuming split-package manifests, package history, and current Laravel source and tests. It found no static or worker-lifetime state, container lifetime, coroutine, resource, native boundary, or cleanup owner.

| ID | Category | Severity | Confidence | Failure and owning boundary | Final decision |
|---|---|---|---|---|---|
| `conditionable-01` | Defect | Major | High | `HigherOrderWhenProxy::condition(bool)` rejects the non-boolean truthy/falsy values accepted by `when()` and returned by higher-order property/method capture | Accept `mixed` at the single setter boundary, normalize once into the existing bool property, and regress direct/property/method truthiness plus the symmetric `unless` path |
| `conditionable-02` | Improvement | Improvement | High | Eight package-owned test methods omit the repository-required `void` return type | Add `: void` without restructuring the tests |
| `conditionable-03` | Defect | Minor | High | `hypervel/log` directly uses the trait but is the sole one of 17 Hypervel consumer packages not to declare `hypervel/conditionable` | Add the direct split-package requirement, matching Hypervel's require-what-you-use convention |
| `testbench-01` | Defect | Minor | High | Testbench CLI subprocesses implicitly rewrite the per-worker package manifest with root providers, and the shared Testbench lifecycle did not restore it between tests | Preserve the manifest once in the Testbench base test lifecycle and make the route-cache test assert its no-provider precondition |

- **Important rejected concerns:** Do not add coroutine context, scoped bindings, cloning, locks, or cleanup machinery to a per-invocation proxy with no hidden shared state. Keep the Eloquent integration test because it verifies the split trait through a real consumer and its Capsule global is reset centrally. Do not consolidate the two upstream-mirrored test locations or add a README absent from this family of Laravel and Hypervel micro-packages.
- **Cross-package implications:** `conditionable-03` corrects the `log` split manifest; the later full `log` audit must retain and revalidate that direct dependency. Future `macroable` and `collections` audits should repeat the same consumer-manifest sweep rather than assuming either a direct-edge or umbrella dependency convention. `testbench-01` belongs to the shared Testbench lifecycle and is also revalidated by the Foundation route-cache subprocess tests; the later full `testbench` and `foundation` audits must retain that ownership boundary.
- **Implementation:** The higher-order proxy now accepts unconstrained condition values and stores their boolean normalization in its existing typed property. The log split manifest now declares its direct Conditionable dependency. Testbench snapshots the resolved package-manifest cache after application setup and restores its exact existence and contents through the existing exhaustive pre-destruction callback lifecycle, without rewriting an unchanged file. The route-cache precondition guard now reports unexpected raw package providers directly.
- **Regression tests:** Conditionable coverage now exercises direct, higher-order property, and higher-order method truthiness with non-boolean values, plus the symmetric `unless` path. The remote-command regression forces a Testbench CLI child to rebuild the shared manifest, proves the write happened, and verifies teardown restored the captured baseline. The previously failing remote-command-before-route-cache sequence passes deterministically.
- **Performance and complexity:** The Conditionable correction adds one allocation-free boolean normalization per higher-order proxy condition. It adds no lookup, lock, yield, retained state, compatibility path, or abstraction. The manifest dependency and test signatures have no runtime cost. Manifest preservation adds small filesystem reads only to Testbench test setup/teardown and performs no write when the cache is unchanged; production and application hot paths are unaffected.
- **Validation and review:** Focused Conditionable, remote-command, route-cache, and cross-test ordering regressions pass. The log split manifest validates strictly, `git diff --check` passes, and the full `composer fix` gate is green. Pre-implementation second-opinion consensus, owner approvals, fresh self-review, and final code-review sign-off are complete; integration into `0.4` remains pending.
- **Assessment:** The Conditionable architecture remains stateless, minimal, and coroutine-safe. The accepted changes correct one narrowed truthiness boundary, one split-manifest dependency, and one independently discovered Testbench worker-state leak at their respective owners without adding production machinery.
