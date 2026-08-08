# Sanctum correctness, cache settlement, and current parity

## Objective

Complete the Sanctum audit by fixing verified authentication, revocation, transaction,
configuration, routing, command, type, metadata, and documentation defects at their lowest owners.
Preserve Hypervel's coroutine-safe guard, explicit session-guard model, token cache, strict
`id|token` format, provider validation, sticky-read optimization, and configurable token model.

Public Laravel APIs remain compatible unless an existing behavior is itself unsafe or incorrect.
The only new protected Sanctum extension point is the token-specific relation factory required to
make documented bulk revocation cache-correct without intercepting unrelated Eloquent relations.

## Evidence baseline

- Hypervel branch baseline: `0.4` at `128c71b73`.
- Audited Hypervel snapshot: `db70c7ce7def14382d7d22d2f90b15e8db0ae9d7`.
- Current Laravel Sanctum reference: `7fb0d860302f9dd45c8d0de363d860e1666a5771`.
- Current Laravel framework reference: `9f27fa054af628015e7ada84b0571e7b86cea03e`.
- Historical Sanctum changes inspected for intent and complete file scope:
  `9526c2c` (optional last-used tracking), `56d32449` (strict abilities), `6cf798f`
  (route switch), `3f44e31` (provider extension structure), `fe361b9` (token generic),
  and `3f57a4c` plus revert `d61eb74` (integer token IDs).
- The complete local Sanctum source, tests, split/root metadata, Boost guide, Database
  transaction manager, Auth model cache, Foundation auth configuration, and middleware
  configuration were traced. Focused probes reproduced every accepted defect.
- `sanctum-01`, `sanctum-02`, and `cache-04` remain complete. The old audit label
  `sanctum-02` for relation deletion collided with the completed JSON finding; the relation
  correction is `sanctum-17`. The custom-model cache seam correction is `sanctum-18`.
  New shared-owner findings are `database-26`, `database-27`, `database-28`, and
  `auth-18`.

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to that plan's **Established remediation vocabulary** section.

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

## Architecture and retained boundaries

- `SanctumGuard` remains a worker-shared direct guard whose resolved user is stored only in
  `CoroutineContext`. Do not port Laravel's mutable `RequestGuard` or request rebinding.
- `PersonalAccessToken` owns token/tokenable cache keys, lookup, model-event invalidation,
  last-used throttling, and cache refill ordering.
- `HasApiTokens` owns the one package-provided token relationship. A Sanctum relation subtype may
  change only this relation's cache-enabled delete path.
- Database connections and `DatabaseTransactionsManager` remain the sole transaction-settlement
  owners. Sanctum and Auth schedule callbacks; neither adds transaction state.
- Unnamed manager callbacks retain Laravel's ambient locality: they follow the most recently
  started open transaction and its enclosing stack on that connection. They do not coordinate
  transactions across connections.
- `Sanctum` owns boot/test worker-static token-model and callback configuration. The existing
  global test subscriber remains the reset owner.
- Foundation configuration owns the shipped `sanctum` guard and required `session_guards`.
  `Middleware::statefulApi()` owns middleware priority.
- Cache remains disabled by default. Cache-disabled relation deletion keeps its existing
  one-query path; authentication does not gain a query, lock, generation, or cache round trip.

## Findings and final decisions

| ID | Category | Severity | Final decision |
|---|---|---:|---|
| `sanctum-17` | Relation revocation cache defect | Major | Use a token-specific `MorphMany` subtype and protected factory; select exact IDs only when caching is enabled, delete exactly that set, and invalidate after successful settlement. |
| `sanctum-03` | Token cache settlement defect | Major | Move automatic invalidation to successful model events and settle it through the model connection. Clear pre-created negative entries on creation. |
| `sanctum-18` | Custom token-model cache defect | Major | Use late static binding consistently for every protected cache seam so custom key/store overrides are read, written, and invalidated coherently. |
| `database-26` | Cross-connection callback defect and upstream defect | Major | Pass the connection name from `Connection::afterCommit()` and `afterRollBack()` to the existing transaction manager. |
| `database-27` | Ambient callback contract documentation | Moderate | Preserve Laravel's latest-open-transaction behavior and correct claims that it coordinates every open connection. |
| `database-28` | Pooled-connection lifecycle warning | Minor | Mark `Connection::unsetTransactionManager()` tests-only because the null manager survives pool release and breaks later callback scheduling. |
| `auth-18` | User-cache settlement defect | Major | Settle Eloquent user cache invalidation on the model connection while preserving event-time identifier resolution and commit-time descriptor discovery. |
| `sanctum-04`, `sanctum-13` | CSRF route and provider parity | Major | Port current route and protected provider structure, adapted to Hypervel's direct guard and middleware owner. |
| `sanctum-05`, `sanctum-10` | Credential transport and token-ID validation | Major | Use the request bearer parser, centralize validation in `findToken()`, reject empty halves and out-of-range integer IDs before cache/SQL, and preserve custom overrides/string keys. |
| `sanctum-06`, `sanctum-07` | Testing helper and ability correctness | Moderate | Select the requested guard, normalize enums once, and compare abilities strictly without `array_flip()`; retain the already-exact trait check. |
| `sanctum-08` | Static cleanup | Minor | Restore the host placeholder from one canonical constant. |
| `sanctum-09`, `sanctum-11` | Config and JSON contracts | Major/Moderate | Revalidate the already-complete numeric config and single-pass throwing JSON corrections; do not rewrite them. |
| `sanctum-12` | Destructive command validation and upstream defect | Major | Reject negative, decimal, and nonnumeric hours before issuing a query; preserve zero. |
| `sanctum-14` | Package metadata | Moderate | Declare all direct split dependencies and mirror provider discovery in the root package. |
| `sanctum-15` | Public documentation | Minor | State exact automatic invalidation, transaction timing, deliberate escape hatches, bounded-cache guidance, and concise public differences. |
| `sanctum-16` | Public/static-analysis types | Minor | Type real runtime contracts, preserve concrete `actingAs()` returns, and use only proof-local static-analysis suppression where PHP cannot express trait capability. |

## Implementation

### 1. Correct named transaction callback ownership and manager-removal guidance (`database-26`, `database-28`)

In `ManagesTransactions`, forward the current connection name to the manager:

```php
$this->transactionsManager->addCallback($callback, $this->getName());
$this->transactionsManager->addCallbackForRollback($callback, $this->getName());
```

The existing public signatures, immediate behavior, FIFO callback queues, nested transaction
records, and manager API remain unchanged. This fixes Laravel's current cross-connection bug:
without the name, `latestApplicableTransaction(null)` may attach a callback to the newest record
from another connection.

State in both connection method docblocks that callbacks belong to that connection. Unnamed
manager callbacks deliberately remain ambient and select the latest applicable record across
connections. This supports independent transactions and matches Laravel. Do not add an
all-connections barrier: the manager cannot infer a callback's dependency set, and suppressing a
callback because an unrelated transaction rolled back is silent and unrecoverable. An early job is
often observable and retryable, but not universally; neither behavior supplies atomic commits
across connections.

Mark `Connection::unsetTransactionManager()` tests-only without changing its signature,
visibility, or behavior. A pooled connection retains the null manager after release, so calling
this method at runtime breaks after-commit scheduling for later borrowers.

Sanctum and Auth use this two-branch settlement shape:

```php
$connection = $model->getConnection();

if ($connection->getTransactionManager() === null && $connection->transactionLevel() === 0) {
    $callback();

    return;
}

$connection->afterCommit($callback);
```

With a manager, Database decides whether to queue or run immediately. Without a manager and an
open transaction, the existing `RuntimeException` is retained so cache correctness fails closed.
Do not catch it, add rollback repair, or build a package transaction abstraction.

### 2. Make relation revocation cache-correct (`sanctum-17`)

Add `PersonalAccessTokenRelation extends MorphMany` and have `HasApiTokens::tokens()` construct it
through a dedicated protected `newTokenRelation()` factory. This deliberately replaces the
incidental use of the model-wide `newMorphMany()` hook for this package-owned relationship. Add a
short source comment explaining the distinction and prove that applications can override the new
factory.

Declare `tokens(): PersonalAccessTokenRelation` natively with
`@return PersonalAccessTokenRelation<$this>`. Define the relation as
`@template TDeclaringModel of Model` extending
`MorphMany<PersonalAccessToken, TDeclaringModel>`. The trait's independent `TToken` template
continues to describe the current access token, including `TransientToken`; it no longer falsely
parameterizes the concrete database relation.

The factory duplicates only Eloquent's small morph-relation construction boundary:

```php
protected function newTokenRelation(): PersonalAccessTokenRelation
{
    $instance = $this->newRelatedInstance(Sanctum::personalAccessTokenModel());
    [$type, $id] = $this->getMorphs('tokenable', null, null);

    return new PersonalAccessTokenRelation(
        $instance->newQuery(),
        $this,
        $instance->qualifyColumn($type),
        $instance->qualifyColumn($id),
        $this->getKeyName(),
    );
}
```

The relation's `delete(): mixed` remains one query when cache is disabled, matching
`Eloquent\Builder::delete()` without narrowing custom `onDelete` results. Since relation deletion
normally reaches the builder through `Relation::__call()`, the disabled branch delegates exactly
to `$this->getQuery()->delete()`; there is no parent relation `delete()` method. When enabled:

```php
$ids = (clone $this->getQuery())->pluck($this->getRelated()->getQualifiedKeyName());

if ($ids->isEmpty()) {
    return 0;
}

$deleted = (clone $this->getQuery())->whereKey($ids->all())->delete();

$this->settleInvalidation($ids->all());

return $deleted;
```

Use cloned Eloquent builders for both selection and deletion so global and soft-delete scopes
apply without retaining the internal exact-ID constraint on a reused relation. Call Eloquent
Builder's existing delete so `SoftDeletes` keeps its `onDelete` behavior. Capture only scalar IDs;
do not hydrate models.

Queue invalidation for every selected ID after a successful delete, regardless of delete count.
This exact-set rule prevents tokens inserted between selection and deletion from being removed
without invalidation. Integer keys use Eloquent's `whereIntegerInRaw()` and avoid bind-parameter
ceilings. Custom string keys keep normal `whereIn()` behavior; do not add chunking or range logic.
Settle the callback through `$this->getRelated()->getConnection()`, never the tokenable parent's
connection; the token model connection owns both the delete and its transaction record. In the
callback, invalidate each ID through `$this->getRelated()::clearTokenCache($id)` so the configured
custom token model's late-static cache namespace remains authoritative.

### 3. Settle token model cache mutations after success (`sanctum-03`)

Before changing listener timing, replace every cache-helper call in `PersonalAccessToken` from
`self::` to `static::`, including reads, tokenable reads, invalidation, and nested key construction.
`updateLastUsedAt()` already uses `static::`; the current mixture makes a documented custom token
model's protected `getCache()`/`getCacheKey()` overrides write under one namespace while reads and
invalidations use another. This correction is `sanctum-18` and adds no runtime work.

```php
$cache = static::getCache();
$cacheKey = static::getCacheKey($id);

static::forgetTokenEntry($cache, $id);
static::clearTokenCache($id);
```

Replace `updating`/`deleting` listeners with `created`, `updated`, and `deleted` listeners:

```php
static::created(function (self $token): void {
    $id = $token->getKey();

    $token->settleCacheMutation(
        fn () => static::forgetTokenEntry(static::getCache(), $id)
    );
});

static::updated(function (self $token): void {
    $id = $token->getKey();
    $lastUsedAtOnly = $token->wasOnlyLastUsedAtChanged();

    $token->settleCacheMutation(fn () => $lastUsedAtOnly
        ? static::forgetTokenEntry(static::getCache(), $id)
        : static::clearTokenCache($id));
});

static::deleted(function (self $token): void {
    $id = $token->getKey();

    $token->settleCacheMutation(fn () => static::clearTokenCache($id));
});
```

The listeners return before this shape when caching is disabled. The helper names are illustrative;
the final compact protected helpers must retain the two-branch settlement semantics from step 1
and snapshot every scalar decision before deferral. Creation clears only the token entry, closing
the security-relevant pre-created negative-cache window.

For successful updates, inspect `getChanges()` after save and remove the actual timestamp column
only when `getUpdatedAtColumn()` is non-null. A remaining exact `['last_used_at']` change is the
internal audit write and clears only the token entry. General changes and every instance delete
clear token and tokenable entries. Keep the extracted `wasOnlyLastUsedAtChanged()` helper protected;
tests assert its observable invalidation result. Cancelled events schedule nothing.

After `updateLastUsedAt()` saves, queue a relation-free token snapshot put after the updated-event
forget. The existing `withoutRelation('tokenable')` clone, FIFO callback ordering, write-sticky
state restoration, throttling, and failed-save restoration remain intact.

### 4. Settle Auth's Eloquent user cache (`auth-18`)

Retain one model listener pair in `EloquentUserProvider`, but replace immediate forget with the
same connection-owned settlement rule. Resolve the optional cache-key callback's identifier segment
at the model event, because the model and request-scoped resolver inputs may change before commit.
Read the descriptor registry inside the committed callback so providers registered while the
transaction is open are also invalidated.

Do not share a new helper across Auth and Sanctum: their payloads and lifecycles differ, while the
Database connection already supplies the shared primitive.

### 5. Restore the usable CSRF route and provider extension structure

Delete `src/sanctum/routes/web.php`. Add current upstream-shaped protected methods in this order:

```php
public function boot(): void
{
    // Existing cache policy, validation, publishing, and command ownership.
    $this->defineRoutes();
    $this->configureGuard();
    $this->configureMiddleware();
}

protected function defineRoutes(): void
{
    if ($this->app->routesAreCached()) {
        return;
    }

    $config = $this->app->make(ConfigRepository::class);

    if (! $config->boolean('sanctum.routes', true)) {
        return;
    }

    Route::group(['prefix' => $config->string('sanctum.prefix', 'sanctum')], function (): void {
        Route::get('/csrf-cookie', [CsrfCookieController::class, 'show'])
            ->middleware('web')
            ->name('sanctum.csrf-cookie');
    });
}
```

`configureGuard()` and `createGuard()` preserve the direct coroutine-safe `SanctumGuard`, guard
name, configured provider, required `session_guards`, optional event dispatcher, expiration, and
last-used setting. Do not port request refresh/rebinding.

`configureMiddleware()` retains the existing frontend cookie hardening. It does not mutate kernel
priority: `Middleware::statefulApi()` owns that in Hypervel. Record this deliberate same-named API
difference in the README, a concise source comment, and a `REMOVED:` marker at the matching
upstream test boundary.

The typed route getters make malformed `sanctum.routes` and `sanctum.prefix` values fail during
provider boot rather than guessing. Document the boolean/string contracts beside the public CSRF
route controls.

Do not merge a Laravel default `auth.guards.sanctum` in the provider. Foundation already publishes
the explicit guard with `session_guards => ['web']`; a package provider cannot invent that required
application choice. Clarify the existing omission marker/difference instead.

In `EnsureFrontendRequestsAreStateful::frontendMiddleware()`, retain the supported
class-string-or-null contract with `$candidate !== null && $candidate !== ''` and strict
deduplication. Do not silently discard invalid non-string configuration; let the middleware
pipeline report it. Keep the missing `authenticate_session` default as the intentional optional
case.

```php
if ($candidate !== null
    && $candidate !== ''
    && ! in_array($candidate, $filtered, true)) {
    $filtered[] = $candidate;
}
```

### 6. Centralize bearer parsing and token lookup validity

`SanctumGuard::getTokenFromRequest()` preserves the configured retrieval callback and otherwise
calls `Request::bearerToken()`. Delete `getBearerToken()`, `isValidBearerToken()`, input/query token
fallback, and the duplicate configured-model allocation.

Default `PersonalAccessToken::findToken()` remains authoritative for the supported format:

```php
if (! str_contains($token, '|')) {
    // Hypervel only supports the id|token format created by createToken().
    // Laravel's legacy plain-token lookup is intentionally omitted because
    // Sanctum's cache and invalidation paths are keyed by token ID.
    return null;
}

[$id, $plainToken] = explode('|', $token, 2);

if ($id === '' || $plainToken === '') {
    return null;
}

if ((new static)->getKeyType() === 'int'
    && (! ctype_digit($id) || filter_var($id, FILTER_VALIDATE_INT) === false)) {
    return null;
}
```

No cache or query runs for invalid identifiers. Do not cast the ID: overflow must not alias a valid
row. Custom string-key models retain nonnumeric IDs, and a custom model's `findToken()` override
remains fully authoritative.

Delete the one-caller protected `isValidTokenIdentifier()` helper rather than retain a second
format owner. `findToken()` is the documented override point; removing the Hypervel-only helper is
a deliberate cleanup, not a Laravel API change.

Port current request-parser assertions for case-insensitive schemes, last `Bearer ` occurrence,
comma truncation, and absence. Add Hypervel assertions for headers, rejected query/body/array input,
empty halves, integer overflow, in-range values, callback transport, string keys, and custom lookup.

### 7. Correct abilities and the testing helper

`PersonalAccessToken::can()` normalizes the requested `UnitEnum|string` once and uses strict linear
membership for wildcard and exact values. Remove `array_flip()`:

```php
$ability = enum_value($ability);

return in_array('*', $this->abilities, true)
    || in_array($ability, $this->abilities, true);
```

`Sanctum::supportsTokens()` already uses an exact trait-map lookup; retain and revalidate it.
`TransientToken` remains unconditional.

`Sanctum::actingAs()` accepts `Authenticatable`, templates and returns the same concrete user,
normalizes both supplied and requested abilities with `enum_value()`, treats wildcard/exact matches
strictly, returns false for unlisted abilities, sets the requested guard's user, and calls
`shouldUse($guard)`.

Preserve the existing `wasRecentlyCreated` behavior for Eloquent and compatible custom
authenticatables. Since that property is not on the `Authenticatable` contract, use narrowly scoped
`property.notFound` suppressions with reasons at the read and assignment; do not add a runtime
`instanceof Model` branch solely for PHPStan.

Its docblock must begin with `Tests only.` and state that it installs a Mockery token double and
replaces the current coroutine's authenticated user/default guard with test state. Mockery remains
a development dependency and this is not an application API.

### 8. Correct static state and type contracts

Define one protected host-placeholder default constant, initialize the property from it, and restore
it in `flushState()`.

Remove the unhelpful generic class template from `Sanctum`. Type its model property, accessor, and
mutator as `class-string<PersonalAccessToken>`. Keep `HasApiTokens`' token generic bounded by
`HasAbilities`, because `TransientToken` is valid.

After `supportsTokens()` proves trait use, keep only identifier-scoped `method.notFound`
suppressions with a reason at the exact `withAccessToken()` calls. Do not add runtime interfaces or
guards for PHPStan. Add `types/Sanctum/Sanctum.php` for acting-as enums, concrete returns, token-model
configuration, and documented trait-only models. The trait-only fixture must call both `tokens()`
and `createToken()` so PHPStan analyzes the otherwise zero-production-use trait and its protected
relation construction.

### 9. Revalidate config and JSON; correct pruning

- Retain the existing integer cast for `sanctum.cache.ttl` and the existing
  `FILTER_VALIDATE_INT` plus null-on-failure form for `last_used_at_update_interval`; startup
  validation owns malformed values and zero is valid.
- Retain `NewAccessToken::toJson()`'s existing single `json_encode()` with caller flags ORed with
  `JSON_THROW_ON_ERROR` and its `JsonException` declaration.
- Resolve the configured token model through `Sanctum::personalAccessTokenModel()`. Validate
  `--hours` as an integer with minimum zero before constructing the prune query. Invalid negative,
  decimal, and nonnumeric values print a concise named error and return `Command::FAILURE`; zero
  and positive integers proceed and return `Command::SUCCESS`.

```php
$hours = filter_var(
    $this->option('hours'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0]],
);

if ($hours === false) {
    $this->error('The --hours option must be a non-negative integer.');

    return Command::FAILURE;
}
```

No config validation subsystem or second JSON pass is added.

### 10. Complete metadata, public docs, and provenance

In the split manifest add direct runtime dependencies: `ext-ctype`, `ext-filter`, `ext-json`,
`hypervel/cookie`, `hypervel/foundation`, `hypervel/session`, and
`symfony/http-foundation`. `symfony/console` already exists. Add Sanctum's provider to root package
discovery, not `DefaultProviders`. Add executable root/split metadata coverage.

Order `src/sanctum/README.md` as header, official Documentation link, Differences From Laravel,
then Ported from. Keep the difference list concise and public:

- token-specific relation factory instead of `newMorphMany()`;
- explicit default guard, removal of Laravel's global `sanctum.guard` accept-list, required
  per-guard `session_guards`, and provider-checked stateful users;
- middleware priority owned by `Middleware::statefulApi()`;
- the currently false claim that every deletion invalidates immediately, replaced with the exact
  instance/relation paths and after-commit timing;
- corrected strict-format and other existing cache differences.

Update the Boost guide to state that instance create/update/delete and cache-enabled token relation
deletes invalidate automatically after successful commit; last-used writes refresh only the token
entry; raw SQL, quiet/eventless mutation, arbitrary builder updates, and bulk restore require
explicit `clearTokenCache()` handling on the configured token model class. Document exact-set
relation deletion without SQL detail, and explain that the prune command's already-expired entries
remain harmlessly cached until TTL.
Advise bounded cache capacity/eviction and normal application rate limiting for attacker-controlled
negative-cache cardinality. Do not imply Hypervel supplies a rate limiter here.

Correct inherited “all open database transactions” wording in the Bus, Foundation, Queue, Mail,
Notifications, Events, Broadcasting, and Scout documentation. Concise source summaries say “open
parent database transactions”; the manager docblocks and Queue guide own the precise rule:
after-commit work uses the most recently started open transaction and runs after its enclosing stack
on that connection commits. Work depending on other connections must be dispatched only when those
dependencies are already committed or the selected transaction will commit last.

### 11. Complete audit records

Update the core plan's dependency index and checklist, plus the completion ledger:

- add final dependency-index rows for `sanctum-03` through `sanctum-10` and `sanctum-12`
  through `sanctum-18`, plus `database-26` through `database-28` and `auth-18`; `sanctum-11` is superseded by the
  completed `sanctum-02` JSON finding and must not become a duplicate row;
- route `database-26` to Database, Sanctum, and Auth and mark all revalidation complete;
- route `database-27` to Database, Bus, Foundation, Queue, Events, Mail, Notifications,
  Broadcasting, and Scout;
- route `database-28` to Database, Auth, and Sanctum;
- route `auth-18` to Auth and Sanctum and mark both complete;
- edit the existing `support-02`, `sanctum-01`, and `cache-04` dependency-index rows to remove
  their pending/later-full-Sanctum wording and mark Sanctum revalidated; retain the completed
  `sanctum-02` JSON row and remove its later-full-Sanctum wording;
- add a completed Sanctum entry with final findings, architecture, compatibility, performance,
  rejected concerns, validation, and this detail-plan link;
- change the core package checklist entry for `sanctum` to complete.

The ledger must state that exact-ID relation selection has no normal integer-key bind ceiling,
while custom string-key models retain the database's ordinary parameter limit. Record
`database-26` as a deliberate upstream correctness fix and the dedicated relation factory as a
documented Hypervel extension difference. Record `database-27` as Laravel-identical ambient
locality, not cross-connection atomic coordination, including the qualified observability tradeoff.

## Test plan

### Database

- Default transaction with a newer second-connection transaction: a callback registered on default
  fires only after default commits.
- Same ordering with default rollback: the callback never fires after the other connection commits.
- Register a rollback callback on a connection that is not the newest open transaction and prove it
  follows only that connection's rollback.
- Callback on a connection with no application transaction while another connection has one runs
  immediately.
- Rollback callback on a connection with no application transaction is not attached to another
  connection's transaction.
- Preserve same-connection, suffixed-connection, nested, commit, and rollback coverage. Add the
  currently absent connection-without-manager exception coverage required by fail-closed consumers.
- Retain the existing manager tests that counterfactually pin unnamed latest-transaction locality;
  no barrier or duplicate ambient test is added.

### Sanctum cache and relations

- Pre-poison a future ID, create that exact token, and authenticate immediately.
- Successful, cancelled, no-transaction, outer/nested commit, and rollback create/update/delete.
- Cache refill before commit cannot survive committed mutation; rollback preserves committed state.
- Last-used FIFO forget/put, timestamp-disabled model, custom timestamp column, failed save,
  throttling, relation-free snapshots, and sticky-read restoration.
- Instance soft delete, restore, and force delete through a custom token model.
- Cache-enabled all-token, constrained, zero-match, soft-delete, transaction, token-model-connection,
  integer-key, and custom string-key relation deletion; cache-disabled delete retains one query.
- Managerless relation deletion inside an open transaction fails closed and preserves both cache
  entries after rollback.
- Override `newTokenRelation()` and prove it remains the supported construction seam.
- Override a custom token model's primary-key name and protected `getCacheKey()`, then warm, mutate,
  and prove reads, refill, instance invalidation, and relation-delete invalidation all use its real
  primary key and subclass namespace.
- Reuse a relation after cache-enabled deletion and prove the internal exact-ID constraint was not
  retained on its builder.

### Sanctum HTTP, provider, command, and types

- Real default CSRF response has a session and expected cookies; assert route name, exact `web`
  middleware, default/custom prefix, disabled routes, cached routes, and malformed route option
  types.
- Exercise protected `defineRoutes()`, `configureGuard()`, `createGuard()`, and
  `configureMiddleware()` through provider test subclasses.
- Bearer parsing and invalid/default/custom lookup cases from step 6.
- Acting-as guard selection, enums, wildcard, unlisted ability, concrete return, and current-token
  template type.
- Strict stored ability comparison, exact existing trait detection, static host reset, retained
  numeric config types, retained throwing JSON, strict optional middleware filtering, and prune
  invalid/zero/positive cases with no-query proof for invalid input.
- Split/root metadata and provider discovery.
- Maximum-level `types/Sanctum/Sanctum.php`; the existing full type gate covers Auth and Database.

### Auth

- Saved/deleted user invalidation outside transactions, after commit, and discarded on rollback.
- No-manager/no-transaction immediate settlement and no-manager/open-transaction failure.
- Cross-connection callback ownership.
- Event-time key-resolver segment with commit-time descriptor discovery.

### Verification cadence

Use focused tests while editing. After implementation, run `composer fix` once as the authoritative
gate; it runs formatting, PHPStan, and the complete parallel suite. Then run `git diff --check`,
stale-reference scans, and a fresh caller/callee, lifecycle, performance, compatibility, and
overengineering review before external code review.

## Performance and compatibility budget

- Bearer authentication becomes cheaper: one model allocation and one duplicate validation pass
  are removed. Integer validation is two local scalar checks before cache/SQL.
- Strict ability scans avoid `array_flip()` allocation and operate on normally tiny lists.
- Token/Auth mutation paths perform the same cache invalidations at the correct settlement point;
  callback registration is local and non-yielding.
- Late static binding replaces early static binding at existing cache-helper calls; it adds no
  operation and makes the documented custom-model seam coherent.
- Cache-enabled token relation deletion adds one ID-selection query. Cache-disabled deletion keeps
  one query. No read path changes.
- `database-26` adds one connection-name argument lookup to callback registration only, not queries
  or request reads.
- `database-27` changes documentation only; unnamed Queue/Event callback selection remains O(1).
- `database-28` changes documentation and tests only.
- Routes, config casts, provider structure, metadata, static cleanup, types, and docs are boot,
  command, test, or build-time only.
- Laravel public names/signatures are restored or preserved. Intentional differences are limited to
  the existing Hypervel guard/cache/session architecture, the dedicated token relation factory,
  strict `id|token` support, removal of undocumented request-input credentials, fail-closed
  destructive input, and the named-connection upstream bug fix.

## Rejected concerns

- No generic Eloquent builder hook, model-wide relation override, reflection into delete callbacks,
  or unbounded model hydration.
- No cache generation, lock, tag, retry, registry, rate limiter, ID range, rollback repair, or raw
  query listener.
- No guarantees across raw SQL, quiet/eventless mutation, arbitrary builder updates, or bulk restore.
- No duplicated transaction owner or shared Auth/Sanctum settlement abstraction.
- No all-connections callback barrier or new Queue/Event dependency protocol; applications must
  choose a dispatch point consistent with their cross-connection dependencies.
- No Laravel `RequestGuard`, request rebinding, mutable middleware priority, or provider-invented
  `session_guards`.
- No cast of integer token IDs, legacy plain-token lookup, or compatibility transport via query/body.
- No runtime interfaces or guards solely for static analysis.
- No universal string-key deletion chunking without a demonstrated supported consumer and limit.
- No cache for `currentApplicationUrlWithPort()` or config-validation framework for two values.

## Completion criteria

- Every accepted Sanctum, Database, and Auth finding is implemented at its lowest owner.
- All superseded helpers, route files, stale comments, false docs, and broad suppressions are removed.
- Counterfactual tests cover every corrected behavior and intentional omission.
- `composer fix`, diff checks, stale scans, self-review, and independent code review are green.
- The core routing index, dependency index, ledger, and checklist truthfully record completion.
