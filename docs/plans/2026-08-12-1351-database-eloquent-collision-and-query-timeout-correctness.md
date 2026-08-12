# Database Eloquent collision and query timeout correctness plan

## Status and objective

Implement one Components database correction that makes Eloquent create-or-retrieve collision fallbacks truthful and makes the documented MySQL/MariaDB query timeout apply to the complete executed select statement.

The change keeps Laravel's public Eloquent and query-builder APIs. It adds no public framework surface, process-global state, worker-lifetime cache, connection mutation, retry loop, or extra work on ordinary Eloquent success paths. PostgreSQL and SQLite timeout behavior remains unchanged because the public timeout contract is explicitly MySQL/MariaDB-only.

Preserve the documented MariaDB timeout rather than narrowing the feature to MySQL. MariaDB has a native single-statement primitive that implements the existing contract without session mutation or a new public API.

This plan is the authoritative design for the Components work that must land before Workflow execution Work Order 3 resumes.

## Verified defects and boundaries

### Eloquent collision fallbacks

The generic Eloquent builder and direct has-one/has-many fallback already read from the write PDO. Two relation variants do not:

- `HasOneOrManyThrough::createOrFirst()` catches an insert collision and rereads through the relation without `useWritePdo()`.
- `BelongsToMany::createOrFirst()` catches a related-model insert collision and rereads the related table without `useWritePdo()`.

Those reads can miss the winning row on a configured replica. Keep every existing builder shape and mutation rule; add write routing only at these two missing sites. Do not normalize the other fallbacks, add locks, or clone builders. The through relation already mutates itself with `where()`, while calls forwarded from the related model create a fresh Eloquent builder.

Two `BelongsToMany` pivot-attach catches can also report success for the wrong unique violation:

- `firstOrCreate()` swallows every pivot insert unique violation and returns the related model.
- `createOrFirst()` re-queries the joined relation by related attributes, which does not prove that the exact related model was attached and can select another matching model.

After an attach collision, success is valid only when the exact intended relation membership is visible on the write PDO. `newPivotStatementForId()` already includes the parent key, related key, configured `wherePivot*` predicates, and `MorphToMany`'s morph discriminator. Reuse that query rather than adding relation-specific SQL or a new pivot API.

PostgreSQL repeatable-read snapshots cannot see rows committed after the caller's snapshot. A locking read does not fix that and can break grouped, union, or one-of-many relation shapes on supported engines. Generic Eloquent cannot restart arbitrary caller work or identify which unique constraint was intended. When exact membership or the winning model is not visible, preserve and rethrow the original unique violation; callers that need idempotent behavior inside repeatable-read must retry their complete owning transaction.

### Query timeout statement placement

`Builder::timeout()` is documented for MySQL and MariaDB selects. The current implementation has five correctness gaps:

- `MariaDbGrammar` inherits MySQL's `MAX_EXECUTION_TIME` optimizer hint. Supported MariaDB 10/11 releases ignore that hint; their portable per-statement form is `SET STATEMENT max_statement_time=<seconds> FOR <statement>`.
- MySQL requires `MAX_EXECUTION_TIME` after the first `SELECT` and applies it to the complete statement. `exists()`, union aggregates, and grouped pagination currently decorate an inner select instead of the executed outer statement.
- The current MySQL replacement is anchored to `^select`, but an ordinary compiled union starts with `(select`; a timed union therefore receives no hint.
- A timed builder embedded as a subquery, relationship aggregate, relationship existence constraint, or union member carries a statement-level setting into a location where it cannot represent an independent timeout.
- `explain()` does not execute selected rows, while MariaDB's prefix cannot be nested after `EXPLAIN`. Keeping the timeout would either be ignored or produce invalid SQL.
- Retained where-subquery and exists builders are assembled by the outer grammar, so two same-database connections with different table prefixes can silently target the outer connection's table. Union members keep their own grammar but compile through its public decorating entry point. A child timed after composition can therefore emit statement decoration inside the outer statement even though the same child may legitimately be executed separately with its own timeout.

Real MySQL and MariaDB checks confirmed:

- MySQL's existing hint interrupts both a long select and a blocked `SELECT ... FOR UPDATE`.
- MariaDB 10.11 ignores that hint, while `SET STATEMENT max_statement_time=... FOR SELECT ...` interrupts both a long select and a blocked locking select.
- MySQL accepts its hint after the first `SELECT` inside a parenthesized union, and MariaDB accepts `SET STATEMENT ... FOR` before the complete parenthesized union statement.
- MariaDB reports its timeout in seconds; MySQL's hint remains in milliseconds.

The API remains select-only. It supports top-level locking selects, but it is not a DML timeout or a complete transaction lock-wait mechanism. Workflow must not use it as a replacement for its engine-specific session lock-wait checks.

### Queryable subquery type parity

Laravel deliberately treats `Relation` as queryable because it forwards to an Eloquent builder, but the `@param` annotations on many Query and Eloquent Builder methods were never updated when that runtime support was added. Hypervel converted those stale annotations into native types, making supported subquery calls fail with `TypeError`. Several forwarding wrappers are also natively narrower than the methods they call, rejecting Query or Eloquent builders before the existing `isQueryable()` logic can handle them.

One related runtime defect exists in both frameworks: `whereSub()` recognizes a Relation but stores it for `Grammar::compileSelect()`, which requires a Query Builder. Cross-database Relation subqueries also read `$relation->from`, although Relation has no property forwarding, and can corrupt the underlying table prefix. Normalize Relations at the existing builder boundaries rather than adding another query abstraction.

The invariant is: no method may declare a parameter type narrower than the method it forwards to, and no parameter type may be narrower than what its own body accepts through `isQueryable()`.

## Final implementation

### Truthful Eloquent recovery

1. In `HasOneOrManyThrough::createOrFirst()`, route only the collision fallback to the write PDO:

   ```php
   return $this->useWritePdo()->where($attributes)->first() ?? throw $exception;
   ```

2. In the first `BelongsToMany::createOrFirst()` fallback, keep the related model's fresh builder and route the read to the write PDO before `first()`.

3. Add one protected `BelongsToMany::hasAttachedPivot(Model $instance): bool` predicate beside the create-or-retrieve methods:

   ```php
   protected function hasAttachedPivot(Model $instance): bool
   {
       return $this->newPivotStatementForId($instance->getKey())
           ->useWritePdo()
           ->exists();
   }
   ```

4. Capture the pivot attach exception in both methods. Return the already-selected related model only when `hasAttachedPivot()` succeeds; otherwise rethrow that attach exception. Refactor `createOrFirst()`'s `tap()` expression into a direct local `$instance`, attach attempt, exact-membership check, and return so the collision subject cannot be lost or replaced.

5. Do not lock the fallback query, classify every unique violation as a concurrency error, or add generic retries. The write-PDO correction fixes replica visibility where the current transaction can see the winner; the exact predicate prevents false success without claiming to solve caller-owned snapshot isolation.

6. Type the create-or-retrieve, save, and create methods as returning plain related models. Those paths attach a pivot row but do not hydrate a `pivot` property; retain the pivot intersection only for models loaded through the relation query.

### Statement-owned query timeout

1. Split the base select grammar at a protected raw-assembly seam and make its public entry point own final-statement decoration:

   ```php
   public function compileSelect(Builder $query): string
   {
       return $this->compileSelectTimeout(
           $query,
           $this->compileSelectQuery($query),
       );
   }

   protected function compileSelectQuery(Builder $query): string
   {
       // Existing select assembly, including group-limit and union handling.
   }

   protected function compileSelectTimeout(Builder $query, string $sql): string
   {
       return $sql;
   }
   ```

   Keep the raw assembler and decorator protected. One base entry point owns the rule that only the complete executed statement is decorated; driver grammars only supply syntax. Do not add `compileSubSelect()`, `toSubSql()`, a public timeout capability API, or another grammar abstraction.

2. Make `compileSelect()` and `compileExists()` the only complete-statement decorating entry points. Every embedded select fragment uses `compileSelectQuery()`:

   - `compileUnionAggregate()` uses the raw assembler for its derived table; the public `compileSelect()` applies the timeout to the aggregate statement.
   - `compileExists()` uses the raw assembler for its inner query, builds `select exists(...)`, then calls `compileSelectTimeout()` on that outer statement.
   - retained `whereSub`, `whereExists`, `whereNotExists`, and union members use their own grammar's raw assembler, so connection-owned details such as table prefixes remain correct while later child timeout changes affect standalone execution but cannot decorate the containing statement;
   - SQLite and PostgreSQL update/delete rewrites use the raw assembler for their internal row-identifier selects.

   The fragment grammar owns raw fragment assembly; the outer grammar owns only the surrounding column/operator, exists/not-exists syntax, union conjunction, and union wrapping. PHP permits the shared declaring class to invoke the protected raw assembler on sibling driver grammar instances and still dispatches to the runtime override. Do not route fragments back through public `compileSelect()` or assemble them with the outer grammar.

   Delete `SQLiteGrammar::compileGroupLimit()`. Hypervel supports SQLite 3.26+, so its pre-3.25 fallback is unreachable; if reached it silently removes the per-group limit, and on supported versions it needlessly resolves PDO to inspect the server version during compilation. The base window-function compiler is the only valid supported path.

3. Remove `MySqlGrammar::compileSelect()` and move its hint injection into `compileSelectTimeout()`, keeping the integer-seconds-to-milliseconds conversion. Match only the compiler-owned MySQL statement prefix, preserving any leading union parentheses and placing the hint after the first `SELECT`:

   ```php
   return preg_replace(
       '/^(\(*)select\b/i',
       '${1}select /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */',
       $sql,
       1,
   );
   ```

   Do not use an unanchored search or generic SQL parser. The raw assembler emits either `select...` or one or more wrapping parentheses followed by `select...`; the narrow prefix replacement fixes ordinary unions without touching nested selects.

4. Override only `compileSelectTimeout()` in `MariaDbGrammar`:

   ```php
   return $query->timeout === null
       ? $sql
       : 'SET STATEMENT max_statement_time=' . $query->timeout . ' FOR ' . $sql;
   ```

   The timeout is a validated positive integer, so it is emitted as a literal and adds no binding or session mutation.

5. In grouped/having pagination counts, copy the timeout from the inner clone to the new outer count builder. Clear it from the clone before `toSql()` so generated SQL and bindings describe one timed executed statement. Simple pagination counts already execute their cloned aggregate directly and keep the timeout unchanged.

6. Reject a timeout on a builder when it is accepted as a statement-producing part of another query. Add one protected Query Builder assertion and reuse it at the four distinct acceptance paths after closures and Eloquent builders are normalized to one scope-applied Query Builder snapshot:

   - `parseSub()` for select/from/join/order, queryable `whereIn()` / `whereNotIn()`, and other parsed subqueries, including `Relation` inputs;
   - `whereSub()`;
   - `addWhereExistsQuery()`; and
   - `union()`.

   Inspect the exact Query Builder snapshot that will be retained. This catches timeouts applied by global scopes and prevents SQL and bindings from being produced by separate scope applications. Throw `InvalidArgumentException` with:

   > An embedded query cannot define its own timeout. Apply the timeout to the outer query instead.

   Assert before cross-database prefixing or mutating the accepted child, storing it, or merging its bindings. Callers may have performed earlier fluent mutations of the outer builder; throwing does not roll those back. The outer builder may still be timed and may contain any number of untimed subqueries or union members.

   Normalize accepted Eloquent union members to `toBase()` before the guard, storage, and binding harvest. This gives the retained union one scope-applied Query Builder snapshot for timeout inspection, SQL, and bindings and lets the grammar's raw compiler receive its declared type. Do not clone members or promise that later Eloquent scope changes alter an attached union.

7. Reuse one protected local assertion in `QueriesRelationships`. Apply it to every `withAggregate()` arm before adding the default selection: the `exists` arm compiles with direct `toSql()`, while the other arms would otherwise reach Query Builder's generic guard only after that selection changed. Apply it once in `addHasWhere()` after constraint merging and before its exists/count branch so both paths use the relationship-specific diagnostic before storing the child or merging its bindings. Throw:

   > A relationship constraint cannot define its own query timeout. Apply the timeout to the outer query instead.

   Keep this helper local; a shared trait, exception type, or public timeout accessor would add machinery without another consumer.

8. In `ExplainsQueries::explain()`, reject a non-null timeout before calling `toSql()` with:

   > A query timeout cannot be applied to an EXPLAIN statement. Clear the timeout before calling explain().

   Do not silently strip the timeout or generate invalid `EXPLAIN SET STATEMENT ...` SQL.

9. Leave PostgreSQL and SQLite's documented timeout support unchanged. Untimed queries, update/delete rewrites, bindings, read/write routing, and connection/session state keep their current behavior. The base raw-assembly and no-op decorator calls add no branch, query, network round trip, allocation proportional to result size, or worker-lifetime state.

### Queryable subquery type correction

1. Normalize `Relation` at the start of `Query\Builder::parseSub()` with `getQuery()`, then normalize Eloquent Builder once with `toBase()`. Timeout inspection, cross-database prefixing, SQL, and bindings then use the same scope-applied Query Builder snapshot. Narrow `prependDatabaseNameIfCrossDatabaseQuery()` and `assertNoTimeoutOnEmbeddedQuery()` to Query Builder. Cross-database qualification applies only to a plain-string source; an absent source remains unchanged, while an opaque raw/derived-table expression must already qualify any database-owned references it contains. This fixes cross-database Relation table qualification, prevents repeated global-scope application, and catches timeouts applied by global scopes without another branch in downstream callers.
2. In `whereSub()`, normalize Query Builders directly and call `toBase()` on both Eloquent Builder and Relation inputs:

   ```php
   $query = $callback instanceof self ? $callback : $callback->toBase();
   ```

3. Add `Relation` to the native and PHPDoc unions for every Query Builder surface whose body accepts queryable subqueries: `createSub()`, `selectSub()`, `from()` / `fromSub()`, every subquery join and lateral-join variant including `straightJoinSub()`, `whereSub()`, `whereBetween()` / `whereBetweenColumns()`, `orderBy()`, and `insertUsing()` / `insertOrIgnoreUsing()`.
4. Make Query Builder forwarding wrappers match their callees: `orWhere()`, `whereNot()`, `orWhereNot()`, all six Between/BetweenColumns wrappers, and `orderByDesc()`, `latest()`, `oldest()`, `reorder()`, and `reorderDesc()`. Correct the queryable element PHPDoc unions on `whereAll()` / `orWhereAll()`, `whereAny()` / `orWhereAny()`, and `whereNone()` / `orWhereNone()`.
5. Make Eloquent Builder's application-facing `where()`, `firstWhere()`, `orWhere()`, `whereNot()`, `orWhereNot()`, `latest()`, and `oldest()` accept the Query Builder, Eloquent Builder, and Relation values handled by the Query Builder methods they forward to.
6. Keep explicit unions rather than using the empty query-builder marker contract, which would accept implementations `parseSub()` cannot handle. Do not widen `whereExists*()` or `union*()`: those APIs do not use `isQueryable()` and intentionally require complete Query/Eloquent builders. Normalize the already-supported Eloquent union member as specified under statement-owned timeouts. Keep subquery `from()` aliases required and do not add exception-state rollback machinery to fluent builder methods.

## Documentation

- Update `src/docs/queries.md` to describe `timeout()` as an outer select-statement limit on MySQL/MariaDB. Show that it belongs on the outer builder, state that timed embedded builders and `explain()` are rejected, and do not present it as a DML or transaction-wide lock-wait control.
- Add one note beside `firstOrCreate()` / `updateOrCreate()` in `src/docs/eloquent.md`: under repeatable-read, a concurrent row committed after the caller's snapshot can remain invisible and the unique violation is rethrown; idempotent collision handling must retry the complete owning transaction from outside it. Relationship docs already lead to this section, so do not duplicate the note.
- Rename `src/docs/database.md`'s “Handling Deadlocks” section to “Handling Concurrency Errors”. Explain that `transaction(..., attempts:)` retries detected deadlocks, serialization failures, and database lock errors after a complete rollback, but does not retry unique violations.
- Keep low-level grammar and relation implementation details in source docblocks/comments. Add a short source comment only where final-statement timeout placement or exact-pivot proof is not clear from the code itself.

## Testing plan

### Eloquent unit and integration coverage

- Update `DatabaseEloquentHasManyThroughCreateOrFirstTest` and `DatabaseEloquentBelongsToManyCreateOrFirstTest` so the two fallback selects are explicitly write-routed. Preserve all existing create, retrieve, update, closure-value, transaction, and model-state assertions.
- Cover both pivot attach catches with:
  - exact parent/related membership visible on the write PDO returns the already-selected related model;
  - an independent pivot unique constraint with no exact membership rethrows the same attach-violation instance;
  - configured `wherePivot`, `wherePivotIn`, `wherePivotNull`, and `wherePivotBetween` predicates remain part of the proof; and
  - `MorphToMany` includes the morph discriminator, so another morph type cannot prove membership.
- Add one SQLite read/write-split integration regression using separate temporary read and write databases. Seed the winning related/through row only on the writer, leave equivalent empty schemas on the reader, force the insert collision on the writer, and prove each corrected fallback returns the writer's row. Keep sticky reads disabled so the test cannot pass because a failed write changed connection state.
- Add a shared real-database pivot regression that runs on SQLite, MySQL, MariaDB, and PostgreSQL: a different pivot row occupies a second unique key, the attempted exact pivot is absent, and both create-or-retrieve methods rethrow instead of reporting attachment success.
- Keep repeatable-read behavior as a documented transaction-owner responsibility; do not build a timing-dependent test that pretends Eloquent can make a stale snapshot current.

### Query grammar and builder coverage

- Extend MySQL grammar tests for simple, distinct, aggregate, ordinary union, union aggregate, `exists()`, top-level locking select, timeout clearing, and outer placement with untimed subqueries. Preserve exact SQL and binding-order assertions, including the hint inside the first parenthesized union `SELECT`.
- Add the matching MariaDB grammar tests, asserting `SET STATEMENT max_statement_time=<seconds> FOR` occurs once at the statement root for simple, aggregate, ordinary union, union aggregate, `exists()`, and locking selects.
- In `DatabaseQueryBuilderTest`, cover grouped/having pagination transferring the timeout to the outer count, an outer timeout with untimed parsed/where-exists/union members, and rejection at each of the four timed embedded-builder acceptance paths. Pin the exact diagnostic and prove the rejected child is not stored and its bindings are not merged; where a specific entry point performs no earlier outer mutation, also pin its unchanged outer state.
- Cover retained where-subquery, exists, and union children under both MySQL and MariaDB: after attaching an untimed child, setting its timeout must leave exactly one decoration at the outer statement root and none in the child fragment, while compiling that child standalone still applies its timeout.
- With stock grammars bound to differently prefixed same-database connections, assert that scalar where-subquery, exists, not-exists, and union fragments retain the child connection's prefix.
- Cover Eloquent parsed-subquery and union inputs whose global scopes set timeouts, proving each scope runs once and the scope-applied snapshot is rejected. Also cover an Eloquent union member with a binding-bearing global scope, pinning exact SQL and bindings through acceptance-time normalization. Do not encode later scope mutation of an attached union as supported behavior.
- Add exact base and offset group-limit SQL/binding assertions. Existing SQLite/PostgreSQL update/delete rewrite tests must remain byte-identical after their internal compiler calls move to the raw assembler.
- In `DatabaseEloquentBuilderTest`, cover `withExists()`, another `withAggregate()` arm, and both the default exists and count relationship paths, pinning the relationship-specific diagnostic before the child is embedded or its bindings are merged.
- Cover timed `explain()` rejection and unchanged untimed explanation SQL.
- Where the existing MySQL/MariaDB grammar test files are touched, add the missing `: void` return type to `testToRawSql()` under the repository's full-typing rule, and pin timeout clearing on both drivers.

### Queryable type coverage

- Use real model relations with mocked connections and assert exact SQL and binding order through representative public Query Builder paths: aliased select/from, cross-database table qualification, `whereSub()`, a subquery join including the straight-join wrapper, insert-using, Between/BetweenColumns wrappers, and order wrappers.
- Cover a cross-database subquery sourced through `fromSub()` with an already-qualified inner source, plus a tableless cross-database subquery, and prove both remain unchanged during qualification.
- Cover `orWhere()` / `whereNot()` with a plain Query Builder as well as Relation compilation so the Query, Eloquent, and Relation union arms cannot be accidentally omitted.
- Cover Eloquent Builder's public where/firstWhere, or/where-not, and latest/oldest families. Keep coverage family-level; do not add a reflection harness or one test per trivial forwarding alias.

### Real timeout enforcement

- Add driver-owned `QueryTimeoutTest` classes under the existing MySQL and MariaDB integration directories so the database workflow discovers them.
- For each engine, use a query that exceeds a one-second limit and assert the engine's timeout error, not only elapsed wall time. Cover ordinary execution with `SLEEP`, `exists()` with `SLEEP` in a predicate over a one-row probe table so the optimizer cannot discard it, and `unionAll()` with `SLEEP` in a returned arm so both compiler entry points and real union-wide enforcement are proven.
- Hold a row lock on a second connection and assert a timed `SELECT ... FOR UPDATE` is interrupted with the engine's timeout error. Use bounded coordination and exception-safe cleanup; never leave an open transaction or probe table.
- Run MariaDB coverage on the supported 10/11 matrix and MySQL coverage on the supported 8/9 matrix. This pins both the older MariaDB syntax requirement and MySQL's first-`SELECT` hint placement.

## Implementation order and verification

1. Correct the two Eloquent write routes and exact pivot predicate, updating and running each existing relation test file immediately.
2. Add and run the read/write-split and real-database pivot regressions before moving to timeout compilation.
3. Add the raw/final select grammar seam, MySQL/MariaDB decorators, internal wrapper placement, pagination transfer, and fail-fast guards. Update and run each grammar/builder test file as it is changed.
4. Correct the complete Query/Eloquent Builder queryable type surface and normalization rules, then run the changed builder test files immediately.
5. Add and run the real MySQL and MariaDB timeout tests through the driver workflow.
6. Update the three documentation sections with the public behavior and transaction boundary.
7. Run the complete database unit suite and all four real database integration groups with `bin/run-database-tests.sh`.
8. Run `composer fix` once at the completed implementation checkpoint. After fixes, run the affected targeted tests and repeat the full checkpoint only when the correction can affect another package or driver.
9. Freshly review every changed caller and callee for Laravel API compatibility, named/protected extension points, false success, query/binding order, read/write routing, transaction isolation, coroutine/worker state, hot-path query or allocation cost, duplicated logic, and overengineering. Then complete adversarial peer review before commit.

## References

- Current Laravel 12.x relation shapes: [BelongsToMany](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Relations/BelongsToMany.php), [HasOneOrManyThrough](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Relations/HasOneOrManyThrough.php).
- Current Laravel 12.x compiler shapes: [base query grammar](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Query/Grammars/Grammar.php), [MySQL grammar](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Query/Grammars/MySqlGrammar.php).
- Laravel's [Relation subquery support](https://github.com/laravel/framework/pull/33180), whose runtime queryable surface is broader than its retained parameter annotations.
- MySQL `MAX_EXECUTION_TIME` statement rules for [8.0](https://dev.mysql.com/doc/refman/8.0/en/optimizer-hints.html#optimizer-hints-execution-time) and [9.1](https://dev.mysql.com/doc/refman/9.1/en/optimizer-hints.html#optimizer-hints-execution-time).
- [MariaDB `SET STATEMENT`](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/set-commands/set-statement) and [statement timeout behavior](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements).
- [PostgreSQL transaction isolation and complete-transaction retry](https://www.postgresql.org/docs/current/transaction-iso.html).

The work is complete only when collision fallbacks never report unproven relation membership, every fallback that must observe a recent winner reads from the writer, timeout SQL appears exactly once on the executed outer statement, timed embedded snapshots fail before they are stored or their bindings are merged, retained children cannot later emit nested decoration, and the full supported database matrix and repository checks pass.
