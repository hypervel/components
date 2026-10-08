# Database lease cleanup and response registration

## Outcome and boundaries

Correct the lifecycle defects reported on [PR #653](https://github.com/hypervel/components/pull/653): expired logical owners can silently borrow an unowned pool slot, read-side extensions can enter the wrong construction path, and raw transactions can reach another borrower. Remove unused response stream-ID plumbing. Preserve automatic idle release, familiar Laravel database APIs, callback behavior and inexpensive normal queries.

Work in `/home/binaryfire/workspace/contrib/hypervel/components`, branch `fix/database-lease-lifecycle`, created from `0.4`. AI package work remains paused in its separate worktree. This is a separate framework PR to complete before returning to AI implementation.

**Status:** implementation, verification and review are complete, including failed-initial-owner cleanup. Complete both PRs' remaining CI and bot-review pass before merging. Follow monorepo `CLAUDE.md` and this repository's `AGENTS.md`. The peer is `claude-ai`; do not compact the peer during this work. Leave the historical framework plan unchanged; track the paused AI work's dependency in its existing orchestration document.

Fix confirmed failures at their owning boundary without adding automatic ownership transfer, per-query coroutine checks, SQL parsing, retry machinery or unrelated refactoring. Any newly proposed Laravel API break requires owner approval.

## Evidence and review coverage

| Albert's comments | Verified cause | Owning change |
|---|---|---|
| [Lease reacquisition](https://github.com/hypervel/components/pull/653#discussion_r4218935407), [resolver cleanup](https://github.com/hypervel/components/pull/653#discussion_r4218946335) | `ConnectionLease::resolvePdo()` borrows after terminal release; its original deferred cleanup has already run, and no new owner is registered. | Give the logical lease an explicit terminal state. |
| [Read extensions](https://github.com/hypervel/components/pull/653#discussion_r4219011376) | Pool eligibility inspects top-level configuration; explicit `::read` construction can invoke an extension selected from a read record. Rebuilding that result as a PDO lease bypasses the extension or receives a non-PDO connection. | Classify all effective read configurations before enabling leases. |
| [Raw transactions](https://github.com/hypervel/components/pull/653#discussion_r4219032223) | Pinning and pool rollback use framework counters. Native PDO and SQL-started transactions bypass those counters; the public `inTransaction()` checks only the writer. | Inspect both physical handles at release boundaries and promptly clean dirty sessions. |
| [Unused stream ID](https://github.com/hypervel/components/pull/653#discussion_r4219075550) | Response registration stores a stream ID which neither cancellation nor cleanup reads. | Remove the property and argument forwarding. |

Diagnostic probes at `/tmp/hypervel-ai-port/AlbertReviewProbeTest.php` and `albert-review-probes.log` reproduce the orphaned borrow, both read-extension failures and raw-transaction leakage on early and terminal release. They assert the observed defects, not the desired behavior; do not copy those assertions into regressions. A two-PDO probe also confirms a read transaction is invisible to the current connection's `inTransaction()` and `hasPinnedSession()`. Permanent tests below must assert the corrected behavior and fail before the fix.

## 1. End logical ownership explicitly

`ConnectionLease` owns the logical connection, its PDO resolvers and its current pool borrow. The logical connection retains transaction bookkeeping, callbacks, query logs and sticky-routing state. Attaching another cleanup callback alone would not define how that state transfers to another execution or make overlapping use safe. End this resource's ownership cleanly instead of silently reopening it after cleanup.

- Add a protected boolean `$ended = false` to `ConnectionLease`.
- Make the lease's existing `release()` and `discard()` terminal: set `$ended` before settling the current pooled connection. Repeated terminal calls with no held slot remain harmless.
- Keep the two internal non-terminal paths recoverable: `releaseIfIdle()` calls `$this->pooledConnection?->release()` directly, while `discardAfterFailure()` calls `$this->pooledConnection?->discard()` directly and keeps its exception precedence.
- In `resolvePdo()`, check `$ended` only inside the branch about to borrow a new slot. Throw `LogicException` with an actionable message explaining that the owning execution ended and the caller must resolve its connection where it will be used. Do not add checks to every query.
- Failed initial publication must discard the logical owner terminally; fall back to the borrowed wrapper if construction did not return an owner. The coroutine defer, non-coroutine task release/discard and replacement of a retained non-coroutine owner keep their existing terminal calls. Keep connections registered in context until their release/rollback callbacks finish.
- Release listeners and rollback callbacks may query or reconnect the still-held slot. Set the terminal flag before settlement so a newly awakened consumer cannot reacquire after detachment; do not reject ordinary access to the slot while cleanup still owns it.
- Ordinary early release, disconnect/reconnect and failed reacquisition remain reusable within a registered live owner. Do not mark every physical discard terminal.

Core branch, within the existing resolver:

```php
if ($this->pooledConnection === null) {
    if ($this->ended) {
        throw new LogicException('This database connection is no longer available because the coroutine or task that resolved it has finished or failed to set it up. Resolve the connection where you use it.');
    }

    // Existing borrow and attachment.
}
```

This prevents the reported orphaned acquisition. It does not claim to intercept an already-running cursor's PDOStatement, an externally retained raw PDO, or every concurrent reference to a connection.

## 2. Classify effective read extensions

Consolidate the constructor eligibility expression into the existing identity-checking method in `DatabasePool`, renamed to `supportsSessionLeases()` to reflect its full responsibility.

1. Preserve name/top-level extension precedence: an extension returned by `ConnectionFactory::getExtension($config, $name->base)` disables leases immediately.
2. Select `read` only for an explicit `::read` pool with read configuration; otherwise inspect `write`. If no selected role record exists, the top-level classification suffices. Do not call `configForWrite()` on absent write configuration.
3. Normalize a single associative record to a one-element list; inspect every record in a configured list. Resolve each effective endpoint using the existing `configForRead()` or `configForWrite()` merge/parser. This inspection must not randomly choose one record for the pool lifetime.
4. For the read role, any effective endpoint with an extension disables leases for the entire pool. Preserve the existing driver/database/prefix identity comparison across selectable records.
5. Do not check write-record extensions when deciding lease eligibility: base construction invokes extensions from top-level configuration, then builds PDO write endpoints through `createConnection()` / `Connection::resolverFor()`. Preserve this behavior rather than disabling valid leases unnecessarily.

Keep read pool-option consistency, SQLite restrictions, per-physical-connection endpoint selection and reconnect behavior intact. Eligibility is decided at pool construction, not per query or dynamically after borrowing. Whole-connection extensions retain their existing object and early-release behavior; no new extension interface is required.

## 3. Protect and settle physical transactions

### Pinning without changing public transaction semantics

Add public internal `Connection::hasPhysicalTransaction(): bool`, with a Laravel-style title and `@internal` docblock. Its base implementation delegates to the existing `inTransaction()` contract, so custom whole-connection drivers can report their physical state. No `ConnectionInterface` addition is needed: pool owners already use concrete `Connection`.

Override it in `PdoConnection` to inspect both already-open, distinct handles:

```php
return $this->inTransaction()
    || ($this->readPdo instanceof PDO
        && $this->readPdo !== $this->pdo
        && $this->readPdo->inTransaction());
```

Append `|| $this->hasPhysicalTransaction()` to `Connection::hasPinnedSession()` after its existing pin, transaction-counter and FK-suppression checks. Do not resolve a closure or open an unused connection just to inspect it. Leave public `inTransaction()` writer semantics unchanged; `RefreshDatabase` uses that method to assess its writer transaction. Keep public `beginTransaction()`, `commit()`, `rollBack()` and transaction counters unchanged. SQL parsing or synthetic counters are unnecessary.

PDO reports transactions started through its native methods or SQL transaction statements; the new inspection automatically pins those sessions across manual and framework-HTTP release. Raw PDO/statement use outside a transaction still needs explicit pinning when it requires session continuity.

### Inspect after callbacks, then settle once

Preserve `PooledConnection::prepareForRelease()`'s tracked `rollBack(0)` and rollback callbacks. In `release()`, inspect the physical holder after preparation, release listeners and logical-lease detachment, including when preparation threw or was cancelled. The physical holder retains the owned PDO objects; inspecting after callbacks catches a transaction left by a listener as well as one left by application code.

| Final physical-status result | Settlement |
|---|---|
| Transaction remains | Decide to discard, log the existing unfinished-transaction error, and call `pool->discard($this)`. |
| Inspection throws or is cancelled | Decide to discard, retain the failure under existing exception precedence, and still settle. |
| No transaction | Preserve existing release and reuse checks. An invalid slot from a preparation failure can return to idle; it reconnects before any subsequent application use. |

Do not skip physical inspection merely because an earlier cancellation was captured. Commit the discard decision before invoking logging that might throw. Use one settlement decision, not overlapping flags or an extra cleanup state machine. Run settlement exactly once; cancellation outranks ordinary errors and existing primary-failure rules remain intact.

The existing discard path calls `destroyConnection()` → `close()` → disconnect, reports ordinary close failures, propagates cancellation and frees pool accounting in `finally`. Reuse it. Merely marking a dirty slot invalid is insufficient: it could hold transaction locks while sitting idle until another borrow. Conversely, discarding every failed preparation with no physical transaction would change behavior without addressing this defect.

### Disconnect both handles

Extend `PdoConnection::disconnectDriverResources()` to inspect and roll back both already-open distinct PDOs. Attempt cleanup of the second even when inspecting or rolling back the first fails. Invalidate the physical-session memo after successful rollback; mark it unknown on failure. Preserve existing treatment of lost-connection failures as already disconnected, retain the first other failure, and give cancellation precedence. Always call `forgetDriverResources()` in `finally`; retain `Connection::disconnect()`'s transaction-manager cleanup and failure ordering.

This also fixes read-side cleanup during explicit disconnect, reconnect and discard. Do not add a separate rollback API or duplicate the pool's close machinery. The pool-held PDO for shared in-memory SQLite must survive a successful rollback/discard of its wrapper, preserving committed data and schema. A failed cleanup must never make an unknown session reusable.

## 4. Remove unused stream identity

- Remove `ResponseCancellation::$stream`, its constructor argument and the unused `register()` argument. Keep registration indexed by connection and producer coroutine, identity-safe removal, cancellation behavior and static cleanup.
- Remove `ResponseBridge::send()`'s unused `streamId` argument and forwarding from HTTP, gRPC and WebSocket servers, including both gRPC call sites and `tests/HttpServer/Fixtures/disconnect-server.php`. Update every source and test caller; do not remove stream IDs used by unrelated HTTP/2 clients.
- Retain coverage for multiple simultaneous producers on one connection, cancellation opt-in, sibling connections and post-production cleanup. Collapse `ResponseBridgeTest::disconnectCancellationOptions` to one opt-in and one opt-out row; record producer labels in `$closed` instead of unused stream IDs.
- Update the Swoole stream-cancel entry in `docs/todo.md` to say stream identity will be introduced into response registrations with the released event. Keep existing-runtime limitations and the future integration task; no polling, speculative event API or cancellation redesign.

## 5. Documentation and compatibility

Update `src/docs/database.md` in its existing pooling/releasing sections, using Laravel-style prose. Explain the new-acquisition exception after ownership ends, while retaining ordinary builder reuse across early release. Explain automatic pinning of native/SQL transactions and cleanup of unfinished transactions at execution end. Keep manual pinning advice for other session-dependent operations. Do not claim every physical PDO is replaced: shared in-memory SQLite retains its pool-owned PDO.

In the whole-connection ownership paragraph, include differing drivers alongside database names and table prefixes, and make the `DB::extend` sentence cover effective read-record extensions in explicit `::read` pools. Audit the database README and `src/docs/porting-from-laravel.md` against these changes; their existing promise that active transactions remain pinned becomes accurate, so do not add redundant internal-fix entries. These changes do not alter Laravel public signatures or intended successful results. The removed response arguments are unused Hypervel internal integration plumbing.

Remove stale descriptions, dead arguments and superseded method references from the modified surfaces. Preserve unrelated docs and all still-open TODOs. Do not rewrite historical plans or document review history as product behavior.

## 6. Tests and verification

Prefer additions to existing tests and data providers. Make each new regression fail on the original source and pass after its fix; run each changed test file immediately. Do not introduce a separate test architecture or a driver-by-driver copy of the lease suite.

| Existing test area | Required coverage |
|---|---|
| `DatabaseConnectionLeaseLifecycleTest::testTerminalCallbacksResolveTheirOwningConnection` | Retain a builder before cleanup; after each existing coroutine/task/failing-listener row, its next acquisition throws and borrowed count stays zero. Preserve callback access during cleanup. |
| Existing lease recovery/task-cleanup tests | Retained builder survives early release and another borrower; repeated early release/task cleanup; listener failure during reacquisition remains recoverable; disconnect/reconnect still works. Initial publication failure or cancellation leaves its retained connection unable to borrow, while fresh resolution succeeds. Extend the existing task test's final `discardConnections()` section to prove that retained owner cannot borrow again either; listener mocks alone do not prove this state transition. |
| `DatabaseConnectionLeaseTest::testConfigFirstExtensionsRetainWholeConnectionOwnership` | Preserve top-level case; add single and listed read-record PDO extension cases. Assert returned extension identity, disabled leases and unaffected early release. Retain mixed-identity, read-selection and existing non-PDO whole-connection tests. |
| `DatabaseConnectionLeaseTest::testSessionDependentScopesPreventEarlyRelease` | Extend rows for native raw transaction, SQL `BEGIN`, and distinct read-PDO transaction; parameterize the connection name. Check manual and HTTP-triggered release retains the slot, then release succeeds after transaction settlement. Retain explicit/FK/tracked transaction rows. |
| Raw transaction lifecycle coverage in `DatabaseConnectionLeaseLifecycleTest` | Coroutine/task cleanup, including a transaction opened by a release listener. Assert physical transaction ends before the next borrow, uncommitted data disappears, and pre-transaction schema/committed data survives shared-memory SQLite wrapper replacement. |
| Existing PDO disconnect tests | Two distinct active handles; one rollback failure does not skip the other; cancellation precedence; lazy handles are not resolved; identical handles are not processed twice. Preserve lost-connection and transaction-manager cleanup tests. Use focused rows/assertions, not a Cartesian matrix. |
| `PooledConnectionTest::testReleaseRollsBackOpenTransactions` | Add a raw-transaction case for whole-connection ownership without a lease; assert rollback and discard after `resetForPool()`. |
| Existing `PooledConnectionTest` release-failure tests | A throwing physical-status inspection on a non-PDO fixture settles by discard; logging cannot undo the discard decision (reuse the throwing logger from `testReleasePreservesTheFirstOrdinaryCleanupFailure`); cancellation still settles once. No-transaction preparation failure retains existing invalid/requeue behavior. |
| Existing response bridge/server tests | Multiple producers on one connection all cancel, another connection stays usable, opt-out and completed production remain unaffected. Update removed-argument callers and eliminate now-identical cases. |

Use the existing real SQLite-backed fixtures for ownership tests and existing mocked PDOs for precise failure paths. Also run `bin/run-database-tests.sh` sequentially for `mysql`, `mariadb` and `pgsql` with their matching local `DB_*` configuration. Do not add new service-specific lease classes just to repeat PDO transaction detection. Framework-wide cleanup changes require full verification before completion, using configured service isolation and established dependency skips.

Implementer workflow after owner authorization:

1. Implement coherent changes, tracing callers/callees and retaining the agreed ownership invariants. Investigate non-trivial new findings as a group and reach peer consensus before changing their design; amend this active plan concisely if needed.
2. After the immediate changed-file tests, run `composer fix` once for these shared lifecycle changes: formatting, PHPStan, parallel tests, Testbench and dogfood checks. Do not duplicate its component checks beforehand or overlap verification commands. Follow AGENTS' targeted-rerun rules on failure, then self-review the whole diff against this plan and Albert's comments.
3. Request complete code review from `claude-ai`, resolve findings until signoff, and report verification. The reviewer does not rerun checks or edit code. Do not compact the peer.
4. At the authorized commit/PR boundary, use coherent whole-file commits with detailed bodies, excluding unrelated edits. Verify authorization for publication rather than inferring it from plan creation. After the follow-up PR merges, reply to each of Albert's five code threads with a link to its fix, within the owner's authorized publication scope. Incorporate the merged `0.4` changes into `feature/ai` before resuming AI package work.

Normal query execution gains no per-query ownership checks. The ended-owner branch is tested only before a new borrow; extension classification runs once per pool; physical transaction checks occur at release/cleanup boundaries and create no new PDOs or database round trips for the built-in drivers. If implementation adds meaningful extra hot-path work or measurements become necessary, agree an idle window with the owner before comparative benchmarks.
