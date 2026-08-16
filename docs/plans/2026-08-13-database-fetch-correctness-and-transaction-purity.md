# Database fetch correctness and transaction purity plan

## Status and objective

Correct the database connection and query-builder fetch-mode contract, preserve every returned row during streaming, make shape-owning terminals independent of query-scoped custom row modes, make transaction-state reads truthful to PHPStan through both the connection contract and `DB` facade, and repair Testbench's orphaned serve-runtime reaper.

This is framework correctness work, not Workflow-specific behavior. It keeps Laravel's query-scoped `fetchUsing()`, deliberately removes Laravel's ineffective Capsule-wide fetch setter, restores current `selectResultSets()` parity, widens one incorrect Hypervel native return type, improves static result types, and fixes interrupted-test cleanup without adding queries, network round trips, new configuration, global state, or caches.

## Verified defects and provenance

Hypervel ported Laravel's PDO fetch-mode feature, including several upstream defects, and has additional Hypervel-specific omissions and typing mistakes. The current local Laravel 13.x source still contains the shared defects; the missing `selectResultSets(..., $fetchUsing)` argument is current Laravel behavior that Hypervel omitted.

| Area | Verified behavior | Ownership |
|---|---|---|
| Raw cursor termination | `while ($record = $statement->fetch(...))` stops on valid `null`, `false`, `0`, `"0"`, and `""` rows. | Shared upstream defect |
| Raw cursor arguments | `fetch(PDO::FETCH_COLUMN, 1)` treats `1` as cursor orientation, not the requested column. SQLite returned no rows in the direct reproduction. | Shared upstream defect |
| Raw cursor defaults | `fetchAll(PDO::FETCH_COLUMN)` defaults to column `0` and `fetchAll(PDO::FETCH_CLASS)` defaults to `stdClass`, while `setFetchMode()` requires those arguments explicitly. A direct `fetch(PDO::FETCH_CLASS)` also fails without a class. | Shared upstream defect |
| Pretend cursor | The pretend callback returns `[]`; cursor then calls `fetch()` on that array. | Shared upstream defect |
| Result sets | Hypervel cannot pass PDO fetch arguments to `selectResultSets()`. | Missing current Laravel parity |
| Capsule default | `Capsule\Manager::setFetchMode()` writes `database.fetch`, but no current connection-construction path reads it. Reviving a connection-wide setter would let numeric or scalar modes break framework-owned queries such as PostgreSQL `insertGetId()`, schema introspection, and migrations. | Shared upstream dead API |
| Query terminals | A custom row mode reaches `exists()`, aggregates, pagination counts, `pluck()`, and scalar-value helpers even though those methods own their result shape. `FETCH_COLUMN` made `exists()` false and `count()` zero for a non-empty SQLite table. | Shared defect, except Hypervel alone forwards the mode from `exists()` |
| Nullable rows | `firstOrFail()` and `findOr()` use `null` as the no-row signal, so a matching scalar `null` row is treated as absent. | Shared upstream defect |
| `find()` | Hypervel declares `object\|array\|null`, but a supported scalar fetch mode returns a scalar and causes a `TypeError`. | Hypervel-only native type defect |
| Cursor callbacks | Query Builder drops a legitimate `null` row after callbacks because `reject(is_null(...))` also represents an empty callback result. | Shared upstream defect |
| Group limits | Array rows are modified by value inside `each()`, so `hypervel_row` remains in `FETCH_ASSOC` results. | Shared upstream defect |
| ID iteration | `orderedLazyById()` reads the alias as an object property and fails for supported associative rows. `eachById()` also uses PDO-controlled result keys to calculate its positional callback index. | Shared upstream defects |
| Temporary columns | `onceWithColumns()` does not restore the original selection when its callback throws. | Shared upstream defect |
| Static analysis | `transactionLevel()` reads mutable state but is considered pure. The generated facade tag cannot carry PHPStan impurity metadata. | Hypervel typing defect |
| Testbench orphan reaper | Swoole changes a serve master's command line to `{app.name}.Master`, while stale-runtime cleanup requires a `testbench serve` or `hypervel serve` command. A terminated test worker can therefore leave the live server tree behind indefinitely. | Hypervel cleanup defect |

Direct SQLite checks also confirmed that setting the statement fetch mode once and iterating the statement returns the requested second column and preserves falsey rows. `FETCH_KEY_PAIR`, `FETCH_GROUP`, and `FETCH_UNIQUE` are whole-result aggregation modes under `fetchAll()`; statement iteration can only expose their per-row forms. Conversely, `FETCH_LAZY` and `FETCH_INTO` are legal streaming row modes that `fetchAll()` rejects.

`Connection::selectOne()` and `Connection::scalar()` deliberately retain their fixed signatures in both Hypervel and current Laravel. They own single-result shapes and are not missing `fetchUsing` parity; `selectResultSets()` is the only omitted connection argument.

## Public contract and performance boundaries

### Fetch-mode ownership

`fetchUsing()` customizes the shape of returned rows. It does not redefine methods that already promise booleans, counts, scalar values, or plucked collections.

| Behavior | Custom fetch mode |
|---|---|
| `get`, `first`, `find`, `firstOrFail`, `findOr`, `sole` | Honor it |
| `chunk`, `each`, and `lazy` | Honor any per-row shape |
| ID-based chunk/each/lazy variants | Honor it when the row remains an array/object containing the required ID alias |
| `cursor` | Honor it per streamed row |
| `simplePaginate` and paginated result rows | Honor it |
| `cursorPaginate` | Honor array/object row modes; cursor pagination still requires named fields |
| `exists` / `doesntExist` families | Ignore it |
| aggregates and pagination-count queries | Ignore it |
| `pluck`, `implode`, `value`, `rawValue`, `soleValue` | Ignore it |

Do not reject legal PDO modes globally or create a mode registry. Operations that need named columns already fail through their existing missing-column or array/object contracts when given an incompatible scalar mode.

### Cost and state

- Keep the default `get()` SQL, binding, statement, and result-processing path unchanged. Its only new local work is choosing the scoped fetch override when one is active.
- Configure a cursor's custom PDO mode once per statement, not on every fetched row. Normalize only the mode-only column and class defaults that `fetchAll()` supplies implicitly. Statement iteration stays streaming and adds no buffering.
- Shape-owning methods use one exception-safe builder-local override. Existing aggregate and pagination clones inherit it, so a `beforeQuery()` callback cannot re-enable a custom row shape for the query it is about to run.
- Rework Query Builder cursor callbacks as one lazy generator instead of a `map()` plus `reject()` pipeline.
- Keep the connection's existing fixed object default immutable. Query-scoped `fetchUsing()` changes only the statement for that query, so framework-owned queries continue to receive the named object fields they require.
- Add no SQL, reconnect, pool checkout, serialization, or network work.

## Final implementation

### 1. Connection fetch execution

Update `Connection::cursor()` so pretend mode returns an explicit `null` sentinel, while real execution returns the prepared `PDOStatement`. Before applying custom arguments with `setFetchMode()` once, preserve the mode-only defaults that `fetchAll()` supplies but `setFetchMode()` requires explicitly:

```php
$statement = $this->run($query, $bindings, function (...) {
    if ($this->pretending()) {
        return null;
    }

    // Prepare, bind, execute, and return PDOStatement.
});

if ($statement === null) {
    return;
}

if ($fetchUsing !== []) {
    // fetchAll() supplies default column and class arguments that setFetchMode()
    // demands explicitly, so a mode-only call keeps the same meaning when streamed.
    if (count($fetchUsing) === 1) {
        $mode = $fetchUsing[0] & ~(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE | PDO::FETCH_CLASSTYPE | PDO::FETCH_PROPS_LATE);

        if ($mode === PDO::FETCH_COLUMN) {
            $fetchUsing[] = 0;
        } elseif ($mode === PDO::FETCH_CLASS && ($fetchUsing[0] & PDO::FETCH_CLASSTYPE) === 0) {
            $fetchUsing[] = stdClass::class;
        }
    }

    $statement->setFetchMode(...$fetchUsing);
}

foreach ($statement as $record) {
    yield $record;
}
```

Derive the base mode by clearing the runtime modifier constants rather than a numeric mask because PHP 8.5 changes their values. Do not include deprecated `FETCH_SERIALIZE`: it has no successful one-argument default to preserve. Leave every other mode and argument list to PDO validation. The default mode remains owned by `prepared()`; do not widen `run()`, add a defensive type branch, or buffer rows.

Update the cursor docblocks on `Connection` and `ConnectionInterface` to `Generator<int, mixed>` and correct the touched cursor/result-set title grammar. The row value is intentionally `mixed` because PDO modes can return scalars, arrays, objects, or `null`.

Add `array $fetchUsing = []` to `Connection::selectResultSets()`, capture it in the execution closure, and pass it to every `fetchAll()`. Keep this concrete-only, matching Laravel: `selectResultSets()` is not behavior required from every `ConnectionInterface` implementation.

Lift `ConnectionInterface::withoutTablePrefix()` to the same callback template and return contract already declared by `Connection`, so interface-typed callers retain their callback result.

Remove the severed connection-wide API rather than reviving unsafe mutable state:

- Remove `Capsule\Manager::setFetchMode()`. It is a public no-op in current Laravel and Hypervel because nothing consumes the `database.fetch` value it writes.
- Do not add `Connection::setFetchMode()`, manager config propagation, a configurable default constant, pool-reset behavior, a facade method, or connection-default documentation/tests.
- Keep `Connection::$fetchMode = PDO::FETCH_OBJ` as the immutable statement default. Framework-owned queries depend on named object fields, and a connection-wide numeric or scalar mode would break supported operations outside Query Builder's terminal methods.
- Add the required `REMOVED:` source and matching test comments at the natural Capsule method positions. Record the deliberate public API omission in the database package README and direct callers to query-scoped `Query\Builder::fetchUsing()`.

Do not protect a connection-wide mode through an inventory of internal selects. PostgreSQL ID retrieval, schema processors, migration storage, and future framework-owned queries would make that list incomplete and prone to drift.

### 2. Query Builder result-shape policy

Keep `runSelect()` as the one row-returning connection call. Add a nullable protected fetch override and select `($this->fetchUsingOverride ?? $this->fetchUsing)` only when making that connection call. A protected, callback-generic `withoutFetchUsing()` helper sets the override to `[]`, invokes its callback, and restores the previous override in `finally`; a throwing query therefore cannot strand the override, and the callback return type is preserved.

```php
$previousOverride = $this->fetchUsingOverride;
$this->fetchUsingOverride = [];

try {
    return $callback();
} finally {
    $this->fetchUsingOverride = $previousOverride;
}
```

Use that scoped helper at shape-owning terminals:

- `exists()` calls `select()` without the fourth argument.
- `aggregate()` executes its existing clone pipeline inside `withoutFetchUsing()`; both grouped and non-grouped aggregate clones inherit the override.
- `getCountForPagination()` executes its existing count pipeline inside `withoutFetchUsing()`. The non-grouped clone needs the override; the grouped branch already runs its aggregate on a fresh builder, and remains covered as a regression guard.
- `pluck()`, `value()`, `rawValue()`, and `soleValue()` execute their existing logic inside `withoutFetchUsing()`. Preserve `rawValue()`'s existing `selectRaw()` mutation and every method's current processor and after-query-callback behavior.
- `implode()` inherits the corrected `pluck()` behavior.

Do not clear the public `fetchUsing` property or introduce new clones: either approach lets `beforeQuery()` callbacks change semantics or changes which builder consumes them. The scoped override preserves each method's current callback ownership—original-builder methods still keep callback mutations and one-shot consumption on the caller, while existing aggregate/count clones remain clones—and ensures a callback that itself calls `fetchUsing()` cannot change a shape-owning result. An original-builder callback's chosen mode remains available to a later row-returning terminal after the override is restored.

Do not add a policy object, terminal allowlist, execution context, or public reset helper. This is transient state on one builder instance, not coroutine or worker state, and it adds no query, I/O, or allocation proportional to result size.

Make `onceWithColumns()` restore the original columns in `finally`; query failures must not leave a reusable builder partly mutated.

### 3. Presence, streaming, and row-shape correctness

Change `firstOrFail()` to retrieve the one-row collection, test collection emptiness, then return its first value. Change `findOr()` similarly after applying the ID predicate. A non-empty collection containing `null` is a found row; only an empty collection runs the fallback or throws.

Keep `first()` and `find()` returning their existing value-or-`null` shape. Those APIs cannot distinguish a scalar `null` row from absence without an incompatible return contract; the collection-aware throwing/fallback methods can and must make that distinction internally.

Widen Query Builder's native `find()` return type to `mixed`, with its PHPDoc carrying `TValue|null`. This is a compatible widening required by the existing public fetch-mode API.

Replace Query Builder cursor's `map()->reject()` chain with a single lazy generator:

```php
foreach ($this->connection->cursor(...) as $key => $item) {
    $items = $this->applyAfterQueryCallbacks(new Collection([$item]));

    if ($items->isNotEmpty()) {
        yield $key => $items->first();
    }
}
```

This preserves a real `null` row or a callback result of `collect([null])`, while an explicitly empty collection still removes the row. Keep the existing rule that a falsey non-object callback return falls back to its input collection.

Change `withoutGroupLimitKeys()` to `transform()` each item and return the cleaned value. Unset array keys on arrays, object properties on objects, and leave scalar rows unchanged. Do not add row-wrapper objects or normalize all modes to arrays.

Use `data_get($results->last(), $alias)` in `orderedLazyById()`, matching `orderedChunkById()`, so associative and object rows share the same alias lookup and diagnostic.

In both `each()` and `eachById()`, calculate the documented integer callback position with a local counter, not the collection key. Preserve `each()`'s current per-chunk zero-based positions and `eachById()`'s global page offset. PDO modes such as `FETCH_UNIQUE` can produce non-sequential or string collection keys even when the row remains usable; leaking that key violates the native `int` callback contract and makes the ID variant attempt integer arithmetic on a string.

### 4. Precise Query Builder generics

Make raw Query Builder's result type reflect the selected PDO mode without weakening default inference:

```php
/**
 * @template TKey of array-key = int
 * @template TValue = \stdClass
 */
class Builder
{
    /** @use BuildsQueries<TKey, TValue> */
    use BuildsQueries;

    /**
     * The @return $this tag lets @phpstan-this-out reach a chained call.
     *
     * @return $this
     *
     * @phpstan-this-out self<array-key, mixed>
     */
    public function fetchUsing(mixed ...$fetchUsing): static;
}
```

Keep the native `static` return and Laravel's same-instance `@return $this` tag. PHPStan needs the explicit tag to apply the out-type to a directly chained call; without it, only a standalone call widens. The fixed `self<array-key, mixed>` out-type intentionally rebinds custom Query Builder subclasses to the base builder in PHPStan after this call. Do not add a return-type extension or weaken default query inference to preserve subclass-specific analysis on this one shape.

- `get()` returns `Collection<TKey, TValue>` and `runSelect()` returns the matching array.
- Make `Processor::processSelect()` generic over the same key/value pair so the existing pass-through processor does not erase the connection result type before `Collection` construction.
- `find()`, `first()`, `firstOrFail()`, and `sole()` use `TValue` in PHPDoc. Rename `findOr()`'s method template to `TFindOrValue` and return `TValue|TFindOrValue` so it does not shadow the builder value template.
- Make the existing result-cleanup and after-query-callback annotations preserve the builder key/value pair; do not attempt to infer arbitrary callback replacement types or change callback runtime behavior.
- Raw cursor/lazy methods remain integer-keyed because they yield rows sequentially.
- Expand `BuildsQueries` to `TKey` and `TValue`. Use `TKey` for collections and callback keys that actually flow from `get()`; keep synthesized page/position arguments as `int` and lazy outputs as integer-keyed.
- Parameterize the trait's mixin as `Query\Builder<TKey, TValue>` so its declaration agrees with its templates and consumers without a class-level mixin inherit the right result pair.
- Eloquent applies the trait as `BuildsQueries<int, TModel>`; its model result contract does not become `mixed`.
- Add `@method $this fetchUsing(mixed ...$fetchUsing)` to Eloquent Builder. Its magic forwarding always returns the Eloquent builder, but Query Builder's out-type otherwise gives a directly chained `fetchUsing()->get()` expression the raw-query result type. The explicit tag also corrects Relation's two-level Eloquent mixin; do not duplicate it on Relation or attempt to repair the existing class-identity loss after another forwarded method in the same chain.
- Calling `fetchUsing()` with no arguments resets runtime behavior but leaves static type conservatively widened. Do not add conditional PHPDoc machinery for a reset call.
- Template defaults keep unparameterized `Query\Builder` references source-compatible and avoid broad annotation churn.

Remove stale local PHPStan ignores only when the new templates make them unnecessary. Do not add runtime casts, assertions, or global PHPStan ignores to satisfy analysis.

### 5. Transaction-state impurity and facade generation

Mark only `ConnectionInterface::transactionLevel()` with `@phpstan-impure`. The interface owns the semantic contract, and implementors inherit it; duplicating the tag on the trait or concrete connection would create drift.

Add `@mixin \Hypervel\Database\ConnectionInterface` to `DB`. Since generated `@method` tags cannot carry impurity metadata, add this narrow hook beside the accessor:

```php
protected static function ignoredFacadeDocumenterMethods(): array
{
    return ['transactionLevel'];
}
```

Explain in the method docblock that the mixin supplies impurity and that exclusion is name-based. Do not exclude the whole interface: the generated manager surface has richer signatures, including `DatabaseManager::disconnect($name)`, that would collide with the no-argument connection method.

Regenerate only the `DB` facade with the repository's facade documenter. The generated `transactionLevel()` tag must disappear, `selectResultSets()` must gain the fourth argument, no connection-wide fetch setter may appear, and every other generated manager/connection method must remain.

### 6. Testbench orphaned serve-runtime cleanup

Remove the command-line predicate from `Bootstrapper::matchesServeProcessIdentity()` and delete its now-unused `processCommand()` helper. A real Swoole master changes its command line to `{app.name}.Master`, so command matching makes the intended reaper reject the process it owns.

Keep every load-bearing ownership check:

- the process is alive and orphaned under PID 1;
- the runtime's `storage/framework/hypervel.pid` equals the candidate PID, distinguishing a serve master from an orphaned PHPUnit worker;
- the runtime marker PID equals the candidate PID;
- the marker's start identity matches the live process incarnation, excluding PID reuse.

Update the stale sweep comment, orphan predicate docblock, and macOS start-identity rationale. Do not replace the broken command check with a configurable process-title regex, add marker fields, or build a repository process supervisor.

Add a focused positive regression using a child process whose real title is changed to `Testbench.Master`, and a negative regression with the same live identity but no server PID file. Do not launch Swoole or require a double-forked PPID-1 process; the regression owns the failed identity predicate and the pid-file safety boundary directly.

### 7. User documentation

Add a concise “Custom Fetch Modes” subsection after “Aggregates” and before “Select Statements” in `src/docs/queries.md`, and add it to that page's contents. Use Laravel-style prose and one `PDO::FETCH_ASSOC` example.

Document only public behavior:

- `fetchUsing()` forwards PDO fetch-mode arguments for row-returning Query Builder operations;
- calling it with no arguments restores the connection's fixed object fetch mode;
- booleans, aggregates, counts, plucks, and scalar helpers keep their documented shapes;
- chunking and lazy streaming honor the selected per-row shape; ID-based variants and cursor pagination still require array/object rows containing their ordering columns;
- streamed cursors apply modes per row: whole-result grouping/keying modes cannot retain `get()`'s aggregate shape, `FETCH_FUNC` is unavailable to cursors, and streaming modes such as `FETCH_LAZY` and `FETCH_INTO` are unavailable to `get()`;
- custom fetch modes belong to the base Query Builder; Eloquent hydration requires array or object rows and cannot consume scalar fetch modes;
- fetch modes are query-scoped; connections and Capsule do not expose a mutable connection-wide default.

Do not document internal overrides, clones, statement sentinels, facade generation, PHPStan metadata, or implementation history. Add one concise database README difference for the deliberately omitted Laravel Capsule setter; other corrections are parity or bug fixes and do not belong there.

## Testing plan

### Connection and facade tests

Update `tests/Database/DatabaseConnectionTest.php` and run it immediately:

- pretend cursor logs the query, resolves no PDO, yields no values, and does not throw;
- a live SQLite cursor preserves `null`, empty string, and `"0"`, continues to later rows, defaults a mode-only `FETCH_COLUMN` to column `0`, and returns column index `1` when requested;
- mode-only `FETCH_CLASS` defaults to `stdClass`, modifier-plus-column modes still receive the default column, and `FETCH_CLASS | FETCH_CLASSTYPE` continues to take its class from column `0` without an appended class;
- `selectResultSets()` forwards the exact custom fetch arguments to every row set;
- existing statement preparation, session synchronization, bindings, query log, and read/write routing remain unchanged.

Update the Capsule manager tests at the upstream method position with a concise `REMOVED:` comment that pins why the ineffective Laravel API is deliberately omitted. Remove every test added for manager propagation, connection-wide defaults, or pool reset because that behavior must not exist.

Extend `tests/FacadeDocumenter/IgnoredMethodsTest.php` to prove the name-based exclusion drops only `transactionLevel()` while retaining `DatabaseManager::disconnect($name)`. Regenerate `src/support/src/Facades/DB.php`, then run that test and `tests/FacadeDocumenter/FacadeDocblocksTest.php` to pin the generated surface.

### Query Builder integration tests

Extend `tests/Integration/Database/QueryBuilderTest.php` and run it after editing on SQLite, then through MySQL, MariaDB, and PostgreSQL:

- `get()` and `cursor()` return the same requested second column and preserve ordered `null`, `""`, `"0"`, and later non-empty values;
- `find()` returns a scalar without a native `TypeError`;
- `firstOrFail()` returns a found scalar `null`, and `findOr()` returns it without invoking its fallback;
- `exists`, `count`, pagination totals, `pluck`, `implode`, `value`, `rawValue`, and `soleValue` keep their documented shapes despite a deliberately incompatible custom row mode;
- a following `get()` on the same builder still uses its custom mode, proving shape-owning terminals did not clear caller state;
- on an original-builder terminal such as `pluck()`, a one-shot `beforeQuery()` callback still mutates the caller, is consumed once, and cannot force an incompatible fetch mode onto that result; its chosen mode remains available to the following row-returning query;
- `FETCH_ASSOC` group-limited results contain no internal ranking key;
- `FETCH_ASSOC` works through `lazyById()` when the ID alias is selected;
- `cursorPaginate()` returns associative rows and derives its next cursor from their ordering field under `FETCH_ASSOC`;
- `each()` keeps per-chunk integer positions and `eachById()` supplies global zero-based positions even when `FETCH_UNIQUE` gives the collection string keys;
- a failed select restores the builder's original columns through `onceWithColumns()`.

Extend `tests/Integration/Database/AfterQueryTest.php` and run it on the same matrix:

- an empty callback collection removes a cursor row;
- a real scalar `null` row and `collect([null])` callback result are retained;
- existing Eloquent and base-builder callback replacement behavior remains unchanged.

Keep cross-engine assertions on portable text/null values. Add a driver-specific boolean assertion only where the driver exposes a stable native boolean; do not normalize legitimate PDO driver differences in production code.

### Testbench cleanup tests

Update `tests/Testbench/BootstrapperTest.php` and run it immediately:

- a live child whose process title is `Testbench.Master`, PID file matches, runtime marker matches, and real start identity matches is recognized as the owned serve process;
- the same live identity without `storage/framework/hypervel.pid` is rejected, proving an orphaned PHPUnit worker cannot be mistaken for a serve master;
- PID-file mismatch, marker mismatch, start-identity mismatch, malformed marker, dead PID, and active-runtime protections remain covered.

### Static type fixtures

Update `types/Database/Query/Builder.php`:

- default Query Builder results remain `stdClass` with integer keys;
- chained and statement-form `fetchUsing()` calls widen `get()` and relevant callbacks to `mixed` with `(int|string)` keys, matching PHPStan's rendered `array-key` form, including when a base-builder method appears later in the chain;
- cursor/lazy outputs remain integer-keyed;
- a no-argument reset remains conservatively widened;
- directly chained Eloquent and Relation `fetchUsing()->get()` results remain their model collections; statement-form Eloquent calls also retain `TModel`;

Add `types/Database/Connection.php`:

- a second interface and `DB::transactionLevel()` read immediately after a narrowing branch is `int`, with no intervening call that could independently invalidate memoization;
- `withoutTablePrefix()` preserves a literal callback result;
- raw cursor values are `mixed`.

Run `vendor/bin/phpstan analyse -c phpstan.types.neon.dist` after each type-fixture change. Run the normal source PHPStan at the checkpoint to detect any generic surface that needs a precise local annotation; do not widen the global design to silence incidental errors.

## Implementation order and verification

1. Correct `Connection`, remove the dead Capsule-wide API and superseded connection-default work, update interface docblocks/templates, and run each affected database test file immediately after editing it.
2. Correct Query Builder terminal ownership, presence checks, cursor callbacks, group-limit cleanup, and ID iteration. Update and run `QueryBuilderTest`, then `AfterQueryTest`, one file at a time.
3. Add the Query Builder, `BuildsQueries`, and `Processor::processSelect()` generics, update Eloquent's trait application, and run the type fixtures.
4. Add transaction impurity metadata, the narrow facade mixin/exclusion, regenerate `DB`, and run facade-documenter coverage plus the new connection type fixture.
5. Fix Testbench's serve-runtime identity predicate and run `BootstrapperTest` immediately.
6. Add the user-facing query documentation and database README difference.
7. Run the full database integration group on `sqlite`, `mysql`, `mariadb`, and `pgsql` with `bin/run-database-tests.sh`.
8. Run `composer fix` once at the completed checkpoint on a host with enough headroom. Keep Composer's existing five-minute timeout and ParaTest's normal six workers; an over-budget run is unhealthy and must fail. If it fails, terminate verified orphans, correct with targeted checks, then run the failed command and every remaining `fix` entry as required by `AGENTS.md`.
9. Freshly trace every changed caller and callee for Laravel API/named-argument compatibility, PDO-mode behavior, query/binding count, callback semantics, Eloquent generic preservation, coroutine/worker state, Testbench cleanup safety, hot-path allocation, stale/dead artifacts, and overengineering. Complete adversarial peer code review before commit.

## References

- Current local Laravel 13.x: `Connection`, `ConnectionInterface`, `Query\Builder`, `BuildsQueries`, and database integration tests under `examples/laravel/framework`.
- Laravel PDO fetch-mode changes: [#54734](https://github.com/laravel/framework/pull/54734) and [#55394](https://github.com/laravel/framework/pull/55394).
- PHP manual: [`PDOStatement::setFetchMode`](https://www.php.net/manual/en/pdostatement.setfetchmode.php), [`PDOStatement::fetchAll`](https://www.php.net/manual/en/pdostatement.fetchall.php), and [`PDOStatement` iteration](https://www.php.net/manual/en/class.pdostatement.php).

The work is complete only when custom row modes preserve every streamed row, row-returning terminals expose the requested shape, shape-owning terminals remain stable, nullable rows are distinguished from absence where the API permits, internal group-limit fields never leak, iteration positions never derive from PDO result keys, no mutable connection-wide fetch API or remnants remain, mutable transaction reads remain impure through the facade, Testbench safely reaps identity-matched orphaned serve trees without targeting PHPUnit workers, documentation states the supported Query Builder/Eloquent boundaries, and all four database engines plus full repository checks pass.
