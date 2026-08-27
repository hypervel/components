# Database After-Commit Callback Completion

## Objective

Ensure one failed after-commit callback cannot silently discard independent work for data that is already committed. Preserve existing callback order, transaction state, exception identity, coroutine isolation, and Laravel-shaped APIs while using the smallest existing ownership boundaries.

This plan covers the Database transaction callback executor and the one Auth cache invalidation callback that contains several independent store operations. It adds no public API, configuration, retry policy, callback tier, registry, logging mechanism, or user-facing documentation.

## Verified behavior and root cause

### Commit sequence

`Hypervel\Database\Concerns\ManagesTransactions` performs a root commit in this order:

1. dispatch the `TransactionCommitting` event;
2. physically commit the PDO transaction;
3. decrement the logical transaction level;
4. ask `DatabaseTransactionsManager` to settle its records and run callbacks;
5. dispatch `TransactionCommitted` even when the manager reports a callback failure;
6. rethrow the earliest manager or committed-event failure.

The database commit is permanent before step 4 begins. A callback exception cannot roll it back or make later callbacks unsafe merely by occurring first.

### Current loss path

The existing failure behavior has two nested stop points:

- `DatabaseTransactionRecord::executeCallbacks()` uses a bare `foreach`; the first `Throwable` skips the rest of that record.
- `DatabaseTransactionsManager::commit()` uses higher-order collection dispatch; a failed record skips every later detached record.

Before callbacks run, the manager has already removed the applicable records from pending and committed coroutine state. Skipped callbacks are therefore neither deferred nor retried; they are permanently lost.

Nested transactions stage inner records before the outer record. Root commit consequently executes commit records in their existing staging order, with callbacks inside each record in registration order. The change must preserve both orders.

### Callback independence

Each registered callback is a separate unit. The transaction manager passes no result or state from one callback to the next, and framework callbacks for events, queues, mail, notifications, broadcasts, Scout, cache invalidation, and application code may be interleaved.

Code whose second step depends on the successful output of a first step belongs inside one callback:

```php
DB::afterCommit(function (): void {
    $result = performFirstStep();
    performDependentStep($result);
});
```

Separate callbacks must not use exception short-circuiting as a dependency mechanism.

Event deferral has three distinct existing boundaries:

- a `ShouldDispatchAfterCommit` event object registers one transaction callback around the entire event dispatch, so its listener loop keeps the normal `Dispatcher` failure and propagation behavior;
- a listener marked for after-commit handling registers its individual invocation as a separate transaction callback, so independently deferred listeners continue after a sibling callback fails;
- events held by `shouldDeferEvent()` use coroutine-scoped event deferral rather than transaction callbacks and are unaffected.

Individually deferred listeners are already detached from the immediate listener loop: their registration closure returns `null`, so they cannot halt dispatch, stop propagation by returning `false`, or contribute a response. The current stop-on-first behavior is therefore not an event contract; it is an accidental result of separate listeners sharing a transaction record, and it can also discard unrelated callbacks registered between them. Do not group deferred listeners into one callback merely to recreate that loss.

### Laravel reference

Current Laravel still has the same bare record loop and higher-order manager dispatch:

- `examples/laravel/framework/src/Illuminate/Database/DatabaseTransactionRecord.php`
- `examples/laravel/framework/src/Illuminate/Database/DatabaseTransactionsManager.php`

Laravel's `EloquentTransactionWithAfterCommitTests::testTransactionCallbackExceptions()` asserts that a later callback does not run. Local history traces that test to framework commit `bbb2add1bd` / PR `#50423`. That change moved the logical transaction decrement before callback execution so a callback failure could not corrupt transaction depth; it did not introduce or justify stop-on-first callback execution.

Neither Laravel's documentation nor Hypervel's Database guide documents stop-on-first as a contract. Preserving it would retain silent loss after an irreversible commit. The owner approved this deliberate correctness divergence.

### Existing Hypervel policy

Hypervel's rollback callbacks already use the required policy at both ownership layers:

- exhaust the callbacks in each record;
- exhaust every detached record;
- preserve the earliest `Throwable`;
- rethrow it after all independent work completes.

Commit and rollback need the same failure policy but separate executors because record selection and ordering differ. A generic callback executor would hide those ownership rules without removing meaningful duplication.

## Final design

### 1. Exhaust each commit record

Modify `src/database/src/DatabaseTransactionRecord.php` so `executeCallbacks()` mirrors the direct error handling of `executeCallbacksForRollback()`:

```php
$exception = null;

foreach ($this->callbacks as $callback) {
    try {
        $callback();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
}

if ($exception !== null) {
    throw $exception;
}
```

Properties of this boundary:

- callback registration order is unchanged;
- every callback receives exactly one attempt;
- the exact first `Throwable` object is rethrown;
- later failures are not aggregated or logged by the transaction layer;
- successful execution returns exactly as before.

Only the earliest exception is surfaced. This matches Hypervel's rollback policy and is strictly better than the current behavior, where later failures are unknowable because their callbacks never run. Adding an aggregate exception or reporting channel would be new machinery with no existing framework contract.

### 2. Exhaust every committed record

Modify `src/database/src/DatabaseTransactionsManager.php` to replace higher-order callback dispatch with a protected `executeCommitCallbacks(Collection $transactions): void` method:

```php
$exception = null;

foreach ($transactions as $transaction) {
    try {
        $transaction->executeCallbacks();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
}

if ($exception !== null) {
    throw $exception;
}
```

Call this method only after records for the committed connection have been partitioned and removed from manager state. Preserve:

- inner-before-outer staging order across records;
- FIFO registration order within each record;
- isolation of records belonging to other connection names;
- detached-state visibility during re-entrant callbacks;
- the existing return value when every callback succeeds;
- the existing first exception observed by callers;
- `TransactionCommitted` dispatch and truthful level/PDO state in `ManagesTransactions`.

Do not change `afterCommit()`, `afterCommitOrNow()`, callback registration, testing transaction selection, event dispatch, retry behavior, or coroutine storage.

Place `executeCommitCallbacks()` immediately beside `executeRollbackCallbacks()`. They implement the same failure policy and must remain visibly paired, while staying separate because their callers own different record selection and ordering.

### 3. Exhaust Auth cache descriptors inside its one callback

`src/auth/src/EloquentUserProvider.php` registers one after-commit callback per model event. That callback loops every cached provider descriptor and calls `ModelCacheCoordinator::invalidate()` for the corresponding store and key.

A lock timeout or cache-store failure in one descriptor currently escapes that single callback and skips later stores. The shared Database fix cannot continue inside one callback, so this loop must independently retain its first `Throwable`, continue through every descriptor, and rethrow after the loop.

Keep this logic inline in the existing callback. It has one caller and is immediately readable; extracting a helper or adding a shared failure executor would add indirection without reuse.

Keep one callback for the whole descriptor loop. The callback deliberately reads `static::$cachedProviders[$modelClass]` when it runs, so a provider enabled after the model mutation but before commit is also invalidated. Registering one callback per descriptor would freeze the set too early and lose that behavior.

This preserves:

- descriptor insertion order;
- exact store, prefix, model, and identifier key construction;
- one invalidation attempt per descriptor;
- the exact earliest exception;
- event registration and after-commit timing;
- lock behavior owned by `ModelCacheCoordinator`.

## Rejected machinery

Do not add:

- privileged "settlement" callbacks or a second callback list;
- callback priorities or dependency metadata;
- retries, compensation, or rollback attempts after commit;
- exception aggregation or framework-owned error logging;
- new public methods, interfaces, configuration, or events;
- locks, context state, caches, or worker-lifetime registries;
- a generic executor shared by commit, rollback, and Auth.

These mechanisms either preserve the defect for ordinary callbacks, change unrelated contracts, or solve needs the framework does not have.

## API, compatibility, and documentation

No method signature, facade, named argument, protected extension point, configuration key, or callback registration API changes.

The behavioral difference appears only after an after-commit callback throws: Hypervel continues independent callbacks before rethrowing the original first failure. This is a deliberate correctness difference from current Laravel and is approved because the commit is already irreversible and stop-on-first silently loses registered work.

Do not add this to `src/docs/database.md`, package READMEs, or `src/docs/porting-from-laravel.md`:

- neither framework documents this failure-ordering detail;
- application and package porters need no code change;
- stop-on-first is not a known public Laravel API developers should account for;
- adding it would lower the signal of user-facing documentation.

The regression tests and this active plan are the correct records of the behavior.

Completed historical plans remain unchanged.

## Performance and scalability

The normal database, request, queue, event, and cache hot paths do not change. Work is added only while executing already-registered callbacks after a transaction commit or model mutation.

On successful callback execution, the added cost is:

- one nullable local per record/manager loop;
- a `try` boundary around a callback that already dominates call cost;
- no extra I/O, allocation proportional to callback count, lock, container resolution, context access, or serialization.

On failure, the framework intentionally performs work that was previously discarded. That additional work is the correctness fix, not avoidable overhead. This change adds no retained callback or descriptor state and does not change either collection's lifetime.

## Tests

### Database manager unit contract

Update `tests/Database/DatabaseTransactionsManagerTest.php` to replace the old stop-on-first assertion with one nested transaction scenario that proves both ownership layers:

- the inner record's first callback throws;
- the inner record's second callback still runs;
- the outer record's first callback throws a different exception;
- the outer record's second callback still runs;
- observed order remains inner FIFO followed by outer FIFO;
- the exact inner/earliest exception is rethrown;
- pending and committed manager collections are empty before control returns.

This single scenario fails if either the record loop or manager loop regresses.

Add a separate multi-connection failure scenario:

- begin B at levels 1 and 2, attach B's callback explicitly to connection B, then commit level 2 to level 1 so its inner record is staged without running;
- begin A at level 1 and attach A's throwing callback explicitly to connection A;
- commit A and assert its exact exception;
- confirm B's staged inner record and pending root record both remain registered;
- commit B's root and confirm the inner callback runs;
- confirm both transaction collections are empty only after B settles.

This pins the load-bearing rule that staged records for other connections are published back to coroutine state before callbacks for the committed connection begin, while pending records remain connection-isolated.

### Database integration contract

Update `tests/Integration/Database/EloquentTransactionWithAfterCommitTests.php` so the existing exception test continues to assert truthful root transaction depth and now asserts the callback registered after the failure also runs. Add a concise comment at that assertion boundary stating that Hypervel deliberately differs from Laravel because committed callbacks are independent and must not be silently discarded during failure.

The integration scenario proves exhaustive execution within the outer record. Its inner record runs before the throwing outer callback, so cross-record continuation is covered only by the manager unit test and must not be removed from that test.

Run the shared trait through every executable wrapper:

- `EloquentTransactionWithAfterCommitTest.php`
- `EloquentTransactionWithAfterCommitUsingDatabaseMigrationsTest.php`
- `EloquentTransactionWithAfterCommitUsingDatabaseTransactionsTest.php`
- `EloquentTransactionWithAfterCommitUsingRefreshDatabaseTest.php`
- `EloquentTransactionWithAfterCommitUsingRefreshDatabaseOnMultipleConnectionsTest.php`

The transaction-wrapper variant is skipped only for its existing in-memory SQLite limitation and runs in the database CI matrix.

Retain the existing `DatabaseConnectionTest` contract proving a manager callback failure still dispatches `TransactionCommitted`, leaves transaction depth at zero, and leaves the PDO outside a transaction.

### Auth multi-store invalidation

Add a regression to `tests/Integration/Auth/EloquentUserProviderCacheTest.php` with two descriptors for one model:

- both coordinator invalidations throw distinct prebuilt exceptions;
- Mockery requires both invalidations exactly once;
- the exact first exception is observed by the caller.

Run the Auth cache unit suite as supporting coverage for descriptor registration and provider behavior.

### Individually deferred event listeners

Add a regression to `tests/Integration/Events/ListenerTest.php` with two listeners marked for after-commit handling:

- dispatch one event while a real transaction manager record is active;
- make the first listener run and throw a prebuilt exception;
- prove the second listener still runs when the record commits;
- prove the exact first exception is rethrown.

This pins the existing `Dispatcher` registration boundary together with the Database failure policy. Manager-only tests cannot prevent a later refactor from grouping sibling listeners into one callback and silently restoring the discarded-work defect. Do not change `Dispatcher`; its separate listener callbacks are the correct Laravel-shaped registration model.

### Verification commands

Run changed tests immediately, then the focused neighboring suites:

```shell
./vendor/bin/phpunit --no-progress tests/Database/DatabaseTransactionsManagerTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseTransactionsTest.php
./vendor/bin/phpunit --no-progress tests/Auth/AuthEloquentUserProviderCacheTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Auth/EloquentUserProviderCacheTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Events/ListenerTest.php
```

Run each executable shared Database integration wrapper listed above. Finish with:

```shell
composer fix
git diff --check
```

## Review checklist

- Trace physical commit, logical level publication, record detachment, callback execution, committed-event dispatch, and exception precedence end to end.
- Confirm records for other connections remain registered and untouched.
- Confirm callback order and exact exception identity remain stable.
- Confirm re-entrant callbacks cannot see detached records or rerun old callbacks.
- Confirm testing managers inherit the corrected executor without changing wrapper-transaction selection.
- Confirm Auth attempts every independent configured store and retains existing keys and lock ownership.
- Re-scan every components `addCallback()`, `afterCommit()`, and `afterCommitOrNow()` consumer for hidden reliance on stop-on-first.
- Confirm `ShouldDispatchAfterCommit` events retain one callback while individually deferred listeners retain one callback each.
- Check for unnecessary helpers, abstractions, comments, public APIs, locks, context state, and user documentation.
- Confirm completed historical plans have no diff.
- Review the final diff for formatting, strict types, Laravel-style naming, coroutine safety, performance, and stale code.

## Downstream revalidation

Components consumers need no source changes beyond the Auth multi-store loop. Events, queues, mail, notifications, broadcasts, Scout, fakes, Sanctum, and ordinary Auth callbacks benefit from the shared executor automatically.

The private Activity Log package contains an explicit workaround and test for the old stop-on-first behavior. Once this components change is consumed there, its buffer finalization code comment and regression test must be updated so committed activity is expected to settle despite an unrelated earlier callback failure; completed Activity Log plans remain historical. The private Workflow package catches publication failures for the still-valid reason that a post-commit publication error must not turn a committed operation into an application failure; no Workflow source change is required.

Merge this Database branch before resuming `fix/permission-audit-remediation` in `components-permission-remediation`. The Permission cache-settlement design depends on exhaustive after-commit execution and should consume the merged framework boundary rather than carry its own workaround.
