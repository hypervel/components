# Fortify And Passkeys Port Plan

Date: 2026-07-01

Author: Codex

Scope: port Laravel Fortify and Laravel Passkeys Server into the Hypervel components monorepo, with Swoole coroutine safety, worker-lifetime performance, clean Hypervel-native APIs, full tests, and updated Boost documentation.

## Source Material Reviewed

Project instructions:

- Monorepo root `CLAUDE.md`, in full.
- Component repo `AGENTS.md`, in full.

Laravel reference packages:

- `examples/laravel/docs/fortify.md`, in full.
- `examples/laravel/fortify/composer.json`.
- `examples/laravel/fortify/config/fortify.php`.
- `examples/laravel/fortify/routes/routes.php`.
- `examples/laravel/fortify/src/Fortify.php`.
- `examples/laravel/fortify/src/Features.php`.
- `examples/laravel/fortify/src/FortifyServiceProvider.php`.
- `examples/laravel/fortify/src/TwoFactorAuthenticationProvider.php`.
- `examples/laravel/fortify/src/TwoFactorAuthenticatable.php`.
- Fortify controllers, requests, responses, contracts, actions, events, stubs, migrations, and tests.
- `examples/laravel/passkeys-server/composer.json`.
- `examples/laravel/passkeys-server/config/passkeys.php`.
- `examples/laravel/passkeys-server/routes/routes.php`.
- `examples/laravel/passkeys-server/database/migrations/2024_01_01_000000_create_passkeys_table.php`.
- `examples/laravel/passkeys-server/src/Passkeys.php`.
- `examples/laravel/passkeys-server/src/PasskeysServiceProvider.php`.
- `examples/laravel/passkeys-server/src/Passkey.php`.
- `examples/laravel/passkeys-server/src/PasskeyAuthenticatable.php`.
- `examples/laravel/passkeys-server/src/Actions/*`.
- `examples/laravel/passkeys-server/src/Http/*`.
- `examples/laravel/passkeys-server/src/Support/WebAuthn.php`.
- `examples/laravel/passkeys-server/src/Support/Aaguids.php`.
- Passkeys tests in `examples/laravel/passkeys-server/tests`.

Additional reference package:

- `spatie/laravel-passkeys`, cloned to `/tmp/spatie-laravel-passkeys` on 2026-07-01 at `b9bb941`.
- Useful ideas from Spatie: explicit WebAuthn 5.3 API hygiene around relying-party entity construction, tests that fail on WebAuthn deprecations, and an extension point for ceremony-step manager configuration.
- Ideas not copied from Spatie: Livewire/UI surface, config-created action objects, single-model `authenticatable_id` schema, per-call serializer construction, and unlocked credential counter updates.
- `laravel/passkeys` frontend package, cloned to `/tmp/laravel-passkeys-frontend` on 2026-07-01 at `e37c46f`, package `@laravel/passkeys` `0.2.0`.
- Frontend compatibility findings: the client is framework-agnostic, uses `@simplewebauthn/browser`, sends JSON requests to the documented Laravel route paths, supports per-call route overrides, reads CSRF from a `csrf-token` meta tag or `XSRF-TOKEN` cookie, and expects JSON response envelopes documented in the Passkeys source plan.

Hypervel reference code:

- Root `composer.json`.
- Existing package manifests, especially `src/sanctum/composer.json` and `src/inertia/composer.json`.
- Existing provider style, especially `src/sanctum/src/SanctumServiceProvider.php`.
- `tests/AfterEachTestSubscriber.php`.
- `src/config/src/Repository.php`.
- `src/database/src/UniqueConstraintViolationException.php`.
- Container support for `bind`, `singleton`, and `scoped`.
- Boost docs layout in `src/boost/docs`.

Verified Hypervel API targets:

- Controllers should extend `Hypervel\Routing\Controller`.
- Login pipelines should use `Hypervel\Routing\Pipeline`.
- Response contracts should extend `Hypervel\Contracts\Support\Responsable`, whose `toResponse(Hypervel\Http\Request $request)` returns `Symfony\Component\HttpFoundation\Response`.
- `Hypervel\Support\ServiceProvider::publishesMigrations()` exists and should be called directly.
- `Hypervel\Support\ServiceProvider::addProviderToBootstrapFile()` exists and should be called directly by the install command.
- `rehashPasswordIfRequired()` exists on Hypervel user providers and should be called directly by two-factor redirect authentication when `hashing.rehash_on_login` is enabled.
- `Hypervel\Foundation\Http\FormRequest::failOnUnknownFields()` exists and `flushState()` resets it.
- `Hypervel\Container\Container::forgetScopedInstances()` exists for request/coroutine scoped cleanup.
- `Hypervel\Database\Eloquent\Model::flushState()` resets Eloquent's static encrypter, so no Fortify-specific model-encrypter cleanup hook is needed.
- `Hypervel\Database\Eloquent\Model::bootTraits()` invokes conventional `boot{TraitName}` methods, so `bootPasskeyAuthenticatable()` is supported.
- `Hypervel\Database\Eloquent\Model::delete()` fires the `deleting` model event for instance deletes, and `Hypervel\Database\Eloquent\SoftDeletes::isForceDeleting()` exists.
- `Hypervel\Auth\GuardHelpers::getProvider()` and `Hypervel\Auth\EloquentUserProvider::getModel()` exist, so passkey login can derive the selected Eloquent guard provider model for owner-type checks.

Current third-party dependency metadata was checked with Composer on 2026-07-01 from the component repo:

```bash
composer show web-auth/webauthn-lib --all
composer show bacon/bacon-qr-code --all
composer show pragmarx/google2fa --all
composer show paragonie/constant_time_encoding --all
composer show symfony/serializer --all
composer show web-auth/cose-lib --all
composer require --dry-run web-auth/webauthn-lib web-auth/cose-lib bacon/bacon-qr-code pragmarx/google2fa paragonie/constant_time_encoding symfony/serializer
```

Composer dry-run resolved these newest stable compatible versions under Hypervel's current PHP 8.4 and Symfony 8 constraints:

- `web-auth/webauthn-lib` `5.3.5`, constraint `^5.3`.
- `web-auth/cose-lib` `4.5.2`, constraint `^4.5`.
- `bacon/bacon-qr-code` `v3.1.1`, constraint `^3.1`.
- `pragmarx/google2fa` `v9.0.0`, constraint `^9.0`.
- `paragonie/constant_time_encoding` `v3.1.3`, constraint `^3.1`.
- `symfony/serializer` `v8.1.1`, constraint `^8.1`.

Packagist also shows development branches `web-auth/webauthn-lib` `5.4.x-dev` and `pragmarx/google2fa` `10.x-dev`. The port should use latest stable compatible releases by default. Choose a dev branch only with an explicit, documented reason and after auditing its API changes.

Third-party sources were also cloned to `/tmp/hypervel-third-party-check` and checked directly:

- `pragmarx/google2fa` tag `v9.0.0`.
- `web-auth/webauthn-lib` tag `5.3.5`.
- `bacon/bacon-qr-code` tag `v3.1.1`.
- `web-auth/cose-lib` tag `4.5.2`.

Confirmed from source:

- `Google2FA::verifyKeyNewer()` accepts a per-call `$window` argument, and `Google2FA::getWindow()` also accepts a per-call `$window` argument.
- `Google2FA::generateSecretKey()` defaults to length `32` in v9.0.0.
- `Webauthn\CeremonyStep\CeremonyStepManager` is `final readonly`, and validators are created around a `CeremonyStepManager`.
- `Webauthn\Denormalizer\WebauthnSerializerFactory` returns a Symfony serializer and relies on `web-auth/webauthn-lib`'s own transitive requirements such as `symfony/property-info`, `symfony/uid`, and `phpdocumentor/reflection-docblock`.
- In `web-auth/webauthn-lib` 5.3.5, passing a non-empty relying-party name or icon into `PublicKeyCredentialEntity` constructor paths is deprecated.
- `Webauthn\PublicKeyCredentialSource` is deprecated in 5.3.5; use `Webauthn\CredentialRecord` for stored credentials and validator input.
- `bacon/bacon-qr-code`'s `SvgImageBackEnd` stores mutable render state (`XMLWriter`, stack, gradient counter) and resets it during `done()`.
- `web-auth/cose-lib` 4.5.2 includes `Cose\Algorithms::COSE_ALGORITHM_ES256` and `Cose\Algorithms::COSE_ALGORITHM_RS256`.

## Top-Level Decisions

### Keep Passkeys As A Separate Hypervel Package

Create both packages:

- `hypervel/passkeys`, namespace `Hypervel\Passkeys`.
- `hypervel/fortify`, namespace `Hypervel\Fortify`.

Fortify should depend on Passkeys and expose passkeys as a Fortify feature, but Passkeys should remain an independently usable WebAuthn credential package.

This is the best final architecture, not a compatibility concession:

- Passkeys have a complete standalone domain: config, routes, model, migration, actions, contracts, responses, events, and WebAuthn support.
- Passkeys carry a heavier specialized dependency graph through `web-auth/webauthn-lib`; Fortify users who do not need WebAuthn should not inherit that domain conceptually in the Fortify source tree.
- The Passkeys package is useful outside Fortify for custom authentication flows.
- Fortify's job is orchestration: feature flags, authentication workflows, password confirmation, route names, and integration defaults.
- Boost docs can still present passkeys under Fortify, because Fortify is the common user-facing entry point.

### Use Latest Stable Third-Party Dependencies

Do not pin older third-party versions merely because Laravel did.

Implementation dependency policy:

1. Add constraints that allow Composer to install the newest stable compatible release for this repo.
2. Run a resolver check before writing code against external APIs.
3. Inspect the actually installed vendor APIs after Composer updates.
4. Adapt the port to the installed versions, not to assumptions from Laravel's package.

Expected current constraints:

```json
{
    "require": {
        "bacon/bacon-qr-code": "^3.1",
        "paragonie/constant_time_encoding": "^3.1",
        "pragmarx/google2fa": "^9.0",
        "symfony/serializer": "^8.1",
        "web-auth/cose-lib": "^4.5",
        "web-auth/webauthn-lib": "^5.3"
    }
}
```

Do not explicitly require WebAuthn's transitive packages such as `symfony/property-info`, `symfony/uid`, or `phpdocumentor/reflection-docblock` unless Hypervel source or tests directly import them. Composer will install them through `web-auth/webauthn-lib`.

Run this before implementation:

```bash
composer require --dry-run web-auth/webauthn-lib web-auth/cose-lib bacon/bacon-qr-code pragmarx/google2fa paragonie/constant_time_encoding symfony/serializer
```

Then perform the real update as part of the dependency commit. Let Composer infer the latest stable compatible constraints at implementation time:

```bash
composer require web-auth/webauthn-lib web-auth/cose-lib bacon/bacon-qr-code pragmarx/google2fa paragonie/constant_time_encoding symfony/serializer
```

If Composer starts resolving newer stable versions than the dry-run listed above, inspect the installed package sources and update this plan's API assumptions before porting the affected classes.

Any Composer update that changes `web-auth/webauthn-lib` must be reviewed deliberately. Keep the no-deprecation WebAuthn tests as the gate, but do not treat a minor WebAuthn dependency update as routine until `CredentialRecord`, relying-party entity construction, serializer, and ceremony manager APIs have been rechecked.

### No Legacy Shims Or Stale Artifacts

The final codebase should look designed for Hypervel from the start:

- No commented-out Laravel code.
- No compatibility branches for unsupported legacy Laravel behavior.
- No dead classes, dead docs, dead tests, or TODO placeholders.
- No deprecated upstream APIs. If Laravel's source uses an API that the latest stable dependency marks deprecated, adapt to the current API or omit the optional behavior; do not suppress or accept deprecations.
- Intentional Laravel differences required by project policy must be short, current, and located where they help future maintainers.

### State Safety Model

Hypervel runs under long-lived Swoole workers. Treat these categories differently:

- Request/coroutine transient data belongs in request objects, session, or `CoroutineContext`.
- Cross-request mutable state must not live on singleton service instances.
- Worker-lifetime immutable or boot-time state is allowed when it has `flushState()` and is cleaned in `tests/AfterEachTestSubscriber.php`.
- Boot-time config mutation is allowed only during provider registration or tests. Do not mutate config during request handling.

Hypervel route controllers can be cached on the route instance for worker-lifetime reuse. Constructor-injected dependencies on controllers must therefore be worker-safe. Holding a specific auth guard object is coroutine-safe because Hypervel guards store current-user state in `CoroutineContext`, but it can freeze the selected guard before early guard-selection middleware calls `Auth::shouldUse()`. Fortify and Passkeys controllers should resolve their guard inside method bodies. Storing request data or mutable response data on a controller is not safe.

WebAuthn ceremony state is a deliberate exception to "use CoroutineContext" because it must persist across requests. Registration, login, and confirmation options belong in the session.

Guard selection must use Hypervel's framework-level primitive, not package-local guard pins or resolvers. Hypervel's `AuthManager::getDefaultDriver()` reads the `CoroutineContext` default guard set by `Auth::shouldUse()` before falling back to `auth.defaults.guard`; `Auth::guard(null)` therefore follows the current request's default guard without leaking across coroutines. Fortify and Passkeys should always resolve the current framework guard with this precedence:

1. A request default set by early middleware through `Auth::shouldUse($guard)`.
2. `auth.defaults.guard` when the request did not select a guard.

Do not add `fortify.guard`, `passkeys.guard`, `Fortify::guardUsing()`, or `Passkeys::guardUsing()`. Package-local guard configuration can diverge from `request()->user()`, Gate, policies, password confirmation, Sanctum-like integrations, and other packages. What is not safe is changing `auth.defaults.guard` or password broker defaults during a request; multi-guard and multi-tenant applications should select the guard in early middleware before `guest`, `auth`, `password.confirm`, Fortify controllers, and Passkeys controllers run.

Password broker selection should derive from the selected guard's provider, not from a Fortify-specific config value. The password reset flow must reset passwords in the same user store the selected guard authenticates. Find exactly one `auth.passwords.*.provider` entry matching the selected guard provider. If none or multiple match, fail clearly and require the application's auth password broker config to be made unambiguous. Do not consult `auth.defaults.passwords` from Fortify request flows, because that global default can point at a different provider than the selected guard.

Passkey ownership should not be tied to one configured user table. Laravel's migration uses one `user_id` foreign key derived from `Passkeys::userModel()`, which supports replacing `App\Models\User` but does not support one application using separate user models such as `User` and `Admin` in the same passkeys table. Hypervel should use a polymorphic owner relation by default so any model implementing `PasskeyUser` can own passkeys concurrently.

### Static State Conventions

Follow the component repo static-state rules:

- Public methods that mutate worker-lifetime static state, singleton-held registries, callbacks, or config must have a short warning in the method docblock when they are boot/test-only.
- Use the exact warning prefix: `Boot-only.`, `Tests only.`, or `Boot or tests only.`
- The second sentence should name the failure mode, for example: "The callback persists in a static property for the worker lifetime and affects every subsequent request."
- Do not add those warning paragraphs to `flushState()`; its docblock should be title-only.
- Put `flushState()` at the end of the class unless trailing magic/lifecycle methods require it immediately before them.
- When a static property's initial value and `flushState()` reset value share a literal, extract a `DEFAULT_*` constant and reference it from both places.
- Add new cleanup calls to `tests/AfterEachTestSubscriber.php` in alphabetical order among existing fully qualified class calls.

## Target Package Layout

Create:

```text
src/passkeys/
  composer.json
  config/passkeys.php
  database/migrations/2024_01_01_000000_create_passkeys_table.php
  LICENSE.md
  resources/aaguids.php
  routes/routes.php
  scripts/sync-aaguids.php
  src/
    Actions/
    Contracts/
    Events/
    Exceptions/
    Http/
    Passkey.php
    PasskeyAuthenticatable.php
    Passkeys.php
    PasskeysServiceProvider.php
    Support/
  README.md

src/fortify/
  composer.json
  config/fortify.php
  database/migrations/
  LICENSE.md
  routes/routes.php
  src/
    Actions/
    Console/
    Contracts/
    Events/
    Http/
    Fortify.php
    FortifyServiceProvider.php
    Features.php
    RoutePath.php
    TwoFactorAuthenticatable.php
    TwoFactorAuthenticationProvider.php
  stubs/
  README.md
```

Tests:

```text
tests/Passkeys/
tests/Fortify/
```

Add root autoload and package replacement:

```json
{
    "autoload": {
        "psr-4": {
            "Hypervel\\Passkeys\\": "src/passkeys/src/",
            "Hypervel\\Fortify\\": "src/fortify/src/"
        }
    },
    "replace": {
        "hypervel/passkeys": "self.version",
        "hypervel/fortify": "self.version"
    },
    "extra": {
        "hypervel": {
            "providers": [
                "Hypervel\\Passkeys\\PasskeysServiceProvider",
                "Hypervel\\Fortify\\FortifyServiceProvider"
            ]
        }
    }
}
```

Order the provider list so `PasskeysServiceProvider` appears before `FortifyServiceProvider`. Fortify configures Passkeys integration during registration.

Package manifest snippets:

The `hypervel/queue` dependency is intentional for both packages if their ported events use `Hypervel\Queue\SerializesModels`, matching existing Hypervel auth event patterns. If the implementation intentionally omits `SerializesModels`, remove that dependency from the affected package instead of relying on it transitively.

```json
{
    "name": "hypervel/passkeys",
    "description": "Passwordless authentication using WebAuthn passkeys for Hypervel.",
    "license": "MIT",
    "keywords": ["php", "hypervel", "passkeys", "webauthn", "passwordless", "swoole"],
    "require": {
        "php": "^8.4",
        "ext-json": "*",
        "hypervel/auth": "^0.4",
        "hypervel/config": "^0.4",
        "hypervel/container": "^0.4",
        "hypervel/contracts": "^0.4",
        "hypervel/database": "^0.4",
        "hypervel/events": "^0.4",
        "hypervel/foundation": "^0.4",
        "hypervel/http": "^0.4",
        "hypervel/queue": "^0.4",
        "hypervel/routing": "^0.4",
        "hypervel/session": "^0.4",
        "hypervel/support": "^0.4",
        "hypervel/validation": "^0.4",
        "paragonie/constant_time_encoding": "^3.1",
        "symfony/serializer": "^8.1",
        "web-auth/cose-lib": "^4.5",
        "web-auth/webauthn-lib": "^5.3"
    },
    "autoload": {
        "psr-4": {
            "Hypervel\\Passkeys\\": "src/"
        }
    },
    "extra": {
        "hypervel": {
            "providers": [
                "Hypervel\\Passkeys\\PasskeysServiceProvider"
            ]
        },
        "branch-alias": {
            "dev-main": "0.4-dev"
        }
    },
    "config": {
        "sort-packages": true
    }
}
```

```json
{
    "name": "hypervel/fortify",
    "description": "Backend controllers and scaffolding for Hypervel authentication.",
    "license": "MIT",
    "keywords": ["php", "hypervel", "fortify", "auth", "swoole"],
    "require": {
        "php": "^8.4",
        "ext-json": "*",
        "bacon/bacon-qr-code": "^3.1",
        "hypervel/auth": "^0.4",
        "hypervel/cache": "^0.4",
        "hypervel/collections": "^0.4",
        "hypervel/config": "^0.4",
        "hypervel/console": "^0.4",
        "hypervel/container": "^0.4",
        "hypervel/contracts": "^0.4",
        "hypervel/database": "^0.4",
        "hypervel/encryption": "^0.4",
        "hypervel/events": "^0.4",
        "hypervel/foundation": "^0.4",
        "hypervel/hashing": "^0.4",
        "hypervel/http": "^0.4",
        "hypervel/passkeys": "^0.4",
        "hypervel/pipeline": "^0.4",
        "hypervel/queue": "^0.4",
        "hypervel/routing": "^0.4",
        "hypervel/session": "^0.4",
        "hypervel/support": "^0.4",
        "hypervel/translation": "^0.4",
        "hypervel/validation": "^0.4",
        "hypervel/view": "^0.4",
        "pragmarx/google2fa": "^9.0",
        "symfony/console": "^8.0"
    },
    "autoload": {
        "psr-4": {
            "Hypervel\\Fortify\\": "src/"
        }
    },
    "extra": {
        "hypervel": {
            "providers": [
                "Hypervel\\Fortify\\FortifyServiceProvider"
            ]
        },
        "branch-alias": {
            "dev-main": "0.4-dev"
        }
    },
    "config": {
        "sort-packages": true
    }
}
```

## Passkeys Source Plan

### Static Configuration API

Port Laravel's `Passkeys` API, but make state resettable and typed.

Required behavior:

- `usePasskeyModel()`.
- `guardName()`.
- `guard()`.
- `authorizeLoginUsing()`.
- `redirectUsing()`.
- `redirectTo()`.
- `ignoreRoutes()`.
- `flushState()`.
- Config readers for relying party ID, allowed origins, timeout, middleware, redirect, and user handle secret.

Default `config/passkeys.php` should not contain a `guard` key. A standard application resolves to `auth.defaults.guard`, while multi-guard applications select the current guard per request with early middleware calling `Auth::shouldUse()`.

Use `Closure::fromCallable()` for callbacks so static properties can be strictly typed.

```php
<?php

declare(strict_types=1);

namespace Hypervel\Passkeys;

use Closure;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Http\Request;
use RuntimeException;

final class Passkeys
{
    private const DEFAULT_PASSKEY_MODEL = Passkey::class;

    private const DEFAULT_REGISTERS_ROUTES = true;

    /** @var class-string<Passkey> */
    private static string $passkeyModel = self::DEFAULT_PASSKEY_MODEL;

    private static bool $registersRoutes = self::DEFAULT_REGISTERS_ROUTES;

    /** @var null|Closure(Request, Contracts\PasskeyUser, Passkey): bool */
    private static ?Closure $authorizeLoginUsing = null;

    /** @var null|Closure(Request): (string|null) */
    private static ?Closure $redirectUsingCallback = null;

    public static function allowedOrigins(): array
    {
        $origins = array_values(array_filter(
            static::config()->array('passkeys.allowed_origins', []),
            static fn (mixed $origin): bool => is_string($origin) && $origin !== '',
        ));

        if ($origins === []) {
            throw new RuntimeException('At least one passkey allowed origin must be configured.');
        }

        return $origins;
    }

    /**
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent passkey login.
     */
    public static function authorizeLoginUsing(?callable $callback): void
    {
        self::$authorizeLoginUsing = $callback === null
            ? null
            : Closure::fromCallable($callback);
    }

    public static function allowsLogin(Request $request, Passkey $passkey): bool
    {
        $user = $passkey->user;

        if (! $user instanceof Contracts\PasskeyUser) {
            return false;
        }

        if (! self::$authorizeLoginUsing instanceof Closure) {
            return true;
        }

        return (bool) (self::$authorizeLoginUsing)($request, $user, $passkey);
    }

    /**
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent successful passkey login response.
     */
    public static function redirectUsing(?callable $callback): void
    {
        self::$redirectUsingCallback = $callback === null
            ? null
            : Closure::fromCallable($callback);
    }

    public static function redirectTo(Request $request): string
    {
        if (self::$redirectUsingCallback instanceof Closure) {
            $redirect = (self::$redirectUsingCallback)($request);

            if (is_string($redirect) && $redirect !== '') {
                return $redirect;
            }
        }

        return static::config()->string('passkeys.redirect', '/');
    }

    public static function guardName(): string
    {
        return static::container()
            ->make(AuthFactory::class)
            ->getDefaultDriver();
    }

    public static function guard(): StatefulGuard
    {
        $guard = static::container()
            ->make(AuthFactory::class)
            ->guard(null);

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('Passkeys requires a stateful authentication guard.');
        }

        return $guard;
    }

    public static function flushState(): void
    {
        self::$passkeyModel = self::DEFAULT_PASSKEY_MODEL;
        self::$registersRoutes = self::DEFAULT_REGISTERS_ROUTES;
        self::$authorizeLoginUsing = null;
        self::$redirectUsingCallback = null;
    }

    private static function config(): Config
    {
        return static::container()->make(Config::class);
    }

    private static function container(): Container
    {
        return Container::getInstance();
    }
}
```

Adapt the container access to the actual Hypervel container contract if the concrete import differs during implementation.

Port `PasskeyLoginResponse` to use the request-aware redirect helper at both response sites. Do not keep Laravel's direct `config('passkeys.redirect', '/')` reads in this response; that would bypass `Passkeys::redirectUsing()` and Fortify's callback bridge.

```php
final class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse(Request $request): Response
    {
        $redirect = Passkeys::redirectTo($request);

        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => redirect()->intended($redirect)->getTargetUrl(),
            ]);
        }

        return redirect()->intended($redirect);
    }
}
```

### Guard And Owner Model Support

Do not copy Laravel's single-user-model passkeys design. Hypervel Passkeys should support:

- Any number of named stateful guards, selected through Hypervel's current request default guard.
- Any number of owner model classes, as long as each implements `PasskeyUser` and uses `PasskeyAuthenticatable`.
- Multiple owner models in the same `passkeys` table at the same time.

Runtime rules:

- Passkey controllers should call `Passkeys::guard()`, which resolves `Auth::guard(null)` at method-call time.
- Do not inject a concrete guard into cached passkey controllers. Resolve the guard inside each method body so the package follows the current request default selected by `Auth::shouldUse()`.
- Login must still reject non-stateful guards with a clear `RuntimeException`.
- Confirmation and management routes must verify the authenticated owner implements `PasskeyUser`.
- Passwordless login must resolve the operating guard first, derive that guard provider's owner morph class, and scope the credential lookup by that `user_type` before verification when possible. If a credential resolves to a missing owner, an owner that no longer implements `PasskeyUser`, or an owner whose morph class does not match the selected guard provider model, throw `InvalidPasskeyException` before authorization or login.
- Eloquent guard providers should derive the owner type from `EloquentUserProvider::getModel()` and `(new $model)->getMorphClass()`. Non-Eloquent or custom providers cannot be inferred safely; fail closed with a clear configuration exception unless an explicit future config mapping from guard name to owner model is added during implementation.
- `PasskeyUser` should require the Eloquent model methods the package uses for ownership, including `getKey()` and `getMorphClass()`.
- Passkeys events such as `PasskeyRegistered`, `PasskeyVerified`, and `PasskeyDeleted` should be dispatched through Hypervel's `hasListeners()` guard pattern so event objects are not constructed when no normal listeners are registered.

The selected guard name should be `AuthFactory::getDefaultDriver()`, which is `Auth::shouldUse()` aware. Use that selected name only for diagnostics and provider lookup; pass null into `AuthFactory::guard()` so Hypervel's auth manager remains the single source of default-guard behavior.

Default ownership shape:

```php
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;

trait PasskeyAuthenticatable
{
    public static function bootPasskeyAuthenticatable(): void
    {
        static::deleting(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->passkeys()->delete();
        });
    }

    public function passkeys(): MorphMany
    {
        return $this->morphMany(Passkeys::passkeyModel(), 'user');
    }
}

final class Passkey extends Model
{
    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }
}
```

The relation name intentionally remains `user`, producing `user_type` and `user_id` columns, so existing package code can continue to read `$passkey->user` while the database supports multiple model classes.

The cleanup hook should preserve passkeys on reversible soft deletes and delete them on normal deletes or force deletes. If Hypervel's model event signatures require a different static boot method shape during implementation, keep the same behavior.

### Service Provider Bindings

Laravel binds all passkey responses as singletons. That is unsafe for `PasskeyRegistrationResponse` because it stores a mutable `$passkey` set by `withPasskey()` and then returns `$this`.

Hypervel rules:

- Stateless response classes may be singletons.
- `PasskeyRegistrationResponseContract` must be `bind`, not `singleton`, unless the class is made immutable and stateless.
- Prefer both: bind it as fresh, and implement `withPasskey()` using cloning so accidental reuse is still safe.

```php
$this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
$this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
$this->app->bind(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
$this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
```

Immutable response shape:

```php
final class PasskeyRegistrationResponse implements PasskeyRegistrationResponseContract
{
    public function __construct(private ?Passkey $passkey = null)
    {
    }

    public function withPasskey(Passkey $passkey): static
    {
        $response = clone $this;
        $response->passkey = $passkey;

        return $response;
    }
}
```

Add a regression test that resolves one response, calls `withPasskey($first)`, then resolves or reuses another response and verifies the second response does not leak `$first`.

### Route Model Binding

Port Passkeys' custom `{passkey}` route binding. This binding is important because the passkey model class is configurable via `Passkeys::usePasskeyModel()`, and route model binding must reject a configured model that does not resolve to a `Passkey` instance.

Hypervel has `Router::bind()`, Eloquent `resolveRouteBinding()`, and `Hypervel\Database\Eloquent\ModelNotFoundException`, so the Laravel shape ports cleanly:

```php
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Support\Facades\Route;

Route::bind('passkey', function (string $value): Passkey {
    $model = Passkeys::passkeyModel();

    $passkey = app($model)->resolveRouteBinding($value);

    if (! $passkey instanceof Passkey) {
        throw (new ModelNotFoundException())->setModel($model, [$value]);
    }

    return $passkey;
});
```

Tests:

- The default binding resolves a real passkey by route key.
- A custom passkey model registered through `Passkeys::usePasskeyModel()` is used by the binding.
- A configured model that resolves to the wrong type throws `ModelNotFoundException`.

### Standalone Routes

Port Passkeys' standalone route file even though Fortify will disable it when Fortify owns the passkey feature.

Standalone routes:

- `GET /passkeys/login/options` -> `PasskeyLoginController@index`, name `passkey.login-options`, bare `guest` middleware, throttle.
- `POST /passkeys/login` -> `PasskeyLoginController@store`, name `passkey.login`, bare `guest` middleware, throttle.
- `GET /passkeys/confirm/options` -> `PasskeyConfirmationController@index`, name `passkey.confirm-options`, bare `auth` middleware, throttle.
- `POST /passkeys/confirm` -> `PasskeyConfirmationController@store`, name `passkey.confirm`, bare `auth` middleware, throttle.
- `GET /user/passkeys/options` -> `PasskeyRegistrationController@index`, name `passkey.registration-options`, bare `auth` plus management middleware, throttle.
- `POST /user/passkeys` -> `PasskeyRegistrationController@store`, name `passkey.store`, bare `auth` plus management middleware, throttle.
- `DELETE /user/passkeys/{passkey}` -> `PasskeyRegistrationController@destroy`, name `passkey.destroy`, bare `auth` plus management middleware.

Port the middleware builder so null throttle entries are filtered out:

```php
$middleware = function (string ...$middleware): array {
    $throttle = config('passkeys.throttle');

    return array_values(array_filter([...$middleware, $throttle]));
};
```

Test standalone registration only when `Passkeys::shouldRegisterRoutes()` is true, and test Fortify's provider flips that state before route bootstrapping.

### Frontend Package Compatibility

Hypervel Passkeys and Fortify passkey routes must remain compatible with `@laravel/passkeys` without a Hypervel-specific frontend fork.

The frontend package defaults are:

- `GET /passkeys/login/options`.
- `POST /passkeys/login`.
- `GET /user/passkeys/options`.
- `POST /user/passkeys`.

It also supports per-call route overrides, so custom Fortify route paths and confirmation routes remain usable when documented:

```js
await Passkeys.verify({
    routes: {
        options: '/passkeys/confirm/options',
        submit: '/passkeys/confirm',
    },
});
```

Keep these JSON contracts:

- Registration options response: `{ "options": PublicKeyCredentialCreationOptionsJSON }`.
- Verification options response: `{ "options": PublicKeyCredentialRequestOptionsJSON }`.
- Registration submit request: `{ "name": string, "credential": RegistrationResponseJSON }`.
- Verification submit request: `{ "credential": AuthenticationResponseJSON }`; `remember` remains optional backend-only compatibility, because the current frontend does not send it.
- Registration submit JSON response: includes at least `id` and `name`, and may include `status`.
- Login / confirmation JSON response: may include `redirect`.

The frontend sends `Accept: application/json` on all requests and `Content-Type: application/json` on POST requests. It includes CSRF headers from either a `csrf-token` meta tag or the `XSRF-TOKEN` cookie, and defaults to `credentials: "same-origin"` unless the application calls `Passkeys.configure({ fetch: { credentials: "include" } })`.

Add backend feature tests that exercise these exact payload and response shapes with JSON requests, so future backend changes do not silently break `@laravel/passkeys`.

### WebAuthn Support

Port `Support\WebAuthn` against the installed `web-auth/webauthn-lib` version, not blindly against Laravel's package.

Use only non-deprecated WebAuthn APIs from the installed latest stable package:

- Do not use `Webauthn\PublicKeyCredentialSource`; it is deprecated in `web-auth/webauthn-lib` 5.3. Use `Webauthn\CredentialRecord` for stored credentials, serializer targets, attestation validator results, assertion validator input, and assertion validator results.
- Do not call `PublicKeyCredentialRpEntity::create(name: ...)` or construct `PublicKeyCredentialRpEntity` with a non-empty name or icon in `web-auth/webauthn-lib` 5.3.5, because that path triggers a dependency deprecation.
- Build the relying-party entity with the ID only unless the installed stable version exposes a non-deprecated API for a display name:

```php
protected function relyingParty(): PublicKeyCredentialRpEntity
{
    return PublicKeyCredentialRpEntity::create(id: Passkeys::relyingPartyId());
}
```

Do not add a `passkeys.relying_party.name` config key until there is a non-deprecated way to emit it. If a future stable WebAuthn release adds such an API, add it then with tests proving no deprecations are emitted.

Keep these worker-lifetime caches:

- Serializer instance.
- Attestation statement support manager.
- Creation ceremony step manager.
- Request ceremony step manager.

They are beneficial because serializer construction is relatively expensive and the objects are not request-specific. Add `flushState()` and call it from `tests/AfterEachTestSubscriber.php`.

The ceremony managers are also safe to cache for the worker lifetime after verifying the installed `web-auth/webauthn-lib` API. In `^5.3`, `CeremonyStepManager` is readonly and the variable inputs are worker-stable allowed origins plus the cached attestation support manager. Validators should still be created fresh around the cached managers.

```php
private static ?CeremonyStepManager $creationCeremony = null;
private static ?CeremonyStepManager $requestCeremony = null;

public static function attestationValidator(): AuthenticatorAttestationResponseValidator
{
    return AuthenticatorAttestationResponseValidator::create(
        ceremonyStepManager: self::$creationCeremony ??= self::ceremonyStepManagerFactory()->creationCeremony(),
    );
}

public static function assertionValidator(): AuthenticatorAssertionResponseValidator
{
    return AuthenticatorAssertionResponseValidator::create(
        ceremonyStepManager: self::$requestCeremony ??= self::ceremonyStepManagerFactory()->requestCeremony(),
    );
}
```

Add a boot-only ceremony factory customization hook for advanced users who need metadata-service or attestation-policy customization without replacing the whole WebAuthn support layer:

```php
/** @var null|Closure(CeremonyStepManagerFactory): CeremonyStepManagerFactory|void */
private static ?Closure $configureCeremonyStepManagerFactoryUsing = null;

/**
 * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent WebAuthn ceremony.
 */
public static function configureCeremonyStepManagerFactoryUsing(?callable $callback): void
{
    self::$configureCeremonyStepManagerFactoryUsing = $callback === null
        ? null
        : Closure::fromCallable($callback);

    self::$creationCeremony = null;
    self::$requestCeremony = null;
}

private static function ceremonyStepManagerFactory(): CeremonyStepManagerFactory
{
    $factory = new CeremonyStepManagerFactory;
    $factory->setAllowedOrigins(Passkeys::allowedOrigins());
    $factory->setAttestationStatementSupportManager(self::attestationStatementSupportManager());

    if (self::$configureCeremonyStepManagerFactoryUsing instanceof Closure) {
        $configured = (self::$configureCeremonyStepManagerFactoryUsing)($factory);

        if ($configured instanceof CeremonyStepManagerFactory) {
            return $configured;
        }
    }

    return $factory;
}
```

Do not copy Spatie's per-call action object creation for this. Hypervel should keep the hook boot-only and keep worker-lifetime caches for the resulting ceremony managers.

Laravel's `toBrowserArray()` uses `assert($serializer instanceof NormalizerInterface)`. Do not use runtime `assert()` for type narrowing. Use an explicit check.

```php
private static function normalizer(): NormalizerInterface
{
    $serializer = self::serializer();

    if (! $serializer instanceof NormalizerInterface) {
        throw new UnexpectedValueException('The WebAuthn serializer must also normalize objects.');
    }

    return $serializer;
}

public static function toBrowserArray(mixed $data): array
{
    $normalized = self::normalizer()->normalize($data, 'json', [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
    ]);

    if (! is_array($normalized)) {
        throw new UnexpectedValueException('Serialized WebAuthn data must normalize to an array.');
    }

    return $normalized;
}

public static function flushState(): void
{
    self::$serializer = null;
    self::$attestationStatementSupportManager = null;
    self::$creationCeremony = null;
    self::$requestCeremony = null;
    self::$configureCeremonyStepManagerFactoryUsing = null;
}
```

After dependency installation, inspect these installed classes before finalizing the port:

```bash
grep -R "class CeremonyStepManagerFactory" -n vendor/web-auth/webauthn-lib vendor
grep -R "class WebauthnSerializerFactory" -n vendor/web-auth vendor
grep -R "class PublicKeyCredentialCreationOptions" -n vendor/web-auth vendor
grep -R "class PublicKeyCredentialRpEntity" -n vendor/web-auth/webauthn-lib vendor
grep -R "class PublicKeyCredentialSource" -n vendor/web-auth/webauthn-lib vendor
```

If the installed APIs differ from Laravel's usage, adapt `GenerateRegistrationOptions`, `GenerateVerificationOptions`, `StorePasskey`, `VerifyPasskey`, and `WebAuthn` together.

Port `scripts/sync-aaguids.php` as a maintenance script so the AAGUID map can be regenerated from the upstream dataset. It is not runtime-autoloaded.

### AAGUID Cache

Keep Laravel's static AAGUID map cache. It is worker-lifetime immutable data loaded from `resources/aaguids.php`, so caching improves performance and is coroutine-safe.

Rename cleanup to Hypervel convention:

```php
public static function flushState(): void
{
    self::$aaguids = null;
}
```

### StorePasskey Duplicate Race

Laravel checks for an existing credential ID before create. That is still useful for a clean error path, but it does not handle concurrent registration of the same credential.

Hypervel must keep the unique database index and catch the unique violation when inserting.

```php
use Hypervel\Database\UniqueConstraintViolationException;

public function createPasskey(PasskeyUser $user, string $name, CredentialRecord $source): Passkey
{
    $credentialId = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);

    try {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);
    } catch (UniqueConstraintViolationException) {
        throw InvalidPasskeyException::make('Unable to register this passkey.');
    }
}
```

Test this with two attempts using the same credential ID: first succeeds, second throws `InvalidPasskeyException`.

### VerifyPasskey Signature Counter Update

Laravel already wraps verification in a transaction and locks the credential row with `lockForUpdate()`. Keep that behavior. It prevents concurrent requests from racing the signature counter update used to detect cloned authenticators.

Port:

```php
$passkey = $this->getPasskey($credential, lock: true);
```

And:

```php
if ($lock) {
    $query->lockForUpdate();
}
```

Add a test that asserts the locked path is used during verification. If direct DB-level concurrency testing is brittle across database drivers, test that `VerifyPasskey::__invoke()` calls `getPasskey()` with `lock: true` through a focused subclass or mock.

### Ownership Checks

Laravel delete uses strict comparison:

```php
abort_unless($passkey->user_id === $user->getKey(), 403);
```

That fails for equivalent int/string primary keys. Laravel's `VerifyPasskey` already uses a safer scalar cast:

```php
if (! is_scalar($identifier) || (string) $passkey->user_id !== (string) $identifier) {
    throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
}
```

Use the scalar string comparison consistently in delete and verification. Extract a private helper if it removes duplication.

Because Hypervel should use a polymorphic passkey owner, also compare the owner class. Equivalent numeric/string keys should match only when the owner morph class is the same:

```php
private function passkeyBelongsToUser(Passkey $passkey, PasskeyUser $user): bool
{
    $identifier = $user->getKey();

    if (! is_scalar($identifier)) {
        return false;
    }

    return $passkey->user_type === $user->getMorphClass()
        && (string) $passkey->user_id === (string) $identifier;
}
```

Use the framework's morph class value rather than blindly comparing PHP class names, so applications with an Eloquent morph map keep working.

Passwordless login needs one additional ownership check because credentials are globally unique while owners are polymorphic. Resolve the selected guard before fetching the passkey, derive the selected guard provider model's morph class, and constrain the lookup:

```php
private function getPasskeyForLogin(string $credentialId, StatefulGuard $guard, bool $lock = false): Passkey
{
    $ownerType = $this->ownerMorphClassForGuard($guard);
    $model = Passkeys::passkeyModel();

    $query = $model::query()
        ->where('credential_id', $credentialId)
        ->where('user_type', $ownerType);

    if ($lock) {
        $query->lockForUpdate();
    }

    $passkey = $query->first();

    if (! $passkey instanceof Passkey) {
        throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
    }

    $user = $passkey->user;

    if (! $user instanceof PasskeyUser || $user->getMorphClass() !== $ownerType) {
        throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
    }

    return $passkey;
}
```

For Eloquent providers, `ownerMorphClassForGuard()` should use `method_exists($guard, 'getProvider')`, verify the provider is `Hypervel\Auth\EloquentUserProvider`, read `getModel()`, instantiate the model, and call `getMorphClass()`. `getProvider()` lives on `Hypervel\Auth\GuardHelpers`, not the `Guard` / `StatefulGuard` contract, so mirror the existing `AuthManager::clearUserCache()` pattern: guard the call with `method_exists()` and add a line-scoped phpstan ignore on the call itself.

```php
if (! method_exists($guard, 'getProvider')) {
    throw new RuntimeException('Passkey passwordless login requires an Eloquent authentication guard provider.');
}

$provider = $guard->getProvider(); /* @phpstan-ignore method.notFound (getProvider() is on GuardHelpers, not the guard contract) */
```

If the selected guard uses a custom provider that cannot expose an Eloquent owner model, passwordless passkey login must fail closed with a clear configuration exception rather than accepting credentials across owner types.

### Session Ceremony State

Keep ceremony options in the session:

- `passkey.registration_options`.
- `passkey.login_options`.
- `passkey.confirmation_options`.

Do not use `CoroutineContext` for these values. They must survive the browser round trip from options endpoint to submission endpoint.

Use `CoroutineContext` only if a ported controller or middleware introduces request-local temporary state that is not already in the request/session/auth guard.

### Passkey Model And Migration

Port the model with strict types and Hypervel namespaces.

Keep date annotations aligned with the actual casts. A `datetime` cast returns Hypervel's configured date class, which defaults to mutable `Hypervel\Support\Carbon`; do not document `CarbonImmutable` unless the model intentionally uses `immutable_datetime`.

Use a polymorphic `user` owner relation instead of Laravel's single-model `belongsTo` relation. This avoids a global `Passkeys::userModel()` static and lets one application register passkeys for multiple authenticatable model classes in the same table.

The stored `credential` JSON represents a `Webauthn\CredentialRecord`. Do not deserialize it as, type it as, or convert it to `Webauthn\PublicKeyCredentialSource`; that class is deprecated in the current latest stable `web-auth/webauthn-lib`.

Port `PasskeyAuthenticatable` with the same stable, non-PII user handle behavior:

```php
public function getPasskeyUserHandle(): string
{
    return hash_hmac(
        'sha256',
        $this->getTable() . '|' . $this->getKey(),
        Passkeys::userHandleSecret(),
        binary: true,
    );
}
```

Document `passkeys.user_handle_secret` and `fortify.passkeys.user_handle_secret` as long-lived secrets. They default to the app key for convenience, but production applications should set a dedicated secret before registering passkeys because changing it changes generated WebAuthn user handles.

Migration:

```php
Schema::create('passkeys', function (Blueprint $table): void {
    $table->id();
    $table->morphs('user');
    $table->string('name');
    $table->string('credential_id')->unique();
    $table->jsonb('credential');
    $table->timestamp('last_used_at')->nullable();
    $table->timestamps();
});
```

`morphs('user')` respects Hypervel's schema builder default morph key type. Numeric IDs work by default; applications using UUID or ULID owner keys must configure the framework morph key type before running the migration, the same way they would for other polymorphic tables.

The stock migration assumes all passkey owner models in the application share the same morph key storage type. If an application genuinely mixes owner key types, for example integer `User` IDs and UUID `Admin` IDs, it should publish the migration before running it and use a string-compatible `user_id` column while keeping the `user_type` / `user_id` relation names. The package code should compare owner identifiers as strings so this customized schema remains supported.

Use `jsonb('credential')` because the root contribution rules require Postgres-specific helpers; Hypervel's grammars fall back to the correct alternative for databases that do not have native `jsonb`.

Laravel's foreign-key cascade is intentionally not copied because a single foreign key cannot support multiple owner tables. The trait should delete related passkeys from its model events so default cleanup remains automatic for Eloquent instance deletes while preserving credentials across reversible soft deletes.

Polymorphic ownership cannot provide database-level cascade integrity for every delete path. Mass deletes, quiet deletes, and raw SQL can bypass Eloquent model events and leave orphaned passkey rows. Own that trade-off explicitly:

- Add a `PruneOrphanedPasskeys` action and `passkeys:prune-orphans` command.
- The command should support `--dry-run` and report counts by `user_type`.
- Group passkeys by `user_type`, resolve morph-map aliases through Hypervel's relation APIs, verify the resolved class is an Eloquent model, chunk owner IDs, and delete passkeys whose owner class is missing or whose owner key no longer exists.
- Keep the model-event cleanup in `PasskeyAuthenticatable` for the common instance-delete path.
- Document that applications using raw SQL or mass deletes should run the prune command on a schedule, or delete passkeys explicitly in the same data-maintenance job.
- Add tests for normal delete cleanup, force-delete cleanup, soft-delete preservation, mass-delete orphan creation, and prune-command cleanup.

## Fortify Source Plan

### Static Configuration API

Port `Fortify` with strict typed static state and `flushState()`.

Use `Closure` properties instead of untyped `callable` properties. PHP does not allow `callable` as a property type.

Fortify should support multiple named guards by using Hypervel's auth default guard mechanism. It should not define its own `fortify.guard` setting. Fortify resolves the current request default guard through `AuthFactory::guard(null)`, which follows `Auth::shouldUse()` in `CoroutineContext`.

Default `config/fortify.php` should not include Laravel's `guard` or `passwords` keys. A standard application still resolves to `auth.defaults.guard` and the single password broker matching that guard's provider, while multi-guard applications can select both from the current request guard.

```php
final class Fortify
{
    private const DEFAULT_REGISTERS_ROUTES = true;

    private static ?Closure $authenticateThroughCallback = null;
    private static ?Closure $authenticateUsingCallback = null;
    private static ?Closure $confirmPasswordsUsingCallback = null;
    private static bool $registersRoutes = self::DEFAULT_REGISTERS_ROUTES;
    private static ?EncrypterContract $encrypter = null;

    /** @var array<string, Closure(Request): (string|null)> */
    private static array $redirectUsingCallbacks = [];

    /**
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent login request.
     */
    public static function authenticateThrough(callable $callback): void
    {
        self::$authenticateThroughCallback = Closure::fromCallable($callback);
    }

    public static function authenticateThroughCallback(): ?Closure
    {
        return self::$authenticateThroughCallback;
    }

    public static function ignoreRoutes(): static
    {
        self::$registersRoutes = false;

        return new static();
    }

    /**
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent response for the named Fortify redirect.
     */
    public static function redirectUsing(string $redirect, ?callable $callback): void
    {
        if ($callback === null) {
            unset(self::$redirectUsingCallbacks[$redirect]);

            return;
        }

        self::$redirectUsingCallbacks[$redirect] = Closure::fromCallable($callback);
    }

    public static function redirects(string $redirect, mixed $default = null, ?Request $request = null): string
    {
        if ($request !== null && isset(self::$redirectUsingCallbacks[$redirect])) {
            $resolved = (self::$redirectUsingCallbacks[$redirect])($request);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return (string) (static::config()->get("fortify.redirects.{$redirect}")
            ?? $default
            ?? static::config()->get('fortify.home'));
    }

    public static function guardName(): string
    {
        return static::container()
            ->make(AuthFactory::class)
            ->getDefaultDriver();
    }

    public static function guard(): StatefulGuard
    {
        $guard = static::container()
            ->make(AuthFactory::class)
            ->guard(null);

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('Fortify requires a stateful authentication guard.');
        }

        return $guard;
    }

    public static function flushState(): void
    {
        self::$authenticateThroughCallback = null;
        self::$authenticateUsingCallback = null;
        self::$confirmPasswordsUsingCallback = null;
        self::$registersRoutes = self::DEFAULT_REGISTERS_ROUTES;
        self::$encrypter = null;
        self::$redirectUsingCallbacks = [];
    }
}
```

If existing Laravel tests or Fortify internals read public static properties directly, prefer changing Hypervel internals/tests to accessors instead of preserving public mutable statics. The goal is a clean final Hypervel design.

All built-in response classes that use `Fortify::redirects()` must pass the current request so request-aware callbacks can resolve the fallback dynamically:

```php
return redirect()->intended(Fortify::redirects('login', request: $request));
```

Keep the existing config fallback behavior when no callback is registered. The callback result is not cached; only the boot-time callback is stored for the worker lifetime.

Service provider guard binding:

- Bind `Hypervel\Contracts\Auth\StatefulGuard` as request/coroutine scoped or equivalent only if needed by ported action constructors, resolving `Fortify::guard()` after the application's guard-selection middleware has run.
- Do not bind a single concrete guard singleton.
- Controllers that may be cached on routes must call `Fortify::guard()` inside method bodies instead of constructor-injecting a guard.

### Password Broker Resolution

Laravel Fortify pins password resets to `config('fortify.passwords')`. Hypervel should not port that Fortify-specific broker setting. It duplicates information already implied by the selected guard's provider and can silently drift from the guard, causing password reset flows to target the wrong user store.

Resolution rule:

1. Resolve the selected guard name through `Fortify::guardName()`.
2. Read `auth.guards.{guard}.provider`.
3. Find exactly one `auth.passwords.*.provider` entry with the same provider.
4. If no broker or multiple brokers match the guard provider, throw a clear configuration exception requiring the application's `auth.passwords` entries to be made unambiguous for that provider.

This preserves Laravel's single-broker scenario while removing the possibility of a Fortify broker/guard mismatch. `Auth::shouldUse('admin')` routes reset-link and reset-password controllers to the broker whose provider matches the selected `admin` guard.

```php
public static function passwordBrokerName(): string
{
    $config = static::config();
    $guard = static::guardName();
    $provider = $config->get("auth.guards.{$guard}.provider");

    if (! is_string($provider) || $provider === '') {
        throw new RuntimeException("Unable to infer a password broker because auth guard [{$guard}] has no provider.");
    }

    $matches = array_keys(array_filter(
        $config->array('auth.passwords'),
        static fn (mixed $broker): bool => is_array($broker)
            && ($broker['provider'] ?? null) === $provider,
    ));

    if (count($matches) !== 1) {
        throw new RuntimeException("Unable to infer a password broker for auth guard [{$guard}]. Ensure exactly one auth.passwords broker uses provider [{$provider}].");
    }

    return $matches[0];
}

protected function broker(): PasswordBroker
{
    return Password::broker(Fortify::passwordBrokerName());
}
```

Password reset token repositories are email-keyed, not polymorphic. When multiple password brokers target different user models that can share email addresses, docs should recommend separate token tables or cache stores per broker. Otherwise one model's reset token can replace another model's token with the same email address.

If an application defines multiple brokers for the same provider to express different token expiry/throttle policies, Fortify should fail instead of guessing. That uncommon case should be solved in the application by making the guard/provider/broker mapping explicit and unambiguous outside Fortify, not by reintroducing a Fortify-only broker override.

### Feature Options

Laravel's `Features::twoFactorAuthentication($options)` and `Features::passkeys($options)` mutate config:

```php
config(['fortify-options.passkeys' => $options]);
```

Keep the public `Features::` API, including options passed from `config/fortify.php`. Do not move these options to static-only storage. Hypervel's config cache loads a generated array and does not re-evaluate config files, so static side effects from config-file evaluation would disappear under cached config.

Use config-backed storage for `fortify-options.*`, but encapsulate it behind `Features::options()` / `Features::option()` / `Features::optionEnabled()` so the rest of Fortify does not read the side-channel directly.

This is config-cache-safe because `config:cache` serializes the full config repository after config files have been evaluated, including `fortify-options.*`. It is also Swoole-safe when called only during config loading, provider registration, or tests.

```php
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as Config;

class Features
{
    public static function optionEnabled(string $feature, string $option): bool
    {
        return static::enabled($feature)
            && static::option($feature, $option) === true;
    }

    public static function option(string $feature, string $option, mixed $default = null): mixed
    {
        return static::options($feature)[$option] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(string $feature): array
    {
        $options = static::config()->get("fortify-options.{$feature}", []);

        return is_array($options) ? $options : [];
    }

    /**
     * Boot/config/test only. The config repository is process-global.
     *
     * @param array<string, mixed> $options
     */
    private static function setOptions(string $feature, array $options): void
    {
        if ($options !== []) {
            static::config()->set("fortify-options.{$feature}", $options);
        }
    }

    public static function twoFactorAuthentication(array $options = []): string
    {
        static::setOptions('two-factor-authentication', $options);

        return 'two-factor-authentication';
    }

    public static function passkeys(array $options = []): string
    {
        static::setOptions('passkeys', $options);

        return 'passkeys';
    }

    private static function config(): Config
    {
        return Container::getInstance()->make(Config::class);
    }
}
```

Update all internal option readers:

- `Fortify::confirmsTwoFactorAuthentication()` uses `Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')`.
- Two-factor management routes use `Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')`.
- `EnableTwoFactorAuthentication` reads `(int) Features::option(Features::twoFactorAuthentication(), 'secret-length', 32)`.
- `TwoFactorAuthenticationProvider` reads `Features::option(Features::twoFactorAuthentication(), 'window')`.
- Passkey management middleware reads `(bool) Features::option(Features::passkeys(), 'confirmPassword', true)`.

Tests must cover that options are cached and read correctly, including `confirm`, `confirmPassword`, `secret-length`, and `window`. Cover the default `secret-length` value of `32`, matching `pragmarx/google2fa` v9's default. No request test should mutate feature options mid-request.

### Fortify To Passkeys Bridge

Fortify should call `Passkeys::ignoreRoutes()`, bridge Fortify WebAuthn config into `passkeys.*` during service registration, and install a request-aware Passkeys redirect callback.

Port Laravel's behavior with Hypervel config repository:

```php
protected function configurePasskeys(): void
{
    Passkeys::ignoreRoutes();

    $config = $this->app->make(Config::class);

    $config->set([
        'passkeys.relying_party_id' => $config->get('fortify.passkeys.relying_party_id', parse_url($config->string('app.url'), PHP_URL_HOST)),
        'passkeys.allowed_origins' => $config->get('fortify.passkeys.allowed_origins', [$config->string('app.url')]),
        'passkeys.user_handle_secret' => $config->get('fortify.passkeys.user_handle_secret', $config->get('app.key')),
        'passkeys.timeout' => $config->get('fortify.passkeys.timeout', 60000),
    ]);

    Passkeys::redirectUsing(
        static fn (Request $request): string => Fortify::redirects('login', request: $request),
    );
}
```

This is safe because provider registration is boot-time process setup, not request-time mutation.

Do not bridge guard, route middleware, throttle, or a static redirect value into `passkeys.*`. Fortify disables standalone Passkeys routes and builds its own route middleware from Fortify config, while both packages resolve the current framework guard through `Auth::guard(null)`. Passkey login redirect is intentionally bridged as a callback because `PasskeyLoginResponse` needs the same post-login destination as Fortify password login, but multi-tenant and multi-guard applications may compute that destination from the current request, current guard, or tenancy context.

Do not port Laravel's `passkeyUserModel()` helper. It exists because Laravel Passkeys stores a single `user_id` foreign key against one configured model. Hypervel's polymorphic `user` owner relation makes that helper unnecessary and avoids a stale global user-model setting.

Document `passkeys.redirect` as the fallback destination after successful standalone passkey login. It is passed to `redirect()->intended($fallback)`, so intended URLs still take precedence. In Fortify mode, Fortify installs a `Passkeys::redirectUsing()` callback that delegates to `Fortify::redirects('login', request: $request)` so password login and passkey login share the same request-aware post-login destination.

### Routes

Fortify routes should import Hypervel controllers:

```php
use Hypervel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Hypervel\Passkeys\Http\Controllers\PasskeyLoginController;
use Hypervel\Passkeys\Http\Controllers\PasskeyRegistrationController;
```

Preserve route names and default paths from Laravel's Fortify docs so frontend packages and user code have predictable endpoints:

- `passkey.login-options`
- `passkey.login`
- `passkey.confirm-options`
- `passkey.confirm`
- `passkey.registration-options`
- `passkey.store`
- `passkey.destroy`

Use `RoutePath::for()` for configurable paths, as Laravel does.

Built-in routes should use bare `guest`, `auth`, and `password.confirm` middleware so they follow the current request default guard selected by earlier middleware or `auth.defaults.guard`.

For simultaneous multi-guard applications with separate paths or domains, document the supported pattern: call `Fortify::ignoreRoutes()` / `Passkeys::ignoreRoutes()`, register route groups per area, and put guard-selection middleware that calls `Auth::shouldUse($guard)` before `guest`, `auth`, `password.confirm`, and the package controllers. Named `guest:admin` alone checks the admin guard but does not set the default guard for controller code, so early guard-selection middleware is required. Do not change config values inside route handlers.

### TwoFactorAuthenticationProvider Window Mutation

Laravel injects `Google2FA` into a singleton provider and mutates the engine window during verification:

```php
$this->engine->setWindow($customWindow);
```

That can leak a custom window into later requests handled by the same worker.

Best Hypervel design for `pragmarx/google2fa` `^9.0`: do not mutate the shared engine. Pass the configured window into `verifyKeyNewer()` and `getWindow()` per call. Those methods accept a `$window` argument in v9, so there is no extra object allocation and no shared mutation.

```php
final class TwoFactorAuthenticationProvider implements TwoFactorAuthenticationProviderContract
{
    public function __construct(
        private readonly Google2FA $engine,
        private readonly ?Repository $cache = null,
    ) {
    }

    public function verify(string $secret, string $code): bool
    {
        $window = Features::option(Features::twoFactorAuthentication(), 'window');
        $window = is_int($window) ? $window : null;

        $key = 'fortify.2fa_codes.' . md5($code);

        $timestamp = $this->engine->verifyKeyNewer(
            $secret,
            $code,
            $this->cache?->get($key),
            $window,
        );

        if ($timestamp === false) {
            return false;
        }

        if ($timestamp === true) {
            $timestamp = $this->engine->getTimestamp();
        }

        $this->cache?->put($key, $timestamp, ($this->engine->getWindow($window) ?: 1) * 60);

        return true;
    }
}
```

If a future latest stable `pragmarx/google2fa` removes the per-call window arguments, stop and update this design before implementing. The fallback is a fresh engine factory, not mutating the shared engine.

Test that a custom window verification does not affect a later default-window verification in the same PHP process.

Default secret length:

- Laravel Fortify defaults its provider wrapper to length `16`.
- `pragmarx/google2fa` v9 defaults `generateSecretKey()` to length `32`.
- Hypervel should default Fortify's `secret-length` option and provider method to `32`, while still honoring an explicit config option.

Also fix Laravel's contract mismatch: Laravel's `TwoFactorAuthenticationProvider` contract declares `generateSecretKey()` with no argument, while `EnableTwoFactorAuthentication` calls it with a length. Hypervel's contract and implementation should agree:

```php
interface TwoFactorAuthenticationProvider
{
    public function generateSecretKey(int $secretLength = 32): string;
}
```

### Recovery Code Replacement

Laravel replaces recovery codes by decrypting the JSON text and running `str_replace()` on the raw JSON string:

```php
Fortify::currentEncrypter()->encrypt(str_replace(
    $code,
    RecoveryCode::generate(),
    Fortify::currentEncrypter()->decrypt($this->two_factor_recovery_codes)
))
```

The database stores one encrypted text string in either design. The difference is what happens inside the plaintext before re-encryption.

Use structured JSON replacement:

```php
public function replaceRecoveryCode(string $code): void
{
    $encrypter = Fortify::currentEncrypter();

    $codes = json_decode(
        $encrypter->decrypt($this->two_factor_recovery_codes),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    if (! is_array($codes)) {
        throw new UnexpectedValueException('Two-factor recovery codes must decode to an array.');
    }

    $replacement = RecoveryCode::generate();

    $codes = array_map(
        static fn (mixed $value): mixed => $value === $code ? $replacement : $value,
        $codes,
    );

    $this->forceFill([
        'two_factor_recovery_codes' => $encrypter->encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
    ])->save();

    RecoveryCodeReplaced::dispatch($this, $code);
}
```

This is safer because only a whole decoded array entry is replaced. It avoids accidental substring replacement inside another recovery code or inside JSON syntax.

### QR Code Rendering

Laravel creates QR renderer objects on every `twoFactorQrCodeSvg()` call. Keep that shape.

Do not cache the QR `Writer`, `ImageRenderer`, or `SvgImageBackEnd`. In `bacon/bacon-qr-code` `v3.1.1`, `SvgImageBackEnd` stores mutable render state (`XMLWriter`, stack, gradient counter) and resets it during `done()`. QR generation is not a hot path, so fresh renderer objects are simpler and safer under long-lived workers.

Acceptable no-cache implementation:

```php
$svg = (new Writer(
    new ImageRenderer(
        new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
        new SvgImageBackEnd()
    )
))->writeString($this->twoFactorQrCodeUrl());
```

### Response Contract Bugs

Laravel has two response classes implementing the wrong contract:

- `TwoFactorEnabledResponse` implements `TwoFactorLoginResponseContract`.
- `TwoFactorDisabledResponse` implements `TwoFactorLoginResponseContract`.

Hypervel should fix these:

```php
use Hypervel\Fortify\Contracts\TwoFactorEnabledResponse as TwoFactorEnabledResponseContract;

final class TwoFactorEnabledResponse implements TwoFactorEnabledResponseContract
{
}
```

```php
use Hypervel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;

final class TwoFactorDisabledResponse implements TwoFactorDisabledResponseContract
{
}
```

Add tests that the container bindings resolve classes implementing the matching contracts.

### Legacy Shim Removal

Do not port Laravel compatibility shims for older framework versions when Hypervel has the modern API:

- `FortifyServiceProvider` should call `publishesMigrations()` directly.
- `InstallCommand` should call `ServiceProvider::addProviderToBootstrapFile()` directly.
- `RedirectIfTwoFactorAuthenticatable` should call `rehashPasswordIfRequired()` directly when `hashing.rehash_on_login` is enabled.
- Laravel version-gated tests such as `RequiresLaravel` attributes and `Application::VERSION < 11` branches should be removed; the modern behavior runs unconditionally.

Add focused tests for the resulting modern path rather than preserving branch coverage for unsupported Laravel versions.

### Response Bindings

Most Fortify response classes are stateless and can remain singletons. The status-bearing password reset/link responses are safe because Laravel resolves them with parameters, for example `app(PasswordResetResponse::class, ['status' => $status])`; Hypervel's container bypasses shared instance caches for parameterized resolutions.

Do not generalize this exception to mutable responses resolved without parameters. `PasskeyRegistrationResponse` remains fresh/immutable as described in the Passkeys section.

### Password Rule

Do not port Laravel Fortify's deprecated `Rules\Password` compatibility class as a live rule. Use Hypervel's validation password rule facilities directly in stubs and actions.

Because project policy requires intentional Laravel differences to be discoverable:

- README: short migration note that Hypervel Fortify intentionally omits Laravel Fortify's deprecated `Rules\Password`.
- Source: short comment in the password validation rule stub/action where the modern Hypervel validation rule is used.
- Tests: cover the modern password validation path and omit Laravel's deprecated `PasswordRuleTest`.

Keep the source comment specific and current; do not create a dead source file solely to explain an omitted class.

### Actions And Controllers

Port all actions/controllers with Hypervel namespaces and strict types.

Pay attention to these Laravel facade replacements:

- `Illuminate\Support\Facades\Auth` -> Hypervel auth manager/facade pattern used in this repo.
- `Illuminate\Support\Facades\Config` -> `Hypervel\Contracts\Config\Repository` or Hypervel config facade if local package style uses it.
- `Illuminate\Support\Facades\DB` -> Hypervel database manager/facade pattern.
- `Illuminate\Support\Facades\Hash`, `Password`, `RateLimiter`, `Route`, `Schema` -> Hypervel equivalents.
- `Illuminate\Http\Request` -> `Hypervel\Http\Request`.
- `Illuminate\Routing\Controller` -> `Hypervel\Routing\Controller`.
- `Illuminate\Routing\Pipeline` -> `Hypervel\Routing\Pipeline`.

Configuration access convention:

- Instance classes should receive `Hypervel\Contracts\Config\Repository` through dependency injection when they need repeat config reads.
- Service providers should read config through `$this->app->get(Config::class)` or the local provider style used elsewhere in components.
- Static helpers may resolve the typed config repository through `Container::getInstance()->make(Config::class)`.
- Avoid raw facade/config-helper reads in package classes except route files, config files, and simple bootstrap helpers.
- Do not call config repository `set()` from request handlers.

Guard access convention:

- Fortify controllers/actions should resolve the guard through `Fortify::guard()` at method-call time, or through a scoped `StatefulGuard` binding only after guard-selection middleware has run.
- Passkeys controllers should resolve the guard through `Passkeys::guard()` at method-call time.
- Do not constructor-inject a concrete guard into cached package controllers.
- Do not call the default auth guard implicitly in package code except through the package `guard()` helpers, where `Auth::guard(null)` intentionally means "use the framework current default guard."
- `AttemptToAuthenticate::fireFailedEvent()` and `RedirectIfTwoFactorAuthenticatable::fireFailedEvent()` must pass `Fortify::guardName()` to `Hypervel\Auth\Events\Failed`. Do not port Laravel's `$this->guard?->name ?? config('fortify.guard')` fallback; `fortify.guard` does not exist, guards are resolved per request, and `name` is not on the `StatefulGuard` contract.

Event dispatch convention:

- Follow Hypervel's existing `hasListeners()` performance pattern from `SessionGuard`, HTTP server request events, routing events, cache events, and permission events.
- Do not construct package event objects when the dispatcher has no normal listeners for that event class.
- Use a tiny helper where it removes duplication, for example `dispatchIfListening(string $eventClass, Closure $event): void`.
- Remember that passive event observers are intentionally not counted by `hasListeners()`; this optimization is for listener-driven extension events.

Do not preserve Laravel return docblocks where real return types can be declared.

Source cleanup while porting:

- `AuthenticatedSessionController` should read `fortify.pipelines.login`, `fortify.limiters.login`, and `fortify.lowercase_usernames` once per method call instead of repeated helper reads. Build the pipeline through `Hypervel\Routing\Pipeline` with the container from the app, then pass the filtered pipe list.
- Registration remains controlled by the app's `CreatesNewUsers` action. Fortify should not try to infer the model to create from the selected guard; multi-guard applications should make their published `CreateNewUser` action guard-aware.
- `InteractsWithTwoFactorState::neverFinishedConfirmingTwoFactorAuthentication()` should avoid loose `!=`; cast or compare the session timestamp strictly.
- `NewPasswordController` and `PasswordResetLinkController` should compare password broker statuses strictly (`===`) against `Password::PASSWORD_RESET` and `Password::RESET_LINK_SENT`.
- Fortify events currently documented as `\App\Models\User` should be typed/documented against `Hypervel\Contracts\Auth\Authenticatable` or the closest Hypervel auth contract, not an application model:
  - `PasswordUpdatedViaController`.
  - `RecoveryCodesGenerated`.
  - `TwoFactorAuthenticationEvent`.

Use `Hypervel\Routing\Controller` consistently; do not omit the base class.

### Views And View Responses

Port Fortify's view response contracts and `SimpleViewResponse`.

Keep Fortify headless by default where config says so, but preserve documented optional view hooks:

- `loginView`.
- `registerView`.
- `requestPasswordResetLinkView`.
- `resetPasswordView`.
- `verifyEmailView`.
- `confirmPasswordView`.
- `twoFactorChallengeView`.

Use container bindings that do not store request-specific data on singleton response instances.

## Static State Cleanup

Add cleanup calls to `tests/AfterEachTestSubscriber.php`:

```php
\Hypervel\Fortify\Fortify::flushState();
\Hypervel\Passkeys\Passkeys::flushState();
\Hypervel\Passkeys\Support\Aaguids::flushState();
\Hypervel\Passkeys\Support\WebAuthn::flushState();
```

If implementation introduces any other static worker-lifetime cache, add `flushState()` and include it here in the same commit.

Do not add cleanup calls for classes that do not exist.

## Documentation Plan

### Boost Fortify Documentation

Copy Laravel's Fortify docs into Boost docs as a starting point:

```bash
cp /home/binaryfire/workspace/monorepo/examples/laravel/docs/fortify.md \
  /home/binaryfire/workspace/monorepo/contrib/hypervel/components/src/boost/docs/fortify.md
```

Then rewrite it for Hypervel before committing:

- Replace Laravel installation commands with Hypervel package instructions.
- Replace namespaces with `Hypervel\Fortify` and `Hypervel\Passkeys`.
- Replace Artisan references with Hypervel console command names where they differ.
- Replace Laravel app paths with Hypervel structure.
- Remove Laravel Jetstream / starter-kit text that is not true for Hypervel unless Hypervel has equivalent support.
- Keep passkeys documented under Fortify as a Fortify feature.
- Explain that `hypervel/passkeys` is a separate package used by Fortify and available standalone.
- Include passkey config keys from `fortify.passkeys`.
- Include `@laravel/passkeys` frontend package usage; it was verified framework-agnostic and compatible with Hypervel routes when the backend preserves the JSON contracts in this plan.
- Add Swoole-specific guidance: do not call `Features::*($options)`, Fortify static callback registration methods, Passkeys static callback registration methods, or `config()->set()` from request handlers; configure them during boot/provider setup.
- Document multi-guard setup: Fortify and Passkeys always use the current request default guard; applications select it with early `Auth::shouldUse()` middleware before `guest`, `auth`, `password.confirm`, and package controllers.
- Document that registration remains app-controlled: multi-guard apps should make their `CreateNewUser` action guard-aware because Fortify cannot infer which user model to create from the selected guard.
- Document request-aware redirects: configure `Fortify::redirectUsing('login', fn (Request $request) => ...)` and `Passkeys::redirectUsing(fn (Request $request) => ...)` during boot, and read current guard / tenancy / domain state inside the callback. The callback is worker-lifetime configuration, but its return value is computed per request and must not be cached globally.
- Document password reset broker setup: Fortify derives the broker from the selected guard provider; exactly one `auth.passwords` broker must match that provider; apps with multiple providers that may share email addresses should use separate reset token tables or cache stores.
- Document multi-model passkeys: each authenticatable model implements `PasskeyUser` and uses `PasskeyAuthenticatable`; the default migration uses `user_type` / `user_id`.
- Document passkey login redirects: standalone Passkeys uses `passkeys.redirect` or `Passkeys::redirectUsing()` as the `redirect()->intended()` fallback, while Fortify mode delegates to `Fortify::redirects('login', request: $request)` so passkey login and password login land in the same request-aware place.
- Document passkey orphan cleanup: model events clean normal instance deletes, while `passkeys:prune-orphans` covers mass deletes and raw data maintenance.
- Remove stale Laravel-only documentation.

Add `fortify.md` to any Boost docs index/list if this repo uses one.

### Package READMEs

Create or update:

- `src/passkeys/README.md`.
- `src/fortify/README.md`.

Keep the READMEs thin, matching this repo's package convention:

- Package name, purpose, and a pointer to the canonical Boost/component docs.
- Minimal install/provider discovery pointer only if the existing package README style includes it.
- Short "Differences vs Laravel" section.
- No full setup guide, route guide, Swoole guide, or duplicated Fortify documentation. Those belong in `src/boost/docs/fortify.md`.

The "Differences vs Laravel" section should stay short and mention only user-visible or maintainer-relevant differences:

- Passkeys registration response is not a mutable singleton.
- Passkeys use a polymorphic `user` owner relation so multiple authenticatable model classes can share the same passkeys table.
- Fortify and Passkeys always follow Hypervel's current default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`; they do not have package-local guard pins.
- Passkeys use current non-deprecated `web-auth/webauthn-lib` APIs, including `CredentialRecord` instead of deprecated credential-source APIs.
- Passkeys include explicit orphan cleanup for polymorphic owners.
- Fortify and Passkeys support boot-time request-aware redirect callbacks for multi-tenant and multi-guard post-login destinations.
- Fortify fixes the two-factor response contract mismatch.
- Fortify's two-factor provider contract accepts the configured secret length, and the default is `32` for `pragmarx/google2fa` v9.
- Fortify derives its password reset broker from the selected guard provider instead of using Laravel's separate `fortify.passwords` setting.
- Recovery code replacement operates on decoded JSON entries.
- Fortify omits Laravel's deprecated `Rules\Password`.
- Fortify tightens loose upstream comparisons and application-model docblocks where Hypervel can express the real contract.

Keep each note to one concise bullet. Avoid moving implementation details from this plan into the README.

## Test Porting Plan

### General Test Rules

Laravel Passkeys uses Pest; Hypervel components use PHPUnit. Convert Pest tests into PHPUnit test classes.

Run relevant tests immediately after creating or modifying test files. Use focused package commands while building the port, then run the repo-standard parallel suite before final handoff.

Recommended commands:

```bash
composer dump-autoload
vendor/bin/phpunit tests/Passkeys
vendor/bin/phpunit tests/Fortify
vendor/bin/phpunit tests/Passkeys tests/Fortify
composer test:parallel
composer analyse
composer lint
```

If the repo's standard test command differs, use the existing component command from `composer.json`.

Create package-specific base test cases:

- `tests/Passkeys/PasskeysTestCase.php`.
- `tests/Fortify/FortifyTestCase.php`.

The base test cases should centralize service provider registration, test migrations, config defaults, route setup, and common fixture helpers. Port Orchestra/Testbench `DefineEnvironment` callbacks into explicit methods on these base cases or PHPUnit-friendly attributes/helpers used by the relevant tests.

Test fixture mapping:

- Move Laravel Fortify `workbench/app` and `workbench/database` fixtures into Hypervel test fixtures under `tests/Fortify/Fixtures` unless an existing shared `src/testbench/workbench` fixture is a better fit.
- Avoid ambiguous fixture class names such as a bare `User` that collide between Fortify and Passkeys; use package-specific namespaces and class names.
- Copy Fortify stub action fixtures into test fixtures and update namespaces instead of building string fixtures inline.
- Convert runtime `$this->app['config']->set(...)` / `config()->set(...)` test setup to `#[WithConfig]` when the value is static for the test. Keep runtime config mutation only where the test is explicitly exercising boot/test-only config mutation.
- Remove `#[RequiresLaravel]` attributes and `Application::VERSION` branches. Hypervel should test the modern path directly.
- Replace Orchestra-specific migration/config attributes with Hypervel Testbench equivalents from `src/testbench` after checking their exact signatures.

### Passkeys Tests To Port

From Laravel:

- `tests/Feature/PasskeysTest.php`.
- `tests/Feature/PasskeyTest.php`.
- `tests/Feature/PasskeyAuthenticatableTest.php`.
- `tests/Feature/WebAuthnTest.php`.
- `tests/Feature/Actions/DeletePasskeyTest.php`.
- `tests/Feature/Actions/GenerateRegistrationOptionsTest.php`.
- `tests/Feature/Actions/GenerateVerificationOptionsTest.php`.
- `tests/Feature/Actions/StorePasskeyTest.php`.
- `tests/Feature/Actions/VerifyPasskeyTest.php`.
- `tests/Feature/Controllers/PasskeyConfirmationTest.php`.
- `tests/Feature/Controllers/PasskeyLoginControllerTest.php`.
- `tests/Feature/Controllers/PasskeyLoginTest.php`.
- `tests/Feature/Controllers/PasskeyRegistrationTest.php`.
- `tests/ArchTest.php`.

Create fixtures for:

- Passkey-capable user model.
- In-memory routes/controller test app.
- WebAuthn credential JSON payloads.
- Session-backed registration/login/confirmation options.
- Deterministic WebAuthn signing helpers converted from Pest globals into a PHPUnit fixture trait.

Laravel's Pest helper imports `Symfony\Component\Uid\Uuid` only to create test AAGUIDs. During the port, prefer a fixed AAGUID value through whatever value type the installed `web-auth/webauthn-lib` API expects. If the PHPUnit fixtures directly import `Symfony\Component\Uid\Uuid`, add `symfony/uid` as a test-only dependency instead of relying on it as a transitive dependency.

Rewrite Pest tests as PHPUnit classes. Reimplement `ArchTest.php` with reflection or filesystem scans:

- Controllers extend `Hypervel\Routing\Controller` and end in `Controller`.
- Actions are invokable.
- Exceptions extend `Exception` and end in `Exception`.
- Form requests extend `Hypervel\Foundation\Http\FormRequest` and end in `Request`.

Do not port Pest-only syntax or architecture helpers.

Additional Hypervel tests:

1. `Passkeys::flushState()` resets model, routes, login authorization callback, and redirect callback.
2. `Aaguids::flushState()` reloads cache after flush.
3. `WebAuthn::flushState()` rebuilds serializer, attestation support manager, ceremony managers, and clears the ceremony factory customization callback after flush.
4. `PasskeyRegistrationResponse` does not leak passkey data across resolves or reuse.
5. `StorePasskey` converts duplicate unique-key insert into `InvalidPasskeyException`.
6. `PasskeyRegistrationController::destroy()` accepts equivalent scalar int/string owner IDs only when the owner morph class also matches, and rejects real mismatches.
7. Session option keys survive across request boundaries.
8. Fortify route integration disables standalone Passkeys route registration.
9. The `{passkey}` route binding uses the configured passkey model and rejects wrong model types.
10. The AAGUID map script/resource path is present and loads the generated map.
11. Two different `PasskeyUser` model classes can own passkeys in the same `passkeys` table.
12. `PasskeyAuthenticatable` deletes related passkeys when an owner model is really deleted or force deleted, and preserves them on reversible soft delete.
13. Ownership checks honor Eloquent morph maps.
14. Passkeys follows the current default guard selected by `Auth::shouldUse()` and has no package-local guard pin.
15. The migration works with Hypervel's default numeric morph key type and has a focused test or schema assertion for UUID/ULID morph key configuration.
16. Passwordless login scopes or rejects credentials whose `user_type` does not match the selected guard provider model's morph class.
17. Passwordless login fails with `InvalidPasskeyException` when a credential's polymorphic owner is missing or does not implement `PasskeyUser`.
18. `passkeys:prune-orphans --dry-run` reports orphaned passkeys without deleting, and `passkeys:prune-orphans` deletes rows whose owner type or owner key no longer resolves.
19. Generating and deserializing passkey registration options emits no `E_USER_DEPRECATED` notices from WebAuthn dependencies.
20. Stored credentials deserialize as `Webauthn\CredentialRecord`; no Hypervel source imports `Webauthn\PublicKeyCredentialSource`.
21. `WebAuthn::configureCeremonyStepManagerFactoryUsing()` customizes ceremony managers at boot and resets cached managers when changed.
22. Representative Passkeys events are not constructed or dispatched when the event dispatcher reports no listeners through `hasListeners()`, and are dispatched when listeners exist.
23. Standalone `Passkeys::redirectUsing()` is evaluated per request through `PasskeyLoginResponse` for both JSON and normal redirects, can read the current guard/tenant context, falls back to `passkeys.redirect` when no callback is registered, and is reset by `Passkeys::flushState()`.
24. JSON route contract compatibility with `@laravel/passkeys`: default endpoints, route override endpoints, `{ options: ... }` envelopes, `{ name, credential }` registration request, `{ credential }` verification request, registration response `id` / `name`, and optional `redirect` response.

### Fortify Tests To Port

From Laravel:

- `AuthenticatedSessionControllerTest.php`.
- `AuthenticatedSessionControllerWithTwoFactorTest.php`.
- `ConfirmablePasswordControllerTest.php`.
- `EmailVerificationNotificationControllerTest.php`.
- `EmailVerificationPromptControllerTest.php`.
- `FortifyServiceProviderTest.php`.
- `InteractsWithTwoFactorStateTest.php`.
- `NewPasswordControllerTest.php`.
- `PasskeyTest.php`.
- `PasswordControllerTest.php`.
- `PasswordResetLinkRequestControllerTest.php`.
- `ProfileInformationControllerTest.php`.
- `RecoveryCodeControllerTest.php`.
- `RegisteredUserControllerTest.php`.
- `TwoFactorAuthenticationControllerTest.php`.
- `VerifyEmailControllerTest.php`.

Do not port `PasswordRuleTest.php` as-is because the deprecated Laravel Fortify rule should not be a live Hypervel API. Replace it with tests for the modern password validation rule path used by Hypervel Fortify stubs/actions.

Fix known upstream test bugs while porting:

- In `RecoveryCodeControllerTest`, replace discarded `$user->fresh();` with `$user = $user->fresh();`.
- In `TwoFactorAuthenticationControllerTest`, replace the discarded `$user->fresh();` in the disable test with `$user = $user->fresh();`.

Port Laravel `#[DefineEnvironment]` usage into explicit Hypervel fixtures/helpers:

- `withTwoFactorAuthentication`.
- `withConfirmedTwoFactorAuthentication`.
- `withoutTwoFactorAuthentication`.
- `withPasskeys`.
- `withoutPasskeys`.
- `withPasskeysLimiter`.
- `withPasskeysConfirmingPasswords`.
- `withPasskeysWithoutPasswordConfirmation`.

Remove Laravel version-gated branches from `AuthenticatedSessionControllerTest` and `VerifyEmailControllerTest`; keep only the behavior expected on current Hypervel.

Additional Hypervel tests:

1. `Fortify::flushState()` resets callbacks, redirect callbacks, route registration, and custom encrypter.
2. `Features::passkeys([...])` and `Features::twoFactorAuthentication([...])` set options when called during setup.
3. `Features::options()` / `Features::option()` read config-cache-safe `fortify-options.*` values for `confirm`, `confirmPassword`, `secret-length`, and `window`; default `secret-length` is `32`.
4. Fortify's provider bridges Fortify WebAuthn passkey settings into `passkeys.*` and installs a Passkeys redirect callback that delegates to `Fortify::redirects('login', request: $request)`.
5. Fortify does not register Passkeys standalone routes when using Fortify passkey routes.
6. `TwoFactorAuthenticationProvider` custom window does not leak to later verifications.
7. `TwoFactorEnabledResponse` implements `TwoFactorEnabledResponseContract`.
8. `TwoFactorDisabledResponse` implements `TwoFactorDisabledResponseContract`.
9. Recovery code replacement changes only the exact decoded array entry.
10. No singleton response stores per-request mutable data.
11. Removed Laravel version shims run through the modern Hypervel paths.
12. Loose Laravel comparisons were tightened without changing success/failure behavior.
13. Fortify event user parameters are auth-contract typed, not app-model typed.
14. `AuthenticatedSessionController` builds the login pipeline from a single config snapshot per call.
15. Fortify authentication and password reset flows follow the current default guard selected by `Auth::shouldUse()`.
16. Built-in Fortify routes use bare `guest`, `auth`, and `password.confirm` middleware so early guard-selection middleware controls the active guard.
17. Fortify derives the password broker from the selected guard provider, and ambiguous or missing matches fail with a clear configuration exception.
18. Fortify's passkey bridge copies WebAuthn settings into `passkeys.*`, installs the request-aware login redirect callback, and does not set route-only settings or a global passkey user model.
19. Fortify passkey login responses honor `Fortify::redirects('login', request: $request)` for both JSON redirect payloads and normal redirects.
20. Failed login events from password and two-factor authentication contain `Fortify::guardName()`, not a removed Fortify guard config or non-contract guard property.
21. Representative Fortify events are not constructed or dispatched when the event dispatcher reports no listeners through `hasListeners()`, and are dispatched when listeners exist.
22. `Fortify::redirectUsing()` callbacks are evaluated per request for relevant response classes, can read current guard/tenant context, fall back to `fortify.redirects.*` / `fortify.home` when absent or null, and are reset by `Fortify::flushState()`.

### Coroutine Safety Tests

Add focused Swoole-style state-leak tests even if they run sequentially in PHPUnit:

- Resolve a singleton/stateless response twice and verify no request data remains.
- Run one 2FA verification with custom window and one with default window in the same process.
- Configure static callbacks in one test and verify `AfterEachTestSubscriber` flushes before the next test.

If the repo has coroutine test helpers, add one concurrent test for passkey response leakage and 2FA provider leakage. Otherwise keep deterministic process-level tests.

## Performance Plan

Keep or add these worker-lifetime caches:

- `WebAuthn` serializer.
- `WebAuthn` attestation statement support manager.
- `WebAuthn` registration ceremony step manager.
- `WebAuthn` authentication ceremony step manager.
- `Aaguids` map.

Consider but do not blindly add:

- Supported WebAuthn algorithm parameter cache. This list is tiny, so caching is optional and likely unnecessary.

Avoid these overheads:

- Do not rebuild WebAuthn serializer per request.
- Do not use `CoroutineContext` for session ceremony data.
- Do not add broad locking around passkey reads; lock only the credential row during verification counter update.
- Do not encrypt each recovery code separately; keep one encrypted JSON string and perform structured replacement inside the decrypted array.
- Do not cache the QR writer/backend; `SvgImageBackEnd` is mutable per render and QR generation is not a hot path.

Expected overhead from safety fixes:

- Fresh/immutable `PasskeyRegistrationResponse`: one small object allocation on registration only.
- Structured recovery-code replacement: one `json_decode()` and `json_encode()` only when a recovery code is consumed.
- Per-call Google2FA window argument: no additional allocation and no shared engine mutation.
- Request-aware guard resolution: one current auth guard lookup per relevant request; Hypervel's auth manager caches guard instances, and current-user state remains coroutine-local.
- Password broker inference: one config scan over `auth.passwords` during reset-link / reset-password requests only.
- Request-aware redirect callbacks: one optional callback invocation on successful response paths only; the callback is worker-lifetime configuration and its result is not cached.
- `hasListeners()` event guards: one listener-registry check per package event site, avoiding event object construction and dispatch overhead when no listeners are registered.
- Unique violation catch: no steady-state overhead; only affects duplicate race path.
- Passkey orphan pruning: no request-path overhead; runs only when the console command is invoked.

These are acceptable and either neutral or positive for long-lived worker correctness.

## Implementation Order

1. Add dependency constraints with latest stable compatible versions and update `composer.lock`.
2. Add root autoload, replace entries, and provider discovery entries.
3. Create `src/passkeys` package skeleton.
4. Port Passkeys config, model, migration, contracts, events, exceptions, support classes, actions, requests, controllers, responses, routes, provider, prune-orphans command, README, and `scripts/sync-aaguids.php`.
5. Add Passkeys static cleanup to `AfterEachTestSubscriber`.
6. Port Passkeys tests and new Hypervel-specific leak/race tests.
7. Create `src/fortify` package skeleton.
8. Port Fortify config, contracts, responses, actions, controllers, requests, two-factor support, routes, provider, console command, stubs, migrations, README.
9. Wire Fortify to Passkeys and ensure standalone Passkeys routes are ignored when Fortify owns the passkey feature.
10. Add Fortify static cleanup to `AfterEachTestSubscriber`.
11. Port Fortify tests and new Hypervel-specific leak/contract/recovery-code tests.
12. Copy `examples/laravel/docs/fortify.md` to `src/boost/docs/fortify.md` and rewrite for Hypervel.
13. Add docs index entries if needed.
14. Run package tests, static analysis, style checks, and full relevant test suite.
15. Final dead-code pass: remove unused imports, unused classes, stale docs, commented-out code, and transitional notes.

## Verification Commands

Use the exact available repo commands after implementation. Expected baseline:

```bash
composer dump-autoload
vendor/bin/phpunit tests/Passkeys
vendor/bin/phpunit tests/Fortify
vendor/bin/phpunit tests/Passkeys tests/Fortify
composer test:parallel
composer analyse
composer lint
```

If dependency changes touch broad framework behavior, also run:

```bash
vendor/bin/phpunit
```

For docs:

```bash
grep -R "Laravel\\\\" -n src/fortify src/passkeys src/boost/docs/fortify.md
grep -R "Illuminate\\\\" -n src/fortify src/passkeys
grep -R "TODO\\|FIXME\\|@todo" -n src/fortify src/passkeys src/boost/docs/fortify.md
grep -R "PublicKeyCredentialSource" -n src/passkeys tests/Passkeys
grep -R "PublicKeyCredentialRpEntity::create(name\\|new PublicKeyCredentialRpEntity" -n src/passkeys tests/Passkeys
```

Any remaining Laravel/Illuminate references must be intentional references in docs or removed.
Any `PublicKeyCredentialSource` reference or non-empty relying-party entity constructor usage should be treated as a bug unless the installed WebAuthn dependency has removed the deprecation and the source comment explains the new API.

## Final Review Checklist

- The package split is implemented cleanly: Passkeys standalone, Fortify integrated.
- Direct third-party dependencies use latest stable compatible versions resolved by Composer at implementation time.
- Source has no request-specific mutable state on singletons.
- All worker-lifetime static caches have `flushState()`.
- `tests/AfterEachTestSubscriber.php` flushes all new static state.
- No `Config::set()` or config repository `set()` occurs during request handling.
- Package event dispatches use the `hasListeners()` guard pattern where events are listener-driven extension points.
- Fortify and Passkeys use the current request default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`; there is no package-local guard config.
- Built-in route middleware uses bare `auth`, `guest`, and `password.confirm` so earlier guard-selection middleware remains authoritative.
- Multi-guard route docs require early guard-selection middleware before `guest`, `auth`, `password.confirm`, and package controllers.
- Fortify password reset broker resolution infers exactly one broker from the selected guard provider and has no Fortify-specific broker override.
- Fortify's passkey integration bridges WebAuthn settings plus a request-aware login redirect callback, and does not bridge route-only guard/middleware/throttle settings.
- Fortify and Passkeys redirect callbacks are boot-only registrations whose return values are computed per request and are reset by `flushState()`.
- Passkey ownership is polymorphic and supports multiple authenticatable model classes in one table.
- Passwordless passkey login constrains credentials to the selected guard provider model's morph class.
- Passkey orphan cleanup is explicit: model-event cleanup for instance deletes plus `passkeys:prune-orphans` for mass/raw delete fallout.
- WebAuthn code uses no deprecated dependency APIs.
- Stored passkey credentials use `CredentialRecord`, not deprecated `PublicKeyCredentialSource`.
- WebAuthn ceremony state stays in session.
- `CoroutineContext` is used only if new request-local transient state requires it.
- `PasskeyRegistrationResponse` cannot leak a previous passkey.
- `TwoFactorAuthenticationProvider` cannot leak custom window settings.
- Two-factor secret generation defaults to length `32`, and the provider contract matches the implementation.
- Recovery code replacement works on decoded JSON array entries.
- Duplicate passkey credential insert races become `InvalidPasskeyException`.
- Passkey ownership comparisons are scalar-safe.
- Passkey login fails cleanly if the stored owner relation is missing or invalid.
- Fortify two-factor response contracts are correct.
- Fortify event user types use auth contracts, not `App\Models\User`.
- Laravel loose comparisons identified in the source plan are strict.
- `AuthenticatedSessionController` uses `Hypervel\Routing\Pipeline` and avoids repeated config reads in a single request.
- Deprecated Laravel Fortify password rule is not ported as a live API.
- Boost `fortify.md` exists and is Hypervel-specific.
- READMEs document only current, intentional behavior.
- Tests cover Laravel parity plus Hypervel-specific Swoole safety concerns.
- Static analysis and style checks pass.
- No stale or dead code, comments, docs, or tests remain.
