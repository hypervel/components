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
- [Guard Methods](#guard-methods)
- [Exceptions](#exceptions)
- [Differences From "php-open-source-saver/jwt-auth"](#differences-from-php-open-source-saver-jwt-auth)

<a name="introduction"></a>
## Introduction

Hypervel JWT provides stateless bearer token authentication using Hypervel's authentication guard system. It is based on the `php-open-source-saver/jwt-auth` package and adapted for Hypervel's long-lived Swoole workers and coroutine runtime.

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
php artisan vendor:publish --provider="Hypervel\JWT\JWTServiceProvider"
```

This publishes a `config/jwt.php` file where you may configure signing keys, token lifetime, parser sources, validation, and blacklist behavior.

<a name="generating-secrets"></a>
### Generating Secrets

For HMAC algorithms such as `HS256`, generate a signing secret using the `jwt:secret` command:

```shell
php artisan jwt:secret
```

This command writes `JWT_SECRET` and `JWT_ALGO=HS256` to your `.env` file.

You may display a generated secret without writing to `.env`:

```shell
php artisan jwt:secret --show
```

If a secret already exists, the command asks before replacing it. You may skip the prompt with `--force`, or skip generation when a secret exists using `--always-no`.

<a name="generating-certificates"></a>
### Generating Certificates

For RSA or EC algorithms, generate a public / private key pair using the `jwt:generate-certs` command:

```shell
php artisan jwt:generate-certs
```

The command writes the generated certificates to `storage/certs` by default and updates `JWT_ALGO`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, and `JWT_PASSPHRASE` in your `.env` file.

You may customize the algorithm and key options:

```shell
php artisan jwt:generate-certs --force --algo=rsa --bits=4096 --sha=512

php artisan jwt:generate-certs --force --algo=ec --curve=prime256v1 --sha=256
```

You may change the output directory using `--dir`. The directory may be absolute or relative to your application's base path.

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

JWT can authenticate any model supported by your configured user provider. If you need to customize the `sub` claim or add model-defined custom claims, implement the `Hypervel\JWT\Contracts\JWTSubject` contract:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\JWT\Contracts\JWTSubject;

class User extends Model implements JWTSubject
{
    /**
     * Get the identifier that will be stored in the subject claim.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return custom claims to add to the token.
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
```

Inline claims passed with the guard's `claims` method override model-defined custom claims for the next token.

<a name="signing-keys-and-algorithms"></a>
### Signing Keys and Algorithms

The JWT driver defaults to the bundled Lcobucci provider:

```php
'driver' => env('JWT_DRIVER', 'lcobucci'),
```

For HMAC algorithms, configure `JWT_SECRET` and `JWT_ALGO`:

```php
'secret' => env('JWT_SECRET'),

'algo' => env('JWT_ALGO', Hypervel\JWT\Providers\Provider::ALGO_HS256),
```

For RSA and EC algorithms, configure `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, and `JWT_PASSPHRASE`:

```php
'keys' => [
    'public' => env('JWT_PUBLIC_KEY'),
    'private' => env('JWT_PRIVATE_KEY'),
    'passphrase' => env('JWT_PASSPHRASE'),
],
```

The key values may be key contents or `file://` paths.

<a name="token-lifetime"></a>
### Token Lifetime

The `ttl` configuration option controls how long newly issued tokens remain valid, in minutes:

```php
'ttl' => env('JWT_TTL', 120),
```

Set this value to `null` to issue tokens without an `exp` claim:

```php
'ttl' => null,
```

You may also configure a different TTL per guard:

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
'lock_subject' => env('JWT_LOCK_SUBJECT', true),
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

The input key defaults to `token`:

```php
'token' => env('JWT_TOKEN', 'token'),
```

You may customize the parser chain:

```php
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\Cookie;
use Hypervel\JWT\Http\Parser\InputSource;

'parser' => [
    AuthHeaders::class,
    InputSource::class,
    Cookie::class,
],
```

Cookie parsing is also available but is not enabled by default. If you add the `InputSource` or `Cookie` parser, it reads the same key configured by `jwt.token`.

For a non-standard header or token scheme, implement `Hypervel\JWT\Contracts\TokenExtractor` and add that class to `jwt.parser`.

<a name="validations-and-leeway"></a>
### Validations and Leeway

The `validations` option controls which validation classes run when a token is decoded:

```php
'validations' => [
    Hypervel\JWT\Validations\RequiredClaims::class,
    Hypervel\JWT\Validations\ExpiredClaim::class,
    Hypervel\JWT\Validations\IssuerClaim::class,
    Hypervel\JWT\Validations\IssuedAtClaim::class,
    Hypervel\JWT\Validations\NotBeforeClaim::class,
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

If your application uses timestamp validations and your servers have small clock differences, configure `leeway` in seconds:

```php
'leeway' => (int) env('JWT_LEEWAY', 0),
```

<a name="blacklist"></a>
### Blacklist

The JWT blacklist lets the package invalidate tokens before they naturally expire:

```php
'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', false),
```

Blacklisting is disabled by default. When enabled, newly issued tokens include a `jti` claim and authenticated blacklist checks require cache access. Enable it when your application needs server-side token invalidation.

The blacklist uses the configured storage provider:

```php
'providers' => [
    'storage' => Hypervel\JWT\Storage\TaggedCache::class,
],
```

You may configure a grace period for concurrent requests that are using the same token while a refresh is in progress:

```php
'blacklist_grace_period' => env('JWT_BLACKLIST_GRACE_PERIOD', 0),
```

The `blacklist_refresh_ttl` option keeps blacklist entries long enough to cover the token's refresh window:

```php
'blacklist_refresh_ttl' => env('JWT_BLACKLIST_REFRESH_TTL', 20160),
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

You may issue a token for an existing user model using `login`:

```php
$token = Auth::guard('api')->login($user);
```

You may issue a token by user ID without setting the current guard user:

```php
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

<a name="reading-the-authenticated-user"></a>
### Reading the Authenticated User

Use the usual auth APIs to read the authenticated user or ID:

```php
$user = Auth::guard('api')->user();

$userId = Auth::guard('api')->id();
```

The `getUserId` method reads the token subject without loading the user model when no user is already cached:

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
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\Support\Facades\Auth;

Route::post('/token/refresh', function () {
    try {
        $token = Auth::guard('api')->refresh();
    } catch (JWTException) {
        abort(401, 'Token cannot be refreshed.');
    }

    abort_if($token === null, 401, 'No token provided.');

    return response()->json(['token' => $token]);
});
```

Do not protect the refresh route with `auth:api`. Refresh must be able to read an expired token that is still inside the refresh window, and normal auth middleware rejects expired access tokens before the handler runs.

The refresh window is controlled by `refresh_ttl`, in minutes:

```php
'refresh_ttl' => env('JWT_REFRESH_TTL', 20160),
```

If `refresh_iat` is `false`, refreshed tokens keep the original `iat` claim. If `refresh_iat` is `true`, refreshed tokens receive a fresh `iat` claim:

```php
'refresh_iat' => env('JWT_REFRESH_IAT', false),
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

Managed claims such as `nbf`, `exp`, `iss`, and `jti` are rebuilt by the package. The `iat` claim is rebuilt only when `refresh_iat` is enabled.

<a name="logging-out-and-invalidating-tokens"></a>
### Logging Out and Invalidating Tokens

The `logout` method clears the current guard user and token. If blacklist is enabled, it also invalidates the current token:

```php
Auth::guard('api')->logout();
```

To invalidate a token directly, enable the blacklist and call `invalidate`:

```php
Auth::guard('api')->invalidate();
```

You may pass `true` to blacklist the token forever:

```php
Auth::guard('api')->invalidate(true);
```

<a name="guard-methods"></a>
## Guard Methods

The JWT guard supports these methods:

```php
Auth::guard('api')->attempt($credentials);      // string|false
Auth::guard('api')->validate($credentials);     // bool
Auth::guard('api')->once($credentials);         // bool
Auth::guard('api')->onceUsingId($id);           // Authenticatable|false
Auth::guard('api')->login($user);               // string
Auth::guard('api')->tokenById($id);             // string|null
Auth::guard('api')->byId($id);                  // Authenticatable|false
Auth::guard('api')->user();                     // Authenticatable|null
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

The `claims` and `setTTL` methods affect only the next token-producing operation.

<a name="exceptions"></a>
## Exceptions

JWT exceptions extend `Hypervel\JWT\Exceptions\JWTException`.

Common exceptions include:

<div class="content-list" markdown="1">

- `SecretMissingException`
- `TokenBlacklistedException`
- `TokenExpiredException`
- `TokenInvalidException`
- `UserNotDefinedException`

</div>

<a name="differences-from-php-open-source-saver-jwt-auth"></a>
## Differences From "php-open-source-saver/jwt-auth"

Hypervel JWT is based on `php-open-source-saver/jwt-auth`, but its internals are adapted for Hypervel:

<div class="content-list" markdown="1">

- Hypervel uses array payloads instead of upstream `Payload`, `Token`, and claim DTO objects.
- Hypervel keeps the `JWT` facade mapped to the array-based `JWTManager`, but does not include upstream `JWTAuth`, `JWTFactory`, or `JWTProvider` facades.
- The parser chain is stateless. Request instances are passed to the parser for each parse so coroutine requests cannot leak through singleton services.
- Cookie token parsing is available but not enabled by default.
- Upstream route-parameter and Lumen parser shortcuts are not included.
- Upstream sliding refresh middleware is not included; use an explicit refresh endpoint that calls `Auth::guard(...)->refresh()`.
- Namshi and Lumen integrations are not included.
- The `show_black_list_exception` option is not included; JWT exceptions fail normally.

</div>
