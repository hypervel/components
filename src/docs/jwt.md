# JWT Authentication

- [Introduction](#introduction)
- [Installation](#installation)
    - [Publishing Configuration](#publishing-configuration)
    - [Generating Secrets](#generating-secrets)
    - [Generating Certificates](#generating-certificates)
- [Configuration](#configuration)
    - [Configuring the Guard](#configuring-the-guard)
    - [User Models](#user-models)
    - [Signing Keys and Algorithms](#signing-keys-and-algorithms)
    - [Custom Drivers](#custom-drivers)
    - [Token Lifetime](#token-lifetime)
    - [Subject Locking](#subject-locking)
    - [Token Sources](#token-sources)
    - [Validations and Leeway](#validations-and-leeway)
    - [Blacklist](#blacklist)
- [Authenticating Requests](#authenticating-requests)
    - [Issuing Tokens](#issuing-tokens)
    - [Protecting Routes](#protecting-routes)
    - [Reading the Authenticated User](#reading-the-authenticated-user)
    - [Refreshing Tokens](#refreshing-tokens)
    - [Logging Out and Invalidating Tokens](#logging-out-and-invalidating-tokens)
    - [Managing Revocations](#managing-revocations)
- [Guard Methods](#guard-methods)
- [Exceptions](#exceptions)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel JWT provides stateless bearer token authentication using Hypervel's authentication guard system.

JWT authentication is useful when your application needs signed tokens that can be sent with API, mobile, or service-to-service requests. If you need first-party SPA session authentication or database-backed personal access tokens, consider [Sanctum](/docs/{{version}}/sanctum) instead.

<a name="installation"></a>
## Installation

You may install the package using Composer:

```shell
composer require hypervel/jwt
```

The package service provider is discovered automatically.

<a name="publishing-configuration"></a>
### Publishing Configuration

You may publish the JWT configuration file using the `vendor:publish` command:

```shell
php artisan vendor:publish --provider="Hypervel\Jwt\JwtServiceProvider"
```

This publishes a `config/jwt.php` file where you may configure signing keys, token lifetime, parser sources, validation, and blacklist behavior.

<a name="generating-secrets"></a>
### Generating Secrets

For HMAC algorithms such as `HS256`, generate a signing secret using the `jwt:secret` command:

```shell
php artisan jwt:secret
```

This command writes `JWT_SECRET` to your `.env` file. It does not add or change `JWT_ALGO`, so an existing HMAC, RSA, or EC algorithm selection is preserved.

You may display a generated secret without writing to `.env`:

```shell
php artisan jwt:secret --show
```

If a secret already exists, the command asks before replacing it. You may skip the prompt with `--force`, or keep an existing secret using `--always-no`, which takes precedence over `--force`. Like new certificates, a new secret requires [restarting the server and other long-running processes](#generating-certificates).

<a name="generating-certificates"></a>
### Generating Certificates

For RSA or EC algorithms, generate a public / private key pair using the `jwt:generate-certs` command:

```shell
php artisan jwt:generate-certs
```

The command writes the generated certificates to `storage/certs` by default and updates `JWT_ALGO`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, and `JWT_PASSPHRASE` in your `.env` file.

> [!WARNING]
> Restart the server and every other long-running application process, including queue workers and custom server processes, before issuing tokens with the new certificate pair. The `php artisan server:reload` command only replaces server workers and is not sufficient.

You may customize the algorithm and key options:

```shell
php artisan jwt:generate-certs --force --algo=rsa --bits=4096 --sha=512

php artisan jwt:generate-certs --force --algo=ec --sha=256
```

RSA keys must be at least 2048 bits. EC keys use the curve their SHA variant requires: `prime256v1` for 256, `secp384r1` for 384, and `secp521r1` for 512.

You may change the output directory using `--dir`. The directory may be absolute or relative to your application's base path. The command writes absolute `file://` paths to `.env`, so if each deployment uses a new release directory, choose a `--dir` that is shared between releases.

You may protect the private key with a passphrase using `--passphrase`, or prompt for it interactively using `--ask-passphrase`:

```shell
php artisan jwt:generate-certs --ask-passphrase
```

<a name="configuration"></a>
## Configuration

<a name="configuring-the-guard"></a>
### Configuring the Guard

To use JWT authentication, configure an auth guard that uses the `jwt` driver:

```php
'guards' => [
    'api' => [
        'driver' => 'jwt',
        'provider' => 'users',
    ],
],
```

You may then protect routes using Hypervel's normal authentication middleware:

```php
Route::middleware('auth:api')->get('/user', function () {
    return Auth::guard('api')->user();
});
```

<a name="user-models"></a>
### User Models

JWT can authenticate any model supported by your configured user provider. If you need to customize the `sub` claim or add model-defined custom claims, implement the `Hypervel\Jwt\Contracts\JwtSubject` contract:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Jwt\Contracts\JwtSubject;

class User extends Model implements JwtSubject
{
    /**
     * Get the identifier that will be stored in the subject claim.
     */
    public function getJwtIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return custom claims to add to the token.
     */
    public function getJwtCustomClaims(): array
    {
        return [];
    }
}
```

Inline claims passed with the guard's `claims` method override model-defined custom claims for the next token.

Custom claims may also set the registered `exp`, `nbf`, `iat`, `iss`, and `jti` claims, replacing the values the package would otherwise add. An explicit `exp` takes precedence over the configured TTL. Date claims accept a Unix timestamp as an integer or string, a `DateTimeInterface` instance such as a Carbon date, or a `DateInterval` that is added to the current time:

```php
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Date;

$token = Auth::guard('api')
    ->claims(['exp' => Date::now()->addDays(7)])
    ->login($user);
```

If you provide your own `jti`, your application is responsible for keeping it unique. The `sub` and `prv` claims always come from the user and its provider, so passing them as custom claims throws a `JwtException`.

<a name="signing-keys-and-algorithms"></a>
### Signing Keys and Algorithms

The JWT driver defaults to the bundled Lcobucci provider:

```php
'driver' => env('JWT_DRIVER', 'lcobucci'),
```

For HMAC algorithms, configure `JWT_SECRET` and `JWT_ALGO`:

```php
'secret' => env('JWT_SECRET'),

'algo' => env('JWT_ALGO', Hypervel\Jwt\Providers\Provider::ALGO_HS256),
```

For RSA and EC algorithms, configure `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, and `JWT_PASSPHRASE`:

```php
'keys' => [
    'public' => env('JWT_PUBLIC_KEY'),
    'private' => env('JWT_PRIVATE_KEY'),
    'passphrase' => env('JWT_PASSPHRASE'),
],
```

The key values may be key contents or a `file://` URI.

An application that only verifies tokens issued by another service, such as an API behind a separate authentication server, only needs the public key. Without a private key, tokens are still verified, but issuing or refreshing a token throws a `JwtException`.

<a name="custom-drivers"></a>
### Custom Drivers

Custom JWT providers must implement the `Hypervel\Jwt\Contracts\ProviderContract` contract, which defines the `encode` and `decode` methods.

You may register a custom JWT provider using the `extend` method. This is typically done in the `boot` method of a service provider:

```php
use App\Jwt\CustomJwtProvider;
use Hypervel\Support\Facades\Jwt;

public function boot(): void
{
    Jwt::extend('custom', fn ($app) => $app->make(CustomJwtProvider::class));
}
```

After registering the driver, you may select it using the `driver` configuration option:

```php
'driver' => 'custom',
```

To customize the bundled provider, such as with your own subclass of `Hypervel\Jwt\Providers\Lcobucci`, register it under the `lcobucci` name instead. The JWT manager creates each driver once and reuses it, so register drivers before the first token is encoded or decoded. Calling `extend` after a driver has been created does not replace it.

<a name="token-lifetime"></a>
### Token Lifetime

The `ttl` configuration option controls how long newly issued tokens remain valid, in minutes:

```php
$ttl = env('JWT_TTL', 120);

return [
    // ...
    'ttl' => $ttl === null ? null : (int) $ttl,
];
```

Set this value to `null` to issue tokens without an `exp` claim:

```php
'ttl' => null,
```

JWT guards inherit the global `jwt.ttl` value when their guard configuration omits the `ttl` option. You may set a guard's `ttl` to an integer to override that value in minutes, or to `null` to issue non-expiring tokens from that guard:

```php
'guards' => [
    'customers' => [
        'driver' => 'jwt',
        'provider' => 'customers',
        'ttl' => 15,
    ],

    'devices' => [
        'driver' => 'jwt',
        'provider' => 'devices',
        'ttl' => null,
    ],
],
```

The global `jwt.ttl` option accepts an integer or `null`.

For one token-producing operation, use `setTTL`:

```php
$token = Auth::guard('api')
    ->setTTL(15)
    ->attempt($credentials);
```

The override is cleared after the token is generated.

<a name="subject-locking"></a>
### Subject Locking

Subject locking is enabled by default:

```php
'lock_subject' => (bool) env('JWT_LOCK_SUBJECT', true),
```

When subject locking is enabled and the user provider exposes its model class, JWT adds a provider hash to each token. This prevents a token issued for one provider model from authenticating against another provider model that happens to have the same ID.

<a name="token-sources"></a>
### Token Sources

By default, JWT reads tokens from the `Authorization` bearer header:

```http
Authorization: Bearer eyJhbGciOi...
```

Request input parsing is available but is not enabled by default because URL tokens can leak through logs, browser history, and referrer headers:

```http
/api/user?token=eyJhbGciOi...
```

Cookie parsing is also available but is not enabled by default. You may customize the parser chain:

```php
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\InputSource;

'parser' => [
    AuthHeaders::class,
    InputSource::class,
    Cookie::class,
],
```

The parsers run in order, and the first token found is used. The `InputSource` parser reads the input key named by `token` from the query string and request body. When both contain a token, the body wins, just like the request's `input` method. The `Cookie` parser reads the cookie named by `cookie_key_name`:

```php
'token' => env('JWT_TOKEN', 'token'),

'cookie_key_name' => env('JWT_COOKIE_KEY_NAME', 'token'),
```

Hypervel [encrypts cookies](/docs/{{version}}/responses#cookies-and-encryption) by default, so the `EncryptCookies` middleware must decrypt the token cookie before the guard reads it. The `web` middleware group includes this middleware and runs it before the `auth` middleware, but the `api` group does not. Cookies that cannot be decrypted are ignored. You may instead exclude the token cookie from encryption using the `encryptCookies` method's `except` argument. This only configures the middleware; it does not add the middleware to any routes. The token is still signed, so leaving the cookie unencrypted only exposes its claims.

Since browsers send cookies automatically, cookie authentication also needs [CSRF protection](/docs/{{version}}/csrf), even though the token is signed and the cookie may be encrypted. The `web` middleware group includes CSRF protection, but the `api` group does not.

For another header, token scheme, or a route parameter, implement `Hypervel\Jwt\Contracts\TokenExtractor` and add that class to `jwt.parser`:

```php
use Hypervel\Http\Request;
use Hypervel\Jwt\Contracts\TokenExtractor;

class AccessTokenHeader implements TokenExtractor
{
    public function parseToken(Request $request): ?string
    {
        return $request->headers->get('X-Access-Token');
    }
}
```

Custom parsers are resolved from the container once and shared by every request, so they must only read the request they are given and never store request or token state.

<a name="validations-and-leeway"></a>
### Validations and Leeway

The `validations` option controls which validation classes run when a token is decoded:

```php
'validations' => [
    Hypervel\Jwt\Validations\RequiredClaims::class,
    Hypervel\Jwt\Validations\ExpiredClaim::class,
    Hypervel\Jwt\Validations\IssuerClaim::class,
    Hypervel\Jwt\Validations\IssuedAtClaim::class,
    Hypervel\Jwt\Validations\NotBeforeClaim::class,
],
```

The default configuration enables required-claim, expiration, issuer, issued-at, and not-before validation. Issuer validation only enforces a value when `jwt.issuer` is configured:

```php
'issuer' => env('JWT_ISSUER'),
```

The `required_claims` option controls which claims must exist in every token:

```php
'required_claims' => [
    'iat',
    'sub',
],
```

If your application uses timestamp validations and your servers have small clock differences, configure `leeway` in seconds. The leeway applies to the `exp`, `nbf`, and `iat` claims and to the end of the refresh window:

```php
'leeway' => (int) env('JWT_LEEWAY', 0),
```

You may add your own validation classes to the `validations` option. A validation implements the `Hypervel\Jwt\Contracts\ValidationContract` contract and throws a `TokenInvalidException` to reject a token:

```php
<?php

namespace App\Auth;

use Hypervel\Jwt\Contracts\ValidationContract;
use Hypervel\Jwt\Exceptions\TokenInvalidException;

class CurrentTokenVersion implements ValidationContract
{
    public function __construct(
        protected TokenVersions $versions,
    ) {
    }

    /**
     * Validate the payload.
     */
    public function validate(array $payload): void
    {
        if (($payload['ver'] ?? null) !== $this->versions->current($payload['sub'])) {
            throw new TokenInvalidException('Token version is outdated.');
        }
    }
}
```

Validations are resolved from the container once and shared by every request, so their dependencies must be safe to share, like a repository or cache that looks up the current value inside `validate`. Never keep the current request, tenant, or other per-request state in a validation. A constructor that accepts a `$config` argument receives the `jwt` configuration array. Refreshing skips validations that also implement `Hypervel\Jwt\Contracts\TemporalValidation`, as it does the expiration check, since the refresh window replaces them.

<a name="blacklist"></a>
### Blacklist

The JWT blacklist lets the package invalidate tokens before they naturally expire:

```php
'blacklist_enabled' => (bool) env('JWT_BLACKLIST_ENABLED', true),
```

Blacklisting is enabled by default, so logging out or refreshing revokes the old token. Newly issued tokens include a `jti` claim, and each authenticated request checks the blacklist in the cache. If your application doesn't need to revoke tokens before they expire, you may set `JWT_BLACKLIST_ENABLED` to `false`; tokens then remain valid until they expire, even after logout.

Blacklist entries are kept in your default cache store. You may choose another store using the `blacklist_store` option:

```php
'blacklist_store' => env('JWT_BLACKLIST_STORE'),
```

Use a store that all of your servers share, such as Redis, so a revoked token is rejected everywhere. Stores that are not shared between servers, such as `file` or `swoole`, only see revocations made on the same server, and the `session` store only sees revocations made within the same session. If the blacklist store is a cache stack with a node-local tier, other servers may accept a revoked token until their local entry expires, so keep that tier's TTL short.

The blacklist works with any cache store. Removing every revocation at once requires a store that supports tags, as described in [managing revocations](#managing-revocations).

The blacklist uses the configured storage provider:

```php
'providers' => [
    'storage' => Hypervel\Jwt\Storage\CacheStorage::class,
],
```

If the storage provider is omitted, Hypervel uses `CacheStorage`. To keep blacklist entries somewhere other than the cache, implement `Hypervel\Jwt\Contracts\StorageContract` and configure your implementation using `jwt.providers.storage`. The `blacklist_store` option only applies to `CacheStorage`.

A grace period keeps a revoked token usable for a number of seconds, allowing concurrent requests that use it to finish. Tokens invalidated with `forceForever` are revoked immediately:

```php
'blacklist_grace_period' => (int) env('JWT_BLACKLIST_GRACE_PERIOD', 0),
```

The `refresh_ttl` option also controls how long blacklist entries are retained. When the refresh lifetime is `null`, revocations for refreshable tokens are retained forever:

```php
$refreshTtl = env('JWT_REFRESH_TTL', 20160);

return [
    // ...
    'refresh_ttl' => $refreshTtl === null ? null : (int) $refreshTtl,
];
```

<a name="authenticating-requests"></a>
## Authenticating Requests

<a name="issuing-tokens"></a>
### Issuing Tokens

The `attempt` method validates credentials and returns a JWT string when authentication succeeds:

```php
use Hypervel\Support\Facades\Auth;

$credentials = $request->only(['email', 'password']);
$guard = Auth::guard('api');
$ttl = $guard->getTTL();

if (! $token = $guard->attempt($credentials)) {
    return response()->json(['message' => 'Invalid credentials.'], 401);
}

return response()->json([
    'access_token' => $token,
    'token_type' => 'bearer',
    'expires_in' => $ttl === null ? null : $ttl * 60,
]);
```

Like Hypervel's session guard, the `attempt` and `once` methods [rehash the user's password](/docs/{{version}}/authentication#automatic-password-rehashing) when it was hashed with outdated settings.

You may issue a token for an existing user model using `login`:

```php
$token = Auth::guard('api')->login($user);
```

To issue a token for a user without making them the current guard user, use `fromUser`, or `tokenById` when you only have the user's ID:

```php
$token = Auth::guard('api')->fromUser($user);

$token = Auth::guard('api')->tokenById($userId);
```

<a name="protecting-routes"></a>
### Protecting Routes

Use Hypervel's normal authentication middleware:

```php
Route::middleware('auth:api')->get('/profile', function () {
    return Auth::guard('api')->user();
});
```

Routes that allow guests don't need any middleware. Calling `Auth::guard('api')->user()` reads the token when it is first needed, and returns `null` when the request has no usable token.

<a name="reading-the-authenticated-user"></a>
### Reading the Authenticated User

Use the usual auth APIs to read the authenticated user or ID:

```php
$user = Auth::guard('api')->user();

$userId = Auth::guard('api')->id();
```

The `id` method loads the user, like the other guards. When you only need the ID, the `getUserId` method reads the token subject without loading the user model, so it does not check that the user still exists:

```php
$userId = Auth::guard('api')->getUserId();
```

Use `userOrFail` when a missing user should throw:

```php
$user = Auth::guard('api')->userOrFail();
```

<a name="refreshing-tokens"></a>
### Refreshing Tokens

The `refresh` method creates a new token from the current token:

```php
$newToken = Auth::guard('api')->refresh();
```

Expose refresh through a dedicated endpoint:

```php
use Hypervel\Jwt\Exceptions\TokenBlacklistedException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Auth;

Route::post('/token/refresh', function () {
    try {
        $token = Auth::guard('api')->refresh();
    } catch (TokenInvalidException|TokenExpiredException|TokenBlacklistedException) {
        abort(401, 'Token cannot be refreshed.');
    }

    abort_if($token === null, 401, 'No token provided.');

    return response()->json(['token' => $token]);
});
```

Do not protect the refresh route with `auth:api`. Refresh must be able to read an expired token that is still inside the refresh window, and normal auth middleware rejects expired access tokens before the handler runs.

The refresh window is controlled by `refresh_ttl`, in minutes:

```php
$refreshTtl = env('JWT_REFRESH_TTL', 20160);

return [
    // ...
    'refresh_ttl' => $refreshTtl === null ? null : (int) $refreshTtl,
];
```

If `refresh_iat` is `false`, refreshed tokens keep the original `iat` claim. If `refresh_iat` is `true`, refreshed tokens receive a fresh `iat` claim:

```php
'refresh_iat' => (bool) env('JWT_REFRESH_IAT', false),
```

You may force the old token to remain blacklisted forever when blacklist is enabled:

```php
$newToken = Auth::guard('api')->refresh(forceForever: true);
```

You may also reset non-persistent custom claims during refresh:

```php
$newToken = Auth::guard('api')->refresh(resetClaims: true);
```

Claims listed in `persistent_claims` are preserved during refresh when they are present on the old token:

```php
'persistent_claims' => [
    'tenant_id',
],
```

Every refreshed token receives a new `nbf` claim. It also receives a new `exp` claim unless the TTL is `null`, a new `jti` claim when the blacklist is enabled, and a new `iat` claim when `refresh_iat` is enabled. Other claims, including `iss`, are kept unless you reset claims. Claims passed with the `claims` method before refreshing take precedence over all of these, but may not set `sub` or `prv`. Before any new claims apply, the old token must still be refreshable. An explicit `iat` then becomes the starting point of the new token's refresh window.

<a name="logging-out-and-invalidating-tokens"></a>
### Logging Out and Invalidating Tokens

The `logout` method invalidates the current token when the blacklist is enabled, then clears the guard's user, token, and decoded payload. For the rest of the request, the guard no longer reads the token from the request:

```php
Auth::guard('api')->logout();
```

Without the blacklist, tokens cannot be revoked, so a logged out token remains valid until it expires. If the blacklist write fails, a `JwtException` is thrown, the guard keeps its current state, and the `Logout` event is not dispatched.

While the blacklist is enabled, you may invalidate a token directly using the `invalidate` method:

```php
Auth::guard('api')->invalidate();
```

You may pass `true` to blacklist the token forever. This also bypasses the configured grace period, so the revocation takes effect immediately:

```php
Auth::guard('api')->invalidate(true);
```

<a name="managing-revocations"></a>
### Managing Revocations

The `Jwt` facade's `blacklist` method gives you access to the blacklist. Its `remove` method removes a single token's revocation, while the `clear` method removes every revocation:

```php
use Hypervel\Support\Facades\Jwt;

$payload = Jwt::decode($token, validate: false, checkBlacklist: false);

Jwt::blacklist()->remove($payload);

Jwt::blacklist()->clear();
```

A token whose revocation is removed can authenticate again until it expires. The `clear` method requires a cache store that supports tags, such as Redis, and only removes blacklist entries. On other stores, it throws an exception instead.

<a name="guard-methods"></a>
## Guard Methods

The JWT guard supports these methods:

```php
Auth::guard('api')->attempt($credentials);      // string|false
Auth::guard('api')->validate($credentials);     // bool
Auth::guard('api')->once($credentials);         // bool
Auth::guard('api')->onceUsingId($id);           // Authenticatable|false
Auth::guard('api')->login($user);               // string
Auth::guard('api')->fromUser($user);            // string
Auth::guard('api')->tokenById($id);             // string|null
Auth::guard('api')->byId($id);                  // Authenticatable|false
Auth::guard('api')->user();                     // Authenticatable|null
Auth::guard('api')->getUser();                  // Authenticatable|null
Auth::guard('api')->userOrFail();               // Authenticatable
Auth::guard('api')->id();                       // int|string|null
Auth::guard('api')->getUserId();                // int|string|null
Auth::guard('api')->claims(['role' => 'admin']);
Auth::guard('api')->setTTL(15);
Auth::guard('api')->setToken($token);
Auth::guard('api')->getToken();
Auth::guard('api')->payload();                  // array
Auth::guard('api')->refresh();
Auth::guard('api')->logout();
Auth::guard('api')->invalidate();
```

The `claims` and `setTTL` methods affect only the next token-producing operation. The `getUser` method returns the user the guard has already resolved, without decoding the token or loading the user.

<a name="exceptions"></a>
## Exceptions

JWT exceptions extend `Hypervel\Jwt\Exceptions\JwtException`.

Common exceptions include:

<div class="content-list" markdown="1">

- `SecretMissingException`
- `TokenBlacklistedException`
- `TokenExpiredException`
- `TokenInvalidException`
- `UserNotDefinedException`

</div>

The guard's `user`, `check`, and `id` methods treat an invalid, expired, or blacklisted token as unauthenticated instead of throwing. Methods that need the token itself, such as `payload` and `refresh`, throw these exceptions. To keep them out of your logs, ignore them with the [`dontReport`](/docs/{{version}}/errors#ignoring-exceptions-by-type) exception method.

<a name="credits"></a>
## Credits

Hypervel JWT began as a port of [PHP Open Source Saver JWT Auth](https://github.com/PHP-Open-Source-Saver/jwt-auth) and has been adapted for Hypervel's framework architecture and coroutine runtime.
