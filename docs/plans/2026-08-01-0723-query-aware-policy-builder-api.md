# Query-Aware Policy Builder API

Make query-aware authorization feel like a native Eloquent feature while keeping authorization ownership in the auth package. The final public path is `whereCan()` for filtering and `withCan()` for boolean annotations. Policy `*Scope` methods are the primary query definition; `*Select` methods remain available as a bindings-safe scalar override. `Gate::scope()` and `Gate::select()` are the underlying primitives and provide symmetric fallback so either query method can power both builder operations.

This is a framework enhancement, not a Laravel port, and it also corrects a pre-existing authorization bypass in the current `Gate::scope()` implementation. Hypervel 0.4 is unreleased, and compatibility with earlier Hypervel-only query-policy APIs and assertion names does not drive the design. Supported Laravel authorization APIs and protected extension points remain unchanged. The implementation should remove the current unsafe and duplicated examples, rename stale test-only surfaces instead of keeping wrappers, and leave every touched file reading as if the feature had been designed this way from the start.

## Goals

- Let applications filter authorized Eloquent rows with `Post::query()->whereCan('edit')`.
- Let applications hydrate one or more strict boolean authorization attributes with `withCan()`.
- Make `*Scope` the normal query-policy method while deriving annotations from it through a correlated `EXISTS` query.
- Apply every authorization constraint through Eloquent's native OR-grouping semantics so neither caller branches nor policy branches can escape the other, global scopes, chained policies, or correlation constraints.
- Keep `*Select` as an explicit scalar boolean override for rules that need the outer query shape or a cheaper inline expression.
- Allow bindings in `*Select` implementations without string interpolation.
- Preserve Gate-level and policy-level `before()` behavior exactly once per query operation while continuing to skip `after()` callbacks.
- Support PHP enum abilities throughout the primitives and builder APIs.
- Keep current-user resolution coroutine-safe and avoid worker-lifetime user capture.
- Produce the same SQL contract and strict boolean hydration on MySQL, MariaDB, PostgreSQL, and SQLite.
- Update the consistency testing trait to exercise the production builder APIs instead of duplicating their implementation.
- Remove stale names, unsafe fixtures, obsolete documentation, redundant comments, and unused imports as part of the change.

## Source and Reference Findings

### Current Hypervel behavior

`src/auth/src/Access/Gate.php` currently implements `scope()` and `select()` as two separate dispatch paths. Both repeat user resolution, Gate `before()` dispatch, policy lookup, policy `before()` dispatch, guest checks, and missing-method errors. They also use `method_exists()` even though normal policy dispatch uses `is_callable()`. A protected `*Scope` or `*Select` method can therefore be treated as available and later fail when invoked.

The current methods are string-only:

```php
public function scope(string $ability, Builder $query): Builder;

public function select(string $ability, Builder|Model|string $query): Expression;
```

The rest of Gate's public ability surface accepts `UnitEnum|string` and normalizes through `enum_value()`. The query primitives should follow the same contract.

The current `select()` return type is the concrete `Hypervel\Database\Query\Expression`. Expressions do not carry bindings. Current fixtures and documentation work around that by interpolating a user identifier into raw SQL:

```php
return DB::raw($query->qualifyColumn('user_id') . ' = ' . (int) $user->id);
```

The integer cast makes that one example safe but teaches an unsafe pattern for UUIDs and other string identifiers. `Hypervel\Database\Query\Builder` is already accepted as a scalar subquery by `selectSub()` / `addSelect()` and preserves bindings, so no new bound-expression wrapper is needed.

`src/testing/src/Concerns/AssertsPolicyQueryConsistency.php` currently calls the Gate primitives directly, manually builds its annotation alias, and casts hydrated values with `(bool)`. That bypasses the intended production API and would hide a nullable SQL result rather than prove the public boolean contract.

### Eloquent and query-builder mechanics

The following behavior was verified in the Hypervel source:

- `Query\Builder::addSelect()` preserves the model's base columns when an aliased expression or subquery is added to a query whose columns are still null.
- `Query\Builder::selectSub()` accepts base and Eloquent builders and transfers their bindings.
- `Query\Builder::whereRaw()` accepts bindings in the query's `where` binding group. Applying a scalar boolean subquery as `whereRaw('(' . $selection->toSql() . ')', $selection->getBindings())` preserves bindings and uses the selection itself as the predicate on every supported driver.
- `Eloquent\Builder::withCasts()` installs query-time casts on the builder's model prototype.
- `HasAttributes::castAttribute()` returns null before primitive casts. A `'bool'` cast therefore cannot turn a nullable SQL result into `false`; null normalization belongs in `Gate::select()`.
- `Eloquent\Builder::withGlobalScope()` installs a scope's extensions immediately. Calling `newQueryWithoutRelationships()->withoutGlobalScopes()` keeps extensions such as `withTrashed()` while removing their constraints and avoids the model's default `$with` / `$withCount` state.
- `Model::newModelQuery()` installs the same model object on the new builder. Mutating that model's table for an inner alias would also mutate the outer builder's model.
- `Model::newInstance()` creates a separate model while preserving the connection name, table, and casts. It is the correct starting point for scope-derived selection.
- `Eloquent\Builder::callScope()` snapshots the existing WHERE count, separates the pre-existing and callback-added WHERE slices, and groups either slice when it contains an OR. Direct policy-method invocation bypasses that protection. A policy scope with `where(...)->orWhere(...)` can therefore escape an existing caller or global-scope constraint; in a derived `EXISTS`, one uncorrelated matching row can authorize every outer row after the correlation is appended. Moving the correlation before the policy scope merely reverses which OR branch escapes. The same grouping path is also required when a plain denial or scalar-selection predicate is appended after a caller OR: without it, `caller_a OR caller_b AND authorization` lets the first caller branch bypass authorization.
- `callScope()` is a protected Laravel extension point. Making it public would cause a fatal visibility incompatibility for a Laravel-compatible custom Builder that overrides it as protected. Add one narrowly named public `applyScopeCallback(callable $scope): static` delegate and leave `callScope()` unchanged; this exposes the needed existing grouping without duplicating it or breaking protected overrides.
- Eloquent Builder dispatches local macros first, global macros second, named scopes third, and base-builder forwarding last. Framework global `whereCan` / `withCan` macros therefore reserve those names against named scopes and application global macros; a deliberately installed per-builder local macro still wins.
- `Eloquent\Builder::flushState()` clears global macros, and `AfterEachTestSubscriber` already calls it. No additional static cleanup hook is required.

### Provider and worker-lifetime behavior

`Application::bootProvider()` invokes provider `boot()` methods through the container, so method injection is supported. `AuthServiceProvider::boot()` currently resolves `CacheManager` and the config repository manually. Since this method will be edited, those existing lookups should be replaced with explicit method injection:

```php
public function boot(
    CacheManager $cache,
    ConfigRepository $config,
    GateContract $gate,
): void
```

The global builder macros are worker-lifetime callbacks. The provider rule requires boot-injected worker-safe dependencies to be captured by callbacks. Gate is safe to capture because its resolver reads the active coroutine's auth resolver when `scope()` or `select()` is invoked; no user is captured in the macro closure.

`src/auth/composer.json` already requires `hypervel/database`, while the database package does not require auth. Registering the macros from AuthServiceProvider follows the existing dependency direction and requires no package metadata change.

The `AfterWorkerStart` callback is different. It is an event listener, worker configuration has been reloaded by that point, and the provider guide explicitly requires event-time resolution for listeners. Its cache, config, and validator lookups remain inside the event callback.

The macros must be overwritten on every provider boot. A `hasGlobalMacro()` guard would preserve a closure that captured an earlier application Gate after a test or application rebootstrap.

### Database workflow

`.github/workflows/databases.yml` runs every shared test under `tests/Integration/Database/` against:

- MySQL 8 and 9
- MariaDB 10 and 11
- PostgreSQL 17 and 18
- SQLite

Driver-specific directories are added only for tests that require one driver. Query-aware policy behavior is shared, so it belongs in one test class directly under `tests/Integration/Database/`. Hypervel does not support SQL Server, MongoDB, or DynamoDB as database drivers; this feature will contain no branches, fixtures, documentation, or tests for them.

### Local Laravel reference

The local reference at `examples/laravel/framework` has no `Gate::scope()`, `Gate::select()`, `whereCan()`, or `withCan()` authorization API. This enhancement therefore does not replace or change a Laravel API and is not filling a missing Laravel parity surface.

The local Laravel source was useful only to confirm the shared Eloquent mechanics: `addSelect()` / `selectSub()` binding behavior, `withAggregate()`'s `EXISTS` plus boolean-cast pattern, global-scope extension installation, self-relation aliases, and builder macro dispatch. No Laravel files will be modified.

### Laravel-style API audit

- `whereCan()` and `withCan()` follow Eloquent's established `where*` filtering and `with*` annotation families. The `"ability as alias"` form follows relationship aggregate aliases, while `can_edit` uses Gate's existing `can` vocabulary and mirrors generated attributes such as `comments_count` and `comments_exists`.
- `editScope()` and `editSelect()` suffix the ability method in the same way Gate already derives related policy methods. A legacy `scopeEdit()` prefix would conflict with Hypervel's attributed local-scope convention.
- `applyScopeCallback()` follows the existing public `Eloquent\Builder::applyAfterQueryCallbacks()` naming shape and remains clearly distinct from `applyScopes()`, which applies registered global scopes to a clone. Its contract is also intentionally distinct from `tap()`: both return the same fluent Builder, but only `applyScopeCallback()` keeps the pre-existing and callback-added WHERE slices separate and groups either slice when it contains a top-level OR.
- `Gate::scope()` and `Gate::select()` are symmetric with the policy suffixes and remain low-level primitives beneath the fluent Builder API. Renaming them would add vocabulary without making normal application code clearer.
- `assertWhereCanMatchesPolicy()` and `assertWithCanMatchesPolicy()` follow Laravel assertion naming and are discoverable from the production methods they verify.
- `withCan()` accepts an array for multiple abilities because its second argument is the optional user. It cannot copy `withCount()`'s variadic convenience without making the user position ambiguous. Documentation consistently uses the array form; do not add a speculative guard for an invalid second ability because normal typed policy parameters already fail fast.

## Final Public API

### Policy methods

The normal per-model policy method remains the source of truth for single-record authorization:

```php
public function edit(User $user, Post $post): bool
{
    return $post->user_id === $user->getAuthIdentifier();
}
```

The primary query-aware definition is the matching `*Scope` method:

```php
use Hypervel\Database\Eloquent\Builder;

public function editScope(User $user, Builder $query): Builder
{
    return $query->where(
        $query->qualifyColumn('user_id'),
        $user->getAuthIdentifier(),
    );
}
```

The scope must add set-membership constraints to and return the exact Builder instance it receives. A replacement builder can discard caller constraints and cannot be safely correlated for annotation, so Gate will reject it with `LogicException`.

An explicit `*Select` method is optional. It must return one nullable scalar boolean value for the outer row as either an expression contract or a base query builder:

```php
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Query\Builder as QueryBuilder;

public function editSelect(User $user, EloquentBuilder $query): QueryBuilder
{
    return $query->getQuery()->newQuery()->selectRaw(
        $query->qualifyColumn('user_id') . ' = ?',
        [$user->getAuthIdentifier()],
    );
}
```

Returning the base builder is deliberate. It carries bindings and makes the scalar contract explicit. An application that builds a valid scalar Eloquent query can return `->toBase()`. Returning an arbitrary Eloquent builder directly is not allowed because a row-producing query is not inherently a scalar boolean selection.

### Gate primitives

Update both the contract and concrete Gate to:

```php
public function scope(UnitEnum|string $ability, Builder $query): Builder;

public function select(
    UnitEnum|string $ability,
    Builder|Model|string $query,
): ExpressionContract|QueryBuilder;
```

Both methods normalize enums through `(string) enum_value($ability)` before callbacks, policy lookup, method formatting, alias generation, or error reporting.

Dispatch is symmetric and the method native to the requested operation wins:

| Requested operation | First choice | Fallback |
|---|---|---|
| `scope()` / `whereCan()` | `*Scope` | Apply `*Select` as a boolean WHERE condition |
| `select()` / `withCan()` | `*Select` | Derive a correlated `EXISTS` selection from `*Scope` |

If the native method is public/callable but does not accept the current guest user, the result is denial. Gate must not fall through to the other method. Policy `before()` still runs first and may explicitly allow or deny the guest, matching the existing order.

If neither public method is callable, throw one `RuntimeException` that names both accepted method names. Use `is_callable([$policy, $method])`, not `method_exists()`, so protected methods are not selected.

Gate-level `before()` callbacks and policy `before()` execute at most once per public query operation. `true` grants the query operation, `false` denies it, and `null` continues to the selected query method. `after()` callbacks remain unused because query operations do not produce the final boolean or `Response` object expected by normal Gate evaluation.

### Eloquent builder macros

Register these global macros from `AuthServiceProvider`:

```php
whereCan(UnitEnum|string $ability, mixed $user = null): static

withCan(UnitEnum|string|array $abilities, mixed $user = null): static
```

Usage:

```php
$posts = Post::whereCan('edit')
    ->paginate();

$posts = Post::query()
    ->whereCan(Ability::Edit, $user)
    ->whereCan('publish', $user)
    ->get();

$posts = Post::query()
    ->withCan(['edit', 'delete', 'publish as publishable'], $user)
    ->get();
```

`whereCan()` accepts one ability. Chaining gives explicit AND behavior without another array/operator API.

`withCan()` accepts one enum/string or a list of them. A string may use the `"ability as alias"` form. It calls `addSelect()` and installs `'bool'` query-time casts for every resulting attribute.

For both macros:

- Omitted or null `$user` uses the captured Gate's current coroutine user.
- A non-null `$user` calls `$gate->forUser($user)` once for that macro invocation.
- An authenticated caller that deliberately wants the guest perspective can call `Gate::forUser(null)->scope()` or `Gate::forUser(null)->select()` directly. The fluent API does not add hidden omitted-versus-explicit-null sentinel behavior.
- `withCan([])` returns the same builder without selections or casts.

## SQL Construction Contracts

### Applying authorization constraints without OR leakage

Every policy `*Scope` invocation and every authorization predicate appended by `Gate::scope()` must go through Eloquent's existing scope-grouping path. Direct invocation is unsafe when the policy adds a top-level OR: the OR can escape caller constraints, later query-aware policy constraints, or global scopes. In scope-derived selection it can also leave one OR branch uncorrelated, making one matching inner row authorize every outer row. A bare denial or selection predicate is also unsafe after a caller's top-level OR because SQL evaluates `AND` before `OR`.

Add a narrow public Builder delegate that keeps Laravel's protected `callScope()` extension point unchanged:

```php
/**
 * Apply a scope callback to the query.
 *
 * Keep existing and callback-added WHERE constraints logically separate.
 *
 * Either slice is grouped when it contains an OR so neither side's branches
 * can escape the other.
 */
public function applyScopeCallback(callable $scope): static
{
    $this->callScope($scope);

    return $this;
}
```

The Gate scope invoker captures the policy method's raw result separately so `callScope()`'s internal `?? $this` fallback cannot hide a policy that returned null:

```php
$scopeResult = null;

$query->applyScopeCallback(function (Builder $scopedQuery) use (
    &$scopeResult,
    $policy,
    $method,
    $user,
): void {
    $scopeResult = $policy->{$method}($user, $scopedQuery);
});

if ($scopeResult !== $query) {
    throw new LogicException(/* same-builder contract message */);
}
```

This passes the real Eloquent builder to the policy, so joins, relationship constraints, and other same-builder query mutations are preserved. Eloquent keeps the pre-existing and callback-added WHERE slices separate and groups either slice when it contains an OR; no WHERE copying, query cloning, or auth-owned grouping implementation is added.

### Applying `*Select` as a WHERE fallback

An expression contract is read using the supplied Eloquent query's grammar and applied through `whereRaw()`. A base query builder is applied directly as a scalar boolean predicate. The complete predicate application runs inside `applyScopeCallback()` so a top-level OR already present on the caller query is grouped before authorization is appended:

```php
$query->applyScopeCallback(function (Builder $scopeQuery) use ($selection): void {
    if ($selection instanceof ExpressionContract) {
        $scopeQuery->whereRaw(sprintf(
            '(%s)',
            $selection->getValue($scopeQuery->getQuery()->getGrammar()),
        ));
    } else {
        $scopeQuery->whereRaw(
            '(' . $selection->toSql() . ')',
            $selection->getBindings(),
        );
    }
});
```

Both layers are required. The explicit parentheses keep a compound scalar expression such as `owner_id = ? OR public = true` together. `applyScopeCallback()` groups any caller OR before the scalar predicate is appended. The base builder is used directly as a boolean predicate rather than compared with a bound PHP `true`; `Connection::prepareBindings()` converts booleans to integers, while PostgreSQL requires the scalar policy selection to remain boolean. SQL null is already denial in a WHERE predicate, so this fallback needs no extra null wrapper.

### Normalizing explicit `*Select` results

The public selection contract is boolean. SQL comparisons over nullable columns can return null, and Eloquent's boolean cast preserves null. Normalize explicit `*Select` results at `Gate::select()`, the lowest owner of the selection contract:

```php
if ($selection instanceof ExpressionContract) {
    return new Expression(sprintf(
        'coalesce((%s), false)',
        $selection->getValue($query->getQuery()->getGrammar()),
    ));
}

return $selection->newQuery()->selectRaw(
    'coalesce((' . $selection->toSql() . '), false)',
    $selection->getBindings(),
);
```

The exact implementation should preserve numeric expression values accepted by the expression contract when assembling the string. Gate/policy `before()` literals and scope-derived `EXISTS` results are already non-null and should not receive a redundant wrapper.

Construct literal results directly with `new Expression('true')` / `new Expression('false')` and remove Gate's unnecessary DB facade import.

### Deriving a selection from `*Scope`

Only the scope-to-selection fallback needs a model key and correlated inner query.

1. Read the outer model and primary-key name. An empty key name throws a clear `LogicException`. Do not add regex validation or composite-key machinery; Eloquent exposes one string key and does not support composite model keys.
2. Build the deterministic reserved alias:

   ```php
   $alias = 'hypervel_reserved_' . hash(
       'xxh128',
       $outerModel::class . "\0" . $ability,
   );
   ```

   Use the full 32-character hex digest. The full alias remains under PostgreSQL's 63-byte identifier limit. No mutable counter, registry, or cache is warranted.
3. Create a separate inner model and query:

   ```php
   $table = $outerModel->getTable();
   $innerModel = $outerModel->newInstance();
   $innerQuery = $innerModel
       ->newQueryWithoutRelationships()
       ->withoutGlobalScopes();

   $innerQuery->from($table . ' as ' . $alias);
   $innerModel->setTable($alias);
   ```

   The inner query retains global-scope-installed extensions but has no global-scope constraints, default eager loads, or default eager counts. The separate model prevents the alias from changing the outer model's table.
4. Invoke `*Scope` through `$innerQuery->applyScopeCallback()` after both the inner `from` and inner model table use the alias. This groups any top-level OR branches added by the policy while preserving all other same-builder mutations. Capture the policy's raw return and enforce that it is the exact `$innerQuery` object.
5. Correlate the aliased inner primary key to the outer model's qualified primary key with `whereColumn()`.
6. Convert the scoped inner query to its base builder once and return a clean base scalar query selecting `exists(<inner SQL>)` with every inner binding preserved.

The outer query owns row visibility. The inner authorization query intentionally excludes global-scope constraints so an outer `withTrashed()` query does not annotate an otherwise-authorized trashed row as false. Policy scopes may still call extensions such as `withTrashed()` because those extensions remain installed.

The documented `*Scope` contract is set membership: add constraints that define which model keys are authorized. Predicates and joins are the normal shape; grouping and `having` are valid only when the resulting query remains a valid correlated `EXISTS` query. Do not promise correct annotation derivation for replacement builders, unions, limit/offset windows, outer queries whose `from` clause aliases the model table, or authorization that depends on result ordering. Eloquent's qualified key comes from the model table rather than parsing an aliased `from` clause, so an outer table alias cannot be correlated safely without brittle SQL introspection.

## Result Alias Contract

The default annotation attribute is `can_{ability}` after converting camel case to snake case and replacing every non-identifier separator, including dots and dashes, with underscores.

Generate the readable portion directly with the existing string helper:

```php
$normalizedAbility = trim(StrCache::snake((string) preg_replace(
    '/[^A-Za-z0-9_]+/',
    '_',
    $ability,
)), '_');

$alias = 'can_' . $normalizedAbility;
```

Examples:

| Input | Attribute |
|---|---|
| `edit` | `can_edit` |
| `edit-post` | `can_edit_post` |
| `editPost` | `can_edit_post` |
| `edit as editable` | `editable` |

Dots are still normalized when generating an alias because a Gate `before()` callback can short-circuit a dotted ability before policy lookup. They are not advertised as a normal policy-method example: `formatAbilityToMethod()` only camel-cases dashes, so a dotted ability cannot map to a callable PHP policy method.

Generated aliases use one cross-driver rule:

- ASCII letters, digits, and underscores only, with the `can_` prefix providing a safe first character.
- At most 63 bytes so PostgreSQL never truncates the selected column name and causes the query-time cast to miss the hydrated attribute.
- If the readable default exceeds 63 bytes, throw `InvalidArgumentException` naming the ability and tell the caller to provide a shorter explicit alias. Silently rewriting the attribute would leave the caller with no discoverable property name.

Explicit aliases are never silently changed. Validate that an explicit alias matches one safe attribute identifier (`[A-Za-z_][A-Za-z0-9_]*`) and is at most 63 bytes. Reject invalid input with `InvalidArgumentException`.

Resolve and validate every alias in one `withCan()` call before changing the builder. Detect duplicate resolved aliases within that call and throw an error that tells the caller to supply distinct explicit aliases. This prevents partial mutation for an invalid alias list. Do not add a registry or inspect selections from earlier chained `withCan()` calls; that would add state and fragile SQL introspection for no useful contract.

## Worker and State Decisions

- The only new worker-lifetime state is the two entries in Eloquent Builder's existing global macro array.
- Each provider boot overwrites both entries with closures capturing the Gate injected for that application boot.
- The closures capture no user, query, model, or authorization result.
- Gate resolves the current user when each macro runs, so concurrent coroutines remain isolated through AuthManager's coroutine-aware resolver.
- A non-null explicit user is held only for the duration of the macro call through the short-lived Gate returned by `forUser()`.
- Do not cache derived SQL, policy results, builders, aliases, or users. Derived SQL includes user-specific bindings, and caching it would risk cross-request data leakage.
- `AfterEachTestSubscriber` already calls `Eloquent\Builder::flushState()`. Do not add duplicate cleanup or another static registry.
- Facade swaps after provider boot are tests-only global mutation and do not replace already injected dependencies. Do not add per-query container lookups to support them.

## Implementation Plan

All edits are performed one file at a time. Every changed or new test class is run immediately after that file is completed.

### 1. Update `src/database/src/Eloquent/Builder.php`

Why: expose Eloquent's existing OR-safe scope application to Gate without changing Laravel's protected extension point, and keep the runtime query-aware macros visible and generic-preserving to IDEs and PHPStan without creating a database-to-auth source dependency.

How:

- Add the narrow fluent delegate next to `applyScopes()` / `callScope()`:

  ```php
  /**
   * Apply a scope callback to the query.
   *
   * Keep existing and callback-added WHERE constraints logically separate.
   *
   * Either slice is grouped when it contains an OR so neither side's branches
   * can escape the other.
   */
  public function applyScopeCallback(callable $scope): static
  {
      $this->callScope($scope);

      return $this;
  }
  ```

- Keep the existing protected `callScope()` method and its behavior unchanged. This preserves Laravel-compatible custom Builder overrides while reusing its WHERE grouping for external scope-like integrations.
- Add two generic-preserving declarations to the class docblock:

  ```php
   * @method $this whereCan(\UnitEnum|string $ability, mixed $user = null)
   * @method $this withCan(\UnitEnum|string|list<\UnitEnum|string> $abilities, mixed $user = null)
  ```

- Keep `whereCan()` and `withCan()` as annotations only. Do not import auth contracts into the database package or add native auth methods/traits.
- Do not change macro dispatch or static cleanup; both already have the required behavior.

### 2. Update `tests/Database/DatabaseEloquentBuilderTest.php`

Why: prove the new generic Eloquent API directly at the layer that owns it rather than relying only on authorization tests.

How:

- Add focused real-builder tests beside the existing scope tests, with `: void` on each new test method.
- Prove the callback receives the exact Builder on which `applyScopeCallback()` was called and the method returns that same Builder for fluent chaining even when the callback returns a different Builder.
- Start with `where(...)->orWhere(...)` before the callback and add another `where(...)->orWhere(...)` inside it. Assert the resulting SQL groups both OR-containing slices independently and preserves binding order.
- Add a no-WHERE callback case and assert the SQL and bindings remain unchanged.
- Keep the tests limited to this public contract. Gate-specific identity enforcement and authorization SQL belong in the auth test file.

Run immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseEloquentBuilderTest.php
```

### 3. Update `src/contracts/src/Auth/Access/Gate.php` and refactor `src/auth/src/Access/Gate.php`

Why: publish the enum-aware, bindings-safe contract and make the two public primitives one coherent dispatch system, fixing callable discovery and null semantics while adding the two safe fallbacks without duplicating authorization hooks. The interface and its only implementation change in one slice because PHP links their signatures when the concrete class loads and no runnable intermediate state exists between them.

How:

- In the contract, replace the concrete expression import with `Hypervel\Contracts\Database\Query\Expression as ExpressionContract`, import the base `Hypervel\Database\Query\Builder` under a clear alias, and import `LogicException` for the documented identity/key failures.
- Widen the contract's `scope()` ability to `UnitEnum|string`.
- Widen the contract's `select()` ability to `UnitEnum|string` and its return to `ExpressionContract|QueryBuilder`, while keeping the existing Builder/Model/class-string input contract.
- Update the contract method text so `select()` describes a scalar boolean selection rather than only a SQL expression. Keep `RuntimeException` documentation for a policy that defines neither accepted public method and add `LogicException` documentation where the scope identity or key contract can fail.
- Add imports for the expression contract, base query builder, and `LogicException`; remove the DB facade import.
- Normalize enum abilities before any callback or method-name work.
- Extract one clearly named internal query-policy resolver used by both public methods. It should:
  - resolve the user once;
  - run Gate `before()` once;
  - resolve the policy;
  - choose the operation-native public callable first and the fallback second;
  - throw one error naming both accepted methods when neither is callable;
  - run policy `before()` once;
  - return the resolved user, policy, selected method, and before result without introducing a value-object class.
- Add one internal scope invoker used by native scope and scope-derived selection. It calls the policy method inside the Builder's public `applyScopeCallback()` grouping delegate, captures the raw policy return separately, compares that result by identity with the received builder, and throws `LogicException` on null or replacement. Do not use `applyScopeCallback()`'s fluent return for the identity check.
- Add one internal selection invoker used by native selection and selection-derived scope. Its accepted result is exactly `ExpressionContract|QueryBuilder`.
- Preserve the existing true/false short-circuits and guest checks. When a chosen native method is guest-ineligible, deny without trying the fallback. Route both denial sites through one small query helper that appends `0 = 1` inside `applyScopeCallback()` so a caller OR cannot escape the denial.
- In `scope()`, call the native scope invoker or apply an explicit selection through the expression/query-builder WHERE paths described above. Wrap the full selection branch in one `applyScopeCallback()` call so both supported selection return forms group a caller OR before authorization.
- In `select()`, normalize shorthand model/class inputs to an Eloquent builder, call and coalesce the native explicit selection, or build the correlated `EXISTS` selection from the scope.
- Keep the inner-query construction and key validation local to the scope-derived selection path. Do not create a policy-query registry or wrapper class.
- Keep all new helpers next to `scope()` / `select()` rather than appending them after `flushState()`.
- Audit the complete touched class section for obsolete comments, duplicated doc text, unused imports, and stale Expression-only claims.

### 4. Rename `tests/Auth/Fixtures/NoScopePostPolicy.php`

Why: after symmetric fallback, a policy is missing query support only when it has neither `*Scope` nor `*Select`. `NoScopePostPolicy` would be misleading because a Select-only policy can now satisfy `scope()`.

How:

- Use `mv` to rename it to `tests/Auth/Fixtures/NoQueryPostPolicy.php`.
- Rename the class to `NoQueryPostPolicy` and update its consumer import.
- Do not keep an old class alias or compatibility file.

### 5. Update `tests/Auth/Fixtures/ScopablePostPolicy.php`

Why: the main fixture must demonstrate the final bindings-safe contract rather than retain interpolated SQL.

How:

- Change `editSelect()` to return `Query\Builder`.
- Build the correlated scalar comparison through `$query->getQuery()->newQuery()->selectRaw(..., [$user->id])`.
- Return a scalar true query for the administrator branch through the same base-builder contract. Use small anonymous policies in the Gate test for the separate Expression-contract branch.
- Remove imports made unused by the final implementation.

### 6. Update `tests/Auth/Fixtures/ScopablePostPolicyWithBefore.php`

Why: retain the policy-before fixture while removing its unsafe raw value interpolation.

How:

- Return a binding-preserving base scalar builder from `editSelect()`.
- Keep `before()` and `editScope()` focused on their existing behavior.
- Remove stale comments or imports found while editing.

### 7. Update `tests/Auth/Fixtures/SelectOnlyPostPolicy.php`

Why: this fixture becomes the canonical proof that `scope()` can fall back to a scalar `*Select` rule.

How:

- Replace its interpolated Expression with a binding-preserving base scalar builder.
- Keep the per-model `edit()` method so consistency assertions can compare it with the query result.

### 8. Rework `tests/Auth/AuthAccessGateScopeSelectTest.php`

Why: cover the full Gate primitive contract and every meaningful failure at the lowest unit boundary.

How:

- Update imports for the renamed fixture, Select-only fixture, expression contract/concrete, Grammar, query builder, enums, and exceptions.
- Add `: void` to every test method in the file, not only new methods.
- Replace FQCN use of Grammar with an import.
- Remove section-divider and explanatory comments that only restate assertions.
- Retain useful existing coverage for user resolution, model/class shorthand, `forUser()`, and allow/deny short-circuits.
- Add or revise focused tests for:
  - backed and unit enum ability normalization;
  - an inline test-specific unit enum alongside the existing backed enum fixture, without another shared fixture file;
  - public/callable discovery, including a protected native method and a public fallback;
  - missing policy and missing both query methods, with both accepted method names in the error;
  - native-method precedence in each direction;
  - Gate `before()` and policy `before()` running once per operation;
  - no `after()` callback for either query operation;
  - guest-ineligible native method denying without fallback;
  - Scope-only selection derivation;
  - Select-only scope derivation for both Expression and base-builder return forms;
  - parentheses around a compound raw selection so it cannot escape existing WHERE constraints;
  - a caller's top-level OR grouped before an Expression selection fallback and before a base-builder selection fallback, with binding order preserved;
  - a caller's top-level OR grouped before a Gate/policy-before denial and before a guest-ineligible native-scope denial;
  - a policy `*Scope` with a top-level OR compiling as one grouped unit against a pre-existing caller constraint in the native `scope()` path;
  - the same OR policy in scope-derived selection compiling with the primary-key correlation outside the complete grouped policy predicate, so no OR branch is uncorrelated;
  - binding preservation for a quote-containing string identifier;
  - explicit Expression and base-builder null coalescing;
  - no redundant coalesce around before literals or derived `EXISTS`;
  - exact same-builder identity enforcement;
  - separate inner model identity and unchanged outer model table;
  - alias qualification visible inside the policy scope;
  - global-scope extension retention and constraint removal during inner construction;
  - empty primary-key name failure only.
- Use real builders where SQL/binding construction is the behavior under test and mocks only where call counts or short-circuiting are the point.
- Do not add unsupported composite-key tests, arbitrary key-name regex tests, SQL Server branches, or tests for facade swapping after boot.

Run immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Auth/AuthAccessGateScopeSelectTest.php
```

### 9. Update `src/auth/src/AuthServiceProvider.php`

Why: install the fluent public API at the auth/database boundary and clean the existing boot dependency style in the method being changed.

How:

- Change `boot()` to method-inject `CacheManager`, `ConfigRepository`, and `GateContract`.
- Remove its boot-time manual cache/config resolutions.
- Register/overwrite both Eloquent Builder global macros before the console early return.
- Capture the injected Gate in both macro closures. Do not capture a user or resolve Gate from the container per call.
- Resolve a non-null explicit user with `forUser()` once per macro invocation.
- Keep `whereCan()` as one direct scope application.
- Keep `withCan()` as a list normalization, full alias pre-validation pass, then selection/cast application pass.
- Add a private 63-byte alias limit constant and small private pure helpers for parsing `"ability as alias"`, generating the default alias with `StrCache::snake()`, and validating aliases. Both overlong defaults and invalid or overlong explicit aliases throw actionable `InvalidArgumentException`s. These helpers are complex enough to benefit from names; do not create a new service, registry, or configuration option.
- Because Eloquent rebinds the macro closure to Builder scope, capture a pure resolver closure created in provider scope rather than calling private provider helpers directly from the rebound closure.
- Keep the listener registration and its event-time cache/config/validator resolution unchanged.
- Keep the existing cache class resolver behavior unless the edit exposes a concrete defect; do not restructure it merely to reduce lines.

Core registration shape:

```php
public function boot(
    CacheManager $cache,
    ConfigRepository $config,
    GateContract $gate,
): void {
    $this->registerQueryBuilderMacros($gate);

    // Existing cache class policy and worker validation follow.
}
```

The macro implementation should preserve the bound builder type:

```php
EloquentBuilder::macro('whereCan', function (
    UnitEnum|string $ability,
    mixed $user = null,
) use ($gate): EloquentBuilder {
    $queryGate = $user === null ? $gate : $gate->forUser($user);

    return $queryGate->scope($ability, $this);
});
```

Use the narrow local PHPDoc or line-scoped PHPStan suppression required for the rebound `$this` only if PHPStan cannot follow it. Do not add a global ignore.

### 10. Update `tests/Auth/AuthServiceProviderTest.php`

Why: keep provider tests aligned with method injection and prove the worker-lifetime macro replacement rule.

How:

- Pass cache, config, and Gate doubles directly to every manual `boot()` call.
- Remove cache/config `Application::make()` expectations that method injection makes obsolete.
- Keep console validation using captured boot dependencies.
- Keep the `AfterWorkerStart` test proving worker-time cache/config/validator resolution from the application.
- Add one common-path assertion that both macros are registered before the console/server branch; do not duplicate the same assertion for both modes.
- Add one rebootstrap test: boot with one Gate, boot again with another Gate, invoke the installed macro on a builder, and prove only the fresh Gate receives the call.
- Add a short WHY comment above the provider-scoped ability resolver closure: Eloquent rebinds macro closures so `$this` inside the macro body is the Builder, not the provider.
- Do not test post-boot facade swaps.

Run immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Auth/AuthServiceProviderTest.php
```

### 11. Add `tests/Auth/AuthEloquentBuilderCanTest.php`

Why: test the documented fluent surface separately from Gate's low-level dispatch mechanics.

How:

- Extend `Hypervel\Testbench\TestCase`; follow the concrete `DatabaseMigrations`, `afterRefreshingDatabase()`, and `Schema::create()` precedent in `tests/Testing/Concerns/AssertsPolicyQueryConsistencyTest.php`.
- Use helper classes with feature-specific names or a test-specific namespace to avoid suite class collisions.
- Register policies on the Gate instance already captured by the booted provider rather than swapping the Gate binding after boot.
- Cover:
  - `whereCan()` with current user, explicit user, and null/current-user semantics;
  - `Post::whereCan('edit')` producing the same row set as `Post::query()->whereCan('edit')`, proving Model's normal static forwarding reaches the global Builder macro;
  - a guest-eligible and guest-ineligible policy method;
  - one enum ability;
  - chaining two `whereCan()` calls as AND;
  - one and multiple `withCan()` abilities;
  - default dashed and camel-case aliases, an explicit alias, and dotted alias normalization only through a Gate `before()` short-circuit;
  - explicit alias validation and the 63-byte boundary;
  - an actionable failure when a generated default alias exceeds 63 bytes;
  - duplicate aliases after normalization, with no builder mutation before the exception;
  - an empty ability list returning the same untouched builder;
  - preservation of normal model columns after `addSelect()`;
  - strict boolean hydrated attributes;
  - a Scope-only policy containing a top-level OR annotating only its genuinely authorized rows through `withCan()`, proving on the real default database that the derived `EXISTS` does not become true for every row.
- Add one concrete coroutine-isolation test named `testCurrentUserQueriesAreIsolatedBetweenCoroutines()`. In each concurrent coroutine, set a different current user through the existing AuthManager-backed helper, interleave with `usleep()`, and run omitted-user `whereCan()` / `withCan()` queries against the application's booted Gate and macros. Assert that each query contains only its own user's binding/result. Do not add more concurrency cases once this proves the static closures capture no user.

Run immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Auth/AuthEloquentBuilderCanTest.php
```

### 12. Update `src/boost/docs/eloquent.md`

Why: `applyScopeCallback()` is a public Eloquent extension API and should be discoverable where Hypervel documents local query scopes.

How:

- Add `Applying Scope Callbacks` to the Query Scopes table of contents and add the matching `<a name="applying-scope-callbacks"></a>` anchor above the new `### Applying Scope Callbacks` subsection between Local Scopes and Pending Attributes.
- Show `applyScopeCallback()` with a reusable callback.
- Explain that it applies the callback to the same Builder, keeps the pre-existing and callback-added WHERE slices separate, groups either slice when it contains an OR, and returns that Builder for fluent chaining. Contrast it with `tap()`, which returns the same Builder but does not provide scope-style WHERE grouping.
- Keep the example focused on query composition; authorization-specific `whereCan()` / `withCan()` usage belongs in `src/boost/docs/authorization.md`.
- Do not add this to `src/boost/docs/database.md`: that page covers connections, raw SQL, and database operations, while Eloquent query scopes are documented in `eloquent.md`.

### 13. Update `src/support/src/Facades/Gate.php`

Why: keep the facade's static API description in sync with the widened Gate contract.

How:

- Change `scope()` ability to `\UnitEnum|string`.
- Change `select()` ability to `\UnitEnum|string` and its return to the expression-contract/base-query-builder union.
- Keep the existing model/class/builder input union.

### 14. Update `types/Database/Eloquent/Builder.php`

Why: prove the Builder annotations preserve both the standard generic builder and custom builder subclasses.

How:

- Add `assertType()` coverage for `whereCan()` and `withCan()` on `Builder<User>`.
- Include an enum or string-array call so parameter documentation is exercised.
- Add one assertion on `Post::query()` to prove `$this` is preserved as its existing `CommonBuilder<Post>` rather than widened to the base class.
- Do not add an assertion or Model annotation for `Post::whereCan()`: Model's existing static forwarding is no more statically analyzable for this macro than it is for normal Builder methods such as `where()`. Do not turn this feature into a Model-wide typing change.
- Do not add a PHPStan extension or a stub file.

### 15. Update `src/testing/src/Concerns/AssertsPolicyQueryConsistency.php`

Why: make the reusable assertions verify the production API and remove stale `*Scope` / `*Select` implementation coupling.

How:

- Rename the methods without compatibility wrappers:
  - `assertWhereCanMatchesPolicy()`
  - `assertWithCanMatchesPolicy()`
- Accept `UnitEnum|string` abilities and make `$user = null` mean current Gate user, exactly like the macros.
- Resolve one Gate from the container for the per-model expected values; call `forUser()` only for a non-null explicit user.
- Clone the supplied base query before applying the macro.
- Drive filtering through `whereCan()`.
- Drive annotation through `withCan()` using `hypervel_policy_result` as the fixed safe explicit test alias unless the caller supplies one.
- Normalize an enum ability with `enum_value()` before appending the explicit ` as {alias}` syntax. Do not call or duplicate the production default-alias formatter.
- Assert the hydrated annotation with strict identity and no manual boolean cast.
- Update failure messages to the public builder terminology.
- Rewrite the trait docblock so it explains consistency purpose without listing methods or referring to optional internal query methods as required.
- State in the trait docblock that the assertions use the application's Gate captured when the query-builder macros were booted. Tests must register policies on that singleton instead of replacing the Gate container binding after provider boot, which would make expected per-model checks and macro queries use different Gate instances.

### 16. Rework `tests/Testing/Concerns/AssertsPolicyQueryConsistencyTest.php`

Why: prove both symmetric fallbacks through the renamed public assertions and clean the currently untyped/stale test file.

How:

- Add `: void` to every test method.
- Configure the existing application Gate captured by the macros instead of replacing its container binding after provider boot.
- Add a Scope-only policy and a Select-only policy with the same per-model boolean rule.
- Use the bindings-safe base-query-builder contract in the Select-only policy.
- Prove `assertWithCanMatchesPolicy()` with the Scope-only policy.
- Prove `assertWhereCanMatchesPolicy()` with the Select-only policy.
- Retain owner, administrator, constrained base-query, custom alias, and empty-model failure coverage without duplicating the full Auth alias test matrix.
- Add one current-user/null case through AuthManager's coroutine-scoped user resolver and use explicit users for the remaining cases.
- Remove the unsafe raw interpolation, old assertion names, manual boolean cast assumptions, and comments that merely narrate fixture counts.

Run immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Testing/Concerns/AssertsPolicyQueryConsistencyTest.php
```

### 17. Add `tests/Integration/Database/AuthQueryAwarePolicyTest.php`

Why: prove the generated SQL, bindings, correlation, scope behavior, and hydration contract once across every supported database driver.

How:

- Place one class directly in `tests/Integration/Database/` and extend `DatabaseTestCase`.
- Use a test-specific namespace so inline `User`, `Post`, and policy helpers cannot collide with other integration files.
- Define the schema in `afterRefreshingDatabase()` with a string primary key, nullable owner identifier, and soft-delete column.
- Register the policy on the application's boot-captured Gate and use the real fluent macros.
- Cover the end-to-end boundaries that unit SQL-shape tests cannot prove:
  - a quote-containing string user identifier remains a binding and filters correctly;
  - Select-only `whereCan()` applies its scalar query as a WHERE fallback;
  - Scope-only `withCan()` produces a correlated `EXISTS` result;
  - nullable explicit selections hydrate strict `false`, never null;
  - authorized selections hydrate strict `true` on every driver;
  - multiple annotations preserve normal model attributes;
  - outer `withTrashed()` owns visibility and a trashed authorized row remains true;
  - a separate policy scope can call the retained `withTrashed()` extension on the unscoped inner builder;
  - one query annotates policy-before-allowed and policy-before-denied abilities to prove real `true` and `false` SELECT literals on every driver.
- Keep driver-neutral SQL and assertions. Do not create copies under `MySql`, `MariaDb`, `Postgres`, or `Sqlite`, and do not add SQL Server handling.
- Do not repeat the policy-scope OR-grouping regressions here. Step 8 proves their exact SQL shape and step 11 proves the derived path against the default real database; SQL operator precedence is identical across the supported drivers, so seven copies add runtime without another boundary.

Run immediately against the configured local/default database:

```bash
./vendor/bin/phpunit --no-progress tests/Integration/Database/AuthQueryAwarePolicyTest.php
```

The existing database workflow then runs this exact class unchanged on all seven supported database/version jobs.

### 18. Restructure `src/boost/docs/authorization.md`

Why: make the safe builder API the normal user path and remove documentation that teaches duplicated or interpolation-prone query rules.

How:

- Keep the existing Query-Aware Policies and Testing Query-Aware Policies navigation/anchors.
- Teach the normal per-model method plus `*Scope` first.
- Show `whereCan()` as the normal filter API, including the idiomatic `Post::whereCan('edit')` static form, chaining through `Post::query()`, and an explicit-user example.
- Show `withCan()` deriving from the same Scope-only policy, including multiple abilities and explicit aliases.
- Present `Gate::scope()` / `Gate::select()` as lower-level primitives for advanced composition, not as the main ceremony.
- Explain explicit `*Select` as an optional scalar boolean override and use the base-query-builder binding example from this plan. Do not interpolate any user-controlled value into raw SQL.
- Document the Expression contract as suitable only when the expression needs no runtime value bindings.
- State the exact same-builder return contract and set-membership meaning.
- Explain symmetric fallback and native-method precedence.
- Explain omitted/null current-user behavior and explicit-user behavior.
- Explain that query-aware assertions and builder macros use the application's booted Gate. Tests should register policies on that Gate rather than replace the container binding after the provider has booted.
- Explain that `before()` receives the builder's model prototype, not the row being authorized. Do not call it universally blank; model-instance shorthand and custom builders can carry state.
- State that query-aware results are boolean-only and point-in-time, carry no denial Response/message, and do not run `after()` callbacks.
- Document the inner global-scope rule: the outer query owns visibility, inner derived authorization excludes scope constraints but retains extensions.
- Document the primary-key requirement and unsupported composite model keys without proposing composite machinery.
- Document the `*Scope` derivation boundary for replacement queries, unions, windows, outer queries that alias the model table in `from`, and ordering-dependent rules.
- Document default and explicit aliases, duplicate behavior, strict boolean hydration, and the 63-byte alias limit in user-friendly prose. Explain that an overlong generated default fails with guidance to provide an explicit alias.
- Rename the consistency assertion examples and show Scope-only and Select-only fallback coverage.
- Remove the old manual `addSelect(['can_edit' => Gate::select(...)])` dance as the recommended path, the unsafe cast/interpolation example, old assertion names, and stale Expression-only wording.
- Match the tone and amount of detail in the surrounding Laravel-style documentation. Keep internal alias hashing and provider mechanics out of the user guide.

The auth package README is focused on package differences and user-cache behavior and contains no query-aware policy section. It remains unchanged.

### 19. Perform the final stale-surface audit

Why: the API rename and broader return contract must not leave dead fixtures, compatibility wrappers, misleading comments, or unsafe examples.

How:

Search the full live source, tests, types, and user docs and manually review every hit:

```bash
grep -RInI "assertScopeMatchesPolicy\|assertSelectMatchesPolicy\|NoScopePostPolicy" src tests types --exclude-dir=vendor --exclude-dir=node_modules
grep -RInI "Gate::scope\|Gate::select\|whereCan\|withCan" src tests types --exclude-dir=vendor --exclude-dir=node_modules
grep -RInI "applyScopeCallback\|function callScope" src tests types --exclude-dir=vendor --exclude-dir=node_modules
grep -RInI "Select.*Expression\|Database.Query.Expression" src/auth src/contracts src/support src/testing tests/Auth tests/Testing src/boost/docs/authorization.md --exclude-dir=vendor
grep -RInI "qualifyColumn.*user.*id\|DB::raw.*user" tests/Auth tests/Testing src/boost/docs/authorization.md --exclude-dir=vendor
```

Expected cleanup:

- Zero live references to the two old consistency assertion names.
- Zero references to `NoScopePostPolicy`.
- No compatibility wrappers or aliases for the renamed Hypervel-only testing methods.
- No query-policy example interpolates a user value into SQL.
- Gate contract, concrete, facade, Builder annotations, types, tests, and docs agree on enums and the selection union.
- `applyScopeCallback()` is the only new public generic grouping delegate and `callScope()` remains protected.
- Every new public name, argument order, fluent return, alias form, exception, and assertion remains consistent with the Laravel-style API audit above; no internal bridge vocabulary leaks into the application-facing authorization examples.
- No stale claim says `*Select` is required for annotations or `*Scope` is required for filtering.
- No changed test method lacks `: void`.
- No unused import, obsolete section comment, dead fixture, or duplicate cleanup hook remains.
- No SQL Server branch, directory, documentation, or test has been introduced.

## Test and Verification Plan

### Incremental file cadence

Run each changed/new test class immediately after completing it:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseEloquentBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Auth/AuthAccessGateScopeSelectTest.php
./vendor/bin/phpunit --no-progress tests/Auth/AuthServiceProviderTest.php
./vendor/bin/phpunit --no-progress tests/Auth/AuthEloquentBuilderCanTest.php
./vendor/bin/phpunit --no-progress tests/Testing/Concerns/AssertsPolicyQueryConsistencyTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/AuthQueryAwarePolicyTest.php
```

Do not continue to the next test file while the current file has a failure. Straightforward mistakes are corrected immediately. A failure that exposes a new source defect or unclear contract is investigated and reported under the repository stop rules before changing behavior.

### Focused behavior suites

After the individual files are green:

```bash
./vendor/bin/phpunit --no-progress tests/Auth
./vendor/bin/phpunit --no-progress tests/Testing/Concerns/AssertsPolicyQueryConsistencyTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/AuthQueryAwarePolicyTest.php
```

The integration class is driver-neutral. The local run proves the configured/default database path; `.github/workflows/databases.yml` supplies the same class to MySQL 8/9, MariaDB 10/11, PostgreSQL 17/18, and SQLite without duplicate test files.

### Static analysis and formatting

Run from the components repository root:

```bash
composer analyse
./vendor/bin/php-cs-fixer fix
```

`composer analyse` runs both the main PHPStan configuration and `phpstan.types.neon.dist`, so it checks the source changes and the new Builder `assertType()` coverage without a duplicate standalone pass. Review every formatter change; formatter-driven multi-file edits are the allowed mechanical exception to one-file manual editing.

### Full suite

```bash
composer test:parallel
```

No Testbench package source changes are part of this design, so `composer test:testbench` is not required.

### Final diff review

```bash
git diff --check
git status --short
git diff --stat
```

Read the complete final diff one file at a time. Re-run the stale-surface searches after formatting. Confirm the diff contains only the coherent final design, not failed attempts, temporary comments, compatibility shims, or unrelated edits.

## Rejected Designs

- **Native methods or an auth concern on Eloquent Builder:** this would create a database-to-auth dependency. Global macros provide the public surface at the existing extension boundary.
- **Making protected `Eloquent\Builder::callScope()` public:** a Laravel-compatible custom Builder may override that protected extension point. Widening the parent would make such a subclass fatally reduce visibility. Keep it protected and add the narrowly named fluent `applyScopeCallback()` delegate instead.
- **Naming the delegate `applyScope()` or using `tap()`:** `applyScope()` is easy to confuse with the existing clone-producing `applyScopes()`, while `tap()` does not group OR conditions. `applyScopeCallback()` matches the same Builder's `applyAfterQueryCallbacks()` naming and states that a callback receives scope semantics.
- **Nesting policy scopes in `where(Closure)`:** Eloquent transfers the nested builder's WHERE clauses, eager loads, and removed-scope markers, but not joins and other query mutations. It would silently weaken the documented same-builder scope contract, while the existing `callScope()` path groups OR clauses on the real builder.
- **Returning Eloquent Builder from `*Select`:** an Eloquent builder is row-producing and not necessarily scalar. Base Query Builder is the precise binding-preserving contract.
- **A bound-expression wrapper class:** base Query Builder already carries SQL and bindings, so another value object duplicates an existing primitive.
- **Only scope-to-select fallback:** symmetric fallback is small, uses existing query-builder operations, and lets a genuinely selection-shaped rule still power filtering.
- **Falling back after a guest-ineligible native method:** method precedence would become user-dependent and surprising. The selected native method denies.
- **Omitted-versus-explicit-null sentinels:** the signature would hide two meanings for null. Current-user/null plus explicit non-null user is the direct contract; Gate primitives handle deliberate guest perspective.
- **Applying global scopes inside derived authorization:** outer visibility already owns row inclusion, and inner SoftDeletes would make outer `withTrashed()` annotations incorrect.
- **Using `newModelQuery()` or the outer model for the inner query:** it loses global-scope extensions or mutates the outer model table. A fresh `newInstance()` plus `newQueryWithoutRelationships()->withoutGlobalScopes()` avoids both defects.
- **Fixed alias or mutable counter for the inner table:** a fixed alias can collide in nested authorization queries, while a counter introduces worker-lifetime state. A deterministic ability/model hash is direct and stateless.
- **Shortening the inner hash:** the full digest fits PostgreSQL's limit. Truncation buys nothing and raises collision risk.
- **Silently truncating or hashing generated result aliases:** PostgreSQL truncation can change the hydrated attribute name and bypass its cast, but a framework-generated replacement is not discoverable from the requested ability. Fail with guidance to supply a short explicit alias.
- **Cross-call alias registry/introspection:** duplicate detection inside one input catches the actionable mistake. Tracking earlier chains needs shared state or brittle query inspection without a useful supported contract.
- **Composite-key machinery:** Hypervel Eloquent exposes one string primary key and does not support composite model keys. Building another key abstraction for one fallback would be dead complexity.
- **Authorization-result or derived-query caching:** selections contain user-specific bindings. Worker-lifetime caching would risk cross-user leakage and offers no sound immutable cache key.
- **Per-macro container Gate resolution:** provider callbacks should capture their injected worker-safe dependency. Supporting test-only facade swaps does not justify a container lookup in every query.
- **Rebooting `AuthServiceProvider` inside a live feature test:** `CacheServiceProvider` finalizes the process-wide serializable-class policy during application startup, so a later auth-provider boot correctly rejects another class-policy registration. Provider rebootstrap belongs in `AuthServiceProviderTest`, where injected doubles isolate that contract; the end-to-end coroutine test uses the application's booted Gate and AuthManager chain.
- **Four driver-specific integration copies or SQL Server handling:** the behavior is shared, the workflow already fans one class across every supported driver, and SQL Server is unsupported.
- **Repeating OR-grouping regressions on every database job:** the defect is SQL shape plus standard AND/OR precedence. A Gate SQL-shape unit test and one default-database feature test cover it without multiplying identical assertions across seven jobs.
- **A PHPStan extension or separate stub framework:** two generic-preserving Builder annotations plus the existing types fixture cover the static surface.
- **Compatibility wrappers for old assertion names or stale fixture classes:** earlier Hypervel compatibility is not a requirement and wrappers would leave dead vocabulary in the final API.

## Expected Final State

- Query-aware policies are normally written once as a per-model method plus a same-builder `*Scope` method.
- `whereCan()` filters and `withCan()` hydrates strict booleans through one documented fluent API.
- An optional bindings-safe scalar `*Select` override can power both annotation and filtering.
- Gate resolves one public query method, runs before hooks once, applies native precedence, and never runs after hooks for query operations.
- Every authorization constraint appended by Gate runs through Eloquent's existing OR-safe grouping on the real Builder, so caller constraints, denials, selection fallbacks, chained policies, global scopes, and derived correlations cannot bypass one another.
- Scope-derived annotations use an isolated aliased inner model, no inner global-scope constraints, preserved scope extensions, a primary-key correlation, and a binding-preserving `EXISTS` scalar query.
- Nullable explicit selections become false at the Gate boundary on all supported databases.
- Provider macros capture only the boot-injected Gate and are replaced on every application boot.
- Current users remain coroutine-local; no query, result, alias, or user cache is added.
- Static tooling sees both fluent methods without coupling database source to auth.
- The consistency trait tests the production macros with strict results and both fallback directions.
- One integration class covers MySQL, MariaDB, PostgreSQL, and SQLite through the existing workflow.
- All old assertion names, unsafe interpolation examples, misleading fixtures, stale comments/docs, and unused code are gone.
