# Fortify correctness, current parity, and configuration contracts

**Status:** Complete; implementation and code review signed off.

## Objective

Complete the Fortify audit by restoring current Laravel construction and extension APIs, retaining
Hypervel's deliberate worker-safe guard and static-configuration boundaries, removing duplicated
configuration defaults, and correcting the published configuration and user guidance.

This work fixes verified defects only. It adds no request state, context slot, lock, cache, retry,
registry, compatibility wrapper, or new configuration mechanism. Existing Hypervel-specific guard,
Passkeys, two-factor, rate-limiter, event, and transaction behavior remains intact.

## Evidence baseline

- Hypervel branch baseline: `0.4` at `5ef235766cd0cb4da8cfebfe02f38c552cb6f52a`.
- Prior audited Hypervel snapshot: `db70c7ce7def14382d7d22d2f90b15e8db0ae9d7`.
- Current Laravel Fortify reference: `35037d82e2b28d59729b052c97131e743f9ede74`.
- Current Laravel documentation reference: `9c5a062c14069bab9054b558829e282f9593a065`.
- The only Laravel Fortify change after the prior audit reference is PHPStan 2 support; its complete
  changed-file set does not alter the source, config, routes, or tests covered here.
- Laravel Fortify PR `#485` / commit `74cd344` introduced lowercase-user support. It deliberately
  kept the internal fallback `false` while publishing `true` for new applications.
- Current Hypervel changes after the audited snapshot were traced. The atomic installer, dedicated
  rate limiter, complete two-factor-state checks, guard selection, and related tests remain correct
  and must be preserved.
- `fortify-01` and `fortify-02` already identify the atomic installer and complete two-factor-state
  corrections. New findings therefore use `fortify-03` through `fortify-09`.

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

- Fortify's unbound controllers and actions remain worker auto-singletons only when stateless.
  Request, session, and current-guard state is resolved during each operation.
- `Fortify::guardName()` and `Fortify::guard()` remain the current-guard authority. Do not restore
  upstream `StatefulGuard` constructor capture on worker-lived objects.
- Fortify's six worker-static configuration slots remain private and typed: three upstream
  callback slots, the route flag, the encrypter, and Hypervel's redirect callback map. Public
  methods own mutation and access, and `Fortify::flushState()` remains the exhaustive test-reset
  boundary.
- Feature identifiers remain safe read-only values at request time. Supplying feature options is
  boot-only because it mutates the process-global Config repository.
- Package top-level config defaults are shallow-merged during provider registration. Nested
  Passkeys settings remain replaceable and keep their deliberate per-key fallbacks.
- The OTPHP provider retains its worker-safe clock, fresh TOTP objects, generic Cache contract,
  configured window, and conservative accepted-code replay identity.
- Fortify owns its integrated polymorphic Passkeys routes. Standalone Passkeys route suppression,
  guard/middleware/redirect ownership, and bridge configuration remain unchanged.
- Listener-gated events, transactionally serialized recovery-code replacement, after-commit event
  publication, guard-scoped throttling, and complete two-factor-state checks remain unchanged.

## Findings and decisions

| ID | Category | Severity | Final decision |
|---|---|---:|---|
| `fortify-03` | Construction API parity | Minor | Remove prohibited DI conversions from ten upstream zero-constructor controllers, restore `EnsureLoginIsNotThrottled`'s one-parameter constructor, and restore status-only `PasswordResetResponse` construction. |
| `fortify-04` | Property and method API parity | Moderate | Restore twenty mutable properties and two protected override methods while retaining necessary private worker-static state. |
| `fortify-05` | Configuration ownership | Minor | Remove duplicate literals from thirteen reads of guaranteed merged config; keep defaults only for genuinely optional/replaced values. |
| `fortify-06` | Worker-lifetime documentation | Minor | Warn on the two public feature methods only when options are supplied. |
| `fortify-07` | Password-reset documentation | Minor | Explain the named-route or custom-URL requirement when view routes are disabled. |
| `fortify-08` | Published configuration | Minor | Add concise Laravel-style headings for every public Fortify config group. |
| `fortify-09` | New-application defaults | Minor | Publish Laravel's intended lowercase and opt-in email-verification defaults while retaining internal fallbacks. |

## Implementation

### 1. Restore upstream construction style (`fortify-03`)

Remove the added constructors and dependency properties from:

- `ConfirmedPasswordStatusController`;
- `ConfirmedTwoFactorAuthenticationController`;
- `EmailVerificationNotificationController`;
- `EmailVerificationPromptController`;
- `PasswordController`;
- `PasswordResetLinkController`;
- `ProfileInformationController`;
- `RecoveryCodeController`;
- `TwoFactorAuthenticationController`;
- `VerifyEmailController`.

Also restore `EnsureLoginIsNotThrottled` to its upstream one-parameter constructor by removing the
container property and resolving `LockoutResponse` with `app()` at the response site.

Resolve response contracts with the existing Laravel-shaped helper at the response site:

```php
return app(TwoFactorConfirmedResponse::class);

return app(RedirectAsIntended::class, ['name' => 'email-verification']);
```

Preserve the existing contract imports and resolve the contracts, not their concrete response
classes. Parameterized `app()` calls bypass singleton caches but still honor the application's
abstract-to-concrete response binding.

Resolve typed config only in methods that use it:

```php
$config = app(Config::class);

if ($config->boolean('fortify.lowercase_usernames') && $request->has(Fortify::email())) {
    // Existing normalization.
}
```

Use call-time `app(Config::class)` resolution in `ProfileInformationController` and
`PasswordResetLinkController`, whose constructors are removed. `NewPasswordController`,
`RegisteredUserController`, and `AuthenticatedSessionController` retain their upstream-shaped
constructors and continue using `$this->config`; remove only their duplicate `false` literals.

Restore `PasswordResetResponse` to status-only construction while combining it with the protected
mutable status correction from step 2:

```php
public function __construct(
    protected string $status,
) {
}

public function toResponse(Request $request): Response
{
    $views = app(Config::class)->boolean('fortify.views');

    // Existing JSON/redirect response selection.
}
```

Do not remove or replace constructors whose upstream shape injects a guard and whose Hypervel
adaptation deliberately avoids retaining that request guard. Those current-guard differences are
already public and tested.

### 2. Restore inherited property and override contracts (`fortify-04`)

Make these fourteen properties `protected` and non-readonly:

| Class | Property |
|---|---|
| `PrepareAuthenticatedSession` | `$limiter` |
| `EnsureLoginIsNotThrottled` | `$limiter` |
| `AttemptToAuthenticate` | `$limiter` |
| `RedirectIfTwoFactorAuthenticatable` | `$limiter` |
| `EnableTwoFactorAuthentication` | `$provider` |
| `ConfirmTwoFactorAuthentication` | `$provider` |
| `LoginRateLimiter` | `$limiter` |
| `TwoFactorAuthenticationProvider` | `$cache` |
| `FailedPasswordResetLinkRequestResponse` | `$status` |
| `FailedPasswordResetResponse` | `$status` |
| `PasswordResetResponse` | `$status` |
| `SuccessfulPasswordResetLinkRequestResponse` | `$status` |
| `LockoutResponse` | `$limiter` |
| `SimpleViewResponse` | `$view` |

Use normal promoted properties for the thirteen typed cases:

```php
public function __construct(
    protected LoginRateLimiter $limiter,
) {
}
```

`SimpleViewResponse::$view` is the exception: change its existing ordinary untyped property from
private to protected, retain the `callable|string` docblock and constructor-body assignment, and do
not promote it because PHP does not permit `callable` as a property type. Its reflection assertion
checks protected/non-readonly state without expecting a declared type.
`TwoFactorAuthenticationProvider::$clock` remains private readonly: it has no upstream equivalent
and belongs to Hypervel's OTPHP engine architecture.

Remove `readonly` from these six typed public properties:

- `RecoveryCodeReplaced::$user` and `$code`;
- `RecoveryCodesGenerated::$user`;
- `PasswordUpdatedViaController::$user`;
- `TwoFactorAuthenticationEvent::$user`;
- `RedirectAsIntended::$name`.

```php
public function __construct(
    public Authenticatable $user,
    public string $code,
) {
}
```

Restore these methods to `protected`, preserving all Hypervel logic and native types:

```php
protected function confirmPasswordUsingCustomCallback(
    Authenticatable&Model $user,
    ?string $password = null,
): bool;

protected function throttleKey(Request $request): string;
```

Keep Fortify's three upstream callback slots, route flag, encrypter, and Hypervel redirect callback
map private. Direct writes cannot remain typed, normalized, and exhaustively reset through the
supported public API. Add one concise README difference directing users from Laravel's writable
static properties to
`authenticateThrough()`, `authenticateUsing()`, `confirmPasswordsUsing()`, `encryptUsing()`, and
`ignoreRoutes()`.

While touching the README, make its existing content follow the required package order: header,
`Documentation: https://hypervel.org/docs/fortify`, approved public differences, then the upstream
link. Remove the local-path documentation sentence rather than retaining a second documentation
surface. Do not add internal implementation notes.

Do not add public setters, compatibility properties, magic access, new bindings, or lifecycle
machinery.

Restore concise upstream constructor title docblocks on every edited constructor that lacks one,
and retain the descriptive `SimpleViewResponse::$view` property annotation.

### 3. Make merged config the only default owner (`fortify-05`)

Remove duplicate defaults from all thirteen current read sites:

```php
return self::config()->string('fortify.username');
return self::config()->string('fortify.email');

return in_array($feature, self::config()->array('fortify.features'), true);

$lowercaseUsernames = $this->config->boolean('fortify.lowercase_usernames');

'prefix' => $config->string('fortify.prefix'),
```

In `routes/routes.php`, retain the existing route-file/helper shape and remove only the literals:

```php
$middleware = (array) config('fortify.middleware');

Route::group(['middleware' => $middleware], function () {
    $enableViews = config('fortify.views');
    $authMiddleware = config('fortify.auth_middleware');
});
```

The complete source owners are `Fortify`, `Features`, `FortifyServiceProvider::configureRoutes()`,
the routes file, the five lowercase-username controller paths, and `PasswordResetResponse`.
`hashing.rehash_on_login` is already corrected and is revalidated rather than edited.

Keep defaults for:

- `fortify-options.*`, whose whole optional tree may be absent;
- `fortify.limiters.verification`, which is intentionally absent from package config;
- `RoutePath` route-specific values;
- the redirect fallback chain;
- `fortify.passkeys.*`, whose containing array may be replaced by the application.

Keep nullable `fortify.domain` on `get()`. Do not recursively merge config, create a config DTO, or
add a validation pass.

### 4. Document conditional feature mutation (`fortify-06`)

Put the lifecycle boundary on each public method rather than private `setOptions()`:

```php
/**
 * Enable the two factor authentication feature.
 *
 * Boot-only when options are supplied. Non-empty options mutate the process-global
 * configuration repository and affect every subsequent request in the worker;
 * calling without options performs no mutation and is safe at request time.
 *
 * @param array<string, mixed> $options
 */
public static function twoFactorAuthentication(array $options = []): string;
```

Use equivalent wording on `passkeys()`. Calls without options remain the normal identifier lookup
used by request-time feature checks. Remove the redundant lifecycle paragraph from `setOptions()`.
Do not split the API into separate read/configure methods.

### 5. Correct the disabled-views guidance (`fortify-07`)

Keep the Boost guide consistent with the corrected lifecycle and published defaults: supplying
feature options is boot-only while no-argument feature identifiers are safe during request
handling; new published config lowercases usernames by default while the unpublished package
fallback remains false; and new installations opt into email verification after their user model
supports it.

After the Views paragraph, explain that password-reset notifications still need a URL when
Fortify's view route is disabled. Give the two supported choices and link the customization API to
`/docs/{{version}}/passwords#reset-link-customization`:

```text
If views are disabled while password resets remain enabled, define a route named
password.reset or configure your frontend reset URL at boot with
ResetPassword::createUrlUsing().
```

Keep detailed reset-notification customization in the existing password documentation; do not
duplicate that guide here.

### 6. Publish a self-explanatory current config (`fortify-08`, `fortify-09`)

Add concise Laravel-style comment headings to `src/fortify/stubs/fortify.php` for:

- guard and route middleware;
- username/email and lowercasing;
- view registration;
- home path and response redirects;
- route prefix/domain and custom paths;
- rate limiters;
- Passkeys bridge settings;
- feature selection.

Explain only user decisions. Do not copy upstream's removed `passwords` setting, describe worker
internals, or repeat the Boost guide.

Align only the published new-application defaults with current Laravel:

```php
'lowercase_usernames' => true,

'features' => [
    Features::registration(),
    Features::resetPasswords(),
    // Features::emailVerification(),
    Features::updateProfileInformation(),
    Features::updatePasswords(),
    // Existing Hypervel two-factor and Passkeys options.
],
```

Keep `src/fortify/config/fortify.php` at `lowercase_usernames => false` with email verification in
its internal fallback list, matching upstream's package/new-install distinction.

### 7. Complete canonical records

- Add the final Fortify section to the audit ledger with findings `fortify-03` through
  `fortify-09`, architecture, retained differences, rejected concerns, tests, performance, and
  validation.
- Change the existing `auth-12`, `fortify-01`, and `fortify-02` dependency-index wording from a
  future Fortify audit to completed revalidation. Add no new dependency row because this work
  creates no cross-package assumption.
- Update the prior Auth and Eloquent ledger cross-revalidation sentences so neither retains a
  future-Fortify-audit claim; state that this audit revalidated `auth-12` and `fortify-02`. Record
  final revalidation of the Horizon-owned `fortify-01` installer finding in Fortify's new section.
- Use the dependency index's established `(revalidation complete)` wording for all three rows.
- Record that restored property mutability changes metadata only: subclasses that store request
  state in a response or limiter property own an appropriate scoped or transient binding rather
  than mutating the provider's worker-cached contract singleton.
- Mark `fortify` complete in the core checklist.
- Remove the stale `Port Fortify package` entry from `docs/todo.md`.
- Keep this plan as the detailed design reference. Do not add change-history prose to it.

## Regression coverage

Create `tests/Fortify/FortifyApiTest.php` for the public construction and extension contracts:

- prove the ten named controllers have no required constructor;
- prove `EnsureLoginIsNotThrottled` retains its exact one-parameter upstream construction;
- construct `PasswordResetResponse` with only a status and exercise both JSON and redirect
  behavior with views disabled and enabled so both call-time Config branches are covered;
- prove the internal package fallback keeps lowercase usernames disabled and email verification
  enabled;
- use an exact reflection data provider for fourteen protected mutable and six public mutable
  properties;
- subclass `ConfirmPassword` and prove parent invocation dispatches through the protected custom
  callback method;
- subclass `LoginRateLimiter` and prove a public operation dispatches through the protected
  throttle-key method;
- reflect all six Fortify static slots to prove they remain private, preventing the parity sweep
  from accidentally reopening the worker-state boundary.

Extend `tests/Fortify/Console/InstallCommandTest.php` to load the published config and assert its
semantic defaults:

```php
$config = require $this->app->configPath('fortify.php');

$this->assertTrue($config['lowercase_usernames']);
$this->assertNotContains(Features::emailVerification(), $config['features']);
```

Keep existing controller, route, guard, static-state, Passkeys, two-factor, provider, and installer
tests authoritative. Focused verification must include all changed Fortify test files and the
complete `tests/Fortify` group. Documentation comments need no brittle text assertions.

Extend `FortifyRouteTest` to prove that disabling views removes the named reset form route while
retaining the password-reset submission routes described by the guide.

Normalize the integrated Passkeys README to the package README order and point it to the public
Fortify documentation instead of the repository-local source path.

## Performance and compatibility budget

- Restored property visibility/mutability and method visibility change metadata only; they execute
  no extra instruction.
- Controller response resolution keeps the existing per-operation container lookup and adds only
  the global container-instance access used by `app()`. Controller Config moves from one
  auto-singleton-construction lookup to one singleton lookup per affected operation. These are
  bounded local map lookups beside auth/session/hash/response work and add no I/O, allocation
  layer, yield, or retained state.
- Removing duplicated config literals does not add work and keeps package config as the sole
  default owner.
- Stub, README, Boost, ledger, checklist, and lifecycle-docblock changes have no request cost.
- No database, Cache/Redis, network, filesystem, lock, retry, serialization, coroutine-context, or
  event operation is added.
- Laravel zero-constructor, status-only response, mutable property, and protected override APIs are
  restored. The only relevant retained API difference is private worker-static configuration with
  documented public method alternatives.

## Rejected concerns and designs

- Do not rewrite TOTP replay keys around timecodes. An accepted code can match adjacent timecodes;
  trying another marker after an atomic add permits duplicate acceptance. The generic Cache
  contract has no exact CAS solution, and Redis-only scripting or a package CAS abstraction is
  disproportionate.
- Do not restore upstream worker-captured guard fields or Google2FA engine state.
- Do not make stateless classes scoped or add per-request Fortify registries.
- Do not cache custom-authentication callback results or tiny QR mapping work.
- Do not add locks around configuration callbacks or optional events.
- Do not recursively merge nested config or add a configuration validation object.
- Do not add optional constructor dependencies, compatibility branches, magic properties, or
  accessors to preserve the flawed Hypervel-only construction/property shapes.
- Do not add a new TOTP secret minimum or alter the current secure default.

## Verification and review

1. Run each changed/new Fortify test file immediately after editing.
2. Run the complete Fortify test group after the coherent implementation is complete.
3. Run `composer fix` once as the authoritative formatter, PHPStan, parallel-suite, Testbench, and
   dogfood gate.
4. Run `git diff --check` and stale-reference scans for removed constructors, duplicate defaults,
   incorrect visibility/readonly modifiers, superseded documentation, and record IDs.
5. Re-read every changed file and trace callers/callees, current guard selection, response binding,
   feature config timing, static cleanup, config merge order, Passkeys ownership, and TOTP/event
   behavior.
6. Perform a fresh Laravel API, coroutine safety, hot-path, retained-memory, stale-code, and
   overengineering review before requesting code review.
7. Complete code-review and final-record review loops before marking the implementation complete.
