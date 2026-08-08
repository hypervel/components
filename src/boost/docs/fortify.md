# Hypervel Fortify

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
    - [Features](#features)
    - [Views](#views)
    - [Routes](#routes)
    - [Redirects](#redirects)
    - [Multi-Guard Applications](#multi-guard-applications)
- [Authentication](#authentication)
    - [Customizing User Authentication](#customizing-user-authentication)
    - [Customizing the Login Pipeline](#customizing-the-login-pipeline)
    - [Rate Limiting](#rate-limiting)
- [Registration](#registration)
- [Password Resets](#password-resets)
- [Email Verification](#email-verification)
- [Profile Information](#profile-information)
- [Passwords](#passwords)
- [Password Confirmation](#password-confirmation)
- [Two-Factor Authentication](#two-factor-authentication)
- [Passkeys](#passkeys)
    - [Frontend Package](#frontend-package)
    - [Request And Response Contracts](#request-and-response-contracts)
    - [Passkey Models](#passkey-models)
    - [Passkey Cleanup](#passkey-cleanup)
    - [Standalone Passkeys](#standalone-passkeys)
- [Swoole And Worker State](#swoole-and-worker-state)

<a name="introduction"></a>
## Introduction

Hypervel Fortify is a headless authentication backend for Hypervel applications. Fortify registers the routes, controllers, actions, response contracts, and feature flags needed for common authentication workflows:

<div class="content-list" markdown="1">

- Login and logout.
- Registration.
- Password reset links and password resets.
- Email verification.
- Profile information updates.
- Password updates.
- Password confirmation.
- Two-factor authentication.
- Passkey authentication through `hypervel/passkeys`.

</div>

Fortify does not provide a frontend. You may render traditional Hypervel views, build your own SPA, or use Fortify as a JSON backend. Applications customize behavior by publishing Fortify's action classes and by binding or configuring response contracts.

The passkey implementation lives in the separate `hypervel/passkeys` package. Fortify integrates with it and exposes passkeys as a Fortify feature, while the passkeys package remains usable for custom authentication flows outside Fortify.

<a name="installation"></a>
## Installation

Install Fortify with Composer:

```shell
composer require hypervel/fortify
```

Then run the install command:

```shell
php artisan fortify:install
```

The command publishes Fortify's configuration, action classes, service provider, and migrations. After publishing, run your migrations:

```shell
php artisan migrate
```

Fortify's package service provider is discovered automatically. The install command also registers the published `App\Providers\FortifyServiceProvider` in `bootstrap/providers.php`; that provider is where application-specific Fortify callbacks and action bindings should be configured.

<a name="configuration"></a>
## Configuration

Fortify's configuration file is published to `config/fortify.php`.

By default, `fortify.guard` is `null` and Fortify uses Hypervel's current default guard for the request. A standard application uses `auth.defaults.guard`; a multi-guard application may select the guard early in middleware by calling `Auth::shouldUse($guard)`. If `fortify.guard` is set, Fortify adds guard-selection middleware to its built-in route group before `guest`, `auth`, and `password.confirm`, so the configured guard becomes the current request guard for all Fortify routes.

When Fortify owns passkey routes, those integrated routes use `fortify.guard`. The standalone `passkeys.guard` setting only matters when using the Passkeys package without Fortify.

Password reset broker selection follows the selected guard. Guards that send password reset links declare their broker with the `passwords` key in `config/auth.php`.

<a name="features"></a>
### Features

Fortify features are enabled in the `features` array:

```php
use Hypervel\Fortify\Features;

'features' => [
    Features::registration(),
    Features::resetPasswords(),
    // Features::emailVerification(),
    Features::updateProfileInformation(),
    Features::updatePasswords(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
        'secret-length' => 32,
        // 'window' => 0,
    ]),
    Features::passkeys([
        'confirmPassword' => true,
    ]),
],
```

Email verification is commented out in newly published configuration. Enable it after your user model implements the [`MustVerifyEmail` contract](/docs/{{version}}/fortify#email-verification).

Supplying options to `Features::twoFactorAuthentication()` or `Features::passkeys()` stores them in the process-global config repository and should only be done during boot. Calling either method without options only returns its feature identifier and is safe during request handling.

<a name="views"></a>
### Views

Fortify does not ship frontend views, but view routes are enabled by default. If `fortify.views` is `true`, Fortify registers view routes for login, registration, password reset, email verification, password confirmation, and two-factor challenge pages. Register view responses with the methods below, or set `fortify.views` to `false` when your application only uses JSON endpoints.

If views are disabled while password resets remain enabled, define a route named `password.reset` or [customize the password reset URL](/docs/{{version}}/passwords#reset-link-customization) during boot with `ResetPassword::createUrlUsing()`.

Register view responses during boot:

```php
use Hypervel\Fortify\Fortify;

public function boot(): void
{
    Fortify::loginView('auth.login');
    Fortify::registerView('auth.register');
    Fortify::requestPasswordResetLinkView('auth.forgot-password');
    Fortify::resetPasswordView('auth.reset-password');
    Fortify::verifyEmailView('auth.verify-email');
    Fortify::confirmPasswordView('auth.confirm-password');
    Fortify::twoFactorChallengeView('auth.two-factor-challenge');
}
```

These methods mutate worker-lifetime bindings and should be called only during application boot or tests.

<a name="routes"></a>
### Routes

Fortify registers its routes automatically unless `Fortify::ignoreRoutes()` is called during boot. Route paths may be customized through `fortify.paths`.

When `fortify.guard` is `null`, Fortify uses bare `guest`, `auth`, and `password.confirm` middleware in its built-in routes. This is intentional: those middleware use the current request default guard selected by your application. When `fortify.guard` is set, Fortify first runs `auth.guard:{guard}` so the configured guard becomes the current request guard before those middleware run.

For full control, disable Fortify's route registration and register routes yourself:

```php
use Hypervel\Fortify\Fortify;

public function register(): void
{
    Fortify::ignoreRoutes();
}
```

<a name="redirects"></a>
### Redirects

Static redirect fallbacks live in `fortify.redirects` and `fortify.home`. Successful login, registration, password confirmation, password reset, email verification, and logout responses use these paths as fallbacks.

Redirects may also be computed per request:

```php
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;

public function boot(): void
{
    Fortify::redirectUsing('login', function (Request $request): string {
        return $request->user()?->isAdmin() ? '/dashboard' : '/account';
    });
}
```

The callback is registered for the worker lifetime, but the callback result is computed for each request. This is safe for multi-tenant and multi-guard applications as long as you do not cache request-specific data in static properties.

This Fortify redirect API controls successful Fortify action fallbacks, such as login or registration responses when no intended URL is stored. It does not configure auth or guest middleware redirects. Use `Auth::redirectGuestsTo()` and `Auth::redirectUsersTo()` for middleware redirects, or the middleware configurator equivalents in `bootstrap/app.php`.

Standalone Passkeys has its own `Passkeys::redirectUsing()` callback and `passkeys.redirect` fallback. When Fortify integrates passkeys, it installs a callback so passkey login uses the same request-aware login redirect as password login.

<a name="multi-guard-applications"></a>
### Multi-Guard Applications

With the default `fortify.guard` value of `null`, Fortify uses the current request default guard. For domain-based, path-based, tenant-based, or role-based guard selection, add middleware early in the stack:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
        'passwords' => 'users',
    ],

    'admin' => [
        'driver' => 'session',
        'provider' => 'admins',
        'passwords' => 'admins',
    ],
],
```

```php
use Closure;
use Hypervel\Contracts\Auth\Factory as Auth;
use Hypervel\Http\Request;

final class SelectAuthenticationGuard
{
    public function __construct(
        private readonly Auth $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $this->auth->shouldUse($request->routeIs('admin.*') ? 'admin' : 'web');

        return $next($request);
    }
}
```

Run this middleware before `guest`, `auth`, `password.confirm`, Fortify controllers, and Passkeys controllers. Named middleware select their guard as the request default: `auth:admin` selects `admin` when authentication succeeds, and `guest:admin` selects `admin` when the request passes the guest check. Use middleware like the example above when many routes should share one guard without naming it on each middleware.

If every built-in Fortify route should use the same guard, set `fortify.guard` instead of writing custom middleware. Fortify will apply that guard to its own routes and to integrated passkey routes. When using `hypervel/passkeys` without Fortify, use the standalone `passkeys.guard` setting the same way.

Registration remains application-controlled. Multi-guard applications should make the published `CreateNewUser` action aware of the selected guard, route, tenant, or domain and create the correct user model there.

<a name="authentication"></a>
## Authentication

Fortify's login route accepts the field configured by `fortify.username` and a `password` field. The default username field is `email`. A `remember` field may be provided to use remember-me authentication.

The published Fortify configuration lowercases usernames and email addresses during login, registration, profile updates, password reset link requests, and password resets. Set `fortify.lowercase_usernames` to `false` to disable this behavior. Applications without a published `config/fortify.php` file use the package fallback of `false`.

<a name="customizing-user-authentication"></a>
### Customizing User Authentication

Use `Fortify::authenticateUsing()` when your application needs custom credential validation:

```php
use App\Models\User;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Hash;

public function boot(): void
{
    Fortify::authenticateUsing(function (Request $request): ?User {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password)) {
            return null;
        }

        $user = User::where('email', $email)->first();

        return $user !== null && is_string($user->password) && Hash::check($password, $user->password)
            ? $user
            : null;
    });
}
```

Register this callback during boot. The callback persists for the worker lifetime.

<a name="customizing-the-login-pipeline"></a>
### Customizing the Login Pipeline

Fortify authenticates requests through a pipeline. You may replace the default pipeline:

```php
use Hypervel\Fortify\Actions\AttemptToAuthenticate;
use Hypervel\Fortify\Actions\PrepareAuthenticatedSession;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;

public function boot(): void
{
    Fortify::authenticateThrough(function (Request $request): array {
        return [
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ];
    });
}
```

<a name="rate-limiting"></a>
### Rate Limiting

The package defaults leave `fortify.limiters.login` and `fortify.limiters.passkeys` unset. Login requests then use Fortify's login throttling pipeline, and passkey routes are not throttled unless configured.

The published configuration sets those limiters to `login` and `passkeys`, and the published `App\Providers\FortifyServiceProvider` registers matching named rate limiters.

The two-factor challenge submit route is throttled by default with `throttle:5,1`. You may set `fortify.limiters.two-factor` to a different throttle string or to a named limiter if your application needs custom keying.

```php
use Hypervel\Http\Request;
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

RateLimiter::for('login', function (Request $request): Limit {
    return Limit::perMinute(5)->by($request->input('email') . '|' . $request->ip());
});
```

<a name="registration"></a>
## Registration

Fortify creates users through the published `App\Actions\Fortify\CreateNewUser` action. Customize that action for your application's validation, user model, tenant, and guard requirements.

The action must implement `Hypervel\Fortify\Contracts\CreatesNewUsers`:

```php
use Hypervel\Contracts\Auth\Authenticatable;

public function create(array $input): Authenticatable
{
    // Validate input and create the correct user model.
}
```

After registration, Fortify logs the created user into the current default guard.

<a name="password-resets"></a>
## Password Resets

Fortify sends reset links and resets passwords through Hypervel's password broker services. Fortify resolves the broker from the selected guard:

1. Resolve the current guard name.
2. Read `auth.guards.{guard}.passwords`.
3. Use the named broker from `auth.passwords`.

If the selected guard does not declare a `passwords` broker, Hypervel throws a configuration exception naming the guard and the key to add.

If multiple user providers may share email addresses, use separate password reset token tables or cache stores per broker so one provider's token does not replace another provider's token for the same email address.

Customize password reset behavior in the published `ResetUserPassword` action.

<a name="email-verification"></a>
## Email Verification

Enable email verification with `Features::emailVerification()` and make your user model implement `Hypervel\Contracts\Auth\MustVerifyEmail`.

The built-in routes include:

<div class="content-list" markdown="1">

- `verification.notice`
- `verification.verify`
- `verification.send`

</div>

<a name="profile-information"></a>
## Profile Information

Enable profile information updates with `Features::updateProfileInformation()`. Customize the published `UpdateUserProfileInformation` action for your validation rules and model fields.

<a name="passwords"></a>
## Passwords

Enable password updates with `Features::updatePasswords()`. Customize the published `UpdateUserPassword` action.

Hypervel Fortify uses Hypervel's framework password rule directly. Laravel Fortify's deprecated `Rules\Password` compatibility wrapper is not included.

<a name="password-confirmation"></a>
## Password Confirmation

Fortify supports password confirmation through the `password.confirm` middleware and built-in confirmation routes. Password confirmation uses the current default guard. Confirmation is stored per guard, and lockout throttling for login attempts is also scoped per guard.

You may customize password confirmation:

```php
use Hypervel\Fortify\Fortify;

Fortify::confirmPasswordsUsing(function ($user, ?string $password): bool {
    return $password === 'known-test-password';
});
```

Register this callback during boot or tests only.

<a name="two-factor-authentication"></a>
## Two-Factor Authentication

Enable two-factor authentication with `Features::twoFactorAuthentication()`. Your user model should implement `Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser` and use `Hypervel\Fortify\TwoFactorAuthenticatable`:

```php
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\TwoFactorAuthenticatable;
use Hypervel\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements TwoFactorAuthenticationUser
{
    use TwoFactorAuthenticatable;
}
```

Your users table must contain Fortify's two-factor columns:

<div class="content-list" markdown="1">

- `two_factor_secret`
- `two_factor_recovery_codes`
- `two_factor_confirmed_at`

</div>

If the `confirmPassword` option is enabled, two-factor management routes require [password confirmation](#password-confirmation). The `confirm` option requires users to verify a valid code before their two-factor configuration is considered fully enabled.

The built-in two-factor routes include:

<div class="content-list" markdown="1">

- `POST /user/two-factor-authentication` enables two-factor authentication.
- `POST /user/confirmed-two-factor-authentication` confirms a newly enabled two-factor configuration.
- `GET /user/two-factor-qr-code` returns the QR code SVG.
- `GET /user/two-factor-secret-key` returns the decrypted secret key.
- `GET /user/two-factor-recovery-codes` returns the user's recovery codes.
- `POST /user/two-factor-recovery-codes` regenerates recovery codes.
- `DELETE /user/two-factor-authentication` disables two-factor authentication.

</div>

Fortify stores recovery codes as one encrypted JSON value. When a recovery code is used, Hypervel Fortify replaces the exact decoded JSON array entry and re-encrypts the whole JSON value.

The two-factor provider defaults to 32-character TOTP secrets. The optional `window` feature option is step-based: a value of `1` accepts the previous, current, and next 30-second periods. Accepted TOTP codes are cached for the full accepted window to prevent replay for as long as Fortify still accepts the code. Hypervel Fortify uses fresh OTPHP TOTP objects with an injected clock, so verification does not mutate shared TOTP engine state in a Swoole worker.

During login, users with enabled two-factor authentication are redirected to the two-factor challenge route. JSON login requests receive a response containing a `two_factor` boolean. The challenge form should submit either a `code` field containing a TOTP code or a `recovery_code` field containing one of the user's recovery codes to `POST /two-factor-challenge`.

<a name="passkeys"></a>
## Passkeys

Enable passkeys with `Features::passkeys()`. Fortify will register passkey login, confirmation, registration, and deletion routes when this feature is enabled.

Passkey registration and deletion routes require [password confirmation](#password-confirmation) by default. Set `Features::passkeys(['confirmPassword' => false])` if your application should allow authenticated users to manage passkeys without first confirming their password.

Fortify bridges these settings into the standalone Passkeys package:

```php
'passkeys' => [
    'relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID', parse_url(config('app.url'), PHP_URL_HOST)),
    'allowed_origins' => env_array('PASSKEYS_ALLOWED_ORIGINS', [config('app.url')]),
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
    'timeout' => (int) env('PASSKEYS_TIMEOUT', 60000),
],
```

Set `PASSKEYS_ALLOWED_ORIGINS` to a comma-separated list when WebAuthn ceremonies should be accepted from more than one origin, such as `https://example.com,https://www.example.com`.

If the relying party ID or allowed origins depend on the current request, such as for custom domains or multi-tenant applications, register request-aware callbacks during boot:

```php
use Hypervel\Http\Request;
use Hypervel\Passkeys\Passkeys;

public function boot(): void
{
    Passkeys::resolveRelyingPartyIdUsing(
        fn (Request $request): string => $request->getHost(),
    );

    Passkeys::resolveAllowedOriginsUsing(
        fn (Request $request): array => ['https://' . $request->getHost()],
    );
}
```

These callbacks take priority over the static config values when a request is available. Without a current request, Passkeys falls back to `passkeys.relying_party_id` and `passkeys.allowed_origins`. The relying party ID and allowed origins are separate WebAuthn settings, so register `resolveAllowedOriginsUsing()` whenever allowed origins vary by request. Static config uses cached WebAuthn ceremony managers; request-aware origins are resolved for each ceremony so origin-specific state does not leak between requests.

The resolved relying party ID must be a registrable-domain suffix of the resolved origins. Otherwise, browsers will reject the WebAuthn ceremony before the server can verify it.

`user_handle_secret` is a long-lived secret used to derive stable WebAuthn user handles. It defaults to the app key for convenience, but production applications should set a dedicated value before registering passkeys. Changing it changes generated user handles.

<a name="frontend-package"></a>
### Frontend Package

Hypervel's passkey routes are compatible with the `@laravel/passkeys` frontend package:

```shell
npm install @laravel/passkeys
```

The frontend package defaults to these backend endpoints:

<div class="content-list" markdown="1">

- `GET /passkeys/login/options`
- `POST /passkeys/login`
- `GET /user/passkeys/options`
- `POST /user/passkeys`

</div>

It also supports route overrides:

```js
await Passkeys.verify({
    routes: {
        options: '/passkeys/confirm/options',
        submit: '/passkeys/confirm',
    },
});
```

<a name="request-and-response-contracts"></a>
### Request And Response Contracts

Custom passkey frontends should use JSON requests and preserve the normal Hypervel CSRF/session credentials. The built-in endpoints use these request and response envelopes:

<div class="content-list" markdown="1">

- `GET /passkeys/login/options` returns `{ "options": ... }`.
- `POST /passkeys/login` accepts `{ "credential": ..., "remember": true|false }`, with `remember` optional, and returns `{ "redirect": "..." }` for JSON requests.
- `GET /passkeys/confirm/options` returns `{ "options": ... }`.
- `POST /passkeys/confirm` accepts `{ "credential": ... }` and returns `{ "redirect": "..." }` for JSON requests.
- `GET /user/passkeys/options` returns `{ "options": ... }`.
- `POST /user/passkeys` accepts `{ "name": "...", "credential": ... }` and returns `{ "status": "passkey-registered", "id": "...", "name": "..." }` for JSON requests.
- `DELETE /user/passkeys/{passkey}` returns `{ "status": "passkey-deleted" }` for JSON requests.

</div>

<a name="passkey-models"></a>
### Passkey Models

Every model that owns passkeys should implement `Hypervel\Passkeys\Contracts\PasskeyUser` and use `Hypervel\Passkeys\PasskeyAuthenticatable`:

```php
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\PasskeyAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;
}
```

Passkeys use a polymorphic `user` relation by default, backed by `user_type` and `user_id` columns. This lets one application store passkeys for multiple authenticatable model classes in the same `passkeys` table.

Passwordless passkey login is scoped to the selected guard provider's Eloquent model. A passkey registered to an `Admin` model cannot authenticate a `User` guard, even if the credential ID exists.

The default migration uses Hypervel's configured morph key type. Applications using UUID or ULID owner keys should configure the framework morph key type before running the passkeys migration. Applications that mix owner key storage types should publish and customize the migration while keeping the `user_type` / `user_id` column names.

<a name="passkey-cleanup"></a>
### Passkey Cleanup

`PasskeyAuthenticatable` deletes related passkeys during normal Eloquent instance deletes and force deletes. Reversible soft deletes preserve passkeys.

Polymorphic ownership cannot provide database-level cascading deletes for every owner table. Mass deletes, quiet deletes, and raw SQL can bypass model events and leave orphaned passkeys. Hypervel Passkeys includes a cleanup command:

```shell
php artisan passkeys:prune-orphans
```

Preview cleanup without deleting rows:

```shell
php artisan passkeys:prune-orphans --dry-run
```

Control the scan size with `--chunk`:

```shell
php artisan passkeys:prune-orphans --chunk=500
```

Applications that delete owners outside Eloquent instance deletes should run this command on a schedule or delete passkeys explicitly in the same maintenance job.

If your application stores morph aliases in `user_type`, register the morph map before pruning. Passkeys for unresolved morph aliases are skipped so the command does not delete live credentials whose owner type cannot be resolved.

<a name="standalone-passkeys"></a>
### Standalone Passkeys

Fortify installs and configures `hypervel/passkeys` for Fortify-owned routes. Applications using Passkeys without Fortify may publish the standalone package config and migrations:

```shell
php artisan vendor:publish --tag=passkeys-config
php artisan vendor:publish --tag=passkeys-migrations
php artisan migrate
```

Standalone routes use `passkeys.guard`, `passkeys.middleware`, `passkeys.management_middleware`, `passkeys.throttle`, and `passkeys.redirect`.

<a name="swoole-and-worker-state"></a>
## Swoole And Worker State

Hypervel workers are long-lived. Fortify and Passkeys expose static configuration methods for boot-time setup, and those callbacks or bindings persist for the worker lifetime.

Call these only during application boot or tests:

<div class="content-list" markdown="1">

- `Fortify::authenticateUsing()`
- `Fortify::authenticateThrough()`
- `Fortify::loginThrough()`
- `Fortify::confirmPasswordsUsing()`
- `Fortify::redirectUsing()`
- `Fortify::ignoreRoutes()`
- `Fortify::viewPrefix()`
- `Fortify::viewNamespace()`
- `Fortify::encryptUsing()`
- `Fortify::createUsersUsing()`
- `Fortify::updateUserProfileInformationUsing()`
- `Fortify::updateUserPasswordsUsing()`
- `Fortify::resetUserPasswordsUsing()`
- `Fortify::redirectUserForTwoFactorAuthenticationUsing()`
- `Features::twoFactorAuthentication($options)`
- `Features::passkeys($options)`
- `Passkeys::usePasskeyModel()`
- `Passkeys::authorizeLoginUsing()`
- `Passkeys::resolveRelyingPartyIdUsing()`
- `Passkeys::resolveAllowedOriginsUsing()`
- `Passkeys::redirectUsing()`
- `Passkeys::ignoreRoutes()`
- `WebAuthn::configureCeremonyStepManagerFactoryUsing()`

</div>

Do not call `config()->set()` or mutate Fortify / Passkeys static configuration from request handlers. Per-request decisions should use request data, session data, middleware, the current guard selected by `Auth::shouldUse()`, or request-aware callbacks registered during boot.
