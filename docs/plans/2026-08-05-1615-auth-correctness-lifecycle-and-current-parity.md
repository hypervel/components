# Auth Correctness, Lifecycle, and Current Parity

## Status and scope

**Status:** Complete. Implementation, validation, fresh self-review, and independent code review are signed off.

Complete the Auth audit against:

- Hypervel `0.4` at this branch's base;
- current Laravel framework source and Auth integration tests under `examples/laravel/framework`;
- the completed `auth-01`, `auth-02`, `support-02`, `container-09`, and `cache-04` decisions;
- current Auth, Events, Foundation configuration, Fortify, Contracts, Support facade, Boost documentation, and split-package metadata surfaces.

The work fixes the verified Auth findings and the same-family issues found while tracing them. It is not a fresh redesign of authentication. Preserve Hypervel's guard and broker architecture:

- `AuthManager`, resolved guards, Gate, password brokers, and configured user providers remain worker-lifetime objects;
- selected guards, brokers, user overrides, and guard user state remain coroutine-local where request code may change them;
- every timed authentication operation continues to clone its `Timebox`;
- Event fakes and event-container rebindings refresh already-resolved consumers without resolving unused services;
- Eloquent user caching retains supported-store validation, static/dynamic tags, tenant-aware identifiers, deduplicated descriptors, and model-event invalidation;
- explicit nullable guest redirects and current enum-aware guard APIs remain intact.

No proposed change removes or narrows a Laravel-facing API. The TokenGuard hash fix, Gate callable fix, and remember-token cleanup correct defects shared with current Laravel. The native TokenGuard input failure and cached-provider model desynchronization are Hypervel-owned defects. Backward compatibility with earlier unreleased Hypervel behavior is not a reason to keep either defect.

### Owner approval gates

| Findings | Gate | Benefit | Cost | Rejected alternative | Laravel/parity effect |
|---|---|---|---|---|---|
| `auth-07` | Improvement | Skip construction and dispatch of unobserved Gate and reset-link events. | One cached `hasListeners()` lookup at each existing dispatch boundary; no new resolution, allocation, or I/O. | Keep unconditional event work or add a shared Auth event helper. | A bounded divergence from current Laravel that follows Hypervel's required optional-event convention. |
| `auth-11` | Public contract widening | Accept enum broker and guard identifiers consistently, including backed zero, while keeping named arguments and null defaulting. | One in-memory enum branch at each public boundary and regenerated facade metadata. | Keep string-only gaps or add conversion wrappers outside the owning managers. | Ports current Laravel broker enum support and extends it only to Hypervel's equivalent guard-owned boundaries. |
| `auth-12` | Configuration structure | Make supported verification and timebox settings discoverable and give shipped scalar defaults one owner. | Two typed config keys and removal of duplicate caller fallbacks; lookup count is unchanged. | Retain hidden/drift-prone caller defaults or add a settings object. | Keeps Laravel-style config ownership; the timebox key is Hypervel-specific and the existing verification fallback remains for replaceable nested app config. |
| `auth-15` | Test improvement | Exercise dispatcher rebinding, reset wiring, real password rehashing, and supported callable forms through framework integration. | Three focused integration files, fixtures, and two characterization cases; production code is unaffected. | Rely on unit tests or clone the full upstream integration suite. | Adapts only current Laravel tests that cover otherwise-unproven Hypervel wiring. |

`auth-03` and `auth-04` do not change a signature or documented Laravel-facing behavior. Hashed `validate()` currently contradicts the same guard's request-authentication mode, while non-string request values currently violate the declared `?string` return contract and throw. They are settled defect fixes under the normal owner-review rule, not exceptional API changes.

## Post-compaction and design rules

After compaction, re-read `AGENTS.md` and this plan in full before editing. Re-open the active source and tests; summaries are navigation only.

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

## Research and final decisions

### Verified lifecycle and ownership

`Event::fake()` reaches container rebind callbacks through the real chain:

```text
Event::fake()
  -> Facade::swap()
  -> Container::instance('events', $fake)
  -> Container::rebound('events')
  -> registered rebinding callbacks
```

`Event::fakeFor()` follows the same chain when installing the fake and restoring the original dispatcher. `resolved('auth.password')` normalizes aliases and checks the canonical singleton key, so the PasswordReset rebind callback can avoid constructing the manager or a broker.

The broker and guard refresh loops have different extension paths. `AuthManager::extend()` can produce custom guards, so its existing `method_exists($guard, 'setDispatcher')` is load-bearing. `PasswordBrokerManager` has no built-in custom creators, but the class is open and its protected `resolve(): PasswordBrokerContract` method is an existing extension point. Its cache therefore remains `array<string, PasswordBrokerContract>`; the refresh loop updates concrete `PasswordBroker` instances and leaves custom contract implementations responsible for their own event lifecycle.

### Findings and dispositions

| ID | Category | Severity | Final result |
|---|---|---:|---|
| `auth-03` | Authentication defect | Major | Hashed TokenGuard validation hashes the credential exactly as request authentication does. |
| `auth-04` | Input-boundary defect | Major | TokenGuard accepts only non-empty strings from each ordered request source and preserves `"0"`. |
| `auth-05` | Authorization callback defect | Major | Gate reflects every valid callable through `Closure::fromCallable()` and weakly caches object callables. |
| `auth-06` | Worker-lifecycle event defect | Major | Event rebinding refreshes only already-resolved concrete password brokers. |
| `auth-07` | Hot-path improvement | Minor | Gate and PasswordBroker construct observational events only when targeted listeners/fakes exist. |
| `auth-08` | Rejected concern | — | Keep AuthorizationException's current falsey-code normalization; no supported caller or failure justifies divergence. |
| `auth-09` | Failure-cleanup defect | Major | Remember-token saves restore the caller model's timestamp setting in `finally`. |
| `auth-10` | Cache identity defect | Major | Remove the duplicate model-segment field and register descriptors when a cached provider changes model. |
| `auth-11` | Current API parity | Minor | Add bounded UnitEnum support to password-broker identifiers and Auth's guard-owned cache-clear boundary. |
| `auth-12` | Configuration ownership defect | Minor | Shipped top-level defaults have one owner; intentionally optional nested settings keep local fallbacks. |
| `auth-13` | Split-package metadata defect | Major | Declare Auth's remaining direct runtime dependencies and pin them with metadata coverage. |
| `auth-14` | Revalidated complete | — | Existing Auth README and user-cache guidance already contain the required provenance and corrected wording. |
| `auth-15` | Coverage improvement | Minor | Add focused current-Laravel integration coverage without cloning the full upstream suite. |
| `auth-16` | Secret-trace defect | Major | Mark the complete eleven-parameter credential/application-key boundary sensitive. |
| `auth-17` | Static cleanup defect | Minor | Gate's nullable WeakMap resets to its literal lazy sentinel, not an allocated empty map. |

## Implementation design

### 1. Make TokenGuard's public contracts coherent (`auth-03`, `auth-04`)

In `src/auth/src/TokenGuard.php`, keep request-token access ordered and lazy. Return the first non-empty string from query, request input, bearer token, and HTTP Basic password; invalid shapes fall through:

```php
$token = $request->query($this->inputKey);

if (is_string($token) && $token !== '') {
    return $token;
}

$token = $request->input($this->inputKey);

if (is_string($token) && $token !== '') {
    return $token;
}

$token = $request->bearerToken();

if (is_string($token) && $token !== '') {
    return $token;
}

$token = $request->getPassword();

return is_string($token) && $token !== '' ? $token : null;
```

Do not build an eager array: later accessors must not run after a higher-priority token succeeds. Do not accept numeric tokens or add a normalizer.

Make explicit validation reject absent, empty, and non-string credentials, then use the configured storage key and hash mode:

```php
$token = $credentials[$this->inputKey] ?? null;

if (! is_string($token) || $token === '') {
    return false;
}

return $this->provider->retrieveByCredentials([
    $this->storageKey => $this->hash ? hash('sha256', $token) : $token,
]) !== null;
```

Add `#[SensitiveParameter]` to `validate()`'s credentials under section 8. Normal request authentication is unchanged; the SHA-256 cost occurs only for an explicit validation call on a guard configured to hash.

### 2. Normalize and cache Gate callables, guard optional events, and repair cleanup (`auth-05`, `auth-07`, `auth-17`)

In `src/auth/src/Access/Gate.php`, rename the closure-only property and document the actual key domain:

```php
/** @var null|WeakMap<object, bool> */
protected static ?WeakMap $guestCallbackCache = null;
```

Closures and invokable objects use their original object identity as the weak key. Callable strings remain uncached; arrays continue through `methodAllowsGuests()`:

```php
protected function callbackAllowsGuests(callable $callback): bool
{
    if (is_object($callback)) {
        $cache = static::$guestCallbackCache ??= new WeakMap;

        return $cache[$callback] ??= $this->resolveCallbackAllowsGuests($callback);
    }

    return $this->resolveCallbackAllowsGuests($callback);
}

private function resolveCallbackAllowsGuests(callable $callback): bool
{
    $parameters = (new ReflectionFunction(
        Closure::fromCallable($callback)
    ))->getParameters();

    return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
}
```

This avoids a bespoke callable-string parser or an unbounded string registry. `Closure::fromCallable($closure)` returns the original closure; an invokable object otherwise produces a fresh closure on each conversion, which is why the WeakMap is keyed by the original object.

Gate must continue resolving the active dispatcher from the container on each evaluation so `Event::fake()` is honored. Guard event construction with the dispatcher's existing cached listener lookup:

```php
if (! $this->container->bound(Dispatcher::class)) {
    return;
}

$events = $this->container->make(Dispatcher::class);

if ($events->hasListeners(GateEvaluated::class)) {
    $events->dispatch(new GateEvaluated($user, $ability, $result, $arguments));
}
```

Do not cache the dispatcher or listener result on Gate. The saving is skipped event construction/dispatch when unused; container resolution remains intentionally current.

Reset the renamed lazy cache to its structural sentinel:

```php
public static function flushState(): void
{
    static::$policyClassCache = [];
    static::$guestMethodCache = [];
    static::$guestCallbackCache = null;
    static::$abilityMethodCache = [];
}
```

No default constant or second cleanup owner is needed; `AfterEachTestSubscriber` already invokes `Gate::flushState()`.

### 3. Keep password brokers on the active event dispatcher (`auth-06`, `auth-07`)

In `src/auth/src/Passwords/PasswordBroker.php`, avoid constructing the event unless it can be observed:

```php
if ($this->events?->hasListeners(PasswordResetLinkSent::class)) {
    $this->events->dispatch(new PasswordResetLinkSent($user));
}
```

Keep custom reset-link callbacks returning before this event. A custom callback owns delivery and its returned status; emitting `PasswordResetLinkSent` afterward would falsely claim the framework sent a notification.

Add the concrete broker mutator beside its related accessors:

```php
/**
 * Set the event dispatcher instance.
 *
 * Boot or tests only. The dispatcher is stored on the worker-lifetime broker
 * and affects every subsequent password reset-link event.
 */
public function setDispatcher(Dispatcher $events): void
{
    $this->events = $events;
}
```

Do not put it on `PasswordBroker` contracts; event dispatch is not behavior every custom broker must provide.

In `PasswordBrokerManager`, import `Hypervel\Contracts\Events\Dispatcher`, document the truthful cache domain without dropping the existing Laravel title, and add the manager refresh method:

```php
/**
 * The array of created "drivers".
 *
 * @var array<string, PasswordBrokerContract>
 */
protected array $brokers = [];

/**
 * Refresh the event dispatcher on resolved brokers.
 *
 * Boot or tests only. Reached by Event::fake() / Event::fakeFor() so cached
 * brokers follow the active dispatcher and its later restoration.
 */
public function refreshEventDispatcher(Dispatcher $events): void
{
    foreach ($this->brokers as $broker) {
        if ($broker instanceof PasswordBroker) {
            $broker->setDispatcher($events);
        }
    }
}
```

The `instanceof` branch is load-bearing for subclasses that override the protected `resolve()` method with another `PasswordBrokerContract`; it is not defensive code for the built-in path. A concrete subclass of `PasswordBroker` still refreshes. Do not use a looser `method_exists()` call, forget/reconstruct brokers, or add a generic dispatcher registry.

In `PasswordResetServiceProvider::register()`, call a new protected `registerEventRebindHandler()` beside `registerPasswordBroker()`, matching `AuthServiceProvider`'s existing structure. The named method registers the callback without creating unused services:

```php
protected function registerEventRebindHandler(): void
{
    $this->app->rebinding('events', function ($app, $dispatcher): void {
        if (! $app->resolved('auth.password')) {
            return;
        }

        $app->make('auth.password')->refreshEventDispatcher($dispatcher);
    });
}
```

Use a truthful local `@var` only if PHPStan cannot infer the canonical string binding. Do not change the canonical binding key for analysis.

### 4. Make Eloquent provider mutations exception-safe and cache-coherent (`auth-09`, `auth-10`)

In `EloquentUserProvider::updateRememberToken()`, preserve normal dirty-model behavior while restoring only the temporary timestamp setting:

```php
$timestamps = $user->timestamps;
$user->timestamps = false;

try {
    $user->save();
} finally {
    $user->timestamps = $timestamps;
}
```

Do not clone the model, restore the token, retry, wrap the exception, or add a transaction. The database write already dominates the negligible `try/finally` cost.

Delete the duplicate `$modelSegment` property, its false optimization docblock, and its assignment in `enableCache()`. Cache lookup uses the authoritative model property directly:

```php
return $this->cachePrefix . ':' . $this->model . ':' . $identifierSegment;
```

Registration stores each cache configuration under the authoritative model class that owns its keyspace. The descriptor retains only values not already represented by its outer key:

```php
$modelClass = $this->model;
$descriptorKey = hash(
    'xxh128',
    ($this->cacheStoreName ?? '') . '|' . $this->cachePrefix . '|' . $modelClass,
);

static::$cachedProviders[$modelClass][$descriptorKey] = [
    'storeName' => $this->cacheStoreName,
    'prefix' => $this->cachePrefix,
];
```

Invalidation rebuilds the key with the model class captured by that model's listener; it does not retain a duplicate model segment in each descriptor.

When the boot/test mutator changes model, register the new descriptor only if caching is active:

```php
public function setModel(string $model): static
{
    $this->model = $model;

    if ($this->cache !== null) {
        $this->registerCacheInvalidationEvents();
    }

    return $this;
}
```

Retain prior descriptors/listeners because old entries may live until expiry and still need invalidation. Existing descriptor hashes and per-model listener flags deduplicate repeats. Do not add unregistration, reference counts, WeakMaps, or provider ownership objects. Hot cache lookup replaces one string-property read with another and gains no new work.

### 5. Complete bounded enum identifier support (`auth-11`)

Use `UnitEnum|string|null` only at password-broker and guard-owned cache boundaries. In `PasswordBrokerManager`, normalize enum values before null-default selection:

```php
public function broker(UnitEnum|string|null $name = null): PasswordBrokerContract
{
    if ($name instanceof UnitEnum) {
        $name = (string) enum_value($name);
    }

    $name ??= $this->getDefaultDriver();

    return $this->brokers[$name] ??= $this->resolve($name);
}
```

Apply the same inline normalization to:

```php
public function resolveBrokerNameForGuard(UnitEnum|string $guard): ?string;
public function setDefaultDriver(UnitEnum|string $name): void;
public function clearUserCache(mixed $identifier, UnitEnum|string|null $guard = null): void;
```

Only null invokes a default. Preserve string `"0"` and `(string) enum_value()` for int-backed zero. Keep the existing `$identifier` parameter name for named-argument compatibility.

Widen `PasswordBrokerFactory::broker()` and `resolveBrokerNameForGuard()` because every conforming implementation owns those inputs. Do not add `setDefaultDriver()` to that contract or `clearUserCache()` to the general Auth factory contract. Regenerate and then lint the `Auth` and `Password` facade docblocks from the concrete methods; do not maintain a divergent hand-written signature:

```text
composer facade -- 'Hypervel\Support\Facades\Auth' 'Hypervel\Support\Facades\Password'
composer facade -- --lint 'Hypervel\Support\Facades\Auth' 'Hypervel\Support\Facades\Password'
```

Do not widen Gate ability internals, routes, database fields, or unrelated string APIs.

### 6. Give shipped Auth settings one correct owner (`auth-12`)

In `src/foundation/config/auth.php`, add concise Laravel-style sections and typed environment boundaries:

```php
'verification' => [
    'expire' => (int) env('AUTH_VERIFICATION_EXPIRE', 60),
],

'timebox_duration' => (int) env('AUTH_TIMEBOX_DURATION', 200000),
```

The comments state that verification expiry is minutes and timebox duration is microseconds. Place the sections with related Auth settings; do not expose Timebox implementation details in public documentation.

Remove caller defaults only for top-level shipped keys that survive the Foundation loader's shallow merge:

```php
$repository->boolean('hashing.rehash_on_login');
$repository->integer('auth.timebox_duration');
$config->integer('auth.password_timeout');
```

Apply those forms in `AuthManager`, `PasswordBrokerManager`, `PasswordConfirmation`, and Fortify's `RedirectIfTwoFactorAuthenticatable`. Record the Fortify change as a deliberate Hypervel config-ownership correction from current upstream Fortify.

Keep the verification fallback because `verification` is a replace-as-a-whole nested array and applications may intentionally omit `expire`:

```php
CarbonImmutable::now()->addMinutes(
    Config::integer('auth.verification.expire', 60)
)
```

Keep per-broker `expire` and `throttle` fallbacks for the same reason: broker entries are user-defined replaceable arrays. Do not add `verification` to `mergeableOptions()` or duplicate the new settings in Testbench's partial Auth overlay.

Add one human-facing sentence to `src/boost/docs/verification.md`: links expire after 60 minutes by default and `auth.verification.expire` controls the lifetime. Use the existing Laravel-style prose; do not document internal timing defenses. Add `tests/Auth/VerifyEmailNotificationTest.php` to prove the configured expiry and the intentional 60-minute fallback produce the signed URL's `expires` timestamp.

### 7. Make the split manifest truthful (`auth-13`)

Add the remaining direct runtime dependencies to `src/auth/composer.json`, sorted under the existing package convention:

```json
"ext-hash": "*",
"hypervel/console": "^0.4",
"hypervel/notifications": "^0.4",
"hypervel/queue": "^0.4",
"symfony/http-foundation": "^8.1",
"symfony/http-kernel": "^8.1"
```

`hypervel/cache`, `hypervel/core`, and `symfony/console` are already declared. The added Hypervel edges close dependency cycles already normal in the split framework; the metadata test validates direct runtime ownership, not acyclicity. Auth differs from Mail's type-only Notifications reference because `VerifyEmail` and `ResetPassword` extend `Notification`, so those Auth classes cannot load without `hypervel/notifications`.

Add `tests/Auth/PackageMetadataTest.php` following the established package pattern, checking all direct dependencies and the Auth/PasswordReset provider discovery entries. Include a concise comment or assertion grouping that preserves the Notifications distinction; do not redesign split-package boundaries.

### 8. Redact every actionable secret-bearing Auth parameter (`auth-16`)

Add `#[SensitiveParameter]` to exactly these eleven parameters:

| Class | Method | Parameter |
|---|---|---|
| `TokenGuard` | `validate` | `credentials` |
| `SessionGuard` | `once` | `credentials` |
| `SessionGuard` | `validate` | `credentials` |
| `SessionGuard` | `attempt` | `credentials` |
| `SessionGuard` | `attemptWhen` | `credentials` |
| `SessionGuard` | `hasValidCredentials` | `credentials` |
| `SessionGuard` | `fireAttemptEvent` | `credentials` |
| `SessionGuard` | `fireFailedEvent` | `credentials` |
| `CacheTokenRepository` | `__construct` | `hashKey` |
| `DatabaseTokenRepository` | `__construct` | `hashKey` |
| `SessionGuard` | `__construct` | `hashKey` |

Use native attributes in place without changing signatures:

```php
public function validate(#[SensitiveParameter] array $credentials = []): bool
```

The constructor keys can contain `app.key`; the credential methods retain inputs in frames while providers, hashing, or events may throw. Leave `Recaller::__construct()` unchanged: its simple parse path has no demonstrated exception trace exposing the credential, so metadata solely for symmetry would be speculative.

Add one compact `tests/Auth/SensitiveParameterTest.php` with a data provider and reflection assertions, following the existing Encryption/Hashing pattern. Do not add runtime wrappers or redaction machinery.

### 9. Add focused integration and characterization coverage (`auth-15`)

Port only the current Laravel integration behavior that covers real framework wiring, adapted for Hypervel's Testbench, coroutine context, guard-declared broker, typed config, and central static cleanup:

- `tests/Integration/Auth/AuthenticationTest.php`: resolve the session guard before `Event::fake()` and prove its dispatcher follows the fake; retain a custom guard without `setDispatcher()` as the control that makes AuthManager's method guard load-bearing.
- `tests/Integration/Auth/ForgotPasswordTest.php`: exercise a real Eloquent user, reset-token repository, notification fake, and `PasswordResetLinkSent`; resolve the broker before the event fake, prove `fakeFor()` restoration with a later real listener, and prove swapping events does not resolve an unused password manager/broker.
- `tests/Integration/Auth/RehashOnLogoutOtherDevicesTest.php`: persist a real user, call the guarded route with real hashing, and assert the refreshed password hash changes.
- package-scoped Auth fixtures needed by those tests, copied/adapted from current upstream rather than duplicated inline when shared.
- `tests/Auth/AuthenticateMiddlewareTest.php`: add current static and first-class invokable custom-driver callback cases. These characterize supported Laravel-facing callback forms; they do not claim a defect in `RebindsCallbacksToSelf`.

Do not port the entire upstream Auth integration suite, service-specific MySQL loops, duplicated policy/middleware coverage, or password-reset customization tests already covered locally. All added/touched test methods use `: void`.

## Regression matrix

| Behavior | Primary coverage |
|---|---|
| Hashed/plain validation, custom keys, invalid shapes, `"0"`, ordered token sources | `AuthTokenGuardTest` |
| Invokable/static-string guest callbacks, closure/array controls, before/after paths, object WeakMap reuse | `AuthAccessGateTest` |
| Gate listener/no-listener/fake dispatch and `flushState()` null sentinel | `AuthAccessGateTest` |
| Password reset listener/no-listener dispatch and concrete `setDispatcher()` | `AuthPasswordBrokerTest` |
| Broker refresh loop, custom broker-contract extension control, no manager construction on rebind, Event fake/fakeFor restoration | manager/provider unit tests plus focused ForgotPassword integration |
| Timestamp restoration on success/failure/from initially false; original exception identity | `AuthEloquentUserProviderTest` |
| New model keyspace, new/old invalidation descriptors, repeat deduplication | `AuthEloquentUserProviderCacheTest` and existing Auth cache integration |
| Unit/string/int/zero broker and guard identifiers; null default; broker cache identity | `AuthPasswordBrokerManagerTest`, `AuthManagerTest`, facade metadata |
| Declared config values/types, inherited Testbench defaults, nested verification fallback, Fortify rehash behavior | `AuthConfigTest`, new `VerifyEmailNotificationTest`, existing Fortify tests |
| Direct split dependencies and provider discovery | new `PackageMetadataTest` |
| Eleven sensitive parameters | new `SensitiveParameterTest` |
| Session dispatcher, reset wiring, logout-device rehash, custom-driver callable forms | focused integration and middleware tests |

## Rejected concerns and prohibited designs

- Do not change `AuthorizationException` without a supported falsey string-code caller and observable failure.
- Do not add a generic Auth event helper, token encoder/normalizer, callable parser, dispatcher cache, dispatcher-aware registry, listener unregistration, descriptor refcount, provider ownership object, settings object, per-request manager, or broad integration-suite clone.
- Do not reconstruct brokers on event rebind or resolve the password manager solely to refresh it.
- Do not move `PasswordResetLinkSent` after custom callbacks.
- Do not add `Recaller` sensitivity metadata solely for symmetry.
- Do not change harmless cache-miss destructuring, deferred-provider omissions, RequestContext ownership, `registerRequirePassword`, Gate boot mutators, generic Cache contracts, or unrelated enum/string boundaries.
- Do not re-edit the already-correct Auth README/cache documentation or add performance benchmarks, telemetry, prose linters, or internal Timebox documentation.

## Audit records

After implementation and review:

- append the Auth work-unit block to `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`, recording final findings, rejected concerns, ownership, performance, Laravel-facing result, validation, and review;
- mark `auth` complete in the core package checklist;
- update dependency-index rows for `auth-01`, `auth-02`, `container-09`, `support-02`, and `cache-04` to record Auth revalidation;
- add `| auth-12 | auth | fortify (revalidation complete); later full fortify audit | <Auth work-unit heading>; finding auth-12 |` to the cross-package dependency index for the owned Fortify call-site correction;
- route the Fortify no-default lookup as revalidated in this transaction, not as a TODO;
- record that broker rebinding and enum support complete existing package patterns;
- record that optional-event guarding complies with the repository's required `hasListeners()` convention;
- record that the broker refresh's concrete check preserves the manager's protected custom-resolution extension point;
- record that TokenGuard's native boundary and Eloquent's cached-model defect are Hypervel-owned;
- leave no deferred item or TODO from this work.

## Verification and self-review

Before implementation, run `composer install` in this worktree and copy the main checkout's `.env` so the worktree has the same Redis integration configuration. A green cache gate is valid only when the Redis-backed Auth cache tests actually execute: confirm the serializer-none and native-PHP serializer cases run rather than skip. Optional serializer cases may still skip when the installed phpredis build lacks those serializers.

Implementation cadence:

1. Change one file at a time with `apply_patch` and run each changed/new test file immediately.
2. Run all Auth unit tests and the focused Auth integration group; run affected Fortify, Contracts, Support-facade, Foundation-config, and documentation checks.
3. Regenerate and lint Auth and Password facade metadata with the two exact `composer facade` commands in section 5, and verify no unrelated facade drift.
4. Run `./vendor/bin/phpstan` and `./vendor/bin/php-cs-fixer fix` through the required final `composer fix` gate.
5. Run `composer fix` from the worktree root; do not weaken, skip, or rewrite tests to obtain green output.
6. Run `git diff --check` and searches for stale `modelSegment` property references, `guestClosureCache`, redundant changed-key defaults, old facade types, undeclared dependencies, and missing sensitive attributes.
7. Freshly trace every changed caller/callee, event-rebind transition, worker/static lifetime, cache key/invalidation path, config-merge case, named argument, event-fake restoration path, and test cleanup boundary.
8. Recheck hot paths: no added I/O, serialization, lock, retry, context slot, or container lookup; listener guards avoid unused event work; cache lookup retains one model-string property access; enum checks are bounded in-memory branches.
9. Remove stale/dead code, imports, comments, tests, and rejected design residue, then request independent code review and continue until sign-off.

The final result is complete only when focused and full gates are green, the fresh self-review finds no omission or unnecessary mechanism, audit records match the implemented code, and independent code review is signed off.
