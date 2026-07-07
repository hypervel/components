# Guard Siloing Completion

Complete the principle established by `2026-07-06-auth-guard-declared-password-brokers.md` (PR #420): the current auth guard is the single source of auth siloing truth. This plan closes the remaining places where an auth decision — password confirmation, guest-route guard selection, sanctum's stateful session acceptance, viaRequest provider resolution, permission's default guard, login throttling — reads a global root or ignores the current guard.

## Background

### The audit

After PR #420 shipped, a full framework audit (Claude + Codex independently, cross-verified against source, owner arbitrating, 2026-07-07) looked for auth decisions that bypass the current guard. "Current guard" always means `AuthManager::getDefaultDriver()`: the coroutine-context override set by `shouldUse()` (via `auth:x`, `auth.guard:x`) falling back to `auth.defaults.guard`.

The design principle, agreed by all parties:

> For auth decisions that decide which actor silo may authenticate, reset passwords, confirm passwords, use sessions, accept tokens, or rate-limit login attempts, the guard is the local source of truth. Any other config key must be either route-selection only (like `fortify.guard`) or a general mechanic that does not choose an actor silo.

Hypervel 0.4 is unreleased. Backward compatibility is not a goal; no fallbacks, shims, or dual roots survive. The final code reads as if designed this way from the start.

### Confirmed holes (each verified against source)

1. **Password confirmation is not guard-siloed.** The session stores one shared key `auth.password_confirmed_at` (`src/session/src/Store.php:705`, `src/fortify/src/Http/Controllers/ConfirmablePasswordController.php:50`) read by `RequirePassword` (`src/auth/src/Middleware/RequirePassword.php:65`) and `ConfirmedPasswordStatusController` (`src/fortify/src/Http/Controllers/ConfirmedPasswordStatusController.php:28`). A user logged into two guards in one browser session confirms under one guard and satisfies `password.confirm` under the other. Every sibling session artifact is already guard-scoped (`login_{guard}_{hash}`, `password_hash_{guard}`, `remember_{guard}_*`); this key is the odd one out. The timeout is also global (`auth.password_timeout`) and baked into the `RequirePassword` singleton at construction (`src/auth/src/AuthServiceProvider.php:77`), so no per-guard timeout can work without moving the read to handle-time.
2. **`guest:admin` names a guard but does not select it.** `RedirectIfAuthenticated::handle()` (`src/auth/src/Middleware/RedirectIfAuthenticated.php:33-44`) checks the named guards and passes through without `shouldUse()`. A login page behind `guest:admin` calling bare `Password::sendResetLink()` resolves the *default* guard's broker — the wrong-table bug class PR #420 eliminated. `auth:admin` selects the matched guard on success (`src/auth/src/Middleware/Authenticate.php:63-64`); `guest:admin` selecting on pass-through completes the rule: naming a guard on an auth middleware makes it current.
3. **Sanctum's stateful accept-list is a process-global second root.** `SanctumGuard::user()` iterates `config('sanctum.guard', 'web')` (`src/sanctum/src/SanctumGuard.php:81`), so every sanctum guard trusts the same session guards; two sanctum guards with different providers cannot declare different trusted sessions. The default is fail-open: a *missing* key means `'web'` is trusted. The stateful path also skips the provider match that the bearer-token path performs (`hasValidProvider()`, `SanctumGuard.php:204`) — a session user from any listed guard is returned even when it does not belong to this guard's provider. Sanctum's `AuthenticateSession` middleware reads the same global (`src/sanctum/src/Http/Middleware/AuthenticateSession.php:38`).
4. **`sanctum.stateful` is misnamed.** It holds *domains*, its env var is already `SANCTUM_STATEFUL_DOMAINS`, and the new per-guard session list needs the "stateful" vocabulary. The repo rule that env vars mirror config key paths makes `sanctum.stateful_domains` the correct name.
5. **`auth.defaults.provider` is a second defaulting root, and viaRequest guards ignore their declared provider.** `CreatesUserProviders::getDefaultUserProvider()` (`src/auth/src/CreatesUserProviders.php:91-94`) reads a key the shipped config does not declare. Its only live consumer is `AuthManager::viaRequest()` (`AuthManager.php:202-207`), which builds its `RequestGuard` with a bare `createUserProvider()` even though the extend closure receives the guard's `$config` — the guard entry's `provider` key is silently ignored. Verified: every other `createUserProvider()` caller passes `$config['provider'] ?? null` (JWT `JWTServiceProvider.php:112`, Sanctum `SanctumServiceProvider.php:52`, broker manager `PasswordBrokerManager.php:65`, both AuthManager guard creators).
6. **Permission has two notions of "default guard".** `Support\Config::defaultGuard()` (`src/permission/src/Support/Config.php:136-139`) reads `auth.defaults.guard` from config, bypassing the coroutine-context override, while `Guard::getDefaultName()` correctly uses the auth manager. `defaultGuard()` feeds the `users()` relations on `Role`/`Permission` and `HasAssignedModels`.
7. **Fortify's login throttle key ignores the guard.** `LoginRateLimiter::throttleKey()` (`src/fortify/src/LoginRateLimiter.php:64-66`) is `username|ip`. One Fortify install serving multiple guard silos (the documented multi-guard pattern) shares lockout buckets across silos: a member lockout blocks admin login for the same email + IP.
8. **Generators read `auth.defaults.guard` directly.** `PolicyMakeCommand.php:76` and `GeneratorCommand::userProviderModel()` (`src/console/src/GeneratorCommand.php:445-455`) read config instead of asking the auth manager. Console has no ambient override so behavior is identical today; this is a consistency alignment that completes the "one way to ask for the default guard" invariant.

### Rejected alternatives

- **Ambient-following sanctum accept-list ("null means current default guard").** Rejected: the set of sessions an API guard accepts would depend on whatever guard was ambient when `auth:sanctum` evaluates — middleware-order-sensitive, silent, and an API route accidentally nested in a member route group would accept member sessions on an admin API guard. Declarations, not context, decide acceptance.
- **Keeping `sanctum.guard` as a global fallback behind the per-guard key.** Rejected by the owner: a second defaulting root for an auth silo decision, the exact disease `auth.defaults.passwords` had.
- **Missing `session_guards` silently meaning tokens-only.** Rejected: unlike password brokers (where tolerated absence still throws at the moment of need), sanctum silence would stay silent forever — the classic "why doesn't my SPA authenticate" time-sink. Throw an instructive config error; `[]` is the explicit tokens-only declaration and doubles as documentation.
- **Per-guard `stateful_domains`.** Rejected: the domains decision runs pre-auth in `EnsureFrontendRequestsAreStateful`, where no guard exists; deriving one would require route-middleware string inference. Cross-origin control is CORS's job; the `statefulDomains()` override hook covers dynamic cases. Stays app-level.
- **Dot-nested confirmation session key (`auth.password_confirmed_at.{guard}`).** Rejected: session keys pass through `Arr::set()` dot segmentation, and the sibling artifacts (`password_hash_{guard}`) use the guard-suffix convention. Use `auth.password_confirmed_at_{guard}`.
- **`guest` with multiple guards selecting nothing.** Rejected: two rules instead of one. `guest:a,b` selects the first listed — deterministic, matches `auth`'s try-in-order semantics where the first is primary. Bare `guest` selects nothing (there is no named guard to select).
- **A new "resolve provider/model for guard" framework API.** Rejected: the guard entry plus `createUserProvider()` already is that API; no consumer exists for more surface.

## Final Behavior

- Confirming a password proves it for the current guard only. `password.confirm` under another guard in the same session prompts again. Guards may declare `password_timeout`; resolution is route parameter → `auth.guards.{guard}.password_timeout` → `auth.password_timeout` (default 10800).
- `guest:admin` makes `admin` the current guard when the request passes through. `guest:a,b` selects `a`. Bare `guest` selects nothing.
- Every sanctum-driver guard entry declares `session_guards`. `['web']` trusts web sessions, `[]` means bearer tokens only, a missing key throws naming the guard and both fixes. Listed guards must be stateful (`StatefulGuard`) or resolution throws. A trusted session user must match the sanctum guard's provider or it is skipped and the next listed guard (then the token path) is tried.
- `sanctum.stateful_domains` is the domains list; `sanctum.guard` no longer exists.
- A `viaRequest` guard uses the `provider` its guard entry declares. `auth.defaults.provider` no longer exists; `createUserProvider(null)` means "no provider". `getDefaultUserProvider()` survives, redefined as "the provider declared by the current default guard" — the same redefinition treatment `Password::getDefaultDriver()` received when `auth.defaults.passwords` was removed.
- Permission's default guard is the auth manager's, context override included.
- Fortify's login throttle key is `guard|username|ip`.
- `make:policy` and generator model inference resolve the default guard through the auth manager.

## Implementation

All work happens in `contrib/hypervel/components`. Every file keeps `declare(strict_types=1);` and follows AGENTS.md (method docblocks, imports not FQCNs, `->make()` not array access, no defensive guards without a real reachable path).

### 1. New: `src/auth/src/PasswordConfirmation.php`

The single owner of confirmation key format and the full timeout resolution chain (explicit override → guard declaration → global fallback), consumed by the auth middleware, both Fortify controllers, and the session store. `final` because it is a fully static utility — subclassing is inert. The guard/global tiers branch on `has()` rather than nesting the global read as a default argument: PHP evaluates default arguments eagerly, so nesting would let a malformed global `auth.password_timeout` throw even when a valid per-guard declaration should win.

```php
<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Config\Repository;

final class PasswordConfirmation
{
    /**
     * Get the session key holding the password confirmation timestamp for the guard.
     */
    public static function sessionKey(string $guard): string
    {
        return "auth.password_confirmed_at_{$guard}";
    }

    /**
     * Get the password confirmation timeout in seconds for the guard.
     *
     * An explicit override (per-route middleware parameter or request input)
     * wins, then the guard's own "password_timeout" declaration, then the
     * application-wide auth.password_timeout value.
     */
    public static function timeout(Repository $config, string $guard, string|int|null $override = null): int
    {
        if ($override !== null) {
            return (int) $override;
        }

        $key = "auth.guards.{$guard}.password_timeout";

        if ($config->has($key)) {
            return $config->integer($key);
        }

        return $config->integer('auth.password_timeout', 10800);
    }
}
```

### 2. `src/auth/src/Middleware/RequirePassword.php`

Timeout and key resolution move to handle-time so the current guard and any per-guard timeout apply. Constructor becomes pure DI; the `$passwordTimeout` property and constructor parameter are deleted. `using()` and `handle()` signatures are unchanged (the per-route `$passwordTimeoutSeconds` override remains the first tier).

```php
use Closure;
use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class RequirePassword
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ResponseFactory $responseFactory,
        protected UrlGenerator $urlGenerator,
        protected AuthFactory $auth,
        protected Repository $config,
    ) {
    }

    // using(): unchanged.
    // handle(): unchanged.

    /**
     * Determine if the confirmation timeout has expired.
     *
     * The confirmation timestamp and timeout are scoped to the current
     * guard, so confirming a password under one guard never satisfies
     * password confirmation under another.
     */
    protected function shouldConfirmPassword(Request $request, string|int|null $passwordTimeoutSeconds = null): bool
    {
        $guard = $this->auth->getDefaultDriver();

        $confirmedAt = Date::now()->unix() - $request->session()->get(PasswordConfirmation::sessionKey($guard), 0);

        return $confirmedAt > PasswordConfirmation::timeout($this->config, $guard, $passwordTimeoutSeconds);
    }
}
```

### 3. `src/auth/src/AuthServiceProvider.php`

Delete `registerRequirePassword()` and its call in `register()`. All four constructor dependencies are container-resolvable, so auto-singleton handles the middleware with no explicit binding: `ResponseFactory` contract is singleton-bound (`src/routing/src/RoutingServiceProvider.php:174`), `UrlGenerator` contract is core-aliased (`src/foundation/src/Application.php:1386`), `Factory` contract via the `auth` alias (`Application.php:1271`), config `Repository` contract via the `config` alias (`Application.php:1293`). Remove the now-unused imports: `RequirePassword`, `ResponseFactory`, `UrlGenerator` (verified: each is referenced only by the deleted method).

### 4. `src/session/src/Store.php`

`passwordConfirmed()` becomes the single confirmation writer, guard-scoped. Imports: add `Hypervel\Auth\PasswordConfirmation`, `Hypervel\Container\Container`, `Hypervel\Contracts\Auth\Factory as AuthFactory`. `Container::getInstance()` is the correct access here — the store is constructed by the session manager without container injection, and the guard must be resolved at call time, not construction time.

```php
    /**
     * Specify that the user has confirmed their password.
     *
     * The confirmation is scoped to the given guard, or to the current
     * default guard when none is given.
     */
    public function passwordConfirmed(?string $guard = null): void
    {
        $guard ??= Container::getInstance()->make(AuthFactory::class)->getDefaultDriver();

        $this->put(PasswordConfirmation::sessionKey($guard), Date::now()->unix());
    }
```

### 5. `src/session/composer.json`

Add `"hypervel/auth": "^0.4"` to `require` (alphabetically first, before `hypervel/cache`). This also fixes a pre-existing packaging bug: `src/session/src/Middleware/AuthenticateSession.php:9` already imports the concrete `Hypervel\Auth\AuthenticationException` with no declared dependency, so a standalone subtree-split install of `hypervel/session` is broken today. No cycle: `src/auth/composer.json` only *suggests* `hypervel/session`. The root `composer.json` needs no change (monorepo `replace` covers all packages).

### 6. `src/support/src/Facades/Session.php` and `src/contracts/src/Session/Session.php`

Docblock line 70: `@method static void passwordConfirmed(string|null $guard = null)`. The `Session` contract also gains `passwordConfirmed(?string $guard = null): void` (implementation-round improvement over the original "no contract change" call): `Request::session()` is typed to the contract, Fortify's controller calls the method through it, and the contract already carries the sibling Store conveniences (`previousUrl()`, `setPreviousRoute()`) — password-confirmation stamping is framework behavior every conforming session implementation must provide now that `RequirePassword` reads the key convention.

### 7. `src/fortify/src/Http/Controllers/ConfirmablePasswordController.php`

Line 50 becomes the single-writer call; the direct `put()` and the now-unused `Hypervel\Support\Facades\Date` import are removed (verified: `Date` is used only on that line).

```php
        if ($confirmed) {
            $request->session()->passwordConfirmed();
        }
```

The confirmation was verified against `Fortify::guard()` — the current guard — and `passwordConfirmed()` now stamps that same guard's key.

### 8. `src/fortify/src/Http/Controllers/ConfirmedPasswordStatusController.php`

Read the current guard's key and timeout through the shared helper. Imports: add `Hypervel\Auth\PasswordConfirmation`, `Hypervel\Fortify\Fortify`.

```php
    /**
     * Get the password confirmation status.
     */
    public function show(Request $request): JsonResponse
    {
        $guard = Fortify::guardName();

        $lastConfirmation = (int) $request->session()->get(PasswordConfirmation::sessionKey($guard), 0);
        $lastConfirmed = Date::now()->unix() - $lastConfirmation;
        $confirmed = $lastConfirmed < PasswordConfirmation::timeout($this->config, $guard, $request->input('seconds'));
        // ... response unchanged
```

Deliberate fix folded in: this controller's fallback default was `900` while the middleware's is `10800` — an upstream Laravel/Fortify inconsistency. Both now resolve through `PasswordConfirmation::timeout()` (default 10800). The shipped config always sets `auth.password_timeout`, so the default only matters for apps that delete the key; unifying it removes a trap.

### 9. `src/auth/src/Middleware/RedirectIfAuthenticated.php`

```php
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $checkGuards = $guards ?: [null];

        foreach ($checkGuards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect($this->redirectTo($request));
            }
        }

        // Naming a guard on an auth middleware makes it current: auth:admin
        // selects the matched guard on success, so guest:admin selects its
        // guard on pass-through. The first listed guard is the primary.
        if ($guards !== []) {
            Auth::shouldUse($guards[0]);
        }

        return $next($request);
    }
```

No selection on the redirect path (an authenticated user is leaving this route group) and none for bare `guest` (no named guard exists).

### 10. `src/sanctum/config/sanctum.php`

- Rename the `'stateful'` key to `'stateful_domains'` (same value expression, same comment block — the header already reads "Stateful Domains"). The env var `SANCTUM_STATEFUL_DOMAINS` now mirrors the key path exactly.
- Delete the entire `'guard'` entry and its "Sanctum Guards" comment block. The replacement comment is unnecessary — the declaration now lives in `config/auth.php` where it is documented.

### 11. `src/sanctum/src/Http/Middleware/EnsureFrontendRequestsAreStateful.php`

Line 84: `return config('sanctum.stateful_domains', []);`. The overridable `statefulDomains()` hook is otherwise unchanged.

### 12. `src/sanctum/src/SanctumServiceProvider.php`

`registerSanctumGuard()` validates the declaration at guard resolution time and passes the list into the guard. Import `InvalidArgumentException`. While in the method, fix the container-access rule violations (`$app['events']`, `$app['config']` → `->make()`; `has()` → `bound()`, matching the framework's optional-events pattern).

```php
        $this->callAfterResolving(AuthManager::class, function (AuthManager $authManager) {
            $authManager->extend('sanctum', function ($app, $name, $config) use ($authManager) {
                $sessionGuards = $config['session_guards'] ?? null;
                $isSessionGuardName = static fn (mixed $guard): bool => is_string($guard) && $guard !== '';

                if (! is_array($sessionGuards) || array_filter($sessionGuards, $isSessionGuardName) !== $sessionGuards) {
                    throw new InvalidArgumentException(
                        "Auth guard [{$name}] uses the sanctum driver but does not declare a valid session guards list. "
                        . "Set auth.guards.{$name}.session_guards to an array of session guard names, or [] to disable stateful session authentication."
                    );
                }

                return new SanctumGuard(
                    name: $name,
                    provider: $authManager->createUserProvider($config['provider'] ?? null),
                    app: $app,
                    sessionGuards: $sessionGuards,
                    events: $app->bound('events') ? $app->make('events') : null,
                    expiration: $app->make('config')->get('sanctum.expiration'),
                );
            });
        });
```

One validation covers all four config-shape errors — missing key, non-array value, non-string entries, empty-string entries — with the same instructive message. An empty string inside the list is malformed config (the explicit tokens-only mode is `[]`), and without this check it would surface later as a confusing `Auth guard [] is not defined.` A bare constructor `TypeError` or a downstream `AuthManager::guard()` type error would lose the config-key pointer this design promises; this is boundary validation of a declared config shape (the same philosophy as the typed config readers), not a defensive runtime guard.

### 13. `src/sanctum/src/SanctumGuard.php`

Constructor gains the declared list (after `$app`, before the optional parameters — a required identity collaborator):

```php
    public function __construct(
        protected string $name,
        UserProvider $provider,
        protected Container $app,
        protected array $sessionGuards,
        protected ?Dispatcher $events = null,
        protected ?int $expiration = null,
    ) {
        $this->provider = $provider;
    }
```

The stateful section of `user()` (currently lines 79-93) is replaced. Imports: add `Hypervel\Contracts\Auth\StatefulGuard`, `InvalidArgumentException`; the `Hypervel\Support\Arr` import goes if no longer used elsewhere in the file (verified: `Arr` is used only in the deleted loop).

```php
        // Check the trusted stateful guards declared by this guard's config
        $authFactory = $this->app->make('auth');

        foreach ($this->sessionGuards as $sessionGuardName) {
            $sessionGuard = $authFactory->guard($sessionGuardName);

            if (! $sessionGuard instanceof StatefulGuard) {
                throw new InvalidArgumentException(
                    "Auth guard [{$this->name}] lists [{$sessionGuardName}] in session_guards, but that guard is not a stateful guard."
                );
            }

            if (! $sessionGuard->check()) {
                continue;
            }

            $user = $sessionGuard->user();

            if (! $this->hasValidProvider($user)) {
                continue;
            }

            if ($this->supportsTokens($user)) {
                /** @var Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $tokenUser */
                $tokenUser = $user;
                $user = $tokenUser->withAccessToken(new TransientToken);
            }

            CoroutineContext::set($contextKey, $user);

            return $user;
        }
```

Behavior deltas, all intended:

- **Provider match on the stateful path** mirrors the bearer-token path's `hasValidProvider()`. A session user outside this guard's provider is skipped; the loop continues to the next listed guard, then falls through to token auth. Skip-and-continue (not abort) because a shared-API guard listing `['member', 'admin']` needs later entries tried.
- **The old `$guard !== $this->name` silent self-skip is deleted.** Listing a sanctum guard (self or another) now throws via the `StatefulGuard` check — `SanctumGuard` implements only the base `Guard` contract. This also closes a real hazard: two sanctum guards listing each other would re-enter `user()` before the coroutine-context cache is populated, recursing without bound.
- The `StatefulGuard` contract check (not `driver === 'session'` config inspection) keeps custom stateful guards registered via `extend()` valid and avoids config-shape inference.

No `getSessionGuards()` accessor: nothing consumes it (the middleware below derives its own list), and dead API surface is not added speculatively.

### 14. `src/sanctum/src/Http/Middleware/AuthenticateSession.php`

The middleware runs at group level, before route auth selects a guard, so it cannot ask "the current sanctum guard" for its list. It derives the union of every sanctum-driver guard's `session_guards` from config: password-hash invalidation correctly applies to any session guard that participates in stateful sanctum auth anywhere in the app (checking a guard the route doesn't use is harmless — it only logs out sessions whose password actually changed, which is correct behavior wherever it happens). Constructor gains the config repository; line 38's `Collection::make(Arr::wrap(config('sanctum.guard')))` becomes `Collection::make($this->sanctumSessionGuards())`. The `Arr` import goes if now unused (verified: `Arr::wrap` at line 38 is its only use); add `Hypervel\Contracts\Config\Repository`.

```php
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected AuthFactory $auth,
        protected Repository $config,
    ) {
    }

    /**
     * Get the session guards declared by the application's sanctum guards.
     *
     * The union across every sanctum-driver guard entry: password-hash
     * invalidation applies to any session guard participating in stateful
     * sanctum authentication anywhere in the application.
     */
    protected function sanctumSessionGuards(): array
    {
        $sessionGuards = [];

        foreach ($this->config->array('auth.guards', []) as $guard) {
            if (($guard['driver'] ?? null) !== 'sanctum') {
                continue;
            }

            $declared = $guard['session_guards'] ?? null;

            if (! is_array($declared)) {
                continue;
            }

            $sessionGuards = [...$sessionGuards, ...array_filter($declared, static fn (mixed $guard): bool => is_string($guard) && $guard !== '')];
        }

        return array_values(array_unique($sessionGuards));
    }
```

A sanctum entry with a missing or malformed `session_guards` declaration contributes nothing here — the loud instructive error belongs to the resolution of the guard that declares it (the service-provider validation above), and a group-level middleware must not turn one unrelated bad entry into a request-wide failure. Nothing is masked: using the bad guard still throws. The existing `instanceof SessionGuard` filter stays: the password-hash mechanism is genuinely session-specific, and non-`SessionGuard` stateful guards have no hash to validate.

### 15. `src/foundation/config/auth.php`

- The `sanctum` guard entry gains its declaration:

```php
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => ['web'],
        ],
```

- Guards section comment: after the existing `passwords` paragraph, add: "Sanctum guards declare the session guards they trust for first-party SPA requests with the `session_guards` key; set it to an empty array for bearer-token-only APIs. Guards may also override the password confirmation window with a `password_timeout` key."
- `password_timeout` section comment (line ~161): append "Individual guards may override this with a `password_timeout` key in their guard configuration."

The `jwt` guard entry is unchanged (not a sanctum driver). `src/testbench/hypervel/config/auth.php` needs no change — it overrides only `providers.users` and inherits `guards` from this base config.

### 16. `src/auth/src/AuthManager.php` — viaRequest

```php
    /**
     * Register a new callback based request guard.
     */
    public function viaRequest(string $driver, callable $callback): static
    {
        return $this->extend($driver, function ($app, $name, $config) use ($callback) {
            return new RequestGuard($name, $callback, $app, $this->createUserProvider($config['provider'] ?? null));
        });
    }
```

`callCustomCreator()` already invokes creators with `($this->app, $name, $config)` (`AuthManager.php:100-103`), so the third parameter is available today and simply unused.

### 17. `src/auth/src/CreatesUserProviders.php`

`getDefaultUserProvider()` is kept but redefined: it no longer reads the removed `auth.defaults.provider` root — it derives from the current default guard, exactly as `PasswordBrokerManager::getDefaultDriver()` was redefined when `auth.defaults.passwords` was removed. Humans and LLMs reaching for the Laravel-shaped method get the guard-siloing-correct answer instead of a missing-method failure (owner decision, 2026-07-07):

The trait is used by `AuthManager`, which itself provides `getDefaultDriver()`, so the body calls it directly:

```php
    /**
     * Get the provider name declared by the current default guard.
     */
    public function getDefaultUserProvider(): ?string
    {
        return $this->app->make('config')->get('auth.guards.' . $this->getDefaultDriver() . '.provider');
    }
```

The boundary that matters is unchanged: `createUserProvider()` does **not** call this method. `createUserProvider(null)` means "no provider" — during guard construction the ambient guard can be a different guard than the one being built, so an implicit ambient fallback there would be a wrong-provider hazard. The method is read-only introspection.

`getProviderConfiguration()` becomes null-only (no falsey reinterpretation — consistent with the `??=` fixes in PR #420's follow-up):

```php
    /**
     * Get the user provider configuration.
     */
    protected function getProviderConfiguration(?string $provider): ?array
    {
        if (is_null($provider)) {
            return null;
        }

        return $this->app->make('config')->get('auth.providers.' . $provider);
    }
```

### 18. `src/support/src/Facades/Auth.php`

No change: docblock line 25 (`@method static ?string getDefaultUserProvider()`) stays — the redefined method keeps the same signature and nullable return. The `Hypervel\Contracts\Auth\Factory` contract never declared either method (verified), so no contract change.

### 19. `src/permission/src/Support/Config.php`

Import `Hypervel\Contracts\Auth\Factory as AuthFactory` (the class already imports `Container`):

```php
    /**
     * Get the default auth guard name.
     */
    public static function defaultGuard(): string
    {
        return Container::getInstance()->make(AuthFactory::class)->getDefaultDriver();
    }
```

The `Repository` import stays (used by `repository()` and other methods). This aligns `defaultGuard()` with `Guard::getDefaultName()` — one answer to "what is the default guard" package-wide, context override included.

### 20. `src/fortify/src/LoginRateLimiter.php`

```php
    /**
     * Get the throttle key for the given request.
     *
     * Scoped to the current guard so lockouts in one actor silo never
     * block logins in another for the same username and IP.
     */
    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Fortify::guardName() . '|' . Str::lower((string) $request->input(Fortify::username())) . '|' . $request->ip());
    }
```

No import change (`Fortify` is the same namespace).

### 21. `src/foundation/src/Console/PolicyMakeCommand.php`

Line 74-76: fix the container-access violation while resolving the guard through the manager:

```php
        $config = $this->hypervel->make('config');

        $guard = $this->option('guard') ?: $this->hypervel->make('auth')->getDefaultDriver();
```

Foundation apps always have auth registered and the base config always provides `auth.defaults.guard`, so no soft path is needed. The rest of the method (LogicException for undefined guards, provider/model lookups) is unchanged.

### 22. `src/console/src/GeneratorCommand.php`

`userProviderModel()` (lines 445-455): the console package does not require `hypervel/auth` (verified in `src/console/composer.json`), so the auth binding is genuinely optional here — the `bound()` check protects a real reachable path (standalone console usage), replacing the current silent null-guard concatenation:

```php
    /**
     * Get the model for the default guard's user provider.
     */
    protected function userProviderModel(): ?string
    {
        // Best-effort guess: console may run without the auth package installed.
        if (! $this->hypervel->bound('auth')) {
            return null;
        }

        $config = $this->hypervel->make('config');

        $provider = $config->get('auth.guards.' . $this->hypervel->make('auth')->getDefaultDriver() . '.provider');

        return $config->get("auth.providers.{$provider}.model");
    }
```

### 23. Documentation (`src/boost/docs/`) — Edit, never rewrite files

**`authentication.md`**

- Line 366 (guest middleware section): append a sentence — when the `guest` middleware names a guard and the request continues, that guard becomes the current default guard for the request; with multiple guards, the first listed is selected.
- Line 391 (the multi-guard paragraph added by PR #420): rewrite. New wording: "Guards that send password reset links declare their password broker with the `passwords` key. Multi-guard applications should set this per guard. On guest routes such as login and password reset requests, naming the guard on the `guest` middleware selects it for the request — `guest:admin` makes `admin` the current guard, so authentication, policies, and password reset flows all follow the same user type. For guest routes that do not use the `guest` middleware, apply `auth.guard:admin` instead:" (the guards config code block that follows the paragraph is unchanged).
- Password confirmation configuration (line ~711): append — confirmation is scoped to the current guard (confirming under one guard never satisfies `password.confirm` under another), and individual guards may override the window with a `password_timeout` key in their guard configuration.

**`sanctum.md`**

- "Setting Sanctum Guard" section (anchor at line 93): rewrite the section body to document `session_guards` on the guard entry: what it declares, the `['web']` default in a fresh app, `[]` as the explicit bearer-token-only mode, the config error when missing, the `StatefulGuard` requirement, and that a trusted session user must belong to the sanctum guard's provider. Retitle the section "Declaring Trusted Session Guards" and update the TOC entry at line 7.
- Lines 417-421: `stateful` configuration option references become `stateful_domains`. The `statefulDomains()` override example (lines 430-438) is unchanged (method name is not renamed).

**`fortify.md`**

- Line 222: the sentence "Named middleware such as `auth:admin` or `guest:admin` checks that guard, but it does not set the default guard that controller code and other packages use." is factually wrong today for `auth:admin` (`Authenticate::authenticate()` calls `shouldUse()` on success) and becomes wrong for `guest:admin` with this plan. Replace with: "Named middleware select their guard as the request default: `auth:admin` selects `admin` when authentication succeeds, and `guest:admin` selects `admin` when the request passes the guest check. Use middleware like the example above when many routes should share one guard without naming it on each middleware."
- Line 371 ("Password confirmation uses the current default guard.") stays — it is now true in the strong sense; append: "Confirmation is stored per guard, and lockout throttling for login attempts is also scoped per guard."

### 24. Package README `Differences From Laravel` entries

Per AGENTS.md, removed or diverged Laravel surfaces are recorded where future upstream merges will look. Owner-approved entries (added during the code-review round; wording may be tightened in place):

**`src/sanctum/README.md`** (existing section, append):
- The global `sanctum.guard` accept-list is removed. Each sanctum-driver guard declares its trusted session guards with `auth.guards.{guard}.session_guards` — `[]` means bearer tokens only, and a missing key is a config error. Stateful session users must also match the sanctum guard's provider; Laravel returns any listed guard's user unchecked.
- `sanctum.stateful` is renamed `sanctum.stateful_domains`, matching the `SANCTUM_STATEFUL_DOMAINS` env var and the key's actual contents.

**`src/fortify/README.md`** (existing section, append):
- Login throttling is scoped per guard (`guard|username|ip`), so a lockout in one actor silo never blocks logins in another.
- Password confirmation follows the current guard: guard-scoped session key, optional per-guard `password_timeout`, and the confirmed-password status endpoint uses the same resolution (also unifying Laravel's mismatched 900/10800 fallback defaults).

**`src/auth/README.md`** (new `## Differences From Laravel` section — includes retroactive entries for PR #420, which never recorded its removals here):
- Password brokers are guard-declared via `auth.guards.{guard}.passwords`; `auth.defaults.passwords` and `AUTH_PASSWORD_BROKER` do not exist, and bare `Password::` calls resolve through the current guard or throw.
- `auth.defaults.provider` does not exist; `getDefaultUserProvider()` returns the provider declared by the current default guard, and `createUserProvider(null)` means no provider.
- `guest:{guard}` selects the first named guard as the request's default guard on pass-through, mirroring how `auth:{guard}` selects on success.
- Password confirmation is guard-scoped (`auth.password_confirmed_at_{guard}`) with an optional per-guard `password_timeout`; `RequirePassword` resolves guard and timeout at handle-time.

**`src/session/README.md`** (new `## Differences From Laravel` section):
- `Store::passwordConfirmed(?string $guard = null)` stamps a guard-scoped key (`auth.password_confirmed_at_{guard}`) instead of Laravel's single shared key, resolving the current guard when none is given.

Passkeys and foundation need no entries: passkeys inherits the confirmation change through the session store with no API of its own changing, and foundation's config shape is documented in the config comments and boost docs.

### 25. Verified inventory: nothing else reads the removed roots

Repo-wide greps (source, non-test) match exactly the lines this plan changes:

- `sanctum.guard`: `SanctumGuard.php:81`, `Http/Middleware/AuthenticateSession.php:38`, `config/sanctum.php:36`, plus `tests/Sanctum/GuardTest.php:53` and `tests/Sanctum/AuthenticateRequestsTest.php:54`.
- `sanctum.stateful`: `config/sanctum.php:17`, `EnsureFrontendRequestsAreStateful.php:84`, `sanctum.md`.
- `auth.defaults.provider`: `CreatesUserProviders.php:93` (read replaced by the guard-derived redefinition), `tests/Auth/AuthManagerTest.php:167,257` (tests rewritten). `getDefaultUserProvider` deliberately remains in `CreatesUserProviders.php` and `Facades/Auth.php:25` with new semantics.
- `auth.password_confirmed_at` (unsuffixed): `Store.php:705`, `RequirePassword.php:65`, `ConfirmablePasswordController.php:50`, `ConfirmedPasswordStatusController.php:28`, `tests/Session/SessionStoreTest.php:484-486`, `tests/Auth/RequirePasswordMiddlewareTest.php` (5 sites), `tests/Fortify/ConfirmablePasswordControllerTest.php` (7 sites), `tests/Passkeys/Feature/Controllers/PasskeyConfirmationTest.php:126`, `tests/Passkeys/Feature/Controllers/PasskeyRegistrationTest.php` (8 sites: lines 27, 52, 70, 125, 148, 205, 229, 280).
- `auth.password_timeout` reads outside config: `AuthServiceProvider.php:77`, `ConfirmedPasswordStatusController.php:32` — both replaced by `PasswordConfirmation::timeout()`.
- `.env` / `.env.example`: no occurrences of `SANCTUM_STATEFUL_DOMAINS` or `AUTH_PASSWORD_TIMEOUT` (nothing to update).

## Test Plan

Run each file immediately after changing it (`./vendor/bin/phpunit --no-progress <file>`), then `composer test:parallel`, phpstan, cs-fixer at the end. All from the repo root.

### `tests/Auth/PasswordConfirmationTest.php` (new)

Unit tests on `Hypervel\Tests\TestCase` with a real `Hypervel\Config\Repository`:

1. `testSessionKeyIsGuardSuffixed` — `sessionKey('admin') === 'auth.password_confirmed_at_admin'`.
2. `testTimeoutUsesGuardDeclaration` — `auth.guards.admin.password_timeout = 900` → `timeout($config, 'admin') === 900`.
3. `testTimeoutFallsBackToGlobal` — no guard key, `auth.password_timeout = 3600` → `3600`.
4. `testTimeoutDefaultsWhenUnconfigured` — empty config → `10800`.
5. `testTimeoutFailsFastOnMalformedGuardValue` — guard key set to `'abc'` → the config repository's typed-read `InvalidArgumentException`.
6. `testGuardDeclarationWinsWhenGlobalIsMalformed` — guard key `900`, global set to `'abc'` → `900` with no exception (pins the `has()` branch: the global tier is never read when the guard declares).
7. `testExplicitOverrideWinsOverAllTiers` — guard and global both set, `timeout($config, 'admin', '300') === 300` (also pins the string-to-int cast of route parameters).

### `tests/Auth/RequirePasswordMiddlewareTest.php` (update + extend)

Existing tests construct the middleware directly and mock the session with the old key — five construction sites (`new RequirePassword($responseFactory, $urlGenerator)` at lines 49, 81, 114, 147, 174), each updated to the new four-argument constructor (add `m::mock(AuthFactory::class)` returning a guard name from `getDefaultDriver()` and a real config `Repository`), and every session expectation updated to `auth.password_confirmed_at_{guard}`. No other code constructs the middleware directly (verified: the only `new RequirePassword` outside this file is the provider binding being deleted). New tests:

8. `testConfirmationIsScopedToCurrentGuard` — auth factory returns `admin`; session holds a fresh `..._web` timestamp but no `..._admin`; middleware redirects (cross-guard confirmation does not satisfy).
9. `testPerGuardTimeoutIsHonored` — guard `admin` declares `password_timeout = 10`; confirmation 11 seconds old → redirect; 9 seconds old → pass.
10. `testRouteParameterOverridesPerGuardTimeout` — route param 5 beats guard declaration 10.

### `tests/Auth/RedirectIfAuthenticatedMiddlewareTest.php` (extend)

Testbench-based; asserts via `Auth::getDefaultDriver()` / `CoroutineContext::has(AuthManager::DEFAULT_GUARD_CONTEXT_KEY)`:

11. `testNamedGuardIsSelectedOnPassThrough` — `handle($request, $next, 'admin')` with no authenticated user → next called and default driver is `admin`.
12. `testFirstListedGuardIsSelectedForMultipleGuards` — `handle(..., 'admin', 'web')` → `admin`.
13. `testBareGuestSelectsNothing` — `handle($request, $next)` → context key absent.
14. `testNoSelectionOnRedirect` — authenticated user → redirect returned and context key absent.

### `tests/Auth/AuthManagerTest.php` (rewrite three tests)

- `testGetDefaultUserProvider` (line ~162): rewrite for the redefined semantics — `auth.defaults.guard = 'web'`, `auth.guards.web.provider = 'users'` → `'users'`; add a context case (`shouldUse('admin')` with `auth.guards.admin.provider = 'admins'` → `'admins'`); add a missing-provider case (current guard entry without a `provider` key → null).
- `testCreateUserProviderReturnsNullWhenNoProviderIsConfigured` (line ~171): drop the `getDefaultUserProvider()` assertion (its null-return path is now covered by the missing-provider case above); keep `assertNull($manager->createUserProvider())` pinning that the bare call never consults the redefined method.
- The viaRequest test at line ~245: replace `auth.defaults.provider = 'foo'` with `'auth.guards.foo' => ['driver' => 'custom', 'provider' => 'foo']`; add an assertion that the resolved `RequestGuard`'s provider is the registered `foo` provider instance (via `getProvider()`).
- New: `testViaRequestGuardWithoutProviderKeyGetsNullProvider` — guard entry without `provider` → `getProvider()` returns null.

### `tests/Session/SessionStoreTest.php` (update + extend)

- Line 484-486: update to the explicit-guard form — `$session->passwordConfirmed('web')` sets `auth.password_confirmed_at_web`.
- New: `testPasswordConfirmedResolvesCurrentGuardWhenNoneGiven` — bind a mock `AuthFactory` (`getDefaultDriver()` → `'admin'`) on `Container::getInstance()`, call `passwordConfirmed()`, assert the `_admin` key. Follow the file's existing container-interaction style.

### `tests/Fortify/ConfirmablePasswordControllerTest.php` (update + extend)

Update existing assertions from the shared key to `auth.password_confirmed_at_{guard}` for the test's guard. New:

15. `testConfirmationStampsCurrentGuardKey` — confirm under the `admin` guard (the Fortify TestCase already configures `web` + `admin`); assert the `_admin` key is set and `_web` is not.
16. Status endpoint: `testStatusReadsCurrentGuardConfirmation` — stamp `_admin`, query the status route under `admin` → confirmed true; under `web` → confirmed false.
17. `testStatusUsesPerGuardTimeout` — `auth.guards.web.password_timeout = 10`, stamp 11 seconds ago → confirmed false.

### `tests/Passkeys/Feature/Controllers/PasskeyConfirmationTest.php` and `PasskeyRegistrationTest.php` (update)

- `PasskeyConfirmationTest.php:126` — update the `session('auth.password_confirmed_at')` assertion to the guard-scoped key (the controller path flows through `Store::passwordConfirmed()` and becomes guard-scoped automatically).
- `PasskeyRegistrationTest.php` — eight `withSession(['auth.password_confirmed_at' => time()])` seeds (lines 27, 52, 70, 125, 148, 205, 229, 280) satisfy the `password.confirm` middleware on the management routes; each becomes the guard-suffixed key for the route's guard.

### `tests/Fortify/AuthenticatedSessionControllerTest.php` (extend)

18. `testLockoutIsScopedToGuard` — drive the `web` login into lockout (five failed attempts for one email + IP), then with the current guard `admin` assert `LoginRateLimiter::tooManyAttempts()` is false for the same request shape (or via a second login route under the admin guard, following the file's existing throttle-test style).

### `tests/Sanctum/GuardTest.php` (config migration + matrix)

Replace `'sanctum.guard' => ['web']` (line 53) with `'session_guards' => ['web']` inside the `auth.guards.sanctum` entry. New tests:

19. `testEmptySessionGuardsIsTokenOnly` — `session_guards => []`, active web session → guard returns null without a token, returns the token user with one.
20. `testMissingSessionGuardsThrowsInstructiveError` — guard entry without the key → `InvalidArgumentException` with the exact message on first resolution.
21. `testNonArraySessionGuardsThrowsInstructiveError` — `session_guards => 'web'` → the same instructive `InvalidArgumentException` (not a `TypeError`).
22. `testInvalidSessionGuardEntriesThrowInstructiveError` — `session_guards => [123]` and `session_guards => ['']` each → the same instructive `InvalidArgumentException` (an empty-string entry is malformed config; the explicit tokens-only mode is `[]`).
23. `testNonStatefulSessionGuardThrows` — `session_guards => ['other-sanctum']` where `other-sanctum` is a sanctum guard → `InvalidArgumentException` naming both guards (also pins the recursion-hazard fix).
24. `testStatefulUserMustMatchProvider` — two providers/models; web session holds a user from the *other* provider → stateful path skips it and token auth still works (mirror of the existing token-path provider test).
25. `testSecondListedSessionGuardIsTried` — `session_guards => ['admin', 'web']`, only `web` session authenticated with a matching-provider user → user returned.

### `tests/Sanctum/AuthenticateRequestsTest.php`, `tests/Sanctum/ActingAsTest.php`, `tests/Sanctum/SimpleGuardTest.php` (config migration)

Move `sanctum.guard` config (AuthenticateRequestsTest line 54) into the guard entries. Every `driver => 'sanctum'` test guard entry gets `'session_guards' => ['web']` — including ActingAsTest's `sanctum` and `api` entries and SimpleGuardTest's `sanctum` entry, which today set no `sanctum.guard` and therefore ran under the old fail-open default of `['web']`; declaring `['web']` preserves their current behavior exactly (the `[]` mode is exercised by the new matrix test, not by retrofitting existing tests). Grep to catch them all: `grep -rn "'driver' => 'sanctum'" tests/`.

### `tests/Sanctum/AuthenticateSessionTest.php` (new)

Tests for the middleware's config-derived union (Testbench base, mirroring `tests/Session/Middleware/AuthenticateSessionTest.php` style):

26. `testUnionOfSanctumGuardsSessionGuardsIsChecked` — two sanctum guard entries declaring `['web']` and `['admin']`; a password change under `admin` logs that session guard out through the middleware.
27. `testSanctumEntryWithoutSessionGuardsContributesNothing` — a sanctum entry missing the key does not break the middleware (no exception from the union derivation; the loud error stays at guard resolution).
28. `testMalformedSessionGuardsEntriesAreSkippedByUnion` — one unused sanctum entry with `session_guards => 'web'` (string) and another with `[123, '', 'admin']`; the middleware still functions and only checks `admin` (the loud error for the malformed entries stays at their guard resolution).

### `tests/Permission/Support/ConfigTest.php` (new)

29. `testDefaultGuardFallsBackToConfig` — no context override → `auth.defaults.guard` value.
30. `testDefaultGuardFollowsCurrentGuard` — `Auth::shouldUse('admin')` → `Config::defaultGuard() === 'admin'` (each test runs in its own coroutine; context cleanup is automatic).

### Existing suites as regression gates

- `tests/Integration/Generators/PolicyMakeCommandTest.php` — behavior is equivalent (console has no context override); the existing suite passing is the coverage. The `GeneratorCommand` unbound-auth branch is not testable in this suite (auth is always bound under testbench) and is deliberately left to the existing best-effort contract; no artificial container surgery to fake a missing package.
- `tests/Fortify/`, `tests/Passkeys/`, `tests/Session/`, `tests/Sanctum/`, `tests/Auth/`, `tests/Permission/` full runs after their respective steps.

No new coroutine-isolation test files: the only context-backed state these changes touch is `DEFAULT_GUARD_CONTEXT_KEY`, whose isolation is already pinned by `tests/Auth/UseGuardMiddlewareTest.php` and the broker manager tests; the new confirmation state is session-stored (per session by nature, not per coroutine).

## Execution Order

1. `PasswordConfirmation` class + `RequirePassword` + `AuthServiceProvider` + `foundation/config/auth.php` comments/`password_timeout` doc text; run `tests/Auth/PasswordConfirmationTest.php`, `tests/Auth/RequirePasswordMiddlewareTest.php`.
2. `Store::passwordConfirmed()` + session composer.json + `Session` facade docblock; run `tests/Session/SessionStoreTest.php`.
3. Fortify confirmation controllers; run `tests/Fortify/ConfirmablePasswordControllerTest.php`, then `tests/Passkeys/Feature/Controllers/PasskeyConfirmationTest.php` and `tests/Passkeys/Feature/Controllers/PasskeyRegistrationTest.php`.
4. `RedirectIfAuthenticated`; run its test file.
5. viaRequest + `CreatesUserProviders` + `Auth` facade docblock; run `tests/Auth/AuthManagerTest.php`.
6. Sanctum: config rename/deletion, `EnsureFrontendRequestsAreStateful`, service provider, `SanctumGuard`, `AuthenticateSession`, foundation `auth.php` sanctum entry; run `tests/Sanctum/` in full.
7. Permission `Config::defaultGuard()`; run `tests/Permission/Support/ConfigTest.php` + `tests/Permission/GuardTest.php`.
8. `LoginRateLimiter`; run `tests/Fortify/AuthenticatedSessionControllerTest.php`.
9. Generators; run `tests/Integration/Generators/PolicyMakeCommandTest.php`.
10. Docs (`authentication.md`, `sanctum.md`, `fortify.md`) and the package README `Differences From Laravel` entries (§24).
11. Final greps (below), then `composer test:parallel`, `./vendor/bin/phpstan`, `./vendor/bin/php-cs-fixer fix` (no flags).

Final verification greps (clear the phpstan cache first: `rm -rf .cache/phpstan`), each expected to return nothing:

```bash
grep -rn "auth\.defaults\.provider" src/ tests/
grep -rn "sanctum\.guard" src/ tests/
grep -rn "sanctum\.stateful[^_]" src/ tests/
grep -REn "auth\.password_confirmed_at([^_A-Za-z0-9]|$)" src/ tests/
grep -rn "'auth\.password_timeout'" src/ | grep -v "PasswordConfirmation.php\|foundation/config/auth.php"
```

(The last grep's two exclusions are the only permitted occurrences: the config definition and the single reader, `PasswordConfirmation::timeout()`.)

## Addendum (2026-07-07, post-bot-review): HMAC-only password-hash artifacts

While verifying the bot-review findings against the upstream source (`examples/laravel/sanctum`), the owner surfaced a missed upstream hardening and a live interop bug in the sanctum port. Consensus (Claude/Codex, owner-directed): fix both in this PR.

**Upstream chronology.** Laravel framework `b5f9532ce2` (PR #58107, shipped v12.45.0) changed remember-cookie/session password-hash artifacts from the raw password hash to an HMAC via `SessionGuard::hashPasswordForCookie()`. Laravel Sanctum `dadd227` (PR #582, v4.2.4) added the matching support to Sanctum's `AuthenticateSession`: store HMAC when the guard exposes the method, validate HMAC first, fall back to the raw hash for legacy sessions. Hypervel's `SessionGuard` (`hashPasswordForCookie()`, line 538) and core session `AuthenticateSession` already carry the HMAC format (with Laravel's raw fallback ported); the sanctum middleware port predates it and still stores/compares the raw hash.

**The interop bug.** Both middlewares share the `password_hash_{guard}` session keys. Core `auth.session` writes the HMAC format; sanctum's middleware compared the same key against the raw `getAuthPassword()` with `!==`. In the standard SPA setup (web login via `auth.session`, stateful API via sanctum's middleware) the comparison always mismatches, falsely logging the user out on the first stateful API request — single guard, stock configuration. Not just parity hardening; a real defect on this branch's surface.

**Greenfield decision (owner).** Hypervel 0.4 is unreleased: the HMAC format is the only valid password-hash artifact. Two distinct fallbacks are deleted, for different reasons:

- The raw-*value* fallback (`|| hash_equals($passwordHash, $storedValue)` and Sanctum's equivalent) is legacy-runtime-artifact compatibility for sessions Hypervel never issued. Deleted.
- The missing-*method* fallback (core middleware's `try/catch BadMethodCallException`) is API-surface tolerance. Deleted without replacement: a guard used with `auth.session` that cannot produce the artifact fails loudly with the natural `BadMethodCallException` (fail-fast rule). The import goes with it.

**Implementation.**

- Core `src/session/src/Middleware/AuthenticateSession.php`: `storePasswordHashInSession()` keeps HMAC storage; `validatePasswordHash()` becomes HMAC-only (`hash_equals($this->guard()->hashPasswordForCookie($passwordHash), $storedValue)`); both try/catches and the `BadMethodCallException` import deleted.
- Sanctum `src/sanctum/src/Http/Middleware/AuthenticateSession.php`: per-guard validation and storage (from the bot-review follow-up) use the already-resolved concrete `SessionGuard` instances from the derived guard list — `hashPasswordForCookie()` is guaranteed by the `instanceof SessionGuard` filter, so no `method_exists`, no re-resolution through the auth manager, no raw fallback. Validation helper `validatePasswordHash(SessionGuard $guard, ?string $passwordHash, string $storedValue): bool` compares with `hash_equals()` (also replacing the pre-existing non-timing-safe `!==`). Nullable hashes remain supported (`hashPasswordForCookie(?string)`; passkey-only accounts).
- Current upstream source is the porting reference; the commits above are chronology/rationale only.

**Tests.**

- Cross-middleware interop regression (the discovered bug): core `auth.session` stores the hash for a guard, sanctum's middleware validates the same session, no logout.
- Sanctum: HMAC round-trip within the middleware; raw stored values are rejected (logout).
- Core session middleware: `OldFormatCookie*` backward-compatibility tests removed with a concise `REMOVED:` comment at the site per AGENTS.md; an inverted test proves raw values are now invalid.

**Docs.** One-line entries in the session and sanctum README `Differences From Laravel` sections: Laravel's raw-hash backward-compatibility fallback is intentionally omitted — Hypervel has no released legacy sessions; only the HMAC format is valid.

## Explicitly Unchanged

- **`auth.verification.expire` stays global** — a signed-URL TTL with no cross-silo hazard; the URL is already signed per user.
- **Permission's model-to-guard inference (`Guard::getConfigAuthGuards()`) stays** — Spatie's architecture, used only when a model declares no `guard_name`, and `getDefaultName()` already prefers the current guard when it is a candidate.
- **JWT settings stay** — the JWT guard already owns its provider and supports per-guard TTL; signing defaults are not actor-silo decisions.
- **`EnsureFrontendRequestsAreStateful` stays app-level** — pre-auth infrastructure; per-guard domains were considered and rejected (see Rejected Alternatives). The `statefulDomains()` override hook is the extension point.
- **Sanctum's bearer-token path** — `hasValidProvider()` was already correct; the stateful path now matches it.
- **Core session `AuthenticateSession`** — already guard-scoped via `password_hash_{guard}`.
- **`Sanctum::actingAs()` `'sanctum'` default** — an explicit test helper, Laravel parity, callers pass a guard when they mean another.
- **`UseGuard` / `auth.guard` middleware** — unchanged and still the right tool for selecting a guard on route groups that use neither `auth` nor `guest`.
