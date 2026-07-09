# Auth Redirect Convenience API

Add provider-friendly auth redirect configuration methods to the Auth facade while keeping the existing `bootstrap/app.php` middleware configuration API. The final design makes auth redirect configuration read as if it was designed around one canonical aggregate implementation from the start: `AuthenticationRedirects` owns the boot-time fan-out, `AuthManager` exposes the provider/facade API, and `Foundation\Configuration\Middleware` exposes the bootstrap API without depending on facade or container boot order.

Hypervel 0.4 is unreleased. Backward compatibility and churn do not drive this plan. The implementation should remove duplicated mapping logic, avoid stale docs, and leave no misleading comments or dead guidance behind.

## Background

### Current behavior verified in source

`src/foundation/src/Configuration/ApplicationBuilder.php` seeds the default unauthenticated redirect during middleware bootstrap:

```php
$middleware = (new Middleware)
    ->redirectGuestsTo(fn () => route('login'));
```

Before this change, `src/foundation/src/Configuration/Middleware.php` owned the aggregate redirect fan-out:

```php
public function redirectGuestsTo(callable|string $redirect): static
{
    return $this->redirectTo(guests: $redirect);
}

public function redirectUsersTo(callable|string $redirect): static
{
    return $this->redirectTo(users: $redirect);
}

public function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): static
{
    $guests = is_string($guests) ? fn () => $guests : $guests;
    $users = is_string($users) ? fn () => $users : $users;

    if ($guests) {
        Authenticate::redirectUsing($guests);
        AuthenticateSession::redirectUsing($guests);
        AuthenticationException::redirectUsing($guests);
    }

    if ($users) {
        RedirectIfAuthenticated::redirectUsing($users);
    }

    return $this;
}
```

The low-level redirect participants are:

- `Hypervel\Auth\Middleware\Authenticate::redirectUsing()` for unauthenticated users rejected by `auth` middleware.
- `Hypervel\Session\Middleware\AuthenticateSession::redirectUsing()` for session-authentication mismatch redirects.
- `Hypervel\Auth\AuthenticationException::redirectUsing()` for auth exceptions that do not carry an explicit redirect path.
- `Hypervel\Auth\Middleware\RedirectIfAuthenticated::redirectUsing()` for authenticated users rejected by `guest` middleware.

Each low-level method stores a static callback that persists for the worker lifetime. Each callback receives the current `Hypervel\Http\Request`, so the callback result can be computed per request even though the registration itself is boot-time state.

`src/foundation/src/Exceptions/Handler.php` converts authentication exceptions into JSON or guest redirects:

```php
protected function unauthenticated(Request $request, AuthenticationException $exception): Response|JsonResponse|RedirectResponse
{
    return $this->shouldReturnJson($request, $exception)
        ? response()->json(['message' => $exception->getMessage()], 401)
        : redirect()->guest($exception->redirectTo($request) ?? route('login'));
}
```

`src/routing/src/Redirector.php` stores the intended URL in `guest()` and uses `/` as the default for `intended()` when no intended URL exists:

```php
public function intended(string $default = '/', int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
{
    $path = $this->session->pull('url.intended', $default);

    return $this->to($path, $status, $headers, $secure);
}
```

Fortify has a separate completion-redirect API. `src/fortify/src/Fortify.php` resolves successful-action fallback redirects through `Fortify::redirects()` and `Fortify::redirectUsing()`, and `src/fortify/src/Http/Responses/LoginResponse.php` uses:

```php
redirect()->intended(Fortify::redirects('login', request: $request));
```

That Fortify API is not the same as auth/guest middleware redirects and should stay documented as a separate concept.

### Upstream-shaped reference

Laravel's `Illuminate\Foundation\Configuration\Middleware` has the same aggregate method names and the same explicit guest-side fan-out to `Authenticate`, `AuthenticateSession`, and `AuthenticationException`. Hypervel should preserve that explicit behavior while moving the aggregate implementation from Foundation to an auth-owned shared configurator so service providers and packages have a clean facade surface without making Foundation middleware bootstrap resolve the Auth facade or container binding.

Laravel's `illuminate/auth` suggests `illuminate/session` for the session guard rather than requiring it. Hypervel can choose a tighter package graph because it is a full-stack framework and 0.4 has no backward-compatibility burden.

## Decisions

### Add the provider-friendly Auth API

Add these methods on `Hypervel\Auth\AuthManager` and document them on `Hypervel\Support\Facades\Auth`:

```php
public function redirectGuestsTo(callable|string $redirect): static;
public function redirectUsersTo(callable|string $redirect): static;
public function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): static;
```

`redirectGuestsTo()` configures where guests are sent when blocked by `auth` middleware.

`redirectUsersTo()` configures where authenticated users are sent when blocked by `guest` middleware.

`redirectTo()` configures both sides at once using named arguments:

```php
Auth::redirectTo(
    guests: fn (Request $request) => route('tenant.login'),
    users: fn (Request $request) => route('tenant.dashboard'),
);
```

Keep `redirectGuestsTo()` and `redirectUsersTo()` typed as `callable|string`, matching the existing Hypervel middleware configurator. Do not widen the single-side convenience methods to `null`: for these methods, `null` would only be a no-op. `redirectTo()` remains `callable|string|null` for each named side because `null` there means "do not configure that side."

### Make AuthenticationRedirects the source of truth

`AuthenticationRedirects` should own the aggregate fan-out. `AuthManager` should keep the provider/facade-facing public API, and `Foundation\Configuration\Middleware` should keep its app-bootstrap convenience methods. Both should delegate to `AuthenticationRedirects`.

This avoids a real boot-order problem. `Foundation\Configuration\Middleware` can run during minimal application builder paths before facade roots are configured or the `auth` binding exists. Resolving `Auth::redirectTo()` from Foundation middleware bootstrap fails with "A facade root has not been set" in that path. Resolving `AuthManager` through the container fails with "Target class [auth] does not exist." The fan-out itself is pure static wiring onto worker-lifetime callback slots, so it does not need a manager instance.

The final layering:

```text
AuthenticationRedirects
  canonical boot-time aggregate redirect wiring

AuthManager / Auth facade
  provider and package public API

Foundation Middleware configurator
  bootstrap/app.php public API

Individual middleware and exception classes
  low-level runtime redirect primitives
```

This gives service providers and reusable packages a first-class API without needing the `Foundation\Configuration\Middleware` instance, which only exists inside `ApplicationBuilder::withMiddleware()`. It also keeps Foundation middleware bootstrap independent of Auth facade/container availability.

### Keep explicit AuthenticateSession fan-out

The aggregate guest redirect should call all three guest-side redirect primitives:

```php
Authenticate::redirectUsing($guests);
AuthenticateSession::redirectUsing($guests);
AuthenticationException::redirectUsing($guests);
```

This is intentional. `AuthenticateSession` can fall through to `AuthenticationException` when its own callback is unset, but relying on that fallback makes the aggregate API less clear and less faithful to the existing behavior. A high-level method named `redirectGuestsTo()` should explicitly configure every guest redirect participant it owns today.

`AuthenticateSession::redirectUsing()` remains a valid low-level primitive. It is not dead code. It can intentionally configure session-mismatch redirects differently from ordinary unauthenticated redirects.

### Accept tight auth/session package coupling

`src/session/composer.json` already requires `hypervel/auth` because `AuthenticateSession` throws `AuthenticationException` and depends on the auth factory. To let `AuthenticationRedirects` explicitly configure `AuthenticateSession`, `src/auth/composer.json` should move `hypervel/session` from `suggest` to `require`.

This creates a tight auth/session package relationship. That is reasonable for Hypervel:

- Hypervel is a full-stack framework, not only a collection of independently useful components.
- `AuthManager::createSessionDriver()` already resolves `session.store`.
- The default web-auth story is session-backed.
- Foundation already requires both auth and session.
- Keeping the aggregate API explicit is cleaner than weakening it to preserve standalone component purity.

Composer can resolve mutually dependent packages when their constraints are satisfiable. The implementation must still validate the package metadata after editing.

### Do not move classes

Do not move `Hypervel\Session\Middleware\AuthenticateSession` into the auth package. Its namespace and package location are Laravel-shaped and should stay stable. The package graph should be adjusted instead.

### Do not add these methods to the auth contract

`Hypervel\Contracts\Auth\Factory` should stay focused on runtime auth factory behavior: `guard()`, `getDefaultDriver()`, and `shouldUse()`. AGENTS.md allows adding methods to contracts only when the behavior is something every conforming implementation must provide. These redirect methods do not meet that bar: they are boot-time configuration methods for Hypervel's concrete auth manager and its middleware stack, like `extend()` and `provider()`. They should be available through `AuthManager` and the `Auth` facade, not required of every possible `Factory` implementation.

### Preserve low-level primitives

Keep the existing `redirectUsing()` methods on:

- `Authenticate`
- `AuthenticateSession`
- `AuthenticationException`
- `RedirectIfAuthenticated`

They are low-level implementation hooks and existing tests exercise them directly. The new aggregate API is a semantic convenience, not a replacement.

### High-level registration precedence

`$middleware->redirectGuestsTo()` / `$middleware->redirectUsersTo()` and `Auth::redirectGuestsTo()` / `Auth::redirectUsersTo()` configure the same underlying callbacks. An application should generally choose one high-level style for each redirect:

- Use `$middleware->...` in `bootstrap/app.php`.
- Use `Auth::...` in service providers and reusable packages.

If both high-level APIs are called for the same redirect, the most recent registration wins because each low-level primitive stores one static callback.

## Implementation Plan

### 1. Update `src/auth/composer.json`

Why: `AuthenticationRedirects` will import and call `Hypervel\Session\Middleware\AuthenticateSession`, so auth must declare the session package as a real dependency.

How:

Move `hypervel/session` from `suggest` to `require`, keeping package order sorted:

```json
"require": {
    "php": "^8.4",
    "nesbot/carbon": "^3.8.4",
    "hypervel/collections": "^0.4",
    "hypervel/config": "^0.4",
    "hypervel/container": "^0.4",
    "hypervel/context": "^0.4",
    "hypervel/contracts": "^0.4",
    "hypervel/database": "^0.4",
    "hypervel/hashing": "^0.4",
    "hypervel/http": "^0.4",
    "hypervel/macroable": "^0.4",
    "hypervel/session": "^0.4",
    "hypervel/support": "^0.4"
}
```

Remove the `suggest` object if it becomes empty.

Do not change root `composer.json` for this dependency relationship. The root already replaces both subtree packages with `self.version`, and sub-package composer files are edited directly in this monorepo because they do not have lockfiles.

### 2. Add `src/auth/src/AuthenticationRedirects.php`

Why: keep the aggregate fan-out in one auth-owned place that can be used from both provider code and Foundation middleware bootstrap without resolving the Auth facade or container binding.

How:

Create:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Session\Middleware\AuthenticateSession;

/**
 * Single source of truth for boot-time auth redirect wiring.
 *
 * Configures the worker-lifetime redirect callbacks on the auth/guest
 * middleware and the authentication exception; it does not resolve or issue
 * redirects at runtime.
 */
class AuthenticationRedirects
{
    /**
     * Configure where guests are redirected by the "auth" middleware.
     *
     * Boot-only. The callback persists in authentication middleware and
     * exception static properties for the worker lifetime and affects every
     * subsequent unauthenticated or session-mismatch request.
     */
    public static function redirectGuestsTo(callable|string $redirect): void
    {
        self::redirectTo(guests: $redirect);
    }

    /**
     * Configure where users are redirected by the "guest" middleware.
     *
     * Boot-only. The callback persists in the guest middleware static property
     * for the worker lifetime and affects every subsequent already-authenticated
     * guest-route request.
     */
    public static function redirectUsersTo(callable|string $redirect): void
    {
        self::redirectTo(users: $redirect);
    }

    /**
     * Configure where users are redirected by the authentication and guest middleware.
     *
     * Boot-only. The callbacks persist in authentication middleware and
     * exception static properties for the worker lifetime and affect every
     * subsequent matching request.
     */
    public static function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): void
    {
        $guests = is_string($guests) ? fn () => $guests : $guests;
        $users = is_string($users) ? fn () => $users : $users;

        if ($guests) {
            Authenticate::redirectUsing($guests);
            AuthenticateSession::redirectUsing($guests);
            AuthenticationException::redirectUsing($guests);
        }

        if ($users) {
            RedirectIfAuthenticated::redirectUsing($users);
        }
    }
}
```

Do not make the class `final` and do not add a private constructor. The class is a small static helper, but `final` does not protect an important invariant here and would drift toward final-by-default reasoning.

Do not add runtime guards for callback signatures. The existing low-level methods accept `callable`; invalid callables already fail naturally.

### 3. Update `src/auth/src/AuthManager.php`

Why: make Auth the canonical service-provider/package API for aggregate auth redirect configuration while delegating the implementation to `AuthenticationRedirects`.

How:

Place the new public methods near `extend()` and `provider()`, because they are boot-only worker-lifetime registration APIs. Do not append them after unrelated methods or near `__call`.

Add:

```php
/**
 * Configure where guests are redirected by the "auth" middleware.
 *
 * Boot-only. The callback persists in authentication middleware and exception
 * static properties for the worker lifetime and affects every subsequent
 * unauthenticated or session-mismatch request.
 */
public function redirectGuestsTo(callable|string $redirect): static
{
    AuthenticationRedirects::redirectGuestsTo($redirect);

    return $this;
}

/**
 * Configure where users are redirected by the "guest" middleware.
 *
 * Boot-only. The callback persists in the guest middleware static property
 * for the worker lifetime and affects every subsequent already-authenticated
 * guest-route request.
 */
public function redirectUsersTo(callable|string $redirect): static
{
    AuthenticationRedirects::redirectUsersTo($redirect);

    return $this;
}

/**
 * Configure where users are redirected by the authentication and guest middleware.
 *
 * Boot-only. The callbacks persist in authentication middleware and exception
 * static properties for the worker lifetime and affect every subsequent
 * matching request.
 */
public function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): static
{
    AuthenticationRedirects::redirectTo(guests: $guests, users: $users);

    return $this;
}
```

### 4. Update `src/support/src/Facades/Auth.php`

Why: the facade is the public service-provider/package entry point.

How:

Add `@method` entries near `viaRequest`, `extend`, and `provider`:

```php
 * @method static \Hypervel\Auth\AuthManager redirectGuestsTo(callable|string $redirect)
 * @method static \Hypervel\Auth\AuthManager redirectUsersTo(callable|string $redirect)
 * @method static \Hypervel\Auth\AuthManager redirectTo(callable|string|null $guests = null, callable|string|null $users = null)
```

The return type is `AuthManager` because the concrete methods return `static` and the facade resolves the `auth` manager.

### 5. Update `src/foundation/src/Configuration/Middleware.php`

Why: remove duplicate aggregate mapping logic and keep `bootstrap/app.php` as a convenience wrapper without resolving the Auth facade or container binding during middleware bootstrap.

How:

Add:

```php
use Hypervel\Auth\AuthenticationRedirects;
```

Change `redirectTo()` to delegate:

```php
/**
 * Configure where users are redirected by the authentication and guest middleware.
 *
 * Boot-only. The callbacks persist in authentication middleware and exception
 * static properties for the worker lifetime and affect every subsequent
 * matching request.
 */
public function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): static
{
    AuthenticationRedirects::redirectTo(guests: $guests, users: $users);

    return $this;
}
```

Keep `redirectGuestsTo()` and `redirectUsersTo()` as they are:

```php
/**
 * Configure where guests are redirected by the "auth" middleware.
 *
 * Boot-only. The callback persists in authentication middleware and exception
 * static properties for the worker lifetime and affects every subsequent
 * unauthenticated or session-mismatch request.
 */
public function redirectGuestsTo(callable|string $redirect): static
{
    return $this->redirectTo(guests: $redirect);
}

/**
 * Configure where users are redirected by the "guest" middleware.
 *
 * Boot-only. The callback persists in the guest middleware static property
 * for the worker lifetime and affects every subsequent already-authenticated
 * guest-route request.
 */
public function redirectUsersTo(callable|string $redirect): static
{
    return $this->redirectTo(users: $redirect);
}
```

Remove imports that become unused after delegation:

```php
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Session\Middleware\AuthenticateSession;
```

Keep config-style FQCN references in `defaultAliases()` and middleware groups unchanged.

### 6. Add tests in `tests/Auth/AuthManagerTest.php`

Why: the provider/facade API lives on `AuthManager`, so its public behavior belongs in the auth manager test file even though `AuthenticationRedirects` owns the fan-out implementation.

How:

Add tests that assert public effects, not protected static properties.

Add imports as needed:

```php
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Support\Facades\Auth as AuthFacade;
```

Test guest redirect configuration through `Authenticate` by invoking the middleware and catching `AuthenticationException`:

```php
public function testRedirectGuestsToConfiguresAuthenticateMiddleware(): void
{
    $manager = new AuthManager($this->getContainer());

    $guard = m::mock(Guard::class);
    $guard->shouldReceive('check')->andReturnFalse();

    $factory = m::mock(AuthFactory::class);
    $factory->shouldReceive('guard')->with(null)->andReturn($guard);

    $manager->redirectGuestsTo('/login');

    $middleware = new Authenticate($factory);

    try {
        $middleware->handle(Request::create('/secret'), fn () => null);
    } catch (AuthenticationException $exception) {
        $this->assertSame('/login', $exception->redirectTo(Request::create('/secret')));

        return;
    }

    $this->fail('AuthenticationException was not thrown.');
}
```

Use the actual imports and local helper setup that fit the existing file. The code above is a shape, not an instruction to add an unused `$container`.

Test that `AuthenticationException` is configured directly:

```php
public function testRedirectGuestsToConfiguresAuthenticationExceptionFallback(): void
{
    $manager = new AuthManager($this->getContainer());

    $manager->redirectGuestsTo(fn (Request $request) => $request->headers->get('X-Tenant') === 'admin'
        ? '/admin/login'
        : '/login');

    $this->assertSame(
        '/admin/login',
        (new AuthenticationException)->redirectTo(Request::create('/', server: ['HTTP_X_TENANT' => 'admin']))
    );
}
```

Test user redirect configuration through `RedirectIfAuthenticated` by swapping the `Auth` facade to a checked guard, mirroring `tests/Auth/RedirectIfAuthenticatedMiddlewareTest.php`:

```php
public function testRedirectUsersToConfiguresRedirectIfAuthenticatedMiddleware(): void
{
    $manager = new AuthManager($this->getContainer());
    $manager->redirectUsersTo(fn (Request $request) => $request->headers->get('X-Tenant') === 'admin'
        ? '/admin'
        : '/dashboard');

    $guard = m::mock(Guard::class);
    $guard->shouldReceive('check')->andReturnTrue();

    $factory = m::mock(AuthFactory::class);
    $factory->shouldReceive('guard')->andReturn($guard);
    AuthFacade::swap($factory);

    $response = (new RedirectIfAuthenticated)->handle(
        Request::create('/login', server: ['HTTP_X_TENANT' => 'admin']),
        fn () => null
    );

    $this->assertStringContainsString('/admin', $response->headers->get('Location'));
}
```

Also cover:

- `redirectTo(guests: ..., users: ...)` configures both sides.
- Strings are normalized to callbacks for both sides.
- Last high-level registration wins for guest and user paths.
- At least one redirect is registered through the `Auth` facade itself, not only by calling `AuthManager` directly.

The facade-path test can swap the manager and call the facade:

```php
AuthFacade::swap($manager);
AuthFacade::redirectGuestsTo('/login');
```

Use `: void` return types for new test methods. Existing tests in this file do not all have return types, but new tests should follow current AGENTS.md typing rules.

Run immediately after editing:

```bash
./vendor/bin/phpunit --no-progress tests/Auth/AuthManagerTest.php
```

### 7. Add a session aggregate test in `tests/Session/Middleware/AuthenticateSessionTest.php`

Why: the guest aggregate explicitly configures `AuthenticateSession`, and this file already has the request, session store, guard mock, and password-hash mismatch scaffolding.

How:

Add a test alongside the existing invalid-password-hash test that configures through `AuthManager` instead of the low-level `AuthenticateSession::redirectUsing()` primitive.

Add imports as needed:

```php
use Hypervel\Auth\AuthManager;
use Hypervel\Container\Container;
```

Shape:

```php
public function testAuthManagerRedirectGuestsToConfiguresSessionMismatchRedirect(): void
{
    $manager = new AuthManager(new Container);
    $manager->redirectGuestsTo('/login');

    // Isolate AuthenticateSession's own slot: clear the exception-level
    // fallback so this test fails if aggregate guest configuration stops
    // configuring AuthenticateSession directly.
    AuthenticationException::flushState();

    $user = new class {
        public function getAuthPassword(): string
        {
            return 'my-pass-(*&^%$#!@';
        }
    };

    $request = new Request(cookies: ['recaller-name' => 'a|b|invalid-mac']);
    $request->setUserResolver(fn () => $user);

    $session = new Store('name', new ArraySessionHandler(1));
    $request->setHypervelSession($session);

    $authFactory = m::mock(AuthFactory::class);
    $authFactory->shouldReceive('viaRemember')->andReturn(true);
    $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
    $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
    $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
    $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

    try {
        (new AuthenticateSession($authFactory))->handle($request, fn () => 'next');
    } catch (AuthenticationException $exception) {
        $this->assertSame('/login', $exception->redirectTo($request));

        return;
    }

    $this->fail('AuthenticationException was not thrown.');
}
```

Use exact expectations needed by the current middleware path. Keep the existing `AuthenticateSession::redirectUsing()` primitive test because that low-level API remains live behavior.

This isolation is required. Without `AuthenticationException::flushState()`, the test would still pass if the aggregate configurator stopped calling `AuthenticateSession::redirectUsing()`, because `AuthenticationException` would supply the same `/login` redirect as a fallback. The point of this test is to protect the explicit three-slot fan-out decision.

Run immediately after editing:

```bash
./vendor/bin/phpunit --no-progress tests/Session/Middleware/AuthenticateSessionTest.php
```

### 8. Add delegation tests in `tests/Foundation/Configuration/MiddlewareTest.php`

Why: once Foundation delegates to `AuthenticationRedirects`, the bootstrap API still needs coverage showing it configures the same aggregate behavior without resolving Auth through the facade or container.

How:

Add lightweight tests:

- `redirectGuestsTo()` configures the `AuthenticationException` fallback.
- `redirectUsersTo()` configures `RedirectIfAuthenticated`.

Keep these tests intentionally smaller than `AuthManagerTest`; the Auth-facing tests cover the full fan-out behavior.

Run immediately after editing:

```bash
./vendor/bin/phpunit --no-progress tests/Foundation/Configuration/MiddlewareTest.php
```

### 9. Update `src/boost/docs/authentication.md`

Why: users need to understand the two high-level entry points and choose the one that fits where their code runs.

How:

In "Redirecting Unauthenticated Users":

- Keep the `bootstrap/app.php` `$middleware->redirectGuestsTo()` example.
- Add the service-provider/package `Auth::redirectGuestsTo()` example:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;

public function boot(): void
{
    Auth::redirectGuestsTo(fn (Request $request) => route('login'));
}
```

- State that strings and request-aware callbacks are supported.
- State that registration is boot-time worker-lifetime state and callback results are computed per request.

In "Redirecting Authenticated Users":

- Keep the `$middleware->redirectUsersTo()` example.
- Add:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;

public function boot(): void
{
    Auth::redirectUsersTo(fn (Request $request) => route('panel'));
}
```

Add a note:

```md
Configure these redirects from `bootstrap/app.php` with the middleware configurator, or from a service provider / package with the `Auth` facade. Both high-level APIs configure the same global redirect callbacks, so an application should generally choose one style for each redirect. If both high-level APIs are called for the same redirect, the most recent registration wins.
```

Update the lower-level-control sentence so it does not imply `AuthenticationException::redirectUsing()` is the only primitive. Mention lower-level primitives only briefly; the main docs should steer users to the high-level APIs.

### 10. Update `src/boost/docs/fortify.md`

Why: Fortify has its own request-aware redirect API, and users need the boundary between middleware redirects and successful-action fallbacks.

How:

In the Redirects section, after the Fortify callback explanation, add a concise distinction:

```md
This Fortify redirect API controls successful Fortify action fallbacks, such as login or registration responses when no intended URL is stored. It does not configure auth or guest middleware redirects. Use `Auth::redirectGuestsTo()` and `Auth::redirectUsersTo()` for middleware redirects, or the middleware configurator equivalents in `bootstrap/app.php`.
```

Keep the Passkeys note and existing Fortify examples.

### 11. No static cleanup changes

Why: all touched static state already has cleanup.

Verified cleanup entries exist in `src/testing/src/PHPUnit/AfterEachTestSubscriber.php` for:

- `AuthenticationException::flushState()`
- `Authenticate::flushState()`
- `RedirectIfAuthenticated::flushState()`
- `AuthenticateSession::flushState()`

Do not add duplicate cleanup elsewhere.

### 12. Final search cleanup

Why: the final codebase should not contain stale descriptions of the old Foundation-owned aggregate or misleading package metadata.

Run:

```bash
grep -RInI --exclude-dir=node_modules --exclude-dir=vendor "redirectGuestsTo\\|redirectUsersTo\\|redirectTo(guests\\|AuthenticationException::redirectUsing\\|Authenticate::redirectUsing\\|AuthenticateSession::redirectUsing\\|RedirectIfAuthenticated::redirectUsing\\|hypervel/session" src tests docs composer.json
```

Manually review every hit:

- `AuthenticationRedirects` should own the aggregate fan-out.
- `Foundation\Configuration\Middleware` should delegate and should not import the low-level redirect classes for this mapping.
- Tests may call low-level primitives when testing those primitives.
- Docs should not describe `$middleware->...` and `Auth::...` as separate stacks or additive chains.
- `src/auth/composer.json` should require session, not suggest it.

## Verification Plan

Run incrementally after each edited test file:

```bash
./vendor/bin/phpunit --no-progress tests/Auth/AuthManagerTest.php
./vendor/bin/phpunit --no-progress tests/Session/Middleware/AuthenticateSessionTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Configuration/MiddlewareTest.php
```

Validate package metadata after editing `src/auth/composer.json`:

```bash
composer validate src/auth/composer.json
composer validate
```

Run the full project gate:

```bash
composer fix
```

`composer fix` runs:

- `lint:fix`
- `analyse`
- `test:parallel`
- `test:testbench`
- `test:dogfood`

The delegation change makes `Foundation\Configuration\Middleware::redirectTo()` call `AuthenticationRedirects` directly during middleware bootstrap. Confirm the real default-seed path stays covered by the full gate and does not resolve the Auth facade or container binding. The current suite includes at least these unauthenticated auth-route redirect assertions:

- `tests/Integration/Foundation/Testing/Concerns/InteractsWithAuthenticationTest.php::testActingAsGuestClearsTheUser`
- `tests/Testbench/Integrations/SlimSkeletonApplicationTest.php::itCanBeRedirectedToLoginRouteNameWhenTryingToAccessAuthenticatedRoutes`

If implementation changes remove that coverage, add a small integration assertion for an unauthenticated request to an `auth` route redirecting to the named `login` route through the default `ApplicationBuilder::withMiddleware()` seed.

If Composer reports a real package issue with the auth/session dependency cycle during validation or the final gate, stop and report the exact failure and the cleanest fix. Do not work around it with `class_exists()` or hidden fallback behavior unless the owner explicitly chooses that tradeoff after seeing the failure.

## Expected Final State

- `Auth::redirectGuestsTo()` and `Auth::redirectUsersTo()` are the clean provider/package API.
- `$middleware->redirectGuestsTo()` and `$middleware->redirectUsersTo()` remain the clean `bootstrap/app.php` API.
- Both high-level APIs configure the same low-level callback slots.
- Guest aggregate configuration explicitly touches `Authenticate`, `AuthenticateSession`, and `AuthenticationException`.
- User aggregate configuration touches `RedirectIfAuthenticated`.
- Auth and session are tightly coupled packages, and that coupling is deliberate and documented in this plan.
- Fortify completion redirects remain separate and documented as separate.
- Static state cleanup remains centralized in `AfterEachTestSubscriber`.
- Docs and tests describe the new design without stale Foundation-owned aggregate wording.
