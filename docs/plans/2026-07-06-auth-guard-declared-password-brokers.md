# Guard-Declared Password Brokers

Make the current auth guard the single source of password broker defaulting. A guard that participates in password resets declares its broker with a `passwords` config key. `auth.defaults.passwords` is removed. Fortify's provider-inference is deleted. The reset email's expiry text comes from the broker that actually created the token.

## Background

### The asymmetry

Hypervel's default **guard** is per-request switchable: `AuthManager::setDefaultDriver()` writes to coroutine context (`src/auth/src/AuthManager.php:194-197`), `getDefaultDriver()` reads context before falling back to `auth.defaults.guard` (`AuthManager.php:168-176`), and both the stock `auth` middleware (`src/auth/src/Middleware/Authenticate.php:63`) and the `auth.guard` alias (`src/auth/src/Middleware/UseGuard.php`, aliased in `src/foundation/src/Configuration/Middleware.php:632`) trigger it via `shouldUse()`.

The default **broker** is not: `PasswordBrokerManager::getDefaultDriver()` reads `auth.defaults.passwords` straight from process-global config (`src/auth/src/Passwords/PasswordBrokerManager.php:107-110`), and `setDefaultDriver()` mutates process-global config with a `Boot-only.` warning (`PasswordBrokerManager.php:117-120`) because per-request config mutation races across coroutines.

In stock Laravel (process-per-request), multi-guard apps work around this with per-request `config(['auth.defaults.passwords' => ...])` mutation in middleware. That escape hatch cannot exist under Swoole, so Hypervel lost a capability Laravel effectively has. The consequence: in a multi-guard app, a route group can select its guard once (`auth.guard:staff`) and every unparameterized auth call follows — except password flows. A bare `Password::sendResetLink()` on a member-facing site silently looks up emails and writes reset tokens against whatever table `auth.defaults.passwords` points at, which is a wrong-table security hazard triggered by nothing more than a forgotten parameter.

### Why guard → broker cannot be inferred

Guards and brokers each reference a provider. The relation runs through the provider and is not one-to-one: several guards share one provider, token-style guards have no broker, and nothing stops two brokers from pointing at one provider. The Fortify port worked around this with provider matching — `Fortify::passwordBrokerName()` (`src/fortify/src/Fortify.php:377-400`) scans `auth.passwords` for brokers whose `provider` matches the current guard's provider and throws unless exactly one matches. That inference is config-shape-sensitive: adding an unrelated broker entry for a shared provider changes (breaks) the resolution of an existing guard. The fix is to make the relation declared, not derived.

### Design consensus

The design went through multi-round adversarial review (Claude/Codex, 2026-07-06) with the project owner arbitrating. Final agreed model, in one sentence:

> A broker is reached through the current guard's `passwords` key, or by explicit name — anything else is a config error.

Rejected alternatives, and why:

1. **`shouldUse()` pushes the broker into context.** Makes the guard key a conditional side-effect that only applies when `shouldUse()` runs; console/queue contexts would ignore it. Rejected for lazy derivation at lookup time, which applies the same rule in every context.
2. **Provider-match inference in the core resolver.** The resolution of bare calls would depend on the global shape of `auth.passwords` (see above). Rejected: only explicit declarations may drive defaulting.
3. **Keeping Fortify's provider inference as a fallback behind the key.** Two resolution rules in one framework, more ways to make mistakes in security-sensitive code. The inference was a workaround for the key not existing; with the key it is dead weight. Deleted.
4. **Keeping `auth.defaults.passwords` as a final fallback.** A second defaulting root that can silently disagree with the guard key, kept only for parity. There is no genuine "no guard" context — commands, queues, and tests always have `auth.defaults.guard`. Removed; the failure mode becomes a loud, self-explaining config exception instead of a silent send through the wrong broker.
5. **Deferring the reset-email expiry fix.** The notification reads the expiry from config-default broker state today (`src/auth/src/Notifications/ResetPassword.php:67`), so the emailed "expires in N minutes" can be wrong whenever the sending broker differs. Owner expanded scope: broker-name propagation ships in this PR so the number is right in every path, queued included.

Backward compatibility is explicitly not a goal (0.4 is unreleased). The final codebase must read as if designed this way from the start: no deprecated fallbacks, no dual sources, no leftover inference code or docs.

## Final Behavior

```php
// config/auth.php (framework base config, inherited by every app)
'defaults' => [
    'guard' => env('AUTH_GUARD', 'web'),
],

'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
        'passwords' => 'users',
    ],
],
```

- `Password::broker('users')` — explicit, unchanged.
- `Password::broker()` / bare `Password::sendResetLink()` / `Password::reset()` — resolve the default broker as: coroutine-context override → current default guard's `passwords` key → throw `InvalidArgumentException` with the exact fix in the message.
- The "current default guard" is `AuthManager::getDefaultDriver()` — coroutine context when `Auth::shouldUse()` / `auth.guard:x` / `auth:x` ran, `auth.defaults.guard` otherwise (commands, queues, tests).
- `Password::setDefaultDriver('x')` — coroutine-scoped override for the current request/coroutine only.
- Fortify password controllers use the same resolution (they call `Password::broker()` bare). `Fortify::passwordBrokerName()` is deleted.
- The reset email's expiry minutes come from the broker that created the token, captured at notification construction time so it survives queueing.
- Guards that never send resets (the skeleton's `sanctum` and `jwt` entries) simply omit the key. They only ever hit the exception if an app actually sends a bare reset while such a guard is the default — and the exception says what to add.

## Implementation

All work happens in `contrib/hypervel/components`. Files are listed in implementation order. Every changed file gets `declare(strict_types=1);` preserved and follows AGENTS.md rules (method docblocks, no FQCNs, `->make()` not array access, method placement near related methods).

### 1. `src/contracts/src/Auth/PasswordBrokerFactory.php`

Add the resolver to the contract — it is public API for userland multi-guard code, and typed consumers must be able to reach it through the contract:

```php
interface PasswordBrokerFactory
{
    /**
     * Get a password broker instance by name.
     */
    public function broker(?string $name = null): PasswordBroker;

    /**
     * Resolve the password broker name declared by the given guard.
     */
    public function resolveBrokerNameForGuard(string $guard): ?string;
}
```

### 2. `src/auth/src/Passwords/PasswordBrokerManager.php`

Full revised class body (imports: add `Hypervel\Context\CoroutineContext`, `Hypervel\Contracts\Auth\Factory as AuthFactoryContract`):

```php
class PasswordBrokerManager implements FactoryContract
{
    /**
     * The coroutine context key holding the per-request default broker override.
     */
    public const string DEFAULT_BROKER_CONTEXT_KEY = '__auth.passwords.default_broker';

    /**
     * The array of created "drivers".
     */
    protected array $brokers = [];

    /**
     * Create a new PasswordBroker manager instance.
     */
    public function __construct(
        protected Container $app,
    ) {
    }

    /**
     * Attempt to get the broker from the local cache.
     */
    public function broker(?string $name = null): PasswordBrokerContract
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->brokers[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given broker.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): PasswordBrokerContract
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Password resetter [{$name}] is not defined.");
        }

        // The password broker uses a token repository to validate tokens and send user
        // password e-mails, as well as validating that password reset process as an
        // aggregate service of sorts providing a convenient interface for resets.
        return new PasswordBroker(
            $this->createTokenRepository($config),
            $this->app->make('auth')->createUserProvider($config['provider'] ?? null),
            $name,
            $this->app->bound('events') ? $this->app->make('events') : null,
            timeboxDuration: $this->app->make('config')->integer('auth.timebox_duration', 200000),
        );
    }

    // createTokenRepository(): unchanged logic, but replace $this->app['cache'],
    // $this->app['hash'], $this->app['db'] with $this->app->make('cache'),
    // $this->app->make('hash'), $this->app->make('db').

    // getConfig(): unchanged.

    /**
     * Resolve the password broker name declared by the given guard.
     */
    public function resolveBrokerNameForGuard(string $guard): ?string
    {
        $config = $this->app->make('config');
        $key = "auth.guards.{$guard}.passwords";

        if (! $config->has($key)) {
            return null;
        }

        $name = $config->string($key);

        return $name !== '' ? $name : null;
    }

    /**
     * Get the default password broker name.
     *
     * Resolves the coroutine-scoped override first, then the broker declared
     * by the current default guard's "passwords" key.
     *
     * @throws InvalidArgumentException when the current default guard does not declare a broker
     */
    public function getDefaultDriver(): string
    {
        if (CoroutineContext::has(self::DEFAULT_BROKER_CONTEXT_KEY)) {
            return CoroutineContext::get(self::DEFAULT_BROKER_CONTEXT_KEY);
        }

        $guard = $this->app->make(AuthFactoryContract::class)->getDefaultDriver();

        if ($name = $this->resolveBrokerNameForGuard($guard)) {
            return $name;
        }

        throw new InvalidArgumentException(
            "Auth guard [{$guard}] does not declare a passwords broker. Set auth.guards.{$guard}.passwords."
        );
    }

    /**
     * Set the default password broker name.
     *
     * Uses coroutine Context so one request's override doesn't affect others.
     */
    public function setDefaultDriver(string $name): void
    {
        CoroutineContext::set(self::DEFAULT_BROKER_CONTEXT_KEY, $name);
    }

    // __call(): unchanged.
}
```

Notes:

- `getDefaultDriver()` resolving through `make(AuthFactoryContract::class)` is cycle-free: `AuthManager::getDefaultDriver()` reads only coroutine context and config.
- The exception is `InvalidArgumentException`, matching the existing `"Password resetter [x] is not defined."` error class in `resolve()`.
- `resolveBrokerNameForGuard()` returns declared names verbatim without validating broker existence — `broker()`/`resolve()` keeps producing the standard missing-resetter error. The resolver selects names; it does not construct brokers.
- `resolveBrokerNameForGuard()` uses typed config per repo rules: an absent key resolves to `null`; a present key is read with `$config->string()`, so malformed values (explicit `null`, int, array) fail fast with the config repository's `InvalidArgumentException` instead of masquerading as "no key". An empty string resolves to `null` (declared-nothing). Tests pin all of this.
- `$this->app->bound('events')` replaces the current `$this->app['events'] ?? null`, matching the framework's optional-events pattern (`src/database/src/DatabaseManager.php:208`).
- Constant placement: top of class, before properties. Method placement: `resolveBrokerNameForGuard()` directly above `getDefaultDriver()`, which stays with `setDefaultDriver()` where they are today.

### 3. `src/auth/src/Passwords/PasswordBroker.php`

The broker learns its own name and stamps it into coroutine context for the duration of `sendResetLink()`, so anything constructed inside — the stock notification, custom notifications from userland `sendPasswordResetNotification()` overrides, and `$callback` bodies — can know which broker is sending. Imports: add `Hypervel\Context\CoroutineContext`.

```php
class PasswordBroker implements PasswordBrokerContract
{
    /**
     * The coroutine context key holding the broker name currently sending a reset link.
     */
    public const string SENDING_BROKER_CONTEXT_KEY = '__auth.passwords.sending_broker';

    /**
     * The timebox instance.
     */
    protected Timebox $timebox;

    /**
     * Create a new password broker instance.
     *
     * @param int $timeboxDuration the number of microseconds that the timebox should wait for
     */
    public function __construct(
        #[SensitiveParameter]
        protected TokenRepositoryInterface $tokens,
        protected UserProvider $users,
        protected string $name,
        protected ?Dispatcher $events = null,
        ?Timebox $timebox = null,
        protected int $timeboxDuration = 200000,
    ) {
        $this->timebox = $timebox ?: new Timebox;
    }

    /**
     * Send a password reset link to a user.
     */
    public function sendResetLink(#[SensitiveParameter] array $credentials, ?Closure $callback = null): string
    {
        return $this->timebox->call(function () use ($credentials, $callback) {
            // ... existing body unchanged up to and including:
            $token = $this->tokens->create($user);

            $hadPrevious = CoroutineContext::has(self::SENDING_BROKER_CONTEXT_KEY);
            $previous = CoroutineContext::get(self::SENDING_BROKER_CONTEXT_KEY);
            CoroutineContext::set(self::SENDING_BROKER_CONTEXT_KEY, $this->name);

            try {
                if ($callback) {
                    return $callback($user, $token) ?? static::RESET_LINK_SENT;
                }

                // Once we have the reset token, we are ready to send the message out to this
                // user with a link to reset their password. We will then redirect back to
                // the current URI having nothing set in the session to indicate errors.
                $user->sendPasswordResetNotification($token);
            } finally {
                $hadPrevious
                    ? CoroutineContext::set(self::SENDING_BROKER_CONTEXT_KEY, $previous)
                    : CoroutineContext::forget(self::SENDING_BROKER_CONTEXT_KEY);
            }

            $this->events?->dispatch(new PasswordResetLinkSent($user));

            return static::RESET_LINK_SENT;
        }, $this->timeboxDuration);
    }

    // Everything else unchanged.
}
```

Notes:

- `$name` is the third constructor parameter, before the optional parameters — a required identity property, positioned with the other required collaborators. The manager passes it positionally (step 2).
- Save/restore (rather than plain destroy) keeps nested sends correct — e.g. a `sendResetLink()` callback that triggers another broker's send.
- `reset()` sends no notification and needs no stamping.

### 4. `src/auth/src/Notifications/ResetPassword.php`

Capture the expiry at construction time from the broker that is actually sending. Construction happens synchronously inside `sendResetLink()` (via `CanResetPassword::sendPasswordResetNotification()`, `src/auth/src/Passwords/CanResetPassword.php:28-31`) while the context stamp is present; storing minutes in a property makes the value survive notification queueing, and freezes it at the moment the token was created — which is when it is true. Imports: add `Hypervel\Auth\Passwords\PasswordBroker`, `Hypervel\Context\CoroutineContext`, `Hypervel\Context\ApplicationContext` is not used — resolve via `Hypervel\Container\Container::getInstance()`… (see note below; the class already uses the `config()` helper and `Lang` facade, so match file style with the `Password` facade).

```php
use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Support\Facades\Password;

class ResetPassword extends Notification
{
    // ... static callbacks unchanged ...

    /**
     * The number of minutes until the reset token expires.
     */
    public int $expireMinutes;

    /**
     * Create a notification instance.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
    ) {
        $this->expireMinutes = $this->resolveExpireMinutes();
    }

    // via(), toMail() unchanged.

    /**
     * Get the reset password notification mail message for the given URL.
     */
    protected function buildMailMessage(string $url): MailMessage
    {
        return (new MailMessage)
            ->subject(Lang::get('Reset your password'))
            ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
            ->action(Lang::get('Reset Password'), $url)
            ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => $this->expireMinutes]))
            ->line(Lang::get('If you did not request a password reset, no further action is required.'));
    }

    /**
     * Resolve the expiry minutes from the broker sending this notification.
     *
     * Reads the sending broker stamped by PasswordBroker::sendResetLink();
     * outside a send flow (tests, previews) the current default broker applies.
     */
    protected function resolveExpireMinutes(): int
    {
        $broker = CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY)
            ?? Password::getDefaultDriver();

        return Container::getInstance()->make(ConfigContract::class)
            ->integer("auth.passwords.{$broker}.expire", 60);
    }

    // resetUrl(), createUrlUsing(), toMailUsing(), flushState() unchanged.
}
```

Notes:

- Method placement: `resolveExpireMinutes()` directly after `buildMailMessage()`, its only caller's neighbor.
- The direct `config('auth.defaults.passwords')` read at the current line 67 disappears with this change — after this PR nothing in the repo reads that key (verified inventory below).
- The expire value is read through the config repository's typed `integer()` (fail-fast on malformed config) rather than a cast; constructor injection is impossible in a notification constructed by userland traits, so the repository is resolved via `Container::getInstance()`. The `Password` facade fallback matches this file's existing facade style (`Lang`).
- The fallback path (`Password::getDefaultDriver()`) can throw in an app whose default guard declares no broker when a `ResetPassword` is constructed outside any send flow — correct fail-fast; inside send flows the stamp always wins.

### 5. `src/support/src/Facades/Password.php`

Docblock updates only:

```php
 * @method static \Hypervel\Contracts\Auth\PasswordBroker broker(string|null $name = null)
 * @method static string|null resolveBrokerNameForGuard(string $guard)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
```

(insert `resolveBrokerNameForGuard` after `broker`; the rest of the docblock is unchanged.)

### 6. `src/fortify/src/Fortify.php`

Delete `passwordBrokerName()` (`Fortify.php:377-400`, docblock included) entirely. Remove the `RuntimeException` import only if `guard()` (which also throws it, `Fortify.php:363-371`) were removed — it is not, so the import stays. No replacement method: with the core default now guard-derived, `Password::broker()` bare *is* the key-only rule, including honoring a per-request `Password::setDefaultDriver()` override, which is correct ambient-context behavior.

### 7. Fortify password controllers

`src/fortify/src/Http/Controllers/NewPasswordController.php:77-82`, `PasswordController.php:50-55`, `PasswordResetLinkController.php:56-61` — each has:

```php
    protected function broker(): PasswordBroker
    {
        return Password::broker(Fortify::passwordBrokerName());
    }
```

becomes:

```php
    protected function broker(): PasswordBroker
    {
        return Password::broker();
    }
```

Import cleanup, verified per file: `PasswordController`'s only `Fortify::` reference is the deleted broker line, so its `use Hypervel\Fortify\Fortify;` import is removed. `NewPasswordController` (`Fortify::email()`, `Fortify::guard()`) and `PasswordResetLinkController` (`Fortify::email()`) keep the import.

### 8. `src/foundation/config/auth.php`

This is the framework base config merged under every app's config (`src/foundation/src/Bootstrap/LoadConfiguration.php:103`), so fresh and minimally-configured apps inherit correct behavior automatically.

- `defaults` block (lines 17-20): remove the `passwords` entry and the `AUTH_PASSWORD_BROKER` env var with it; update the section comment to mention only the guard:

```php
    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" for your
    | application. You may change this value as required, but it's a
    | perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],
```

- `web` guard gains its broker; `sanctum` and `jwt` intentionally do not (token guards do not send session-style reset links by default; an app that sends resets while such a guard is the default declares the key — the exception message says exactly that). Update the guards section comment to document the key:

```php
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
            'passwords' => 'users',
        ],
        // sanctum, jwt: unchanged, no passwords key.
    ],
```

  Comment addition to the guards section (after the existing provider paragraph): "Guards that send password reset links declare their broker with the `passwords` key, referencing an entry in the `passwords` array below."

- `passwords` section (line ~147): entries unchanged; extend the section comment's first paragraph with: "Guards reference these brokers via their `passwords` key."

`src/testbench/hypervel/config/auth.php` needs no change: it overrides only `providers.users` and inherits `defaults` and `guards` (including the new `web.passwords`) from the base config.

### 9. `src/auth/src/Console/ClearResetsCommand.php`

Behavior flows through automatically (no argument → `broker(null)` → `getDefaultDriver()` → current default guard's broker). Two touch-ups while in the file:

- `$this->hypervel['auth.password']` → `$this->hypervel->make('auth.password')` (container access rule).
- Signature help text updated to state the new default: `{name? : The name of the password broker (defaults to the current guard's broker)}`.

### 10. Documentation (`src/boost/docs/`)

Use Edit for targeted section changes; never rewrite whole files.

**`passwords.md`**
- After the "Configuration" intro (line ~25), add a short section stating the rule: "A guard that sends password resets declares its broker with the `passwords` key" with the `web` guard config example, and the resolution order for bare `Password::` calls (per-request override via `Password::setDefaultDriver()` → current guard's `passwords` key → config error).
- In the two "You may be wondering how Hypervel knows how to retrieve the user record" paragraphs (lines ~129 and ~195), append a sentence: the broker used by bare `Password::` calls is the one declared by the current guard's `passwords` key; pass a name to `Password::broker()` to target any other broker.

**`authentication.md`**
- In the guard configuration reference, document the `passwords` key alongside `driver` and `provider`, with the one-sentence rule and a note that multi-guard apps set it per guard so route groups (`auth.guard:staff`) make all password flows follow the site's actor type.

**`fortify.md`**
- Line 85 ("Password reset broker selection is also derived from the selected guard. Fortify reads the selected guard's provider and requires exactly one `auth.passwords` broker to use that provider…"): replace with "Password reset broker selection follows the selected guard: the guard declares its broker with the `passwords` key in `config/auth.php`."
- Lines 313-321 (the "Fortify derives the broker from the selected guard provider" steps and the throw-rather-than-guess note): replace the derivation steps with the key rule — resolve the current guard, read `auth.guards.{guard}.passwords`, and a missing key produces a configuration error naming the guard and the fix. Drop the multiple-providers-sharing-emails token-table caveat only if it no longer applies — it still applies (separate brokers still need separate token tables), so keep that paragraph.
- Multi-Guard Applications section (lines ~180-210): add the `passwords` key to the example guard config so the documented pattern is complete.

**`docs/plans/2026-07-01-fortify-passkeys-port.md`** — prepend a superseded note (Edit, never rewrite the historical body):

```md
> Superseded note: the Fortify password broker inference described in this historical plan was later removed by `2026-07-06-auth-guard-declared-password-brokers.md`. Guards now declare password brokers with `auth.guards.{guard}.passwords`; `Fortify::passwordBrokerName()` no longer exists.
```

This keeps the dated record intact while preventing a future grep of the plans directory from mistaking the old inference design for current guidance.

### 11. Verified inventory: nothing else reads the removed key

Repo-wide grep for `auth.defaults.passwords` (source, non-test) matches exactly: `PasswordBrokerManager::getDefaultDriver()`, `PasswordBrokerManager::setDefaultDriver()`, `ResetPassword.php:67`, `src/foundation/config/auth.php:19`. All four are changed by this plan. Repo-wide grep for `AUTH_PASSWORD_BROKER` matches only the config line being removed (no `.env` / `.env.example` occurrences). `src/boost/docs/*.md` contains zero occurrences of either.

Final verification greps (last implementation step), each expected to return nothing:

```bash
grep -rn "auth\.defaults\.passwords\|AUTH_PASSWORD_BROKER\|passwordBrokerName" src/ tests/ .env .env.example
```

Scope notes: clear the PHPStan result cache first (`rm -rf .cache/phpstan`) so stale analysis artifacts don't pollute the grep. `docs/plans/` is excluded by design — historical plan documents (e.g. `2026-07-01-fortify-passkeys-port.md`, which describes the provider inference it introduced at the time) are dated records of past work, not living documentation; rewriting them would falsify history. Living documentation (`src/boost/docs/`, config comments, READMEs) must contain zero stale references.

## Test Plan

Run each file immediately after writing it (`./vendor/bin/phpunit --no-progress <file>`), then `composer test:parallel` at the end. All from the repo root.

### `tests/Auth/AuthPasswordBrokerManagerTest.php` (extend)

Existing test (`testBrokerFailsFastWhenAppKeyIsNotConfigured`) is kept. New tests follow the file's existing style: a real `Hypervel\Container\Container` with `instance('config', new Repository([...]))`, plus `instance()`-bound mocks (`m::mock`) for `Hypervel\Contracts\Auth\Factory` where guard state matters. Coroutine context is real — the base `Hypervel\Tests\TestCase` runs each test in a fresh coroutine:

1. `testResolveBrokerNameForGuardReturnsDeclaredBroker` — guard config `['passwords' => 'staff']` → `'staff'`.
2. `testResolveBrokerNameForGuardReturnsNullWhenAbsent` — guard without the key → `null`.
3. `testResolveBrokerNameForGuardReturnsNullForEmptyString` — `passwords => ''` → `null`; and `testResolveBrokerNameForGuardFailsFastOnMalformedValues` — `passwords` set to explicit `null`, `123`, `['users']` → the config repository's `InvalidArgumentException` for each (typed `string()` read; malformed config never masquerades as "no key").
4. `testDefaultDriverResolvesFromCurrentDefaultGuard` — auth factory `getDefaultDriver()` returns `'web'`, guard declares `passwords => 'users'` → `getDefaultDriver()` returns `'users'`.
5. `testDefaultDriverThrowsWhenGuardDeclaresNoBroker` — expect `InvalidArgumentException` with message `Auth guard [web] does not declare a passwords broker. Set auth.guards.web.passwords.`.
6. `testSetDefaultDriverOverridesGuardDeclaration` — after `setDefaultDriver('other')`, `getDefaultDriver()` returns `'other'` without consulting the auth factory.
7. `testSetDefaultDriverIsCoroutineIsolated` — `parallel()` two coroutines (pattern per AGENTS.md: `usleep()` between mutation and read; existing reference: `tests/Auth/UseGuardMiddlewareTest.php`), each sets a different override, each reads back its own; a third un-overridden coroutine still resolves via the guard key.
8. `testBrokerWithExplicitNameBypassesDefaultResolution` — `broker('admins')` succeeds on a container where `'auth'` is bound (a mock expecting `createUserProvider()` — `resolve()` needs it to build the broker) but `Hypervel\Contracts\Auth\Factory` is not bound at all, proving explicit names never consult default-guard state (the two are distinct keys on a bare container).

### `tests/Auth/AuthPasswordBrokerTest.php` (extend)

Constructor fallout — six construction sites, not one: `getBroker()` at line 130 (`new PasswordBroker(...)`) plus the five partial mocks passing `array_values($mocks)` as constructor args at lines 23, 32, 62, 95, and 117. Fix once at the source: `getMocks()` gains `'name' => 'users'` as its third entry, so `array_values($mocks)` yields `(tokens, users, name)` in constructor order and all five partial-mock sites need no edit; `getBroker()` becomes `new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'])`. While touching the file, add `: void` return types to all existing test methods (repo testing rule; the file currently has none).

New tests:

9. `testSendResetLinkStampsSendingBrokerContext` — user mock's `sendPasswordResetNotification` asserts `CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY) === 'users'` while called.
10. `testSendResetLinkRestoresPreviousSendingBrokerContext` — pre-set the key to `'outer'`, run `sendResetLink`, assert the key is `'outer'` after; and `testSendResetLinkForgetsSendingBrokerContextWhenNoneExisted` — with no pre-set value, assert `CoroutineContext::has(...) === false` after.
11. `testSendResetLinkStampsContextForCallback` — the `$callback` variant observes the stamped name.

### `tests/Auth/ResetPasswordNotificationTest.php` (new file)

Tests for the notification. `resolveExpireMinutes()` uses the `Password` facade for the fallback broker name and the config repository's typed `integer()` method for expiry, so the base is `Hypervel\Testbench\TestCase` (real container, config, facades), with `#[WithConfig('auth.passwords.admins', ['provider' => 'users', 'table' => 'admin_password_reset_tokens', 'expire' => 15])]` seeding the second broker (the base config already provides `auth.passwords.users` with `expire = 60`):

12. `testExpiryIsCapturedFromSendingBrokerContext` — stamp `admins`, construct, assert `expireMinutes === 15` and the built mail line contains `15`.
13. `testExpiryFallsBackToDefaultBrokerOutsideSendFlow` — no stamp, auth default guard `web` with `passwords => 'users'` → `expireMinutes === 60`.
14. `testExpirySurvivesSerialization` — construct with stamp `admins`, `unserialize(serialize($notification))`, forget the sending-broker context key, build mail → still `15` (queued-notification guarantee).

### `tests/Fortify/FortifyStaticStateTest.php` (rewrite broker tests)

`testPasswordBrokerIsDerivedFromCurrentGuardProvider` and `testPasswordBrokerInferenceFailsWhenProviderMappingIsAmbiguous` (`FortifyStaticStateTest.php:29-52`) are replaced — they test deleted inference. Replacements (in a Fortify test file or moved to an integration-flavored auth test if more natural — keep in place, the Fortify TestCase already has multi-guard config):

15. `testPasswordBrokerFollowsGuardDeclaration` — `shouldUse('web')` → `Password::getDefaultDriver() === 'users'`; `shouldUse('admin')` → `'admins'`.
16. `testPasswordBrokerThrowsWhenGuardDeclaresNone` — a guard configured without the key + `shouldUse()` → `Password::broker()` throws `InvalidArgumentException` naming the guard.

### `tests/Fortify/TestCase.php` (config update)

Guard entries gain their keys (`tests/Fortify/TestCase.php:44-45`): `auth.guards.web` += `'passwords' => 'users'`, `auth.guards.admin` += `'passwords' => 'admins'`. This also carries the existing Fortify controller tests (`NewPasswordControllerTest`, `PasswordResetLinkControllerTest`, `PasswordControllerTest`), which now resolve brokers through the key.

### `tests/Auth/ClearResetsCommandTest.php` (new file)

The command file is being touched (container access, signature text) and currently has zero coverage. `Hypervel\Testbench\TestCase` base; swap the manager with a mock (`$this->app->instance('auth.password', $mock)`); the mock's `broker()` returns a broker mock whose `getRepository()->deleteExpired()` is expected once:

17. `testClearsExpiredTokensThroughDefaultBroker` — `artisan('auth:clear-resets')` calls `broker(null)`.
18. `testClearsExpiredTokensThroughNamedBroker` — `artisan('auth:clear-resets', ['name' => 'admins'])` calls `broker('admins')`.

### Full suite + static analysis

- `composer test:parallel` from the repo root; investigate every failure (expected fallout: any test that relied on `auth.defaults.passwords` or the old constructor signature).
- `./vendor/bin/phpstan` (no flags) — new code must pass; the deleted Fortify method removes its `RuntimeException` path from analysis.
- `./vendor/bin/php-cs-fixer fix` (no flags) before finalizing.

## Execution Order

1. Contract (`PasswordBrokerFactory`) + `PasswordBrokerManager` + facade docblock; run manager tests.
2. `src/foundation/config/auth.php` — the base config must change before any Testbench-based test runs, so the default `web` guard carries `passwords => 'users'` and the notification/Fortify tests exercise the real default app shape.
3. `PasswordBroker` name/stamping; run broker tests.
4. `ResetPassword` notification; run new notification tests.
5. Fortify: delete `passwordBrokerName()`, simplify controllers, update Fortify TestCase config; run `tests/Fortify/`.
6. `ClearResetsCommand` + its new test.
7. Docs (`passwords.md`, `authentication.md`, `fortify.md`, superseded note on the passkeys plan).
8. Final greps per the inventory section (`auth.defaults.passwords`, `AUTH_PASSWORD_BROKER`, `passwordBrokerName` over `src/`, `tests/`, `.env*` after clearing the PHPStan cache) proving zero stragglers.
9. `composer test:parallel`, phpstan, cs-fixer.

## Explicitly Unchanged

- `AuthManager` — already correct; it is the pattern being mirrored. One deliberate asymmetry: its `getDefaultDriver()` uses a truthy `get()` check on the context key where the broker manager uses `has()` + `get()`. For guards this is behaviorally equivalent — `shouldUse()` normalizes empty names to the config default before storing (`$name = $name ?: $this->getDefaultDriver()`), so an empty-string context value cannot exist through any public path. No change needed there. Follow-up (bot-review round): `AuthManager` was subsequently aligned after all — `guard()` / `shouldUse()` use null-only defaulting and `getDefaultDriver()` uses a `has()`-based context read, so this rationale is superseded.
- `UseGuard` / `Authenticate` middleware — already trigger `shouldUse()`; the broker now follows for free.
- `PasswordBroker::reset()` and token repositories — actual token expiry enforcement was always broker-correct; only the email text was wrong.
- No BC shims anywhere: a leftover `auth.defaults.passwords` in an app's config is inert (ordinary unused config key), never read, never documented, no deprecation path.
