# Hypervel JWT Native Parity Refactor Plan

## Goal

Refactor `src/jwt` into a complete, clean, Hypervel-native JWT package that keeps the current array-based, coroutine-safe architecture while bringing over the useful behavior from `php-open-source-saver/jwt-auth`.

The final codebase reads as if it was designed this way from the start:

- no stale comments or docs
- no dead exception/classes
- no upstream object model copied back in
- no mutable request/token/custom-claim state on worker-lifetime singletons
- no avoidable hot-path overhead
- no behavior that silently differs from the documented API

## Source References

Current Hypervel package:

- `src/jwt/src/JwtGuard.php`
- `src/jwt/src/JwtManager.php`
- `src/jwt/src/JwtServiceProvider.php`
- `src/jwt/src/Providers/Lcobucci.php`
- `src/jwt/src/Validations/*`
- `src/jwt/config/jwt.php`
- `src/jwt/README.md`
- `tests/Jwt/*`

Current Hypervel auth patterns:

- `src/auth/src/SessionGuard.php`
- `src/auth/src/AuthManager.php`
- `src/auth/src/EloquentUserProvider.php`
- `src/auth/src/Events/*`

Upstream JWT package clone:

- `/tmp/claude-20000/-home-binaryfire-workspace-monorepo/eeff203a-4261-4d58-80c6-bab17f200296/scratchpad/jwt-auth-fork`

Important upstream files:

- `src/JWTGuard.php`
- `src/JWT.php`
- `src/JWTAuth.php`
- `src/Manager.php`
- `src/Factory.php`
- `src/Claims/*`
- `src/Http/Parser/*`
- `src/Http/Middleware/*`
- `src/Console/JWTGenerateSecretCommand.php`
- `src/Console/JWTGenerateCertCommand.php`
- `src/Providers/AbstractServiceProvider.php`
- `src/Providers/LaravelServiceProvider.php`
- `config/config.php`
- `docs/auth-guard.md`
- `docs/quick-start.md`

## Research Summary

The current Hypervel package is a slim reimplementation, not a direct port. It uses:

- array payloads instead of upstream `Payload`, `Token`, `Claim`, and `Collection` objects
- `JwtManager` for encode/decode/refresh/invalidate
- `JwtGuard` with `CoroutineContext` for per-coroutine user and payload caches
- a simple validation pipeline under `src/jwt/src/Validations`

That core design is correct for Hypervel. Upstream stores mutable request/token/custom-claim/last-attempted state on objects such as `JWT`, `JwtAuth`, `JWTGuard`, and `Parser`. In Laravel those instances are request-scoped or refreshed with the request. In Hypervel they would be worker-lifetime singletons, so copying them directly would leak state between coroutines.

The missing pieces are useful behavior, not a reason to restore the upstream object graph.

## Final Design Decisions

### Keep the Array-Based Payload Model

Do not port upstream `Payload`, `Token`, `Claims`, `Claims\Factory`, or claim DTO classes.

Why:

- A JWT payload is a flat claim map.
- The upstream object graph adds per-decode allocations and indirection on the hot path.
- The object graph does not add capability that cannot be represented by arrays plus validation.
- The array model is easier to keep coroutine-safe.

How:

- Keep `JwtManager::encode(array $payload): string`.
- Keep `JwtManager::decode(...): array`.
- Keep `JwtGuard::getPayload(): array`.
- Add a small array-based claim builder so default claim construction is centralized without restoring claim objects.

`JwtManager` receives the claim factory explicitly because refresh claim construction moves there:

```php
public function __construct(
    protected Container $container,
    protected ClaimFactory $claimFactory,
) {
    parent::__construct($container);

    $this->blacklist = $container->make(BlacklistContract::class);
    $this->blacklistEnabled = $this->config->boolean('jwt.blacklist_enabled', false);
}

public function encode(array $payload): string
{
    if ($this->blacklistEnabled && ! array_key_exists('jti', $payload)) {
        $payload['jti'] = (string) Str::uuid();
    }

    return $this->driver()->encode($payload);
}
```

`JwtManager::encode()` owns blacklist `jti` stamping so the public `Jwt::encode([...])` path still produces invalidatable tokens when blacklist is enabled. It preserves caller-provided `jti` values and adds none when blacklist is disabled.

### Add Central Claim Building, Not Claim Objects

Current Hypervel claim construction is split across:

- `JwtGuard::login()`
- `JwtManager::encode()`
- `JwtManager::buildRefreshClaims()`

This causes drift and made refresh behavior incomplete. Add a dedicated array-based builder.

New file:

- `src/jwt/src/ClaimFactory.php`

Shape:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Jwt;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Jwt\Contracts\JwtSubject;
use Hypervel\Support\Facades\Date;

class ClaimFactory
{
    protected const array MANAGED_REFRESH_CLAIMS = ['iat', 'nbf', 'exp', 'iss', 'jti'];

    protected static array $subjectModelHashes = [];

    protected ?string $issuer;

    protected bool $lockSubject;

    public function __construct(Repository $config)
    {
        /** @var null|string $issuer */
        $issuer = $config->get('jwt.issuer');
        $this->issuer = ($issuer === null || $issuer === '') ? null : $issuer;

        $this->lockSubject = $config->boolean('jwt.lock_subject', true);
    }

    /**
     * Build claims for a newly issued token.
     */
    public function make(
        Authenticatable $user,
        UserProvider $provider,
        ?int $ttl,
        array $customClaims = [],
    ): array {
        $claims = [
            'sub' => $this->subjectIdentifier($user),
        ];

        if ($this->lockSubject && method_exists($provider, 'getModel')) {
            /** @phpstan-ignore-next-line method.notFound */
            $claims['prv'] = $this->subjectModelHash($provider->getModel());
        }

        if ($user instanceof JwtSubject) {
            $claims = array_merge($claims, $user->getJwtCustomClaims());
        }

        return $this->withDefaults(array_merge($claims, $customClaims), $ttl);
    }

    /**
     * Build claims for a refreshed token.
     */
    public function refresh(
        array $payload,
        ?int $ttl,
        bool $refreshIssuedAt,
        bool $resetClaims,
        array $persistentClaims,
        array $customClaims = [],
    ): array {
        $managed = array_flip(self::MANAGED_REFRESH_CLAIMS);
        $persistent = array_diff_key(
            array_intersect_key($payload, array_flip($persistentClaims)),
            $managed,
        );

        $claims = $resetClaims
            ? $persistent
            : array_diff_key($payload, $managed);

        $claims = array_merge($claims, $persistent, $customClaims, [
            'sub' => $payload['sub'],
        ]);

        if (! $refreshIssuedAt) {
            $claims['iat'] = $payload['iat'];
        }

        if (array_key_exists('prv', $payload)) {
            $claims['prv'] = $payload['prv'];
        }

        return $this->withDefaults($claims, $ttl);
    }

    /**
     * Stamp standard claims, then apply caller claims on top.
     */
    protected function withDefaults(array $claims, ?int $ttl): array
    {
        $now = Date::now();

        $defaults = [
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
        ];

        if ($ttl !== null) {
            $defaults['exp'] = $now->addMinutes($ttl)->getTimestamp();
        }

        if ($this->issuer !== null) {
            $defaults['iss'] = $this->issuer;
        }

        return array_merge($defaults, $claims);
    }

    /**
     * Determine the subject identifier for a user.
     */
    public function subjectIdentifier(Authenticatable $user): mixed
    {
        return $user instanceof JwtSubject
            ? $user->getJwtIdentifier()
            : $user->getAuthIdentifier();
    }

    /**
     * Check whether a decoded token belongs to the configured provider model.
     */
    public function subjectMatchesProvider(array $payload, UserProvider $provider): bool
    {
        if (! $this->lockSubject || ! method_exists($provider, 'getModel')) {
            return true;
        }

        /** @phpstan-ignore-next-line method.notFound */
        $model = $provider->getModel();

        return isset($payload['prv'])
            && hash_equals($this->subjectModelHash($model), (string) $payload['prv']);
    }

    /**
     * Hash the subject model class.
     */
    protected function subjectModelHash(string|object $model): string
    {
        $class = is_object($model) ? $model::class : $model;

        return static::$subjectModelHashes[$class] ??= hash('xxh128', $class);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$subjectModelHashes = [];
    }
}
```

Implementation notes:

- `prv` uses `xxh128`, not upstream `sha1`, because this is an internal signed-token model discriminator and Hypervel already uses `xxh128` for internal non-crypto hashes.
- Static `$subjectModelHashes` is safe because model class strings are immutable worker-lifetime metadata.
- Add `ClaimFactory::flushState()` to `tests/AfterEachTestSubscriber.php`.
- `jwt.issuer` is config-driven. Do not use upstream's request URL issuer because JWTs can be issued from CLI/jobs and request URL issuer adds request-dependent behavior.
- `jti` remains owned by `JwtManager::encode()` so direct `Jwt::encode([...])` callers still get invalidatable tokens when blacklist is enabled.

### Add JwtSubject as an Optional Contract

New file:

- `src/jwt/src/Contracts/JwtSubject.php`

Shape:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

interface JwtSubject
{
    /**
     * Get the identifier that will be stored in the subject claim.
     */
    public function getJwtIdentifier(): mixed;

    /**
     * Return custom claims to add to the token.
     */
    public function getJwtCustomClaims(): array;
}
```

Why:

- Upstream supports model-defined JWT identifiers and custom claims.
- Hypervel does not require every auth model to implement this contract. Normal `Authenticatable` models keep working with `getAuthIdentifier()`.

How:

- `ClaimFactory::subjectIdentifier()` uses `JwtSubject::getJwtIdentifier()` when implemented.
- `ClaimFactory::make()` merges `JwtSubject::getJwtCustomClaims()` before inline claims.
- Inline claims passed through `claims()` still win.

### Keep Auth Events Inline in JwtGuard

Do not extract a shared trait for auth events.

Why:

- The shared code is small.
- `SessionGuard` and `JwtGuard` event sets are not identical.
- `SessionGuard` has session-specific events and remember-me flags.
- Keeping the methods inline matches the current guard pattern.

How:

Update the `JwtGuard` constructor and then add the event helpers:

```php
use Closure;
use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Jwt\Http\Parser\Parser;

public function __construct(
    protected string $name,
    UserProvider $provider,
    protected ManagerContract $jwtManager,
    protected ClaimFactory $claimFactory,
    protected Parser $parser,
    protected Container $app,
    protected ?int $ttl = 120,
) {
    $this->provider = $provider;
}

protected ?Dispatcher $events = null;

/**
 * Register an authentication attempt event listener.
 */
public function attempting(callable $callback): void
{
    $this->events?->listen(Attempting::class, $callback);
}

/**
 * Get the event dispatcher instance.
 */
public function getDispatcher(): ?Dispatcher
{
    return $this->events;
}

/**
 * Set the event dispatcher instance.
 */
public function setDispatcher(Dispatcher $events): void
{
    $this->events = $events;
}

/**
 * Dispatch the given event if listeners are registered.
 */
protected function dispatchIfListening(string $eventClass, Closure $event): void
{
    if ($this->events?->hasListeners($eventClass)) {
        $this->events->dispatch($event());
    }
}
```

Then add JWT-specific fire helpers:

```php
protected function fireAttemptEvent(array $credentials): void
{
    $this->dispatchIfListening(Attempting::class, fn () => new Attempting($this->name, $credentials, false));
}

protected function fireValidatedEvent(AuthenticatableContract $user): void
{
    $this->dispatchIfListening(Validated::class, fn () => new Validated($this->name, $user));
}

protected function fireFailedEvent(?AuthenticatableContract $user, array $credentials): void
{
    $this->dispatchIfListening(Failed::class, fn () => new Failed($this->name, $user, $credentials));
}

protected function fireLoginEvent(AuthenticatableContract $user): void
{
    $this->dispatchIfListening(Login::class, fn () => new Login($this->name, $user, false));
}

protected function fireAuthenticatedEvent(AuthenticatableContract $user): void
{
    $this->dispatchIfListening(Authenticated::class, fn () => new Authenticated($this->name, $user));
}

protected function fireLogoutEvent(?AuthenticatableContract $user): void
{
    $this->dispatchIfListening(Logout::class, fn () => new Logout($this->name, $user));
}
```

Performance:

- `hasListeners()` is the existing Hypervel event guard pattern.
- Event objects are constructed only when listeners exist.
- The no-listener path adds only a cached listener lookup.

### Fix JwtGuard Authentication API

Current bugs:

- `attempt()` returns `bool`, while upstream returns token string or `false`.
- `once()` calls `attempt(..., true)` and mints a token.
- `onceUsingId()` calls `login()`, mints a token, and returns `true` instead of the resolved user.
- `login()` can cache the new user under a new-token key while `user()` keeps parsing the old request token.
- `claims()` persists after the token is minted despite the "next token" docblock.

Final API:

```php
public function attempt(array $credentials = [], bool $login = true): string|bool
{
    $this->fireAttemptEvent($credentials);

    $user = $this->provider->retrieveByCredentials($credentials);
    $this->setContextState('lastAttempted', $user);

    if ($user !== null && $this->provider->validateCredentials($user, $credentials)) {
        $this->fireValidatedEvent($user);

        return $login ? $this->login($user) : true;
    }

    $this->fireFailedEvent($user, $credentials);

    return false;
}

public function validate(array $credentials = []): bool
{
    return (bool) $this->attempt($credentials, false);
}

public function once(array $credentials = []): bool
{
    if ($this->validate($credentials)) {
        $this->setUser($this->getLastAttempted());

        return true;
    }

    return false;
}

public function onceUsingId(mixed $id): AuthenticatableContract|false
{
    if ($user = $this->provider->retrieveById($id)) {
        $this->setUser($user);

        return $user;
    }

    return false;
}
```

Add context helpers in `JwtGuard`, mirroring the current `SessionGuard` idea for simple state while keeping user and payload caches token-keyed:

```php
protected const string GUARD_CONTEXT_KEY_PREFIX = '__auth.guards.';

private const string NO_EXPIRY = '__jwt.ttl.no_expiry';

protected function getContextState(string $key, mixed $default = null): mixed
{
    return CoroutineContext::get($this->getContextStateKey($key), $default);
}

protected function setContextState(string $key, mixed $value): void
{
    CoroutineContext::set($this->getContextStateKey($key), $value);
}

protected function forgetContextState(string $key): void
{
    CoroutineContext::forget($this->getContextStateKey($key));
}

protected function getContextStateKey(string $key): string
{
    return static::GUARD_CONTEXT_KEY_PREFIX . $this->name . '.' . $key;
}

protected function getUserContextKey(?string $token = null): string
{
    $token ??= $this->getToken();

    if ($token === null || $token === '') {
        return $this->getContextStateKey('user.default');
    }

    return $this->getContextStateKey('user.' . hash('xxh128', $token));
}

protected function getPayloadContextKey(string $token): string
{
    return $this->getContextStateKey('payload.' . hash('xxh128', $token));
}

public function getLastAttempted(): ?AuthenticatableContract
{
    return $this->getContextState('lastAttempted');
}

public function hasUser(): bool
{
    self::$nullUserSentinel ??= new stdClass;

    $cached = CoroutineContext::get($this->getUserContextKey());

    return $cached !== null && $cached !== self::$nullUserSentinel;
}

public function setUser(AuthenticatableContract $user): static
{
    CoroutineContext::set($this->getUserContextKey(), $user);
    $this->fireAuthenticatedEvent($user);

    return $this;
}

public function forgetUser(): static
{
    CoroutineContext::forget($this->getUserContextKey());

    return $this;
}

protected function cachedUser(): ?AuthenticatableContract
{
    self::$nullUserSentinel ??= new stdClass;

    $cached = CoroutineContext::get($this->getUserContextKey());

    return ($cached === null || $cached === self::$nullUserSentinel) ? null : $cached;
}
```

Current token override:

```php
public function setToken(string $token): static
{
    $this->setContextState('token', $token);

    return $this;
}

public function getToken(): ?string
{
    return $this->getContextState('token') ?: $this->parseToken();
}

protected function requireToken(): string
{
    if (! $token = $this->getToken()) {
        throw new JwtException('Token could not be parsed from the request.');
    }

    return $token;
}
```

Use `getToken()` in `user()`, `getPayload()`, `refresh()`, `logout()`, and `invalidate()`. This fixes login/setToken behavior when a request already has an older bearer token.

User cache keys must be derived from `getToken()`, not a single guard-wide user slot. This preserves the current token-keyed behavior while fixing the login bug: `login()` sets the new token first, then `setUser()` writes to the new token's user cache. It also keeps `setToken($a)->user()` and `setToken($b)->user()` correct inside the same coroutine.

Payload cache keys also stay token-keyed:

```php
protected function decodeToken(string $token): array
{
    return CoroutineContext::getOrSet(
        $this->getPayloadContextKey($token),
        fn () => $this->jwtManager->decode($token)
    );
}
```

`login()` sets the token and user:

```php
public function login(AuthenticatableContract $user): string
{
    $token = $this->makeTokenForUser($user);

    $this->setToken($token);
    $this->setUser($user);
    $this->fireLoginEvent($user);

    return $token;
}
```

`claims()` is "next token" only:

```php
public function claims(array $claims): static
{
    $contextKey = $this->getContextStateKey('claims');
    $existing = CoroutineContext::get($contextKey, []);

    CoroutineContext::set($contextKey, array_merge($existing, $claims));

    return $this;
}

protected function pullCustomClaims(): array
{
    $contextKey = $this->getContextStateKey('claims');
    $claims = CoroutineContext::get($contextKey, []);
    CoroutineContext::forget($contextKey);

    return $claims;
}
```

### Add Per-Guard and Per-Call TTL

Current bug:

- `JwtServiceProvider` ignores `auth.guards.*.ttl`.

Final service provider behavior:

```php
$ttl = array_key_exists('ttl', $config)
    ? $config['ttl']
    : $app->make('config')->get('jwt.ttl', 120);
```

Use `array_key_exists()` so `ttl => null` means "no expiration".

Runtime per-call TTL:

```php
public function setTTL(?int $ttl): static
{
    $this->setContextState('ttl', $ttl ?? self::NO_EXPIRY);

    return $this;
}

public function getTTL(): ?int
{
    $ttl = $this->getContextState('ttl');

    if ($ttl === null) {
        return $this->ttl;
    }

    return $ttl === self::NO_EXPIRY ? null : (int) $ttl;
}
```

After `login()`, clear only the per-call override:

```php
$ttl = $this->getTTL();
$token = $this->jwtManager->encode($this->claimFactory->make(..., ttl: $ttl, ...));
$this->forgetContextState('ttl');
```

Why:

- Guard instances are worker-lifetime singletons.
- Runtime TTL overrides must not live on `$this->ttl`.
- Per-guard default TTL belongs in the guard constructor and is safe.
- Per-call override belongs in `CoroutineContext`.
- Refresh uses the same effective TTL as minting. `setTTL()` is a one-shot override for the next token-producing operation, including refresh.
- `getTTL()` uses a non-null sentinel because `CoroutineContext::get()` and `CoroutineContext::has()` both collapse stored `null` into "absent".

### Add Subject Locking

Current bug:

- A token with `sub = 5` minted for one Eloquent provider can authenticate against another Eloquent provider that also has id `5`.

Final behavior:

- Add `jwt.lock_subject` config, default `true`.
- When minting through `JwtGuard`, include `prv` when the provider exposes `getModel()`.
- When resolving `user()`, reject payloads whose `prv` does not match the provider model.
- When `lock_subject` is true and the provider exposes `getModel()`, missing `prv` fails. Hypervel 0.4 has no legacy token compatibility requirement, and requiring the claim gives the clean security behavior.
- When provider does not expose `getModel()`, skip the check, matching upstream's safe fallback for non-Eloquent providers.

Full `user()` shape:

```php
public function user(): ?AuthenticatableContract
{
    self::$nullUserSentinel ??= new stdClass;

    $token = $this->getToken();
    $contextKey = $this->getUserContextKey($token);

    $cached = CoroutineContext::get($contextKey);

    if ($cached === self::$nullUserSentinel) {
        return null;
    }

    if ($cached !== null) {
        return $cached;
    }

    if (! $token) {
        CoroutineContext::set($contextKey, self::$nullUserSentinel);

        return null;
    }

    try {
        $payload = $this->decodeToken($token);
    } catch (JwtException) {
        CoroutineContext::set($contextKey, self::$nullUserSentinel);

        return null;
    }

    $sub = $this->claimFactory->subjectMatchesProvider($payload, $this->provider)
        ? ($payload['sub'] ?? null)
        : null;

    $user = $sub !== null ? $this->provider->retrieveById($sub) : null;

    if ($user === null) {
        CoroutineContext::set($contextKey, self::$nullUserSentinel);

        return null;
    }

    $this->setUser($user);

    return $user;
}
```

### Add `userOrFail()`, `getUserId()`, `id()`, `tokenById()`, `byId()`, `payload()`, and `invalidate()`

These are useful upstream APIs and map cleanly to Hypervel.

```php
public function userOrFail(): AuthenticatableContract
{
    if (! $user = $this->user()) {
        throw new UserNotDefinedException;
    }

    return $user;
}

public function getUserId(): int|string|null
{
    if ($user = $this->cachedUser()) {
        return $user->getAuthIdentifier();
    }

    try {
        $payload = $this->getPayload();
    } catch (JwtException) {
        return null;
    }

    if (! $this->claimFactory->subjectMatchesProvider($payload, $this->provider)) {
        return null;
    }

    return $payload['sub'] ?? null;
}

public function id(): int|string|null
{
    return $this->getUserId();
}

public function tokenById(mixed $id): ?string
{
    if (! $user = $this->provider->retrieveById($id)) {
        return null;
    }

    return $this->makeTokenForUser($user);
}

public function byId(mixed $id): AuthenticatableContract|false
{
    return $this->onceUsingId($id);
}

public function payload(): array
{
    return $this->getPayload();
}

public function getPayload(): array
{
    if (! $token = $this->getToken()) {
        return [];
    }

    return $this->decodeToken($token);
}

public function invalidate(bool $forceForever = false): static
{
    $this->jwtManager->invalidate($this->requireToken(), $forceForever);

    return $this;
}
```

`login()` and `tokenById()` must share one token-building helper. This is justified reuse: both paths need the same claim construction, one-shot custom claims, one-shot TTL handling, and cleanup.

```php
protected function makeTokenForUser(AuthenticatableContract $user): string
{
    $token = $this->jwtManager->encode(
        $this->claimFactory->make($user, $this->provider, $this->getTTL(), $this->pullCustomClaims())
    );

    $this->forgetContextState('ttl');

    return $token;
}
```

`getPayload()` returns an empty array when no token is present, preserving current behavior. It remains a strict inspection API for present tokens and throws JWT exceptions for invalid tokens. `payload()` is a direct alias.

`user()` and `getUserId()` translate invalid, expired, malformed, or blacklisted tokens into `null`. That is guard resolution behavior, not exception hiding: `auth:jwt` then follows the normal `AuthenticationException` / 401 path. `getPayload()` and `payload()` remain strict inspection APIs and continue throwing JWT exceptions for invalid tokens.

`getUserId()` returns the already-set user's auth identifier when the guard has one, otherwise decodes the payload and returns `sub` without loading the user record. It must still enforce subject locking, matching `user()` and upstream's `validateSubject()` path. Override `id()` to call it so `Auth::id()` remains cheap on JWT requests.

### Fix Logout Without Swallowing Real Errors

Current bug:

- `jwt.blacklist_enabled` defaults to false.
- `JwtGuard::logout()` always calls `JwtManager::invalidate()`.
- `JwtManager::invalidate()` throws when blacklist is disabled.

Do not catch and swallow all JWT exceptions like upstream. Hypervel's fail-fast rule is better.

Add this to `ManagerContract` and `JwtManager`:

```php
public function hasBlacklistEnabled(): bool;
```

```php
public function hasBlacklistEnabled(): bool
{
    return $this->blacklistEnabled;
}
```

Then:

```php
public function logout(bool $forceForever = false): void
{
    $user = $this->cachedUser();
    $token = $this->getToken();

    $this->forgetUser();
    if ($token) {
        CoroutineContext::forget($this->getPayloadContextKey($token));
    }

    $this->forgetContextState('token');

    if ($token && $this->jwtManager->hasBlacklistEnabled()) {
        $this->jwtManager->invalidate($token, $forceForever);
    }

    $this->fireLogoutEvent($user);
}
```

Why:

- Local guard state is cleared either way.
- Server-side token invalidation runs only when configured.
- Misconfigured blacklist storage still fails naturally.
- Default logout no longer throws just because blacklist is disabled.

### Add Refresh-Aware Decoding

Current bug:

- `JwtManager::refresh()` calls `decode($token)` through the normal validation pipeline.
- If `ExpiredClaim` validation is enabled, expired tokens cannot be refreshed even when they are inside `refresh_ttl`.

Final behavior:

- Add `Hypervel\Jwt\Contracts\TemporalValidation` as a marker contract for timestamp/lifetime checks.
- Add a refresh validation mode to `JwtManager`.
- Required-claims validation still runs.
- Temporal validations are skipped for refresh.
- Refresh window validation uses original `iat`.
- Blacklist checks still run for the old token before refresh.

Simple shape:

```php
interface TemporalValidation
{
}
```

```php
protected function decodeForRefresh(string $token, bool $checkBlacklist = true): array
{
    $payload = $this->driver()->decode($token);

    $this->validatePayload($payload, refreshFlow: true);

    if ($this->blacklistEnabled && $checkBlacklist && $this->blacklist->has($payload)) {
        throw new TokenBlacklistedException('The token has been blacklisted');
    }

    return $payload;
}

protected function validatePayload(array $payload, bool $refreshFlow = false): void
{
    foreach ($this->config->array('jwt.validations', []) as $validation) {
        $validation = $this->getValidation($validation);

        if ($refreshFlow && $validation instanceof TemporalValidation) {
            continue;
        }

        $validation->validate($payload);
    }
}
```

Validation behavior:

- `RequiredClaims` does not implement `TemporalValidation`, so it always runs.
- `ExpiredClaim`, `IssuedAtClaim`, and `NotBeforeClaim` implement `TemporalValidation`, so they are skipped only during refresh.
- `ValidationContract` stays simple: `public function validate(array $payload): void;`.

`JwtManager::refresh()`:

```php
public function refresh(
    string $token,
    bool $forceForever = false,
    bool $resetClaims = false,
    array $customClaims = [],
    int|null|false $ttl = false,
): string
{
    $payload = $this->decodeForRefresh($token);
    $this->validateRefreshWindow($payload);

    $claims = $this->claimFactory->refresh(
        payload: $payload,
        ttl: $ttl === false ? $this->config->get('jwt.ttl', 120) : $ttl,
        refreshIssuedAt: $this->config->boolean('jwt.refresh_iat', false),
        resetClaims: $resetClaims,
        persistentClaims: $this->config->array('jwt.persistent_claims', []),
        customClaims: $customClaims,
    );

    if ($this->blacklistEnabled) {
        $this->invalidate($token, $forceForever);
    }

    return $this->encode($claims);
}
```

`ManagerContract::refresh()` must accept `$resetClaims` and inline custom claims:

```php
public function refresh(
    string $token,
    bool $forceForever = false,
    bool $resetClaims = false,
    array $customClaims = [],
    int|null|false $ttl = false,
): string;
```

`JwtGuard::refresh()` passes both arguments:

```php
public function refresh(bool $forceForever = false, bool $resetClaims = false): ?string
{
    if (! $token = $this->getToken()) {
        return null;
    }

    $customClaims = $this->pullCustomClaims();
    $ttl = $this->getTTL();
    $this->forgetContextState('ttl');
    $this->forgetUser();
    CoroutineContext::forget($this->getPayloadContextKey($token));

    $newToken = $this->jwtManager->refresh($token, $forceForever, $resetClaims, $customClaims, $ttl);
    $this->setToken($newToken);

    return $newToken;
}
```

### Fix NotBeforeCliam

Rename:

- `src/jwt/src/Validations/NotBeforeCliam.php`
- `tests/Jwt/Validations/NotBeforeCliamTest.php`

To:

- `src/jwt/src/Validations/NotBeforeClaim.php`
- `tests/Jwt/Validations/NotBeforeClaimTest.php`

Update:

- namespace imports
- config commented class reference
- tests

No compatibility alias is needed. Hypervel 0.4 is greenfield.

### Add SecretMissingException

New file:

- `src/jwt/src/Exceptions/SecretMissingException.php`

```php
<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Exceptions;

class SecretMissingException extends JwtException
{
}
```

Use it in `Lcobucci::getSigningKey()` and `Lcobucci::getVerificationKey()` when symmetric secret is missing.

Do not use it for missing asymmetric keys. Keep those messages specific to public/private key config.

### Add Stateless Token Parser

Current Hypervel parsing is hardcoded:

- bearer header
- request input `token`

Upstream supports parser chain, but upstream `Parser` stores mutable request state.

Add Hypervel-native stateless parser classes:

- `src/jwt/src/Http/Parser/Parser.php`
- `src/jwt/src/Http/Parser/AuthHeaders.php`
- `src/jwt/src/Http/Parser/InputSource.php`
- `src/jwt/src/Http/Parser/Cookie.php`
- `src/jwt/src/Contracts/TokenExtractor.php`

Contract:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

use Hypervel\Http\Request;

interface TokenExtractor
{
    /**
     * Parse a token from the request.
     */
    public function parseToken(Request $request): ?string;
}
```

Parser:

```php
class Parser
{
    /**
     * @param array<int, TokenExtractor> $chain
     */
    public function __construct(
        protected array $chain,
    ) {
    }

    public function parseToken(Request $request): ?string
    {
        foreach ($this->chain as $extractor) {
            $token = $extractor->parseToken($request);

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }
}
```

Register parser as a singleton containing only parser objects and config-derived immutable settings.

Default chain:

- `AuthHeaders`
- `InputSource`

Parser config:

- `jwt.token` controls the request input and cookie key name.
- `jwt.parser` controls the ordered extractor class list.
- `Cookie` is shipped but opt-in. Upstream enables cookie parsing in Laravel; Hypervel does not enable it by default because the current Hypervel guard only reads the bearer header and request input, and enabling cookie auth by default broadens the auth source.
- Cookie decryption is handled by Hypervel's normal cookie middleware; the JWT parser must not manually decrypt cookies.

Extractor shapes:

```php
class AuthHeaders implements TokenExtractor
{
    public function parseToken(Request $request): ?string
    {
        $header = $request->header('Authorization')
            ?: $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION');

        if (! is_string($header)) {
            return null;
        }

        $position = strripos($header, 'Bearer');

        if ($position === false) {
            return null;
        }

        $token = substr($header, $position + strlen('Bearer'));

        return trim(str_contains($token, ',') ? strstr($token, ',', true) : $token) ?: null;
    }
}

class InputSource implements TokenExtractor
{
    public function __construct(
        protected string $key = 'token',
    ) {
    }

    public function parseToken(Request $request): ?string
    {
        $token = $request->input($this->key);

        return is_string($token) && $token !== '' ? $token : null;
    }
}

class Cookie implements TokenExtractor
{
    public function __construct(
        protected string $key = 'token',
    ) {
    }

    public function parseToken(Request $request): ?string
    {
        $token = $request->cookie($this->key);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
```

`JwtGuard::parseToken()`:

```php
public function parseToken(): ?string
{
    if (! RequestContext::has()) {
        return null;
    }

    return $this->parser->parseToken($this->app->make('request'));
}
```

Do not store the request on the parser.

Do not port upstream route-param or Lumen parsers. Hypervel has no matching route-token JWT path, and keeping the parser surface request-passed and stateless is the correct Swoole shape. Record the decision in the README under differences and with a concise source comment in parser registration so future porting does not re-add mutable request parser behavior.

### Add Middleware

Port only the useful missing sliding-token middleware to Hypervel style:

- `src/jwt/src/Http/Middleware/RefreshToken.php`
- `src/jwt/src/Http/Middleware/AuthenticateAndRenew.php`

Do not add `jwt.auth` or `jwt.check`. Normal authentication is already covered by Hypervel's `auth:jwt` middleware, and optional JWT auth is handled by reading the guard lazily.

Register aliases in `JwtServiceProvider::boot()`:

```php
$router = $this->app->make('router');

$router->aliasMiddleware('jwt.refresh', RefreshToken::class);
$router->aliasMiddleware('jwt.renew', AuthenticateAndRenew::class);
```

Middleware uses the auth manager / guard, not upstream `JwtAuth`.

Base behavior:

```php
protected function guard(?string $guard = null): JwtGuard
{
    $resolved = $this->auth->guard($guard);

    if (! $resolved instanceof JwtGuard) {
        throw new RuntimeException('JWT middleware requires a JWT guard.');
    }

    return $resolved;
}
```

`RefreshToken` and `AuthenticateAndRenew` set `Authorization: Bearer <token>` on the response.

All middleware must be stateless. Do not store token/user/request on middleware properties.

### Add Commands

Port command behavior, not upstream `EnvHelperTrait`.

New files:

- `src/jwt/src/Console/JwtSecretCommand.php`
- `src/jwt/src/Console/JwtGenerateCertsCommand.php`

Add `"hypervel/console": "^0.4"` to `src/jwt/composer.json`; JWT commands extend `Hypervel\Console\Command` and the package does not currently require it.

Use Hypervel command patterns:

- `#[AsCommand(name: 'jwt:secret')]`
- `#[AsCommand(name: 'jwt:generate-certs')]`
- `Hypervel\Support\Env::writeVariable()` / `writeVariables()`
- `$this->hypervel->environmentFilePath()`

Register in `JwtServiceProvider::register()`:

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        JwtSecretCommand::class,
        JwtGenerateCertsCommand::class,
    ]);
}
```

`jwt:secret` behavior:

- generate 64-character secret
- `--show` prints without writing
- `--force` overwrites without confirmation
- `--always-no` skips if key exists
- write `JWT_SECRET`
- write `JWT_ALGO=HS256`

`jwt:generate-certs` behavior:

- generate RSA or EC cert pair
- support force, algo, bits, sha, dir, curve, passphrase, ask-passphrase
- write `JWT_ALGO`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`
- use typed returns and fail fast on OpenSSL failure

### Service Provider Wiring

Update `JwtServiceProvider`:

- inject config via `$app->make('config')`
- use `->make()`, not array access
- let `ClaimFactory` auto-singleton as an unbound concrete
- bind stateless parser with its configured extractor chain
- register commands
- register middleware aliases
- pass dispatcher to `JwtGuard`
- pass per-guard TTL with `array_key_exists`

Manager and parser bindings:

```php
$this->app->singleton('jwt', fn ($app) => new JwtManager(
    $app,
    $app->make(ClaimFactory::class),
));

$this->app->singleton(Parser::class, function ($app) {
    $config = $app->make('config');
    $tokenKey = $config->string('jwt.token', 'token');

    $chain = array_map(
        fn (string $extractor) => match ($extractor) {
            InputSource::class, Cookie::class => new $extractor($tokenKey),
            default => $app->make($extractor),
        },
        $config->array('jwt.parser', [AuthHeaders::class, InputSource::class]),
    );

    // The parser chain is stateless; request instances are passed per parse so
    // coroutine requests cannot leak through a singleton parser.
    return new Parser($chain);
});
```

Guard construction:

```php
return new JwtGuard(
    name: $name,
    provider: $authManager->createUserProvider($config['provider'] ?? null),
    jwtManager: $app->make('jwt'),
    claimFactory: $app->make(ClaimFactory::class),
    parser: $app->make(Parser::class),
    app: $app,
    ttl: array_key_exists('ttl', $config)
        ? $config['ttl']
        : $app->make('config')->get('jwt.ttl', 120),
);
```

Then:

```php
$guard->setDispatcher($app->make('events'));
```

### Remove Replaced Code

Delete stale code after the new shape is in place:

- `JwtGuard::getContextKeyForToken()`; user caching is still token-keyed, but now through `getUserContextKey()` based on `getToken()`.
- `JwtManager::buildRefreshClaims()`; refresh claim construction belongs to `ClaimFactory::refresh()`.
- `Date` and `Str` imports from `JwtGuard`; claim stamping and parsing move to `ClaimFactory` / parser classes.
- `Collection` import from `JwtManager`; refresh claim construction moves to `ClaimFactory`.
- `Str` import and `$blacklistEnabled` property from `ClaimFactory`; blacklist-gated `jti` generation stays in `JwtManager::encode()`.

Do not leave compatibility wrappers or comments for these removed internals.

Keep `JwtGuard::flushState()`. It still flushes `Macroable` macros and is already registered in `tests/AfterEachTestSubscriber.php`. Static subject model hashes move to `ClaimFactory::flushState()`, which must also be registered there.

### Config Updates

Update `src/jwt/config/jwt.php`:

- fix `ttl` comment to 2 hours
- add `refresh_iat`
- add `issuer`
- add `lock_subject`
- add parser config
- update `NotBeforeClaim` spelling
- add command mention in secret comment

Add commented reference entries to the relevant `.env.example` files for:

- `JWT_ISSUER`
- `JWT_REFRESH_IAT`
- `JWT_LOCK_SUBJECT`
- `JWT_TOKEN`

Config shape:

```php
'issuer' => env('JWT_ISSUER'),

'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', false),

'refresh_iat' => env('JWT_REFRESH_IAT', false),

'lock_subject' => env('JWT_LOCK_SUBJECT', true),

'token' => env('JWT_TOKEN', 'token'),

'parser' => [
    \Hypervel\Jwt\Http\Parser\AuthHeaders::class,
    \Hypervel\Jwt\Http\Parser\InputSource::class,
],
```

Use normal Hypervel config style for boolean env values. Typed config getters will fail fast if unsupported string values are provided; broader env boolean parsing would be a framework-wide policy, not a JWT-only config workaround.

Decision:

- Keep `jwt.blacklist_enabled` default false for performance. Blacklist adds cache I/O on authenticated requests.
- Keep `jti` blacklist-gated.
- Keep `ttl` default 120 minutes.
- Keep default validations safe: `RequiredClaims`, `ExpiredClaim`, `IssuerClaim`, `IssuedAtClaim`, and `NotBeforeClaim` are enabled. `IssuerClaim` is a no-op until `jwt.issuer` is configured.
- Keep `required_claims` default as `iat` and `sub`. Do not require `exp` when `ttl` can be null.

### README and Boost Docs

Keep `src/jwt/README.md` as the short package landing page used by the other component packages.

It must include:

- `Ported from: https://github.com/PHP-Open-Source-Saver/jwt-auth`
- a concise description of the package
- a pointer to `src/boost/docs/jwt.md` for full usage docs

Add `src/boost/docs/jwt.md` as the full user-facing documentation surface, matching the style of the other Boost docs.

It must cover:

- installation/config publishing
- `jwt:secret`
- `jwt:generate-certs`
- configuring the jwt guard
- user model requirements and optional `JwtSubject`
- signing keys and algorithms
- token lifetime and per-call TTL
- subject locking
- header/input token parsing and opt-in cookie parsing
- validations, leeway, blacklist, refresh window, and `refresh_iat`
- login with `attempt()`
- `login()`
- `once()` / `onceUsingId()`
- `tokenById()`
- `user()`, `userOrFail()`, `getUserId()`, `id()`
- `claims()`
- `refresh()`
- `logout()` / `invalidate()` and blacklist requirements
- middleware aliases
- exceptions
- differences from upstream

Differences section:

- Hypervel uses arrays, not `Payload`, `Token`, or claim DTO objects.
- Hypervel keeps the existing `Jwt` facade mapped to the array-based `JwtManager`, but does not ship upstream `JwtAuth`, `JwtFactory`, or `JwtProvider` facades.
- Hypervel does not include Namshi or Lumen integration.
- Hypervel parser is stateless and request is passed per parse.
- Hypervel does not enable cookie token parsing by default; upstream enables it in Laravel. The `Cookie` parser is available and can be added to `jwt.parser` when an application explicitly wants cookie-based JWT auth.
- Hypervel does not support upstream route-param/Lumen parser shortcuts because they are framework-specific and not part of Hypervel's intended JWT path.
- `show_black_list_exception` is not included; exceptions fail normally.

Also update the Boost docs index and authentication docs:

- add `jwt.md` to `src/boost/docs/documentation.md`
- mention JWT as a stateless API-auth option from `src/boost/docs/authentication.md`
- keep the custom guard example generic so it does not imply users should build their own JWT guard

### Source Comments for Intentional Omissions

Add only concise comments where future porting will naturally look.

`JwtServiceProvider` near omitted upstream object/facade binding surface:

```php
// Hypervel intentionally keeps JWT as an array-based manager/guard package.
// Upstream JWT/JWTAuth/Payload/Token/Claim/facade bindings store mutable request
// state and do not fit worker-lifetime singleton guards.
```

Parser registration:

```php
// The parser chain is stateless; request instances are passed per parse so
// coroutine requests cannot leak through a singleton parser.
```

Do not add noisy comments to routine methods.

### Tests

Keep existing `tests/Jwt` tests and update them to the new behavior. Add new tests in the same package directory. No external service tests are needed for this package.

#### Guard API Tests

File:

- `tests/Jwt/JwtGuardTest.php`

Add/replace tests:

- `attempt()` returns token string on successful login.
- `attempt($credentials, false)` returns true and does not call encode.
- invalid attempt returns false and fires failed event when listeners exist.
- `validate()` returns bool.
- `login()` sets current token and current user even when request contains an older bearer token.
- `once()` does not call encode and sets current user only.
- `onceUsingId()` does not call encode, sets current user only, and returns the user or `false`.
- `tokenById()` returns token without setting current user/token.
- `getUserId()` and `id()` return the token subject without fetching the user and reject subject-lock mismatches.
- `claims()` affects only the next minted token and is cleared afterward.
- `setTTL()` affects only the next token and is cleared afterward.
- `setTTL(null)` omits `exp` and does not leak into a later token.
- per-guard `ttl => null` omits `exp`.
- per-guard `ttl` is honored on refresh.
- `setTTL(n)->refresh()` produces an `n` minute token and does not leak the override into a later token.
- `userOrFail()` throws `UserNotDefinedException`.
- `payload()` aliases `getPayload()`.
- `getPayload()` returns `[]` when no token is present.
- `getPayload()` throws when a present token is invalid.
- `setToken()` overrides request token for `user()` and `getPayload()`.
- `setToken($a)->user()` then `setToken($b)->user()` resolves different token-keyed users in the same coroutine.
- `logout()` clears current user and token.
- `logout()` clears the decoded payload cache for the active token.
- `logout()` does not call invalidate when blacklist is disabled.
- `logout(true)` passes force flag when blacklist is enabled.
- `invalidate()` calls the manager with the current token and force flag.
- `userOrFail()` returns the resolved user on success.

#### Auth Event Tests

File:

- `tests/Jwt/JwtGuardEventTest.php` or merge into `JwtGuardTest.php` if size stays readable.

Tests:

- no event object is dispatched when `hasListeners()` returns false.
- `Attempting` fires only when listener exists.
- `Validated` fires on valid credentials.
- `Failed` fires on invalid credentials.
- `Login` fires on login.
- `Authenticated` fires on `setUser()` / successful `user()` resolution.
- `Logout` fires on logout.
- `attempting()` registers listener through dispatcher.

#### Subject Locking and JwtSubject Tests

File:

- `tests/Jwt/JwtSubjectTest.php`

Fixtures:

- `tests/Jwt/Fixtures/JwtSubjectUser.php`
- `tests/Jwt/Fixtures/JwtSubjectAdmin.php`

Tests:

- `JwtSubject::getJwtIdentifier()` is used for `sub`.
- `JwtSubject::getJwtCustomClaims()` are merged.
- inline `claims()` override model claims.
- `prv` is included when `jwt.lock_subject` is true and provider has `getModel()`.
- `prv` is omitted when lock subject is false.
- matching provider model authenticates.
- mismatched provider model returns null and caches null sentinel.
- mismatched provider model makes `getUserId()` / `id()` return null.
- missing `prv` fails when lock subject is true and provider has `getModel()`.
- provider without `getModel()` skips subject locking.

#### Coroutine Safety Tests

File:

- `tests/Jwt/JwtGuardCoroutineSafetyTest.php`

Use `parallel()` and `usleep()`:

- concurrent `claims()` calls do not bleed.
- concurrent `setTTL()` calls do not bleed.
- concurrent `setToken()` calls do not bleed.
- decoded payload cache is per token and per coroutine.
- token switching inside one coroutine does not reuse another token's user or payload cache.
- login in one coroutine does not set user in another coroutine.

#### Manager Refresh Tests

File:

- `tests/Jwt/JwtManagerTest.php`

Add/update:

- refresh with `ExpiredClaim` enabled succeeds when inside refresh window.
- normal decode with `ExpiredClaim` enabled still rejects expired token.
- refresh fails when `refresh_ttl` window expired.
- `refresh_iat=false` keeps original `iat`.
- `refresh_iat=true` stamps a fresh `iat`.
- `resetClaims=false` keeps original non-managed custom claims, configured persistent claims, `sub`, and existing `prv`, while stamping fresh managed defaults.
- `resetClaims=true` keeps configured persistent claims, `sub`, and existing `prv`, while dropping non-persistent custom claims and stamping fresh managed defaults.
- configured persistent claims cannot preserve stale managed claims such as old `exp` or `jti`.
- inline `claims()` are applied to the next refresh and cleared afterward.
- guard `refresh()` sets the returned token as the active token and clears old user/payload caches.
- guard `refresh()` consumes the effective TTL, including explicit `null` for no expiry.
- refresh invalidates old token only when blacklist enabled.
- `hasBlacklistEnabled()` returns config-derived value.
- `JwtManager::encode()` adds `jti` when blacklist is enabled and preserves caller-provided `jti`.

#### Claim Factory Tests

File:

- `tests/Jwt/ClaimFactoryTest.php`

Tests:

- builds default `sub`, `iat`, `exp`.
- omits `exp` when TTL is null.
- includes config issuer only when configured.
- includes `nbf`.
- uses `xxh128` `prv` with static model-hash cache.
- `flushState()` clears model-hash cache.
- refresh builder handles persistent claims, custom claims, `refresh_iat`, `resetClaims`.

#### Parser Tests

File:

- `tests/Jwt/Http/ParserTest.php`

Tests:

- bearer header parses.
- request input parses, including query-string input.
- cookie parses through the normal request cookie bag when `Cookie` is included in `jwt.parser`.
- default parser config does not include `Cookie`.
- non-string token input returns null.
- parser does not retain request between calls.

#### Middleware Tests

Files:

- `tests/Jwt/Http/Middleware/RefreshTokenTest.php`
- `tests/Jwt/Http/Middleware/AuthenticateAndRenewTest.php`

Tests:

- missing token throws unauthorized.
- invalid token throws unauthorized.
- refresh sets Authorization response header.
- renew authenticates then sets refreshed Authorization response header.
- middleware resolves the configured guard and fails clearly if it is not `JwtGuard`.

#### JWT Provider Tests

File:

- `tests/Jwt/Providers/LcobucciTest.php`

Tests:

- missing symmetric secret throws `SecretMissingException` when signing.
- missing symmetric secret throws `SecretMissingException` when verification needs a symmetric key.
- missing asymmetric keys keep their existing specific key-related exception messages.

#### Command Tests

Files:

- `tests/Jwt/Console/JwtSecretCommandTest.php`
- `tests/Jwt/Console/JwtGenerateCertsCommandTest.php`

Use `Hypervel\Testbench\TestCase` because commands write `.env` and cert files under the runtime skeleton.

Tests:

- `jwt:secret --show` prints key and does not write `.env`.
- `jwt:secret --force` writes `JWT_SECRET` and `JWT_ALGO=HS256`.
- existing key without force prompts/skips based on option.
- missing `.env` reports the problem without writing.
- cert command writes private/public key files.
- cert command writes `JWT_ALGO`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`.
- cert command refuses to overwrite existing files without `--force`.
- invalid algo fails.

#### Provider Tests

File:

- `tests/Jwt/JwtServiceProviderTest.php` or existing `JwtGuardTest` provider section.

Tests:

- guard receives per-guard TTL.
- guard receives explicit `ttl => null`.
- dispatcher is set.
- parser singleton is stateless.
- only `jwt.refresh` and `jwt.renew` middleware aliases are registered.
- commands are registered in console.
- `TaggedCache` storage uses `$app->make('cache')->store()`, not array access.

#### Validation Rename Tests

Rename and update:

- `tests/Jwt/Validations/NotBeforeClaimTest.php`

Tests:

- token before `nbf` throws.
- token at or after `nbf` passes.

### Verification Commands

After each test file or logical group:

```bash
cd /home/binaryfire/workspace/monorepo/contrib/hypervel/components
./vendor/bin/phpunit --no-progress tests/Jwt/JwtGuardTest.php
```

After source changes:

```bash
cd /home/binaryfire/workspace/monorepo/contrib/hypervel/components
composer fix
```

`composer fix` runs:

1. php-cs-fixer
2. phpstan
3. test:parallel

After green checks:

- self-review all changed code
- re-read current upstream files for each ported feature and confirm no useful behavior was missed
- re-check worker-lifetime and coroutine state
- request Claude review and loop until signoff

## Implementation Order

1. Add `JwtSubject`, `TemporalValidation`, `ClaimFactory`, `SecretMissingException`, parser contract/classes, middleware classes, and command classes.
2. Update `JwtManager` and `ManagerContract` for claim factory, refresh-aware decode, resetClaims, refresh_iat, blacklist state, and `SecretMissingException`.
3. Update validations, including `NotBeforeClaim` rename and `TemporalValidation` support.
4. Update `JwtGuard` for events, token override, fixed attempt/once/login/logout behavior, per-call TTL, subject matching, and added methods.
5. Update `JwtServiceProvider` registration, middleware aliases, parser registration, command registration, per-guard TTL, and dispatcher wiring.
6. Update config.
7. Update README.
8. Update and add tests in the groups above, running each file as it is completed.
9. Run `composer fix`.
10. Self-review against Hypervel source, upstream source, Swoole/coroutine constraints, and the plan.
11. Request Claude code review and loop until signoff.

## Performance Review

Hot path additions:

- `JwtGuard::getToken()` checks one coroutine key before parsing request.
- `JwtGuard::user()` checks subject hash only when `lock_subject` is enabled and provider has `getModel()`.
- Auth events use `hasListeners()` before constructing events.
- Parser chain loops over a small list of stateless parsers.
- `JwtSubject` check is a cheap `instanceof`.

Avoided overhead:

- no `Payload` object
- no `Token` object
- no per-claim DTOs
- no mutable singleton parser/request object
- no always-on `jti` generation when blacklist is disabled
- no blacklist cache lookup when blacklist is disabled

Worker-lifetime caching:

- subject model hash map in `ClaimFactory`
- existing validation instances in `JwtManager`
- existing provider signer/config caching in `Lcobucci`

Coroutine state:

- current token override
- current user/null sentinel
- last attempted user
- per-call custom claims
- per-call TTL
- decoded payload cache

This is the cleanest performance shape: immutable metadata is cached for the worker lifetime; request/per-call state stays in `CoroutineContext`; feature checks are cheap; optional cache I/O only happens when blacklist is enabled.

## Final State Checklist

- `attempt()` returns token string or false.
- `validate()` remains boolean.
- `login()` sets current token and current user.
- `once()` and `onceUsingId()` do not mint tokens.
- `claims()` is one-token-only.
- per-guard TTL works, including explicit null.
- per-call TTL is coroutine-local.
- subject locking prevents cross-provider id collisions.
- `JwtSubject` works but is not required.
- refresh works for expired tokens inside refresh window.
- `refresh_iat` works.
- `resetClaims` works.
- logout works with blacklist disabled and invalidates when enabled.
- events are guarded with `hasListeners()`.
- parser is stateless.
- commands exist.
- middleware aliases exist.
- `NotBeforeClaim` is spelled correctly everywhere.
- README has upstream attribution and real docs.
- intentional upstream omissions are recorded concisely.
- tests cover behavior and coroutine isolation.
- `composer fix` is green.

## Addendum: Tymon Follow-Up Review

After the main implementation plan was written, Tymon's current `tymon/jwt-auth` package was reviewed as an additional source:

- `/tmp/claude-20000/-home-binaryfire-workspace-monorepo/eeff203a-4261-4d58-80c6-bab17f200296/scratchpad/jwt-auth-tymon`

Fold these final corrections into the implementation. This addendum supersedes the earlier middleware-related plan items, including the "Add Middleware" section, middleware test plan, implementation-order middleware references, and final checklist item that says middleware aliases exist.

### Remove Sliding Refresh Middleware

Delete the sliding-token middleware and do not register `jwt.refresh` or `jwt.renew` aliases:

- `src/jwt/src/Http/Middleware/RefreshToken.php`
- `src/jwt/src/Http/Middleware/AuthenticateAndRenew.php`
- `tests/Jwt/Http/Middleware/RefreshTokenTest.php`
- `tests/Jwt/Http/Middleware/AuthenticateAndRenewTest.php`

Why:

- Route protection already belongs to Hypervel's normal auth middleware plus the JWT guard.
- The useful refresh primitive is `Auth::guard('api')->refresh()`.
- Refreshing on every request turns normal traffic into token rotation traffic, requires blacklist writes for each request using the middleware, creates concurrent-request races, and forces clients to replace their stored token from every response header.

Document the explicit refresh endpoint pattern in `src/boost/docs/jwt.md` instead. The refresh route must not be protected with `auth:api`, because normal auth validation rejects expired access tokens before `JwtGuard::refresh()` can run its refresh-window validation.

```php
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Support\Facades\Auth;

Route::post('/token/refresh', function () {
    try {
        $token = Auth::guard('api')->refresh();
    } catch (JwtException) {
        abort(401, 'Token cannot be refreshed.');
    }

    abort_if($token === null, 401, 'No token provided.');

    return response()->json(['token' => $token]);
});
```

Keep `blacklist_grace_period`; explicit refresh can still race when clients submit concurrent refresh requests or make concurrent requests around refresh time.

### Lazily Resolve Blacklist Storage and Fail Fast When Enabled

`jwt.blacklist_enabled` defaults to `false`. When it is disabled, `JwtManager` should not resolve `BlacklistContract` at all:

```php
$this->blacklistEnabled = $this->config->boolean('jwt.blacklist_enabled', false);
$this->blacklist = $this->blacklistEnabled
    ? $container->make(BlacklistContract::class)
    : null;
```

Keep the existing nullable blacklist property and the existing `$this->blacklistEnabled &&` guards before blacklist use.

The default blacklist storage remains `Hypervel\Jwt\Storage\TaggedCache`. This matches the old Hypervel 0.3 package and gives blacklist entries an isolated tag so `clear()` flushes only JWT blacklist entries.

Add a fail-fast check in the `BlacklistContract` binding, not inside `TaggedCache`, so disabled-blacklist applications with non-taggable default cache stores do not fail during manager construction:

```php
$repository = $app->make('cache')->store();

if ($config->boolean('jwt.blacklist_enabled', false) && ! $repository->supportsTags()) {
    throw new RuntimeException(
        'The JWT blacklist requires a taggable cache store. Use a taggable store or set a custom jwt.providers.storage.'
    );
}

$storage = new TaggedCache($repository);
```

Only run this check when `jwt.providers.storage` is `TaggedCache::class`; custom storage providers are responsible for their own storage requirements.

### Use the Current Lcobucci Validation API

`lcobucci/jwt` 5.6.0 deprecates `Configuration::setValidationConstraints()`. Use `withValidationConstraints()` directly with no compatibility shim:

```php
return $config->withValidationConstraints(
    new SignedWith($this->signer, $this->getVerificationKey())
);
```

This keeps `buildConfig()` immutable and preserves `onConfigurationChanged()`, which assigns the returned configuration instance back onto the provider.

### Round Blacklist TTL Up

Carbon 3 returns fractional minute differences. Blacklist entries must not expire fractionally early, so round the TTL up:

```php
return (int) ceil(abs(
    $exp->max($iat->addMinutes($this->refreshTTL))
        ->addMinute()
        ->diffInMinutes()
));
```

### Do Not Add Mutable Header / Prefix Parser Configuration

Do not port Tymon's `AuthHeaders::setHeaderName()` or `setHeaderPrefix()` methods.

Why:

- `Authorization: Bearer` is the standard JWT request header.
- `AuthHeaders` stays stateless and safe as a worker-lifetime singleton.
- Applications that need a custom header or scheme can add a custom `TokenExtractor` class to `jwt.parser`.

### Addendum Test Coverage

Add or update tests for:

- `JwtManager` does not resolve `BlacklistContract` when `jwt.blacklist_enabled` is false.
- disabled blacklist with a non-taggable default cache store does not throw.
- enabled blacklist with `TaggedCache` and a non-taggable cache store throws a clear exception.
- enabled blacklist with `TaggedCache` and a taggable cache store works.
- custom blacklist storage bypasses the tag-support check.
- blacklist TTL uses ceiling rounding for fractional minute differences.
- Lcobucci signed-token validation still works after switching to `withValidationConstraints()`.
- `JwtServiceProvider` no longer registers `jwt.refresh` or `jwt.renew` aliases.
