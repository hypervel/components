# Passport

- [Introduction](#introduction)
    - [Passport or Sanctum?](#passport-or-sanctum)
- [Installation](#installation)
    - [Deploying Passport](#deploying-passport)
    - [Rotating Signing Keys](#rotating-signing-keys)
    - [Database Signing Keys](#database-signing-keys)
    - [Porting From Laravel](#porting-from-laravel)
- [Configuration](#configuration)
    - [Token Lifetimes](#token-lifetimes)
    - [Overriding Default Models](#overriding-default-models)
    - [Overriding Routes](#overriding-routes)
    - [Rate Limiting](#rate-limiting)
    - [Token and Client Caching](#token-and-client-caching)
- [Server Metadata and Public Keys](#server-metadata-and-public-keys)
- [Client Authentication](#client-authentication)
    - [JWT Client Assertions](#jwt-client-assertions)
- [Dynamic Client Registration](#dynamic-client-registration)
    - [Authorized Registration](#authorized-registration)
    - [Additional Client Metadata](#additional-client-metadata)
- [Pushed Authorization Requests](#pushed-authorization-requests)
- [Token Introspection](#token-introspection)
- [Authorization Code Grant](#authorization-code-grant)
    - [Managing Clients](#managing-clients)
    - [Requesting Tokens](#requesting-tokens)
    - [Managing Tokens](#managing-tokens)
    - [Refreshing Tokens](#refreshing-tokens)
    - [Revoking Tokens](#revoking-tokens)
    - [Purging Tokens](#purging-tokens)
- [Authorization Code Grant With PKCE](#code-grant-pkce)
    - [Creating the Client](#creating-a-auth-pkce-grant-client)
    - [Requesting Tokens](#requesting-auth-pkce-grant-tokens)
- [Device Authorization Grant](#device-authorization-grant)
    - [Creating a Device Authorization Grant Client](#creating-a-device-authorization-grant-client)
    - [Requesting Tokens](#requesting-device-authorization-grant-tokens)
- [Client Credentials Grant](#client-credentials-grant)
    - [Retrieving Tokens](#retrieving-tokens)
- [Personal Access Tokens](#personal-access-tokens)
    - [Creating a Personal Access Client](#creating-a-personal-access-client)
    - [Customizing the User Provider](#customizing-the-user-provider-for-pat)
    - [Managing Personal Access Tokens](#managing-personal-access-tokens)
- [Protecting Routes](#protecting-routes)
    - [Via Middleware](#via-middleware)
    - [Passing the Access Token](#passing-the-access-token)
- [Token Scopes](#token-scopes)
    - [Defining Scopes](#defining-scopes)
    - [Default Scope](#default-scope)
    - [Assigning Scopes to Tokens](#assigning-scopes-to-tokens)
    - [Checking Scopes](#checking-scopes)
- [Resource Indicators](#resource-indicators)
    - [Checking the Audience](#checking-the-audience)
- [Proof-Bound Tokens (DPoP)](#proof-bound-tokens)
- [SPA Authentication](#spa-authentication)
- [Events](#events)
- [Multiple Authorization Servers](#multiple-authorization-servers)
- [Customizing Authentication](#customizing-authentication)
- [Building on Passport](#building-on-passport)
    - [Authorization Attributes](#authorization-attributes)
    - [Additional Access Token Claims](#additional-access-token-claims)
    - [Authorization Interaction and Refresh Policy](#authorization-interaction-and-refresh-policy)
    - [Token Responses, Issuers and Public Keys](#token-responses-issuers-and-public-keys)
- [Testing](#testing)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Passport provides an OAuth2 server for your Hypervel application. It supports authorization code with PKCE, device authorization, client credentials, and personal access tokens. Passport is built on top of the [League OAuth2 server](https://github.com/thephpleague/oauth2-server).

> [!NOTE]
> This documentation assumes you are already familiar with OAuth2. If you do not know anything about OAuth2, consider familiarizing yourself with the general [terminology](https://oauth2.thephpleague.com/terminology/) and features of OAuth2 before continuing.

<a name="passport-or-sanctum"></a>
### Passport or Sanctum?

Before getting started, you may wish to determine if your application would be better served by Passport or [Sanctum](/docs/{{version}}/sanctum). If your application needs to support OAuth2, then you should use Passport.

However, if you are attempting to authenticate a single-page application, mobile application, or issue API tokens, you should use [Sanctum](/docs/{{version}}/sanctum). Sanctum does not support OAuth2; however, it provides a much simpler API authentication development experience.

<a name="installation"></a>
## Installation

You may install Passport via the `install:api` Artisan command:

```shell
php artisan install:api --passport
```

This command will publish and run the database migrations necessary for creating the tables your application needs to store OAuth2 clients and access tokens. The command will also create the encryption keys required to generate secure access tokens.

To publish the installation files without running migrations or creating a personal access client, pass `--without-migration-prompt`. This option is also available on `passport:install`. After migrating, run `passport:keys` if you use the database key store, and `passport:client --personal` if you need personal access tokens.

After running the `install:api` command, add the `Hypervel\Passport\HasApiTokens` trait and `Hypervel\Passport\Contracts\OAuthenticatable` interface to your `App\Models\User` model. This trait provides helper methods to inspect the authenticated user's token and scopes:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

Hypervel's `config/auth.php` already includes a `passport` guard. Use this guard to authenticate incoming API requests:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'passport' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```

<a name="deploying-passport"></a>
### Deploying Passport

When deploying Passport to your application's servers for the first time, run the `passport:keys` command. By default, this writes the RSA signing key pair to your application's storage directory. Keep the private key out of source control and provision the same keys on every instance that issues or verifies tokens:

```shell
php artisan passport:keys
```

Keys default to 4096 bits. You may choose a different length with `--length`; the minimum is 2048 bits:

```shell
php artisan passport:keys --length=2048
```

The `passport:install` command also accepts `--length`, as does `passport:keys:rotate` when using the [database key store](#database-signing-keys). Keys are parsed and retained by each worker. After changing file or environment keys, restart the server and every other long-running process that issues or verifies tokens, including queue workers. `server:reload` is not sufficient.

If necessary, you may define the path where Passport's keys should be loaded from. You may use the `Passport::loadKeysFrom` method to accomplish this. Typically, this method should be called from the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Passport::loadKeysFrom(__DIR__.'/../secrets/oauth');
}
```

<a name="loading-keys-from-the-environment"></a>
#### Loading Keys From the Environment

Alternatively, you may publish Passport's configuration file using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --tag=passport-config
```

After the configuration file has been published, you may load your application's encryption keys by defining them as environment variables:

```ini
PASSPORT_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----
<private key here>
-----END RSA PRIVATE KEY-----"

PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----
<public key here>
-----END PUBLIC KEY-----"
```

<a name="rotating-signing-keys"></a>
### Rotating Signing Keys

When managing keys through files or environment variables, publish each replacement before using it to sign tokens:

1. Add the new public key to `passport.verification_keys`, which accepts PEM contents or file paths. Restart every instance and long-running process so they can verify it.
2. Configure the new private and public keys as the signing pair, and retain the previous public key in `verification_keys`. Restart those processes again.
3. After every token signed by the old key has expired, remove its public key and restart those processes.

Access tokens include a `kid` header identifying their signing key. Existing tokens continue to verify while that public key is retained. Authorization codes and refresh tokens are encrypted with a separate key derived from your application key, so changing an RSA signing key does not invalidate them.

If you customize grant encryption through `Passport::encryptTokensUsing`, return random key material that remains the same for the worker lifetime. Configure this callback during provider boot.

By default, rotating `APP_KEY` invalidates outstanding authorization codes, refresh tokens and API cookies. With the database key store, `app.previous_keys` keeps stored private keys readable, but does not preserve the derived keys used for those credentials. Applications using `Passport::encryptTokensUsing` may supply separate, stable key material.

<a name="database-signing-keys"></a>
### Database Signing Keys

The database key store supports rotation without restarting workers. Private keys are encrypted with your application key. This protects private keys in a database dump; it does not protect them from someone who also has the application key. Keep old application keys in `app.previous_keys` when rotating the application key so stored private keys remain readable.

After running Passport's migrations, enable the store and create the first key:

```ini
PASSPORT_KEY_STORE=database
```

```shell
php artisan passport:keys
```

To move an existing file-based installation to the database store, import its keys before changing `PASSPORT_KEY_STORE`:

```shell
php artisan passport:keys:import
```

The database key store must be empty before importing. The import includes `verification_keys` and retains the keys until the latest stored access token expires. It also accounts for your configured token lifetimes so tokens issued before file workers restart remain valid. If other services use those keys with longer token lifetimes, or custom signing code uses lifetimes Passport cannot determine, provide their latest expiry explicitly:

```shell
php artisan passport:keys:import --retain-until="2030-01-01T00:00:00Z"
```

After importing, set `PASSPORT_KEY_STORE=database` and restart your long-running processes once to select the new store. Later rotations require no restart.

Passport registers a daily rotation check when the database store and key rotation are enabled. Run your application's [scheduler](/docs/{{version}}/scheduling#running-the-scheduler); you do not need to add the rotation task yourself. Configure automatic rotation in `config/passport.php`:

```php
'key_rotation' => [
    'enabled' => (bool) env('PASSPORT_KEY_ROTATION', true),
    'days' => (int) env('PASSPORT_KEY_ROTATION_DAYS', 90),
],
```

Replacement keys are published for verification before they sign tokens. The default `key_activation_delay` is 86400 seconds, and each worker checks for changed keys every `key_refresh_interval` seconds, which defaults to 60. The activation delay must exceed the refresh interval. Existing keys remain available for verification until the tokens they signed have expired.

You may also manage keys manually:

```shell
php artisan passport:keys:list
php artisan passport:keys:rotate --length=2048
php artisan passport:keys:rotate --due
php artisan passport:keys:revoke your-key-id
```

Revocation is immediate in storage. If the revoked key was signing, Passport creates an immediately active replacement. Tokens signed by the revoked key stop working as workers refresh their keys; during that refresh interval, a token signed by the replacement may fail on another worker. External servers caching the [public key endpoint](#server-metadata-and-public-keys) may retain the old keys for its HTTP cache lifetime in addition to the worker refresh interval. Use this command for emergency revocation and use ordinary rotation for planned changes.

Applications may import customer-supplied private keys through `Hypervel\Passport\SigningKeyRepository::import`. Imported keys must be RSA keys of at least 2048 bits. They activate after `passport.key_activation_delay`, following any key already waiting to activate. An imported key follows the same automatic rotation policy as a generated key. If your application promises customer-managed keys, set `passport.key_rotation.enabled` to false, or override `SigningKeyRepository::rotateDueKeys()` when managing several key sets, and let customers supply replacements.

Key management uses the default cache store for atomic locks. The store must be shared by every process that manages the same key set. Key-management operations cannot run inside an application database transaction; Passport commits its own changes before releasing the lock.

<a name="porting-from-laravel"></a>
### Porting From Laravel

Consult the [Passport porting guide](/docs/{{version}}/porting-from-laravel#passport) before moving an existing Laravel installation. Hypervel omits the implicit and password grants and deprecated management APIs, and changes credential storage and encryption. Use authorization code with PKCE or device authorization for user authentication.

<a name="configuration"></a>
## Configuration

Configure Passport's static methods and callbacks in a service provider's `boot` method. They are shared by every request on a worker. Use [server settings](#multiple-authorization-servers) when configuration must vary between requests.

<a name="token-lifetimes"></a>
### Token Lifetimes

By default, Passport issues long-lived access tokens that expire after one year. If you would like to configure a longer / shorter token lifetime, you may use the `tokensExpireIn`, `refreshTokensExpireIn`, and `personalAccessTokensExpireIn` methods. These methods should be called from the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use Carbon\CarbonInterval;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Passport::tokensExpireIn(CarbonInterval::days(15));
    Passport::refreshTokensExpireIn(CarbonInterval::days(30));
    Passport::personalAccessTokensExpireIn(CarbonInterval::months(6));
}
```

> [!WARNING]
> Changing an access or refresh token's `expires_at` column does not change the expiry embedded in the signed or encrypted token. If you need to invalidate a token, [revoke it](#revoking-tokens).

<a name="overriding-default-models"></a>
### Overriding Default Models

You are free to extend the models used internally by Passport by defining your own model and extending the corresponding Passport model:

```php
use Hypervel\Passport\Client as PassportClient;

class Client extends PassportClient
{
    // ...
}
```

After defining your model, you may instruct Passport to use your custom model via the `Hypervel\Passport\Passport` class. Typically, you should inform Passport about your custom models in the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use App\Models\Passport\AuthCode;
use App\Models\Passport\Client;
use App\Models\Passport\DeviceCode;
use App\Models\Passport\RefreshToken;
use App\Models\Passport\RefreshTokenFamily;
use App\Models\Passport\SigningKey;
use App\Models\Passport\Token;
use Hypervel\Passport\Passport;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Passport::useTokenModel(Token::class);
    Passport::useRefreshTokenModel(RefreshToken::class);
    Passport::useAuthCodeModel(AuthCode::class);
    Passport::useClientModel(Client::class);
    Passport::useDeviceCodeModel(DeviceCode::class);
    Passport::useRefreshTokenFamilyModel(RefreshTokenFamily::class);
    Passport::useSigningKeyModel(SigningKey::class);
}
```

Token, consent, authorization-code, device-code, refresh-token, refresh-token-family, pushed-authorization-request and database signing-key models must use the same database connection. Client models may use a different connection. If an application's user or client-owner model uses the default connection while Passport uses another, declare that default connection explicitly on the owner model so Eloquent does not inherit Passport's connection for the owner relationship.

Do not wrap the OAuth token endpoint in application transaction middleware. Reuse detection and device polling must remain committed when an exchange fails. Personal access token creation and explicit token revocation may run inside an application transaction and follow its commit or rollback.

<a name="overriding-routes"></a>
### Overriding Routes

To change only the route prefix, set `path` in `config/passport.php`; it defaults to `oauth`:

```php
'path' => 'auth/oauth',
```

Passport keeps the same route names, so generated authorization and device URLs use the configured prefix. When deploying behind a reverse proxy, also configure your application's [trusted proxies](/docs/{{version}}/requests#configuring-trusted-proxies) so generated URLs use the correct external host, scheme and path prefix.

Sometimes you may wish to customize the routes defined by Passport. To achieve this, you first need to ignore the routes registered by Passport by adding `Passport::ignoreRoutes` to the `register` method of your application's `AppServiceProvider`:

```php
use Hypervel\Passport\Passport;

/**
 * Register any application services.
 */
public function register(): void
{
    Passport::ignoreRoutes();
}
```

Then, you may copy `vendor/hypervel/passport/routes/web.php` to your application's `routes/passport.php` file and modify it to your liking. Register it through the `then` callback of `withRouting` in `bootstrap/app.php`, without an enclosing `web` middleware group, so token and other protocol endpoints remain stateless. The copied file applies browser middleware to the routes that need it.

The discovery route is registered separately from that file. Include it outside the OAuth prefix when registering your routes:

```php
use Hypervel\Http\Middleware\SetCacheHeaders;
use Hypervel\Passport\Http\Controllers\ServerMetadataController;
use Hypervel\Support\Facades\Route;

->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function () {
        Route::group([
            'as' => 'passport.',
            'prefix' => config()->string('passport.path'),
            'namespace' => '\Hypervel\Passport\Http\Controllers',
            'middleware' => config()->array('passport.middleware'),
        ], base_path('routes/passport.php'));

        Route::get('/.well-known/oauth-authorization-server/{issuerPath?}', [ServerMetadataController::class, 'show'])
            ->where('issuerPath', '.*')
            ->name('passport.metadata')
            ->middleware([...config()->array('passport.middleware'), SetCacheHeaders::using('etag')]);
    },
)
```

For more information, see [customizing routing](/docs/{{version}}/routing#routing-customization).

<a name="rate-limiting"></a>
### Rate Limiting

Passport uses the named `passport` rate limiter on `POST /oauth/token`, `POST /oauth/device/code`, `GET /oauth/device/authorize`, `POST /oauth/par`, `POST /oauth/revoke` and the optional registration endpoint. Limiting the device authorization page prevents repeated attempts to guess user codes. By default, the limiter allows 60 requests per minute for each authenticated user or IP address. You may customize this limit in your application's `AppServiceProvider`:

```php
use Hypervel\Http\Request;
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('passport', fn (Request $request) => Limit::perMinute(120)
        ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
}
```

Introspection requires confidential client authentication and does not share this limit: a resource server may introspect on every API request. To add an application-specific limit, [override its route](#overriding-routes). For additional options, consult the [rate limiting documentation](/docs/{{version}}/rate-limiting#named-rate-limiters).

<a name="token-and-client-caching"></a>
### Token and Client Caching

You may enable shared caching to avoid the access-token and client database lookups on repeated API requests:

```ini
PASSPORT_CACHE_ENABLED=true
PASSPORT_CACHE_STORE=redis
PASSPORT_CACHE_TTL=300
```

Caching is disabled by default. A null or empty `cache.store` uses your application's default cache store. JWT signatures and expiration are checked on every request. The user lookup is separate and may be cached through your [Eloquent user provider](/docs/{{version}}/authentication#user-lookup-cache).

Use a cache store shared by every process that changes clients or revokes tokens. Redis or a shared database cache works across application servers. A file store requires shared storage, and a Swoole store is suitable only when every writer runs in the same server. Request-local and worker-local array stores are not supported. Keep access to this cache as restricted as access to your session store.

Passport invalidates entries after model updates and deletes, package revocations, and `update` or `delete` through `$user->tokens()`, `$client->tokens()` and `$user->oauthApps()`. Invalidation waits until a database transaction commits. Reads inside a transaction use the database directly.

Invalidation waits for any cache fill on the same entry. If the lock times out, `LockTimeoutException` is thrown after the database change has been saved. During token issuance, Passport reports invalidation failures after its commit and returns the issued credentials, so the client receives its replacement tokens. Explicit revocation operations still throw. The affected entry may remain cached until it is cleared or expires.

When your database uses separate read and write connections, Passport reads access tokens and clients from the write connection, whether caching is enabled or disabled. This ensures that newly issued credentials and revocations are visible immediately.

Other bulk writes, such as `DB::table('oauth_access_tokens')->update(...)` or `Token::query()->update(...)`, bypass model events and Passport's invalidation. Their changes become visible when cached entries expire. Use the supported model or relationship methods when a revocation must take effect immediately.

If you update access tokens through a query builder, you may clear each affected token's cache entry explicitly:

```php
use Hypervel\Passport\Passport;

$tokenModel = Passport::tokenModel();

$tokenModel::query()->whereKey($tokenId)->update(['revoked' => true]);
$tokenModel::clearTokenCache($tokenId);
```

This method uses the token model's database connection. If a transaction is open, shared cache invalidation waits for its outermost commit; otherwise, it runs immediately. A rollback leaves the existing committed cache entry in place.

<a name="server-metadata-and-public-keys"></a>
## Server Metadata and Public Keys

Set `PASSPORT_ISSUER` to your authorization server's public HTTPS URL:

```dotenv
PASSPORT_ISSUER=https://passport-app.test
```

The issuer cannot contain user information, a query string or a fragment. Passport uses this exact value in discovery, the `iss` claim of access tokens, authorization responses, and the audience expected in JWT client assertions. A null issuer disables discovery and omits `iss`, while ordinary OAuth flows and the public key endpoint remain available. You may leave it null for local HTTP development.

Clients can discover the server's endpoints and supported features by requesting `GET https://passport-app.test/.well-known/oauth-authorization-server`. This RFC 8414 document lists the features enabled by your application. For a path-based issuer such as `https://passport-app.test/accounts/example`, the discovery URL is `https://passport-app.test/.well-known/oauth-authorization-server/accounts/example`.

The public JSON Web Key Set is available at `GET /oauth/jwks`, named `passport.jwks`. It contains the selected server's current, upcoming and retained public keys. Configure external resource servers to use the `jwks_uri` published in discovery.

The discovery route is named `passport.metadata` and is independent of `passport.path`. Both discovery and the ordinary Passport routes use `passport.middleware`. Endpoint URLs use named routes, so your configured prefix, route defaults and trusted proxy headers are honored. Behind a proxy with an application prefix, forward the canonical well-known URL to the application while keeping the normal `X-Forwarded-Prefix` header for endpoint URL generation. Applications defining their own routes should retain these route names and apply `Hypervel\Http\Middleware\SetCacheHeaders::using('etag')` to discovery and JWKS.

Resource-server integrations may add a protected-resource metadata URL to Passport's Bearer and DPoP authentication challenges. Register `Passport::resolveResourceMetadataUsing` during provider boot with a callback accepting the current `Request` and returning a URL, or `null` for routes it does not serve. The callback runs only when a challenge is needed. [MCP](/docs/{{version}}/mcp#oauth) registers this callback automatically; ordinary API requests do not need additional configuration.

For browser clients on other origins, add the appropriate paths to your application's `cors.php` configuration, adjusting `oauth/*` if you changed Passport's prefix:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie', 'oauth/*', '.well-known/oauth-authorization-server*'],
```

Discovery and JWKS support ETags and conditional requests. File keys require revalidation on every request. With database keys, the HTTP cache lifetime is the smaller of `key_refresh_interval` and `key_activation_delay - key_refresh_interval`: 60 seconds with the defaults. Clients must revalidate expired responses. This allows planned keys to reach clients before activation while limiting the additional delay during emergency revocation.

Custom `ServerSettings` default to a `metadataCacheLifetime` of zero, which also requires revalidation. You may supply a positive number of seconds when your key publication delay accounts for both settings-cache staleness and this HTTP cache lifetime. Remember that the HTTP lifetime also delays external clients learning about an emergency key removal.

When an issuer is configured, authorization responses include the standard `iss` parameter, including redirected errors and form-post responses. Clients supporting RFC 9207 should compare it with the issuer they selected before exchanging the authorization code.

<a name="client-authentication"></a>
## Client Authentication

Passport supports the following client authentication methods:

| Method | Credentials |
| --- | --- |
| `none` | A public client's `client_id`, without a secret. |
| `client_secret_basic` | An HTTP Basic header containing the client ID and secret. |
| `client_secret_post` | The `client_id` and `client_secret` form fields. |
| `private_key_jwt` | A signed JWT assertion using the client's registered public keys. |

Clients created through `ClientRepository` accept Basic or form credentials when they have a secret, unless an explicit `token_endpoint_auth_method` is set. Public clients use `none`; they may also identify themselves through Basic with an empty password. Public clients without an explicit method ignore supplied secrets. Dynamically registered clients accept only their registered method. Do not send Basic credentials together with a form secret or assertion.

For Basic authentication, form-encode the client ID and secret before constructing the header:

```php
$response = Http::asForm()
    ->withBasicAuth(urlencode($clientId), urlencode($clientSecret))
    ->post($tokenUrl, ['grant_type' => 'client_credentials']);
```

A client's nullable `scopes` attribute limits its allowed scopes. Null leaves the client unrestricted; an empty array allows none. Authorization and device requests reject explicit scopes outside this limit. When scopes are omitted, Passport intersects the default scopes with the client's limit and retains that selection through login and consent.

<a name="jwt-client-assertions"></a>
### JWT Client Assertions

To use `private_key_jwt`, register a public `jwks` document or an HTTPS `jwks_uri`, and set the server's canonical `issuer`. Supported signing algorithms are `RS256` and `ES256`; the default is `RS256`. RSA keys must be between 2048 and 4096 bits, with public exponents no larger than 32 bits; the usual exponent of 65537 is supported. ES256 keys use the P-256 curve. Do not supply private key material. A key set may also contain public keys for other purposes; Passport selects only keys suitable for the registered assertion algorithm. Registration accepts key declarations only for `private_key_jwt` clients. Inline and remote documents are limited to 64 KiB.

The assertion's `iss` and `sub` must equal the client ID. Its sole `aud` must be the exact server issuer, as a string or a single-element array; endpoint URLs and multiple audiences are rejected. Include `exp` and a unique `jti`; `iat` is optional. Passport allows at most five minutes between `iat` and `exp`, or five minutes of remaining validity when `iat` is absent, allowing 60 seconds for clock differences. The optional `nbf` must permit immediate use within that allowance. Sign a new assertion for each request:

```php
$response = Http::asForm()->post($tokenUrl, [
    'grant_type' => 'client_credentials',
    'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    'client_assertion' => $signedAssertion,
]);
```

Configure `passport.replay.store` to use a shared Redis or database cache store. A null or empty value uses the default store. Local stores, stack stores and failover stores cannot preserve a shared replay history and are rejected. Redis must use `noeviction`; a separate logical Redis database alone does not change that policy. Do not flush replay entries while assertions remain valid. Authentication fails if replay storage is unavailable.

A database replay store cannot share an active application transaction, since rolling back that transaction would restore a used assertion. Use a separate cache connection when wrapping requests in transactions. Redis is recommended for high-volume assertion authentication, avoiding a database write per assertion.

Database cache stores retain expired replay entries until they are pruned. Schedule the existing cache cleanup command, supplying your replay store's name:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('cache:prune-db-expired replay')->everyFiveMinutes();
```

Omit `replay` when using the default cache store. Choose a pruning interval appropriate to your request volume; storage includes entries waiting for the next cleanup as well as entries that are still valid.

Remote keys use `passport.cache.store`, independently of whether token caching is enabled. The store must support atomic locks. `passport.client_keys.ttl` defaults to one hour; a new key identifier may trigger an earlier refresh, limited by `passport.client_keys.refresh_interval`, which defaults to 60 seconds. Publish replacement keys before clients begin signing with them. Remote requests use public HTTPS destinations, bounded response sizes and timeouts.

<a name="dynamic-client-registration"></a>
## Dynamic Client Registration

Dynamic registration is disabled by default. To enable `POST /oauth/register`, call `enableDynamicClientRegistration` from your service provider's `register` method, before Passport registers its routes:

```php
use Hypervel\Passport\Passport;

public function register(): void
{
    Passport::enableDynamicClientRegistration();
}
```

Clients submit JSON metadata to this endpoint:

```json
{
    "client_name": "Calendar",
    "redirect_uris": ["https://calendar.example.com/callback"],
    "grant_types": ["authorization_code", "refresh_token"],
    "token_endpoint_auth_method": "none",
    "scope": "calendar:read"
}
```

Set `passport.registration.scopes` to the scopes anonymous registrations may request. It defaults to an empty array. Anonymous registration supports authorization-code and refresh grants, including shared-secret and JWT-authenticated clients, but cannot grant client-credentials authority, wildcard scopes or `passport:register`. Including either reserved scope in the anonymous allowance prevents application startup.

The response returns HTTP 201 with the effective registered metadata, including defaults, and a new `client_id`. The default authentication method is `client_secret_basic`. Methods requiring a secret return it once as `client_secret`; store it securely, since Passport retains only its hash. Invalid metadata returns `invalid_client_metadata` or `invalid_redirect_uri` with HTTP 400. Unknown fields are ignored unless an extension validates them.

Web clients must register exact HTTPS callbacks without fragments or wildcards. Set `application_type` to `native` to use loopback HTTP callbacks at `127.0.0.1` or `[::1]`, or a reverse-domain private-use scheme such as `com.example.app:/callback`. Public native clients should explicitly select `none`.

<a name="authorized-registration"></a>
### Authorized Registration

To register machine clients or request scopes beyond the anonymous limit, send a real access token containing the explicit `passport:register` scope. Define that scope through `Passport::tokensCan`. It grants client-administration authority, including enabled machine grants and any defined scope, including `*`; the provisioning token's own API scopes do not limit the new client. A wildcard-only token does not qualify, and browser sessions or API cookies do not grant provisioning authority.

Clients without a scope ceiling can request `passport:register` during authorization, so give third-party clients a ceiling that excludes it. When user-issued tokens may contain this scope, check the user's administration permission in a `ClientMetadataValidated` listener: resolve the user using `$event->token->user_id` and `$event->token->provider`, and throw `InvalidClientMetadataException` to reject unauthorized registration.

A user-issued token associates the new client with that user through the token's recorded provider. The user must still exist and implement `OAuthenticatable`. A machine-issued token creates an unowned client. Submitted owner, provider and secret fields cannot override these decisions.

Invalid access tokens receive an `invalid_token` response with HTTP 401. Valid tokens missing the provisioning scope receive `insufficient_scope` with HTTP 403. Both responses include a `WWW-Authenticate` challenge. Requests using another authentication scheme receive HTTP 401 with a bare Bearer challenge; requests without credentials use anonymous registration.

Passport does not register client-management endpoints. Manage existing clients through your application's authorized administration routes and normal model APIs.

<a name="additional-client-metadata"></a>
### Additional Client Metadata

Listen for `Hypervel\Passport\Events\ClientMetadataValidated` to validate additional fields or apply application policy. The synchronous event exposes `submitted` and core `validated` metadata, the existing `client` when validating an update, and the provisioning `token` when present. Place accepted fields in `$event->extensions`; registration persists and returns them. Add corresponding columns and casts to your configured client model. Extensions cannot replace core metadata, ownership or credentials.

Reject metadata by throwing `InvalidClientMetadataException::invalidClientMetadata($description)` or `invalidRedirectUri($description)`. The registration endpoint renders these as protocol errors. Application management code may use `ClientMetadataValidator::validate($metadata, $client)` to validate a complete metadata document and run the same extension listeners. This method returns protocol metadata; the application remains responsible for authorization and persistence, including mapping `client_name` to `name` and `scope` to the model's `scopes` array. Direct model writes bypass metadata validation.

<a name="pushed-authorization-requests"></a>
## Pushed Authorization Requests

Pushed authorization requests (PAR) let a client send authorization parameters directly to Passport instead of placing them in the browser URL. Submit the usual authorization parameters as a form to `POST /oauth/par`, using the client's registered authentication method:

```php
$response = Http::asForm()
    ->withBasicAuth(urlencode($clientId), urlencode($clientSecret))
    ->post('https://passport-app.test/oauth/par', [
        'response_type' => 'code',
        'redirect_uri' => 'https://third-party-app.com/callback',
        'scope' => 'calendar:read',
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
    ]);
```

Public clients send their `client_id` without a secret and must supply an `S256` PKCE challenge. Keep the corresponding verifier and state as you would for an ordinary authorization-code request.

A successful response has HTTP status 201 and contains `request_uri` and `expires_in`. Redirect the browser to the authorization endpoint with just the client ID and this reference:

```php
return redirect('https://passport-app.test/oauth/authorize?' . http_build_query([
    'client_id' => $clientId,
    'request_uri' => $response->json('request_uri'),
]));
```

Passport uses the pushed parameters and ignores replacement authorization parameters in that browser request. A reference expires after 90 seconds by default, configured through `passport.par.lifetime`, and can be consumed only once. Login, confirmation and consent then continue through the saved browser interaction; they do not consume the reference again. Scopes and the callback are rechecked against current client policy without recalculating the original defaults.

Set a client's `require_pushed_authorization_requests` attribute to `true` to require PAR for that client. The same field is accepted during dynamic registration. It defaults to `false`; enabling it rejects direct authorization requests, including previously saved direct interactions.

PAR accepts form bodies up to 64 KiB. Errors return JSON, without redirecting to a callback; oversized bodies receive HTTP 413. Unknown, expired, consumed or mismatched references return `invalid_request_uri`. Exclude browser requests that consume PAR references from application-wide database transactions: a rollback could restore a used reference while its browser session survives.

Schedule `passport:purge` to remove unused expired references. These are removed immediately on expiry whenever expired records are purged, regardless of `--hours`; `--revoked` alone leaves them unchanged. To customize storage, extend `Hypervel\Passport\PushedAuthorizationRequest` and register it with `Passport::usePushedAuthorizationRequestModel()` during application boot. Its database connection must match the other Passport credential models.

<a name="token-introspection"></a>
## Token Introspection

Confidential clients may check an access or refresh token by sending a form request to `/oauth/introspect`, named `passport.token.introspect`. Authenticate using the client's registered [authentication method](#client-authentication):

```php
use Hypervel\Support\Facades\Http;

$response = Http::asForm()->withBasicAuth(urlencode($clientId), urlencode($clientSecret))
    ->post('https://passport-app.test/oauth/introspect', [
        'token' => $accessToken,
    ]);
```

A valid token belonging to the client returns `active: true` with its client ID, scope, subject when present, issuer and expiry. Access tokens also include their audience and token type. Invalid, expired, revoked or inaccessible tokens return only `{"active": false}`. Public clients cannot use this endpoint. Responses must not be cached.

By default, clients may inspect only their own tokens. To allow a resource server to inspect another client's access tokens, register `authorizeIntrospectionUsing` during provider boot. The callback receives the authenticated client and stored access token, after Passport checks the token's normal validity and [authentication policy](#customizing-authentication):

```php
use App\Services\ResourceAccess;
use Hypervel\Passport\Client;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Token;

/**
 * Bootstrap any application services.
 */
public function boot(ResourceAccess $resources): void
{
    Passport::authorizeIntrospectionUsing(
        fn (Client $client, Token $token): bool => $resources->mayInspect($client, $token)
    );
}
```

Your policy must restrict the caller to the intended resource and enforce the token's audience when applicable. This callback does not grant permission to inspect another client's refresh tokens or revoke their credentials. A refresh token can remain active after its access token expires.

Deleting a user row does not revoke OAuth credentials. When deleting an account, [revoke its consents](#remembering-consent) to invalidate their authorizations and refresh-token families, and [revoke its remaining tokens](#revoking-tokens), including personal access tokens. Applications requiring a live account check may use `authenticateAccessTokensUsing`; introspection applies this policy without loading the user itself.

<a name="authorization-code-grant"></a>
## Authorization Code Grant

Using OAuth2 via authorization codes is how most developers are familiar with OAuth2. When using authorization codes, a client application will redirect a user to your server where they will either approve or deny the request to issue an access token to the client.

To get started, we need to instruct Passport how to return our "authorization" view.

All the authorization view's rendering logic may be customized using the appropriate methods available via the `Hypervel\Passport\Passport` class. Typically, you should call this method from the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use Hypervel\Inertia\Inertia;
use Hypervel\Passport\Passport;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    // By providing a view name...
    Passport::authorizationView('auth.oauth.authorize');

    // By providing a closure...
    Passport::authorizationView(
        fn ($parameters) => Inertia::render('Auth/OAuth/Authorize', [
            'request' => $parameters['request'],
            'authToken' => $parameters['authToken'],
            'client' => $parameters['client'],
            'redirectUri' => $parameters['redirectUri'],
            'user' => $parameters['user'],
            'scopes' => $parameters['scopes'],
            'permissions' => $parameters['permissions'],
        ])
    );
}
```

Passport automatically defines the `/oauth/authorize` route that returns this view. Your `auth.oauth.authorize` template should include a form that makes a POST request to the `passport.authorizations.approve` route to approve the authorization and a form that makes a DELETE request to the `passport.authorizations.deny` route to deny it. Both routes use `/oauth/authorize/consent` and require the `auth_token` supplied to the view and the application's CSRF token. Passport retains the client, callback and protocol parameters in the session; the forms do not need to submit them again.

The `redirectUri` view parameter contains the saved, validated callback, including when the client omitted it from the authorization request or used a pushed request. For HTTP and HTTPS callbacks, display its host on third-party consent screens so users can recognize the destination; client names and logos are supplied by the client itself. For other callback schemes, display the complete URI so users can identify the receiving application by its scheme. Escape these values as you would any other client-supplied text.

Passport's consent pages use `Hypervel\Http\Middleware\FrameGuard` to prevent other sites from framing them. Apply the same protection to your application's login pages.

Use a server-side session driver and a shared lock-capable session blocking store for browser authorization. Passport locks each interaction request and stores pending authorizations separately, so completing one tab does not consume another. Cookie sessions cannot provide these guarantees: an older browser cookie can replay pending state, and concurrent requests can overwrite each other's entries. The default SameSite `lax` policy supports cross-site authorization redirects; `strict` can withhold the session through a redirect chain and lose the pending interaction.

The `authorization.lifetime` and `authorization.max_pending` options in `config/passport.php` default to ten minutes and ten pending interactions per session. Expired entries are removed and the oldest entry is evicted when the limit is reached.

<a name="remembering-consent"></a>
#### Remembering Consent

Passport remembers approval independently of issued tokens. A user does not need to approve the same permissions again when an access token expires. By default, approval is remembered until the user forgets or revokes it. You may set a duration in a service provider:

```php
Passport::rememberConsentFor(now()->addDays(30));
```

Reusing an approval does not extend its duration. To let users approve only some permissions, submit a `scopes` array containing their selected scope identifiers. Extensions may also provide named permission sets through the `permissions` view parameter; submit the selected values under the same names. Values not present in the original presentation are rejected. Omitting either field approves its entire presented set; send an empty array or an empty form value to approve none of that set. A `remember` value of `false` grants access without remembering the approval for the next authorization.

When using checkboxes, place an empty hidden input before them so unchecking every scope submits an explicit empty selection:

```blade
<input type="hidden" name="scopes" value="">

@foreach ($scopes as $scope)
    <label>
        <input type="checkbox" name="scopes[]" value="{{ $scope->id }}" checked>
        {{ $scope->description }}
    </label>
@endforeach
```

Likewise, place a hidden false value before a remember checkbox. Otherwise, leaving the checkbox unchecked omits the field and uses the configured remember duration:

```blade
<input type="hidden" name="remember" value="0">

<label>
    <input type="checkbox" name="remember" value="1" checked>
    Remember this approval
</label>
```

Retrieve a user's approvals through `$user->oauthConsents()`, or a client's approvals through `$client->consents()`. Use `forget()` to require consent next time without revoking existing credentials. Use `revoke()` to revoke linked codes, device codes, access tokens and refresh tokens, and delete the approval:

```php
$consent = $user->oauthConsents()->findOrFail($consentId);

$consent->forget();

// Revoke the authorization and its credentials...
$consent->revoke();
```

Deleting a consent model also revokes its credentials. Mass-query deletes and raw database writes bypass this model behavior; use the model methods when revoking access. To customize remembered permission coverage, extend `Hypervel\Passport\Consent`, override `covers()`, and register the model with `Passport::useConsentModel()`.

The consent's `scopes` attribute records approval for requests without a [resource](#resource-indicators). A null value means there is no remembered approval for that access; an empty array means it was approved without scopes. Resource-specific approvals are recorded separately in `permissions['resources']`.

<a name="managing-clients"></a>
### Managing Clients

Developers building applications that need to interact with your application's API will need to register their application with yours by creating a "client". Typically, this consists of providing the name of their application and a URI that your application can redirect to after users approve their request for authorization.

<a name="managing-first-party-clients"></a>
#### First-Party Clients

The simplest way to create a client is using the `passport:client` Artisan command. This command may be used to create first-party clients or testing your OAuth2 functionality. When you run the `passport:client` command, Passport will prompt you for more information about your client and will provide you with a client ID and secret:

```shell
php artisan passport:client
```

If you would like to allow multiple redirect URIs for your client, you may specify them using a comma-delimited list when prompted for the URI by the `passport:client` command. Any URIs which contain commas should be URI encoded:

```shell
https://third-party-app.com/callback,https://example.com/oauth/redirect
```

<a name="managing-third-party-clients"></a>
#### Third-Party Clients

Since your application's users will not be able to utilize the `passport:client` command, you may use `createAuthorizationCodeGrantClient` method of the `Hypervel\Passport\ClientRepository` class to register a client for a given user:

```php
use App\Models\User;
use Hypervel\Passport\ClientRepository;

$user = User::find($userId);

// Creating an OAuth app client that belongs to the given user...
$client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
    user: $user,
    name: 'Example App',
    redirectUris: ['https://third-party-app.com/callback'],
    confidential: false,
    enableDeviceFlow: true
);

// Retrieving all the OAuth app clients that belong to the user...
$clients = $user->oauthApps()->get();
```

The `createAuthorizationCodeGrantClient` method returns an instance of `Hypervel\Passport\Client`. You may display the `$client->id` as the client ID and `$client->plainSecret` as the client secret to the user. The secret is stored as a SHA-256 digest and cannot be retrieved later. Use `ClientRepository::regenerateSecret` to issue a replacement.

To revoke a client and reject its access tokens, call `$client->revoke()`.

<a name="requesting-tokens"></a>
### Requesting Tokens

<a name="requesting-tokens-redirecting-for-authorization"></a>
#### Redirecting for Authorization

Once a client has been created, developers may use their client ID and secret to request an authorization code and access token from your application. First, the consuming application should make a redirect request to your application's `/oauth/authorize` route like so:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Str;

Route::get('/redirect', function (Request $request) {
    $request->session()->put('state', $state = Str::random(40));

    $query = http_build_query([
        'client_id' => 'your-client-id',
        'redirect_uri' => 'https://third-party-app.com/callback',
        'response_type' => 'code',
        'scope' => 'user:read orders:create',
        'state' => $state,
        // 'prompt' => '', // "none", "consent", "login", or "select_account"
    ]);

    return redirect('https://passport-app.test/oauth/authorize?'.$query);
});
```

The `prompt` parameter may be used to specify the authentication behavior of the Passport application.

If the `prompt` value is `none`, Passport returns a protocol error when login, account selection or consent requires user interaction. The value `none` cannot be combined with another prompt. If the value is `consent`, Passport always displays the approval screen, even when the requested permissions were previously granted. When the value is `login`, guests sign in normally; users with an existing session must confirm their identity without being logged out. Remember-cookie restoration does not count as fresh authentication.

If no `prompt` value is provided, Passport requests consent when remembered approval does not cover the requested permissions, unless the client's trusted `skipsAuthorization()` policy permits access.

Passport's default reauthentication response redirects to your application's `password.confirm` route. Fortify and Passkeys can provide this confirmation flow. Applications with their own confirmation screens may bind `Hypervel\Passport\Contracts\ReauthenticationResponse`; after verifying the user, call `$request->session()->passwordConfirmed($guard)` and return to the response's `returnUrl`. The response receives `client`, `user` and `authToken` as well. Starting a challenge clears that guard's earlier password-confirmation timestamp, so it also expires the application's previous confirmation window for that guard.

The `select_account` prompt displays the view registered with `Passport::accountSelectionView()`. Register this view explicitly; `viewPrefix()` does not register an account-selection view. Without one, Passport returns the `account_selection_required` protocol error. The view receives `client`, `user`, `request` and `authToken`. Submit `auth_token` and a CSRF token to `passport.authorizations.account.continue` to continue as that user, or to `passport.authorizations.account.switch` to sign out and choose another account. Both routes accept POST requests. A new login after an explicit account switch must still complete account selection.

Clients may request `response_mode=form_post` to receive the authorization code or protocol error through an automatically submitted form instead of a query redirect. A manual submit button is provided when JavaScript is unavailable. Errors for an invalid client or callback remain local and are never sent to an untrusted URL. Protocol requests may also POST form data to `/oauth/authorize`; this endpoint redirects to GET without starting a session. Consent decisions belong at `/oauth/authorize/consent`.

> [!NOTE]
> Remember, the `/oauth/authorize` route is already defined by Passport. You do not need to manually define this route.

<a name="approving-the-request"></a>
#### Approving the Request

When receiving authorization requests, Passport will automatically respond based on the value of `prompt` parameter (if present) and may display a template to the user allowing them to approve or deny the authorization request. If they approve the request, they will be redirected back to the `redirect_uri` that was specified by the consuming application. The `redirect_uri` must match the `redirect` URL that was specified when the client was created.

Sometimes you may wish to skip the authorization prompt, such as when authorizing a first-party client. You may accomplish this by [extending the `Client` model](#overriding-default-models) and defining a `skipsAuthorization` method. If `skipsAuthorization` returns `true`, Passport approves the client after any required login or account selection, unless the consuming application requests `prompt=consent`. Dynamically registered clients are never considered first-party clients. This trusted policy does not create or widen remembered user approval:

```php
<?php

namespace App\Models\Passport;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Passport\Client as BaseClient;
use Hypervel\Passport\Scope;

class Client extends BaseClient
{
    /**
     * Determine if the client should skip the authorization prompt.
     *
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
```

The `firstParty()` method returns `true` only for ownerless clients whose `third_party` flag is `false`. Passport and MCP dynamic registration set that flag to `true`, keeping externally registered clients subject to consent. When importing a Laravel Passport schema, add the boolean `third_party` column with a default of `false` and mark any imported externally registered clients as `true`.

<a name="requesting-tokens-converting-authorization-codes-to-access-tokens"></a>
#### Converting Authorization Codes to Access Tokens

If the user approves the authorization request, they will be redirected back to the consuming application. The consumer should first verify the `state` parameter against the value that was stored prior to the redirect. If the state parameter matches then the consumer should issue a `POST` request to your application to request an access token. The request should include the authorization code that was issued by your application when the user approved the authorization request:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Http;

Route::get('/callback', function (Request $request) {
    $state = $request->session()->pull('state');

    throw_unless(
        is_string($state) && $state !== '' && $state === $request->state,
        InvalidArgumentException::class,
        'Invalid state value.'
    );

    $response = Http::asForm()->post('https://passport-app.test/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => 'your-client-id',
        'client_secret' => 'your-client-secret',
        'redirect_uri' => 'https://third-party-app.com/callback',
        'code' => $request->code,
    ]);

    return $response->json();
});
```

This `/oauth/token` route will return a JSON response containing `access_token`, `refresh_token`, and `expires_in` attributes. The `expires_in` attribute contains the number of seconds until the access token expires.

> [!NOTE]
> Like the `/oauth/authorize` route, the `/oauth/token` route is defined for you by Passport. There is no need to manually define this route.

<a name="managing-tokens"></a>
### Managing Tokens

You may retrieve a user's authorized tokens using the `tokens` method of the `Hypervel\Passport\HasApiTokens` trait. For example, this may be used to offer your users a dashboard to keep track of their connections with third-party applications:

```php
use App\Models\User;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Passport\Token;

$user = User::find($userId);

// Retrieving all of the valid tokens for the user...
$tokens = $user->tokens()
    ->where('revoked', false)
    ->where('expires_at', '>', Date::now())
    ->get();

// Retrieving all the user's connections to third-party OAuth app clients...
$connections = $tokens->load('client')
    ->reject(fn (Token $token) => $token->client->firstParty())
    ->groupBy('client_id')
    ->map(fn (Collection $tokens) => [
        'client' => $tokens->first()->client,
        'scopes' => $tokens->pluck('scopes')->flatten()->unique()->values()->all(),
        'tokens_count' => $tokens->count(),
    ])
    ->values();
```

<a name="refreshing-tokens"></a>
### Refreshing Tokens

If your application issues short-lived access tokens, users will need to refresh their access tokens via the refresh token that was provided to them when the access token was issued:

```php
use Hypervel\Support\Facades\Http;

$response = Http::asForm()->post('https://passport-app.test/oauth/token', [
    'grant_type' => 'refresh_token',
    'refresh_token' => 'the-refresh-token',
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret', // Required for confidential clients only...
    'scope' => 'user:read orders:create',
]);

return $response->json();
```

This `/oauth/token` route will return a JSON response containing `access_token`, `refresh_token`, and `expires_in` attributes. The `expires_in` attribute contains the number of seconds until the access token expires.

You may request fewer scopes for an access token by including `scope` in the refresh request. The replacement refresh token retains its original scopes, so a later refresh can request other originally approved permissions. Each access token remains subject to the client's current scope restrictions.

Refresh tokens are single-use by default. Each successful refresh replaces the refresh token; store the replacement before making another refresh request. If a used refresh token is presented again, Passport revokes the refresh-token family and its access tokens. Do not send concurrent refresh requests with the same token.

You may set `Passport::$revokeRefreshTokenAfterUse = false` during provider boot for confidential clients or public clients using [DPoP](#proof-bound-tokens). With rotation disabled, public clients receive refresh tokens only when supplying a valid proof, and may exchange only refresh tokens already bound to their proof key. An existing unbound refresh token cannot be upgraded while rotation is disabled.

<a name="revoking-tokens"></a>
### Revoking Tokens

You may revoke a token by using the `revoke` method on the `Hypervel\Passport\Token` model. You may revoke a token's refresh token using the `revoke` method on the `Hypervel\Passport\RefreshToken` model:

```php
use Hypervel\Passport\Passport;
use Hypervel\Passport\Token;

$token = Passport::token()->find($tokenId);

// Revoke an access token...
$token->revoke();

// Revoke the token's refresh token...
$token->refreshToken?->revoke();

// Revoke all of the user's tokens...
User::find($userId)->tokens()->each(function (Token $token) {
    $token->revoke();
    $token->refreshToken?->revoke();
});
```

OAuth clients may revoke their own access or refresh credentials through `POST /oauth/revoke`, named `passport.token.revoke`. Send a form request and authenticate using the client's registered authentication method. Public clients send their `client_id` without a secret:

```php
use Hypervel\Support\Facades\Http;

Http::asForm()->withBasicAuth(urlencode($clientId), urlencode($clientSecret))
    ->post('https://passport-app.test/oauth/revoke', [
        'token' => $refreshToken,
    ])->throw();
```

The endpoint returns an empty successful response even if the credential is already revoked, expired, invalid or belongs to another client. Revoking an access token affects only that token. Revoking an unexpired refresh token revokes its entire refresh-token family and linked access tokens, including replacements issued by a concurrent refresh. An expired refresh credential cannot revoke its family. To revoke the whole remembered authorization, use [consent revocation](#remembering-consent).

Explicit revocation follows an application's database transaction, and cache invalidation and events wait for commit. Revocation uses the Passport rate limiter; confidential-client introspection does not. Both endpoints accept an optional `token_type_hint`; the credential's format determines its type regardless of that hint.

<a name="purging-tokens"></a>
### Purging Tokens

When tokens have been revoked or expired, you might want to purge them from the database. Passport's included `passport:purge` Artisan command can do this for you:

```shell
# Purge revoked and expired tokens, auth codes, and device codes...
php artisan passport:purge

# Only purge tokens expired for more than 6 hours...
php artisan passport:purge --expired --hours=6

# Only purge revoked tokens, auth codes, and device codes...
php artisan passport:purge --revoked

# Only purge expired tokens, auth codes, and device codes...
php artisan passport:purge --expired
```

Revoked refresh tokens remain until they expire so Passport can detect reuse. Empty refresh-token families are removed once none of their refresh tokens remain.

You may also configure a [scheduled job](/docs/{{version}}/scheduling) in your application's `routes/console.php` file to automatically prune your tokens on a schedule:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('passport:purge')->hourly();
```

<a name="code-grant-pkce"></a>
## Authorization Code Grant With PKCE

The Authorization Code grant with "Proof Key for Code Exchange" (PKCE) is a secure way to authenticate single page applications or mobile applications to access your API. This grant should be used when you can't guarantee that the client secret will be stored confidentially or in order to mitigate the threat of having the authorization code intercepted by an attacker. A combination of a "code verifier" and a "code challenge" replaces the client secret when exchanging the authorization code for an access token.

<a name="creating-a-auth-pkce-grant-client"></a>
### Creating the Client

Before your application can issue tokens via the authorization code grant with PKCE, you will need to create a PKCE-enabled client. You may do this using the `passport:client` Artisan command with the `--public` option:

```shell
php artisan passport:client --public
```

<a name="requesting-auth-pkce-grant-tokens"></a>
### Requesting Tokens

<a name="code-verifier-code-challenge"></a>
#### Code Verifier and Code Challenge

As this authorization grant does not provide a client secret, developers will need to generate a combination of a code verifier and a code challenge in order to request a token.

The code verifier should be a random string of between 43 and 128 characters containing letters, numbers, and `"-"`, `"."`, `"_"`, `"~"` characters, as defined in the [RFC 7636 specification](https://tools.ietf.org/html/rfc7636).

The code challenge should be a Base64 encoded string with URL and filename-safe characters. The trailing `'='` characters should be removed and no line breaks, whitespace, or other additional characters should be present.

Passport requires the `S256` challenge method. A `plain` challenge or a challenge without a method is rejected.

```php
$encoded = base64_encode(hash('sha256', $codeVerifier, true));

$codeChallenge = strtr(rtrim($encoded, '='), '+/', '-_');
```

<a name="code-grant-pkce-redirecting-for-authorization"></a>
#### Redirecting for Authorization

Once a client has been created, you may use the client ID and the generated code verifier and code challenge to request an authorization code and access token from your application. First, the consuming application should make a redirect request to your application's `/oauth/authorize` route:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Str;

Route::get('/redirect', function (Request $request) {
    $request->session()->put('state', $state = Str::random(40));

    $request->session()->put(
        'code_verifier', $codeVerifier = Str::random(128)
    );

    $codeChallenge = strtr(rtrim(
        base64_encode(hash('sha256', $codeVerifier, true))
    , '='), '+/', '-_');

    $query = http_build_query([
        'client_id' => 'your-client-id',
        'redirect_uri' => 'https://third-party-app.com/callback',
        'response_type' => 'code',
        'scope' => 'user:read orders:create',
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        // 'prompt' => '', // "none", "consent", "login", or "select_account"
    ]);

    return redirect('https://passport-app.test/oauth/authorize?'.$query);
});
```

<a name="code-grant-pkce-converting-authorization-codes-to-access-tokens"></a>
#### Converting Authorization Codes to Access Tokens

If the user approves the authorization request, they will be redirected back to the consuming application. The consumer should verify the `state` parameter against the value that was stored prior to the redirect, as in the standard Authorization Code Grant.

If the state parameter matches, the consumer should issue a `POST` request to your application to request an access token. The request should include the authorization code that was issued by your application when the user approved the authorization request along with the originally generated code verifier:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Http;

Route::get('/callback', function (Request $request) {
    $state = $request->session()->pull('state');

    $codeVerifier = $request->session()->pull('code_verifier');

    throw_unless(
        is_string($state) && $state !== '' && $state === $request->state,
        InvalidArgumentException::class
    );

    $response = Http::asForm()->post('https://passport-app.test/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => 'your-client-id',
        'redirect_uri' => 'https://third-party-app.com/callback',
        'code_verifier' => $codeVerifier,
        'code' => $request->code,
    ]);

    return $response->json();
});
```

<a name="device-authorization-grant"></a>
## Device Authorization Grant

The OAuth2 device authorization grant allows browserless or limited input devices, such as TVs and game consoles, to obtain an access token by exchanging a "device code". When using device flow, the device client will instruct the user to use a secondary device, such as a computer or a smartphone and connect to your server where they will enter the provided "user code" and either approve or deny the access request.

To get started, we need to instruct Passport how to return our "user code" and "authorization" views.

All the authorization view's rendering logic may be customized using the appropriate methods available via the `Hypervel\Passport\Passport` class. Typically, you should call this method from the `boot` method of your application's `App\Providers\AppServiceProvider` class.

```php
use Hypervel\Inertia\Inertia;
use Hypervel\Passport\Passport;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    // By providing a view name...
    Passport::deviceUserCodeView('auth.oauth.device.user-code');
    Passport::deviceAuthorizationView('auth.oauth.device.authorize');

    // By providing a closure...
    Passport::deviceUserCodeView(
        fn ($parameters) => Inertia::render('Auth/OAuth/Device/UserCode')
    );

    Passport::deviceAuthorizationView(
        fn ($parameters) => Inertia::render('Auth/OAuth/Device/Authorize', [
            'request' => $parameters['request'],
            'authToken' => $parameters['authToken'],
            'client' => $parameters['client'],
            'user' => $parameters['user'],
            'scopes' => $parameters['scopes'],
        ])
    );

    // ...
}
```

Passport will automatically define routes that return these views. Your `auth.oauth.device.user-code` template should include a form that makes a GET request to the `passport.device.authorizations.authorize` route. The `passport.device.authorizations.authorize` route expects a `user_code` query parameter.

Your `auth.oauth.device.authorize` template should include a form that makes a POST request to the `passport.device.authorizations.approve` route to approve the authorization and a form that makes a DELETE request to the `passport.device.authorizations.deny` route to deny the authorization. Submit the supplied `auth_token` and the application's CSRF token. Passport retains the device request and client information; forms may also submit the [selected scopes and remember choice](#remembering-consent).

<a name="creating-a-device-authorization-grant-client"></a>
### Creating a Device Authorization Grant Client

Before your application can issue tokens via the device authorization grant, you will need to create a device flow enabled client. You may do this using the `passport:client` Artisan command with the `--device` option. This command will create a first-party device flow enabled client and provide you with a client ID and secret:

```shell
php artisan passport:client --device
```

Additionally, you may use `createDeviceAuthorizationGrantClient` method on the `ClientRepository` class to register a third-party client that belongs to the given user:

```php
use App\Models\User;
use Hypervel\Passport\ClientRepository;

$user = User::find($userId);

$client = app(ClientRepository::class)->createDeviceAuthorizationGrantClient(
    user: $user,
    name: 'Example Device',
    confidential: false,
);
```

<a name="requesting-device-authorization-grant-tokens"></a>
### Requesting Tokens

<a name="device-code"></a>
#### Requesting a Device Code

Once a client has been created, the consuming device should make a `POST` request to your application's `/oauth/device/code` route. Confidential clients authenticate using their registered [client authentication method](#client-authentication), both when requesting a device code and when polling for tokens. For example, a client using form credentials may send:

```php
use Hypervel\Support\Facades\Http;

$response = Http::asForm()->post('https://passport-app.test/oauth/device/code', [
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret',
    'scope' => 'user:read orders:create',
]);

return $response->json();
```

This will return a JSON response containing `device_code`, `user_code`, `verification_uri`, `interval`, and `expires_in` attributes. The `expires_in` attribute contains the number of seconds until the device code expires. The `interval` attribute contains the number of seconds the consuming device should wait between requests when polling `/oauth/token` route to avoid rate limit errors.

> [!NOTE]
> Remember, the `/oauth/device/code` route is already defined by Passport. You do not need to manually define this route.

<a name="user-code"></a>
#### Displaying the Verification URI and User Code

Once a device code request has been obtained, the consuming device should instruct the user to use another device and visit the provided `verification_uri` and enter the `user_code` in order to approve the authorization request.

<a name="polling-token-request"></a>
#### Polling Token Request

Since the user will be using a separate device to grant (or deny) access, the consuming device should poll your application's `/oauth/token` route to determine when the user has responded to the request. The consuming device should use the minimum polling `interval` provided in the JSON response when requesting device code to avoid rate limit errors. In this example, `$deviceCode` is the decoded JSON response from the device code request:

```php
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;

$interval = $deviceCode['interval'];

do {
    Sleep::for($interval)->seconds();

    $response = Http::asForm()->post('https://passport-app.test/oauth/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'client_id' => 'your-client-id',
        'client_secret' => 'your-client-secret', // Required for confidential clients only...
        'device_code' => $deviceCode['device_code'],
    ]);

    if ($response->json('error') === 'slow_down') {
        $interval += 5;
    }
} while (in_array($response->json('error'), ['authorization_pending', 'slow_down'], true));

return $response->json();
```

If the user has approved the authorization request, this will return a JSON response containing `access_token`, `refresh_token`, and `expires_in` attributes. The `expires_in` attribute contains the number of seconds until the access token expires.

<a name="client-credentials-grant"></a>
## Client Credentials Grant

The client credentials grant is suitable for machine-to-machine authentication. For example, you might use this grant in a scheduled job which is performing maintenance tasks over an API.

Before your application can issue tokens via the client credentials grant, you will need to create a client credentials grant client. You may do this using the `--client` option of the `passport:client` Artisan command:

```shell
php artisan passport:client --client
```

Next, assign the `Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner` middleware to a route:

```php
use Hypervel\Http\Request;
use Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner;

Route::get('/orders', function (Request $request) {
    // Access token is valid and the client is resource owner...
})->middleware(EnsureClientIsResourceOwner::class);
```

To restrict access to the route to specific scopes, you may provide a list of the required scopes to the `using` method:

```php
Route::get('/orders', function (Request $request) {
    // Access token is valid, the client is resource owner, and has both "servers:read" and "servers:create" scopes...
})->middleware(EnsureClientIsResourceOwner::using('servers:read', 'servers:create'));
```

Client-credentials tokens use the client identifier as their subject and do not authenticate an application user.

<a name="retrieving-tokens"></a>
### Retrieving Tokens

To retrieve a token using this grant type, make a request to the `oauth/token` endpoint:

```php
use Hypervel\Support\Facades\Http;

$response = Http::asForm()->post('https://passport-app.test/oauth/token', [
    'grant_type' => 'client_credentials',
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret',
    'scope' => 'servers:read servers:create',
]);

return $response->json()['access_token'];
```

To authorize a client-credentials token for every scope, request `scope` as `*`. This scope is supported for client credentials and personal access tokens.

<a name="personal-access-tokens"></a>
## Personal Access Tokens

Sometimes, your users may want to issue access tokens to themselves without going through the typical authorization code redirect flow. Allowing users to issue tokens to themselves via your application's UI can be useful for allowing users to experiment with your API or may serve as a simpler approach to issuing access tokens in general.

> [!NOTE]
> If your application is using Passport primarily to issue personal access tokens, consider using [Sanctum](/docs/{{version}}/sanctum), Hypervel's lightweight first-party library for issuing API access tokens.

<a name="creating-a-personal-access-client"></a>
### Creating a Personal Access Client

Before your application can issue personal access tokens, you will need to create a personal access client. You may do this by executing the `passport:client` Artisan command with the `--personal` option. If you have already run the `passport:install` command, you do not need to run this command:

```shell
php artisan passport:client --personal
```

<a name="customizing-the-user-provider-for-pat"></a>
### Customizing the User Provider

If your application uses more than one [authentication user provider](/docs/{{version}}/authentication#introduction), you may specify which user provider the personal access grant client uses by providing a `--provider` option when creating the client via the `artisan passport:client --personal` command. The given provider name should match a valid provider defined in your application's `config/auth.php` configuration file. Personal access clients without an explicit provider use the `passport` guard's provider. You can then [protect your route using middleware](#multiple-authentication-guards) to ensure that only users from the guard's specified provider are authorized.

The `HasApiTokens` trait uses the Eloquent provider that matches the model and is used by a Passport guard. If several matching providers are used by Passport guards, override the model's `getProviderName(): string` method to select the intended provider. Otherwise, creating or listing tokens and authorizations throws an exception.

<a name="managing-personal-access-tokens"></a>
### Managing Personal Access Tokens

Once you have created a personal access client, you may issue tokens for a given user using the `createToken` method on the `App\Models\User` model instance. The `createToken` method accepts the name of the token as its first argument and an optional array of [scopes](#token-scopes) as its second argument:

```php
use App\Models\User;
use Hypervel\Support\Facades\Date;
use Hypervel\Passport\Token;

$user = User::find($userId);

// Creating a token without scopes...
$token = $user->createToken('My Token')->accessToken;

// Creating a token with scopes...
$token = $user->createToken('My Token', ['user:read', 'orders:create'])->accessToken;

// Creating a token with all scopes...
$token = $user->createToken('My Token', ['*'])->accessToken;

// Retrieving all the valid personal access tokens that belong to the user...
$tokens = $user->tokens()
    ->with('client')
    ->where('revoked', false)
    ->where('expires_at', '>', Date::now())
    ->get()
    ->filter(fn (Token $token) => $token->client->hasGrantType('personal_access'));
```

<a name="protecting-routes"></a>
## Protecting Routes

<a name="via-middleware"></a>
### Via Middleware

Passport includes an [authentication guard](/docs/{{version}}/authentication#adding-custom-guards) that validates access tokens on incoming requests. Use the shipped `passport` guard by assigning `auth:passport` to routes that require an authenticated user:

```php
Route::get('/user', function () {
    // Only API authenticated users may access this route...
})->middleware('auth:passport');
```

> [!WARNING]
> If you are using the [client credentials grant](#client-credentials-grant), use the `Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner` middleware to protect your routes. These tokens do not represent an application user.

<a name="multiple-authentication-guards"></a>
#### Multiple Authentication Guards

If your application authenticates different types of users that perhaps use entirely different Eloquent models, you will likely need to define a guard configuration for each user provider type in your application. This allows you to protect requests intended for specific user providers. For example, given the following guard configuration the `config/auth.php` configuration file:

```php
'guards' => [
    'passport' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],

    'passport-customers' => [
        'driver' => 'passport',
        'provider' => 'customers',
    ],
],
```

The following route will utilize the `passport-customers` guard, which uses the `customers` user provider, to authenticate incoming requests:

```php
Route::get('/customer', function () {
    // ...
})->middleware('auth:passport-customers');
```

Tokens record the provider of the user who authorized them. A token issued for `users` cannot authenticate a `customers` user, even if both users have the same ID. Personal access tokens record their model's provider, and first-party API cookies record the session guard's provider.

When a client's `provider` is set, only a user authenticated through that provider may authorize it. A null client provider allows authorization through any provider; the resulting token still belongs to the provider that approved it.

The `passport.guard` setting selects the session guard used for authorization pages. Set it to null to follow the request's current default guard, including `Auth::shouldUse()`.

<a name="passing-the-access-token"></a>
### Passing the Access Token

When calling routes that are protected by Passport, your application's API consumers should specify their access token as a `Bearer` token in the `Authorization` header of their request. For example, when using the `Http` Facade:

```php
use Hypervel\Support\Facades\Http;

$response = Http::withHeaders([
    'Accept' => 'application/json',
    'Authorization' => "Bearer $accessToken",
])->get('https://passport-app.test/api/user');

return $response->json();
```

<a name="token-scopes"></a>
## Token Scopes

Scopes allow your API clients to request a specific set of permissions when requesting authorization to access an account. For example, if you are building an e-commerce application, not all API consumers will need the ability to place orders. Instead, you may allow the consumers to only request authorization to access order shipment statuses. In other words, scopes allow your application's users to limit the actions a third-party application can perform on their behalf.

<a name="defining-scopes"></a>
### Defining Scopes

You may define your API's scopes using the `Passport::tokensCan` method in the `boot` method of your application's `App\Providers\AppServiceProvider` class. The `tokensCan` method accepts an array of scope names and scope descriptions. The scope description may be anything you wish and will be displayed to users on the authorization approval screen:

```php
/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Passport::tokensCan([
        'user:read' => 'Retrieve the user info',
        'orders:create' => 'Place orders',
        'orders:read:status' => 'Check order status',
    ]);
}
```

<a name="default-scope"></a>
### Default Scope

If a client does not request any specific scopes, you may configure your Passport server to attach default scopes to the token using the `defaultScopes` method. Typically, you should call this method from the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use Hypervel\Passport\Passport;

Passport::tokensCan([
    'user:read' => 'Retrieve the user info',
    'orders:create' => 'Place orders',
    'orders:read:status' => 'Check order status',
]);

Passport::defaultScopes([
    'user:read',
    'orders:create',
]);
```

<a name="assigning-scopes-to-tokens"></a>
### Assigning Scopes to Tokens

<a name="when-requesting-authorization-codes"></a>
#### When Requesting Authorization Codes

When requesting an access token using the authorization code grant, consumers should specify their desired scopes as the `scope` query string parameter. The `scope` parameter should be a space-delimited list of scopes:

```php
Route::get('/redirect', function () {
    $query = http_build_query([
        'client_id' => 'your-client-id',
        'redirect_uri' => 'https://third-party-app.com/callback',
        'response_type' => 'code',
        'scope' => 'user:read orders:create',
    ]);

    return redirect('https://passport-app.test/oauth/authorize?'.$query);
});
```

<a name="when-issuing-personal-access-tokens"></a>
#### When Issuing Personal Access Tokens

If you are issuing personal access tokens using the `App\Models\User` model's `createToken` method, you may pass the array of desired scopes as the second argument to the method:

```php
$token = $user->createToken('My Token', ['orders:create'])->accessToken;
```

<a name="checking-scopes"></a>
### Checking Scopes

Passport includes two middleware that may be used to verify that an incoming request is authenticated with a token that has been granted a given scope.

<a name="check-for-all-scopes"></a>
#### Check For All Scopes

The `Hypervel\Passport\Http\Middleware\CheckToken` middleware may be assigned to a route to verify that the incoming request's access token has all the listed scopes:

```php
use Hypervel\Passport\Http\Middleware\CheckToken;

Route::get('/orders', function () {
    // Access token has both "orders:read" and "orders:create" scopes...
})->middleware(['auth:passport', CheckToken::using('orders:read', 'orders:create')]);
```

<a name="check-for-any-scopes"></a>
#### Check for Any Scopes

The `Hypervel\Passport\Http\Middleware\CheckTokenForAnyScope` middleware may be assigned to a route to verify that the incoming request's access token has *at least one* of the listed scopes:

```php
use Hypervel\Passport\Http\Middleware\CheckTokenForAnyScope;

Route::get('/orders', function () {
    // Access token has either "orders:read" or "orders:create" scope...
})->middleware(['auth:passport', CheckTokenForAnyScope::using('orders:read', 'orders:create')]);
```

<a name="scope-attributes"></a>
#### Scope Attributes

If your application uses [controller middleware attributes](/docs/{{version}}/controllers#middleware-attributes), you may use the `Hypervel\Passport\Attributes\AuthorizeToken` attribute as a convenient shortcut for Passport's scope middleware:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Passport\Attributes\AuthorizeToken;

#[AuthorizeToken('orders:read')]
#[AuthorizeToken('orders:create', only: ['store'])]
class OrderController
{
    #[AuthorizeToken(['orders:read', 'orders:create'], anyScope: true)]
    public function index()
    {
        // Access token has either "orders:read" or "orders:create" scope...
    }

    public function store()
    {
        // Access token has both "orders:read" and "orders:create" scopes...
    }
}
```

By default, the `AuthorizeToken` attribute requires all given scopes. If you pass `anyScope: true`, the request is authorized when the token has at least one of the given scopes.

<a name="checking-scopes-on-a-token-instance"></a>
#### Checking Scopes on a Token Instance

Once an access token authenticated request has entered your application, you may still check if the token has a given scope using the `tokenCan` method on the authenticated `App\Models\User` instance:

```php
use Hypervel\Http\Request;

Route::get('/orders', function (Request $request) {
    if ($request->user()->tokenCan('orders:create')) {
        // ...
    }
});
```

<a name="additional-scope-methods"></a>
#### Additional Scope Methods

The `scopeIds` method will return an array of all defined IDs / names:

```php
use Hypervel\Passport\Passport;

Passport::scopeIds();
```

The `scopes` method will return a collection of all defined scopes as instances of `Hypervel\Passport\Scope`:

```php
Passport::scopes();
```

The `scopesFor` method will return an array of `Hypervel\Passport\Scope` instances matching the given IDs / names:

```php
Passport::scopesFor(['user:read', 'orders:create']);
```

You may determine if a given scope has been defined using the `hasScope` method:

```php
Passport::hasScope('orders:create');
```

<a name="resource-indicators"></a>
## Resource Indicators

Scopes describe what a token may do. Resource indicators describe where it may be used. If your authorization server issues tokens for several APIs, define their identifiers and descriptions in your service provider's `boot` method:

```php
use Hypervel\Passport\Passport;

Passport::tokensCanAccess([
    'https://api.example.com/calendar' => 'Calendar API',
    'https://api.example.com/contacts' => 'Contacts API',
]);
```

Each identifier must be an absolute URI without a fragment. Passport compares identifiers exactly; it does not fetch them or match URL prefixes. Independently configured authorization servers may provide a `resources` catalog through `ServerSettings`. These entries extend or replace matching entries in the global catalog. Leave the global catalog empty when each server needs its own set of resources.

Clients include `resource` in authorization requests, pushed authorization requests, or initial device requests. Repeat the parameter to request more than one API, using `resource=...&resource=...` rather than PHP's bracket notation. Unknown resources return `invalid_target`.

The consent view receives `resources`, a list of `ProtectedResource` objects with `id` and `description` properties, alongside `scopes` and `permissions`. Resource permissions are paired with their scopes:

```php
[
    'resources' => [
        'https://api.example.com/calendar' => ['read', 'write'],
        'https://api.example.com/contacts' => ['read'],
    ],
]
```

Custom consent forms may submit a subset through `permissions[resources]`, using each resource URI as a key and its selected scopes as the value. Selections must also be within the submitted `scopes` selection. Omitting `permissions` approves the presented permissions. An explicitly empty resource selection denies authorization; a resource with an explicitly empty scope list grants access to that resource without scope permissions. Remembered consent retains the resource and scope pairs, so permission to write to one API never grants permission to write to another.

At code exchange or refresh, clients may select one or more originally approved resources using `resource`. If omitted, Passport selects all approved resources. A token intended for several resources contains only scopes approved for every selected resource. The refresh token retains the original authorization, allowing later refresh requests to select another approved API. An authorization made without resources cannot acquire a resource during exchange or refresh.

Each refresh revokes the access token associated with the refresh token being used, including when selecting another API. To keep separate tokens for APIs that do not trust one another, obtain a separate authorization for each API. Remembered consent can avoid repeated consent prompts.

Client-credentials requests may also supply `resource`. Their tokens use the requested audiences and the client's permitted scopes, without user consent.

<a name="checking-the-audience"></a>
### Checking the Audience

Resource-restricted access tokens contain their resource identifiers in `aud`. The `client_id` claim always identifies the client, independently of the audience. Tokens issued without resources retain the client ID as their audience. Both claims are protected from custom claim callbacks.

Use `CheckTokenAudience` on routes that require a resource-specific token, together with your normal authentication and scope middleware:

```php
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\CheckTokenAudience;

Route::get('/calendar', [CalendarController::class, 'index'])->middleware([
    'auth:passport',
    CheckTokenAudience::using('https://api.example.com/calendar'),
    CheckToken::using('read'),
]);
```

You may pass several identifiers to `using` when an API accepts more than one audience. The token must match at least one. To require several audiences, add a separate middleware entry for each identifier.

The `using` method preserves commas and percent signs in resource identifiers. If you write a middleware string manually, escape each identifier's `%` as `%25` and `,` as `%2C` so it remains one argument.

The audience check uses the validated token and performs no additional database lookup. Browser cookie credentials cannot satisfy it. External resource servers must likewise validate the token's signature, lifetime and expected audience. [Introspection](#token-introspection) reports `aud` and `client_id` separately.

For resource-restricted dynamic client registration, register the canonical registration endpoint URL with `tokensCanAccess` and include it in the provisioning token's audience, alongside the explicit `passport:register` scope.

Routes without an audience check accept otherwise valid tokens issued for other resources. To restrict your own API to tokens intended for it, register it as a resource and apply `CheckTokenAudience` alongside your authentication and scope checks.

<a name="proof-bound-tokens"></a>
## Proof-Bound Tokens (DPoP)

Demonstrating Proof of Possession (DPoP) binds a token to a client's key. Possessing the token alone is then insufficient to use it: the client must also sign a proof for each request. Enable DPoP in `config/passport.php` or your environment:

```ini
PASSPORT_DPOP_ENABLED=true
PASSPORT_REPLAY_STORE=redis
```

Passport supports `RS256` and `ES256` proofs and publishes these algorithms in its server metadata. RSA keys must be between 2048 and 4096 bits, with public exponents no larger than 32 bits; the usual exponent of 65537 is supported. ES256 uses P-256. Use a client library that implements DPoP to create these proofs. The proof's public key is included in its JWT header; Passport does not fetch proof keys from a URL.

At the token endpoint, send the signed proof in the `DPoP` header alongside the normal grant and client authentication parameters. The response contains `token_type: DPoP`, and the access token's protected `cnf.jkt` claim identifies the proof key. Set a client's `dpop_bound_access_tokens` attribute to true to require proofs for its token requests. This attribute is also accepted during dynamic registration.

For API requests, send both headers:

```http
Authorization: DPoP <access-token>
DPoP: <signed-proof>
```

Each proof includes a unique `jti`, its issue time in `iat`, the HTTP method in `htm`, and the external request URL in `htu`, without its query or fragment. Resource-request proofs also include `ath`, the base64url-encoded SHA-256 hash of the access token. Passport checks the proof signature, key binding, method, URL, time and replay history. Trailing slashes remain significant. Generate a fresh proof for every request, including retries.

Proofs have a one-minute lifetime with a 60-second clock allowance. A proof must be less than two minutes old and cannot be more than 60 seconds ahead of the server. Replay entries remain until the full acceptance deadline; a proof initially accepted from a fast client clock can remain valid for up to three minutes. This profile does not issue server nonces.

Proof-bound access tokens expire after at most five minutes by default, or sooner when the grant's normal lifetime is shorter. Configure the cap during service-provider boot:

```php
use DateInterval;
use Hypervel\Passport\Passport;

Passport::dpopTokensExpireIn(new DateInterval('PT2M'));
```

An individual `ServerSettings` may supply `dpopTokensExpireIn`; null uses the global cap. Ordinary bearer-token lifetimes are unchanged. Short access-token lifetimes also limit the usefulness of proofs generated in advance.

Authorization requests may include `dpop_jkt` to bind the resulting code to a key. Pushed authorization requests may supply this parameter, a proof header, or both; when both are present, they must identify the same key. Code exchange must then prove possession of that key. Initial device authorization does not bind the device code or check a proof; the proof supplied while exchanging the approved device code binds the issued tokens.

Public-client refresh tokens issued with a proof retain that key binding through later refreshes. Confidential-client refresh tokens rely on client authentication unless the presented refresh token already has a proof binding. Adding a proof to a replacement does not change an older unbound token or disable refresh-token reuse detection.

DPoP uses the same token and client caches as bearer authentication, with additional signature verification and, by default, one shared replay-store operation per proof. Repeated authentication checks within one request reuse the verified proof. Ordinary bearer requests perform neither operation. Follow the [replay-store requirements](#jwt-client-assertions), including database pruning and Redis's `noeviction` policy. For high-volume APIs, use Redis rather than a database replay store.

Replay protection defaults to enabled. You may disable only DPoP proof replay tracking with `passport.dpop.replay_protection` or `PASSPORT_DPOP_REPLAY_PROTECTION=false`. Pass `dpopReplayProtection` to `ServerSettings` to override that setting for an individual server; null inherits the configuration. Disabling it removes the replay-store operation and storage cost, but still verifies the signature and all other proof checks. Client-assertion replay protection and refresh-token reuse detection remain enabled.

> [!WARNING]
> Without proof replay tracking, anyone holding a captured proof and its matching credential may repeat requests to the same method and URL until the proof or credential expires. The request body and query may differ because the proof does not cover them. Keep replay tracking enabled unless your application accepts this tradeoff. Independently deployed resource servers choose their own replay policy; tokens and discovery metadata do not transmit it.

Bearer and DPoP schemes must match their tokens. Failed Bearer or DPoP credentials never fall back to an API cookie. Other authorization schemes, such as Basic authentication supplied by a reverse proxy, do not prevent cookie authentication. When DPoP support is disabled, proof-bound tokens and grants cannot be used without their proof: token and PAR proofs are rejected, `dpop_jkt` is rejected, and API requests using the DPoP scheme fail authentication. Introspection still reports the token's binding. Bearer-scheme API requests, initial device authorization, revocation and introspection ignore an unrelated `DPoP` header.

Configure [trusted hosts](/docs/{{version}}/requests#configuring-trusted-hosts) and [trusted proxies](/docs/{{version}}/requests#configuring-trusted-proxies) so Passport sees the same external URL the client signs. Browser clients need `DPoP` in their allowed CORS request headers and `WWW-Authenticate` in their exposed response headers. Passport's authentication challenges advertise Bearer and, when enabled, DPoP; application error bodies and login redirects are preserved.

<a name="spa-authentication"></a>
## SPA Authentication

When building an API, it can be extremely useful to be able to consume your own API from your JavaScript application. This approach to API development allows your own application to consume the same API that you are sharing with the world. The same API may be consumed by your web application, mobile applications, third-party applications, and any SDKs that you may publish on various package managers.

Typically, if you want to consume your API from your JavaScript application, you would need to manually send an access token to the application and pass it with each request to your application. However, Passport includes a middleware that can handle this for you. All you need to do is append the `CreateFreshApiToken` middleware to the `web` middleware group in your application's `bootstrap/app.php` file:

```php
use Hypervel\Passport\Http\Middleware\CreateFreshApiToken;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        CreateFreshApiToken::class,
    ]);
})
```

> [!WARNING]
> You should ensure that the `CreateFreshApiToken` middleware is the last middleware listed in your middleware stack.

This middleware will attach a `hypervel_token` cookie to your outgoing responses. This cookie contains an encrypted JWT that Passport will use to authenticate API requests from your JavaScript application. The JWT has a lifetime equal to your `session.lifetime` configuration value. The cookie follows the session cookie's resolved path, domain, secure, SameSite and partitioned settings. Now, since the browser will automatically send the cookie with all subsequent requests, you may make requests to your application's API without explicitly passing an access token:

```js
axios.get('/api/user')
    .then(response => {
        console.log(response.data);
    });
```

<a name="customizing-the-cookie-name"></a>
#### Customizing the Cookie Name

If needed, you can customize the `hypervel_token` cookie's name using the `Passport::cookie` method. Typically, this method should be called from the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Passport::cookie('custom_name');
}
```

<a name="csrf-protection"></a>
#### CSRF Protection

When using this method of authentication, include a valid CSRF token header in your requests. [Axios](https://github.com/axios/axios) uses the encrypted `XSRF-TOKEN` cookie value to send an `X-XSRF-TOKEN` header on same-origin requests.

> [!NOTE]
> If you choose to send the `X-CSRF-TOKEN` header instead of `X-XSRF-TOKEN`, you will need to use the unencrypted token provided by `csrf_token()`.

<a name="events"></a>
## Events

Passport raises events when issuing and revoking tokens. You may [listen for these events](/docs/{{version}}/events) to prune or revoke other access tokens in your database. Events dispatch after the database transaction commits; a rolled-back issuance or revocation dispatches none:

<div class="overflow-auto">

| Event Name                                    |
| --------------------------------------------- |
| `Hypervel\Passport\Events\AccessTokenCreated`  |
| `Hypervel\Passport\Events\AccessTokenRevoked`  |
| `Hypervel\Passport\Events\RefreshTokenCreated` |

</div>

Because these events dispatch after the issuance commits, listeners cannot stop it. If a listener throws, Passport reports the exception and still returns the issued tokens.

Bulk updates through `tokens()` relationships or query builders do not dispatch `AccessTokenRevoked`. Revoke tokens through their model's `revoke()` method when you rely on this event.

<a name="multiple-authorization-servers"></a>
## Multiple Authorization Servers

Applications that serve several independently configured authorization servers may bind an implementation of `Hypervel\Passport\Contracts\ServerSettingsResolver`:

```php
use App\OAuth\OrganizationServerSettings;
use Hypervel\Passport\Contracts\ServerSettingsResolver;

public function register(): void
{
    $this->app->singleton(ServerSettingsResolver::class, OrganizationServerSettings::class);
}
```

The resolver has two methods:

- `current(): ?string` returns the current settings identifier, or null to use the default server. It runs on every OAuth endpoint request and every bearer-token authentication, and should read already-resolved request context without querying the database. Include both the authorization server's identity and its settings version in the identifier, and change it whenever its keys, issuer, scopes, resources, lifetimes or DPoP replay policy change.
- `resolve(string $identifier): ServerSettings` loads the settings for that identifier. Passport caches the resulting servers and parsed keys for reuse by the worker.

For example, your resolver may return the following settings:

```php
use DateInterval;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\ServerSettings;

return new ServerSettings(
    keys: [new ServerKey(publicKey: $publicKey, privateKey: $privateKey)],
    tokensExpireIn: new DateInterval('PT15M'),
    refreshTokensExpireIn: new DateInterval('P30D'),
    scopes: ['projects:read' => 'Read projects'],
    issuer: 'https://organization.example.com',
);
```

`ServerKey` accepts PEM contents or file paths. Omit its private key for verification-only use. An optional `activatesAt` selects when a private key may start signing; publish future public keys before that date. The newest active private key signs tokens, and every supplied public key may verify them.

`ServerSettings` also accepts `personalAccessTokensExpireIn` and `clientCredentialsTokensExpireIn`. A null lifetime uses the corresponding global Passport lifetime. The `dpopTokensExpireIn` and `dpopReplayProtection` options configure [proof-bound tokens](#proof-bound-tokens), inheriting the global settings when null. Its scope definitions extend the global catalog configured by `Passport::tokensCan`, and its `resources` definitions extend [`Passport::tokensCanAccess`](#resource-indicators). A null issuer omits `iss` and disables discovery; it does not fall back to `passport.issuer`. A configured issuer follows the [server metadata](#server-metadata-and-public-keys) URL requirements, and `metadataCacheLifetime` controls discovery and JWKS HTTP caching.

When using database-managed keys, return the keys from `SigningKeyRepository::serverKeys()` instead of rebuilding plain `ServerKey` objects from database rows. This preserves the information Passport needs to retain each signing key for its tokens' lifetimes. Your configured signing-key model and repository select the appropriate key set; Passport does not supply an organization schema or resolve organizations itself.

To manage separate database key sets, add an owner column and scope to your signing-key model and bind a `SigningKeyRepository` subclass. Override `keySetName()` to name the set's lock, which serializes key changes, and `rotateDueKeys()` to rotate each eligible set. Run key management operations in the appropriate set's context.

Resolve the authorization server before OAuth processing, for example from a trusted host or path. A token cannot select its own server when that selection determines which keys are trusted. Applications using separate keys therefore need server-specific OAuth endpoints, including the token endpoint. Applications sharing one server and key pair can use a shared token endpoint and carry ownership attributes from authorization through refreshes.

Each worker retains up to `passport.server_cache_size` settings entries, which defaults to 1000. Increasing this limit uses more worker memory; reducing it causes more settings loads and key parsing after entries are evicted. The default server is retained separately.

When settings change, make the new identifier available to every application instance. A newly published key should activate only after every instance can load it. If your application caches the identifier, choose an activation delay longer than that cache lifetime. Emergency key removal also takes effect as instances receive the new identifier.

<a name="customizing-authentication"></a>
## Customizing Authentication

Passport provides callbacks for applications that need additional ownership or access checks. Register them during provider boot, and read request-specific values from request context when they run. They do not replace your application's authorization policies.

`authenticateAccessTokensUsing` receives the stored token and its normal validity result. Returning false rejects it on guard, token-middleware and introspection paths. Returning true cannot make a revoked token valid. The callback runs even when the token was loaded from the cache. Explicit revocation does not use this callback, so a temporary access restriction cannot prevent a client from revoking its own credential:

```php
use App\Services\CurrentOrganization;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Token;

/**
 * Bootstrap any application services.
 */
public function boot(CurrentOrganization $organizations): void
{
    Passport::authenticateAccessTokensUsing(
        fn (Token $token, bool $isValid): bool => $isValid
            && $token->organization_id === $organizations->currentId()
    );
}
```

The `CurrentOrganization` application service reads the current request's organization. Register an additional client check with `Passport::authenticateClientsUsing(fn (Client $client): bool => ...)`. It runs at OAuth endpoints and during bearer authentication, and when choosing a personal access client. Cached clients still pass through this check.

Your configured token model may override the static `findToken(string $id): ?static` method to change how bearer authentication loads tokens. For example, a model may omit an application scope during the lookup, then enforce ownership through `authenticateAccessTokensUsing`.

Use `Passport::bindCookiesUsing` to bind first-party API cookies to a request-derived value when several application partitions share a cookie domain:

```php
use Hypervel\Http\Request;

Passport::bindCookiesUsing(
    fn (Request $request): ?string => $request->attributes->get('organization_id')
);
```

The cookie's binding must match the value returned for the incoming request. A null value matches null. This is separate from the cookie's provider binding and CSRF validation.

The Passport guard's `token()` method returns the validated bearer token, including for client-credentials requests where no user exists. It returns null for cookie authentication. You may read stored attributes, such as `$token->organization_id`, directly from the returned token without another database lookup.

<a name="building-on-passport"></a>
## Building on Passport

<a name="authorization-attributes"></a>
### Authorization Attributes

`AuthorizationRequestValidated` runs before browser login and also when a client submits a pushed authorization request. Its `parameters` contain the authoritative authorization input, excluding PAR authentication secrets. PAR has no browser session or user; use `$event->request->routeIs('passport.par')` when a listener needs to distinguish it. Additional prompts are applied only during browser authorization. Validation runs again when an interaction resumes, so listeners should support repeated calls.

Listen for `AuthorizationUserResolved` to capture data when a user authorizes a client. Its attributes survive the consent screen and are saved with the authorization code or approved device code. Values must be JSON-compatible. Add the corresponding columns to your configured models' tables:

```php
use Hypervel\Passport\Events\AuthorizationUserResolved;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Event;

Event::listen(AuthorizationUserResolved::class, function (AuthorizationUserResolved $event): void {
    $event->attributes['organization_id'] = $event->request->attributes->get('organization_id');
});

Passport::inheritGrantAttributes(['organization_id']);
```

`inheritGrantAttributes` copies the named values from the redeemed code or refresh token into the new access and refresh tokens, and into newly created refresh-token families. Add these columns to all affected tables. This allows a shared token endpoint to preserve the ownership chosen at authorization. Passport always carries its own provider and consent information; listeners cannot replace these protected fields.

The event also exposes the approving user, provider, guard, client, scopes and authoritative protocol `parameters`. Use `parameters` when interpreting authorization input, since resumed requests contain only the interaction identifier. Add application permission sets through `$event->permissions['your.namespace']`, using lists of strings, or set `$event->rememberConsentFor` to a `DateInterval` or `null` for this approval. The `resources` permission namespace is reserved.

Reopening an interaction keeps the permissions and remember duration first presented and captures attributes again. A protocol rejection ends the interaction, so an older consent form can no longer approve it. Switching accounts discards the previous user's captured presentation.

Listen for `AuthorizationApproved` when an extension needs the final selected scopes and permissions. Its `approval` is `AuthorizationApproval::Prompt`, `Remembered`, or `Policy`, according to the actual decision. It runs inside the credential transaction before issuance, and may add saved attributes or reject the request. Keep these listeners synchronous and limit them to local checks and writes on the credential connection; use after-commit or queued work for side effects. A failure rolls back consent and credentials together.

Personal access and client-credentials tokens have no earlier authorization to inherit from. Use your configured models' creation hooks to assign their application-specific attributes.

<a name="additional-access-token-claims"></a>
### Additional Access Token Claims

Use `accessTokenClaimsUsing` to add claims for APIs that validate access tokens independently. The callback receives the saved token model, including inherited authorization attributes:

```php
use Hypervel\Passport\Token;

Passport::accessTokenClaimsUsing(fn (Token $token): array => [
    'organization_id' => $token->organization_id,
]);
```

The claims `iss`, `sub`, `aud`, `exp`, `iat`, `nbf`, `jti`, `scopes`, `client_id` and `cnf` are reserved. Attempting to replace one throws an `InvalidArgumentException`. JWT claims are readable by their recipient, so include only information appropriate for that client.

<a name="authorization-interaction-and-refresh-policy"></a>
### Authorization Interaction and Refresh Policy

Listen for `AuthorizationRequestValidated` to apply additional interaction requirements after the client and callback have been validated:

```php
use Hypervel\Passport\Events\AuthorizationRequestValidated;
use Hypervel\Support\Facades\Event;

Event::listen(AuthorizationRequestValidated::class, function (AuthorizationRequestValidated $event): void {
    if ($event->request->attributes->get('requires_fresh_authentication', false)) {
        $event->prompts[] = 'login';
    }
});
```

In this example, application middleware sets the request attribute according to its authentication policy. Listeners may add `login`, `consent` or `select_account` prompts. With `prompt=none`, a required interaction produces the corresponding protocol error. Authorization policy listeners may throw a League `OAuthServerException`; Passport renders it using the validated callback, original state and response mode. These listeners must run synchronously, not through a queue.

`Passport::issueRefreshTokensUsing` receives the newly saved access token and returns whether a refresh token may be issued:

```php
use Hypervel\Passport\Token;

Passport::issueRefreshTokensUsing(
    fn (Token $accessToken): bool => $accessToken->can('offline_access')
);
```

For authorization-code and device exchanges, false omits the refresh token. During a refresh exchange, false rejects the exchange with `invalid_grant` and rolls back the new tokens, leaving the existing credentials unchanged. Applications may use inherited authorization attributes to enforce an absolute renewal limit.

<a name="token-responses-issuers-and-public-keys"></a>
### Token Responses, Issuers and Public Keys

Set `passport.issuer` or `PASSPORT_ISSUER` to identify the default server in access tokens and authorization responses and enable [server discovery](#server-metadata-and-public-keys). Custom server settings use their own issuer; a null issuer leaves it unset without falling back to the configuration value.

You may register a custom response type with `Passport::useAuthorizationServerResponseType`. Extend `Hypervel\Passport\Bridge\BearerTokenResponse` and override `getExtraParams()` to add fields, merging `parent::getExtraParams()` to retain the final granted `scope`. Its protected `getToken()`, `getGrant()` and `getSigningKey()` methods expose the current issuance's saved token, redeemed credential and pinned signing key without additional queries. The grant is null for issuance without an earlier credential, such as client credentials.

Passport's access token entity also exposes its saved model through `getToken()`. Repeated calls to `toString()` return the same signed token, allowing your response to include a hash of the exact access token sent to the client. If response generation or signing fails, Passport rolls back the new tokens. Other League response types remain supported and are responsible for their own response fields.

`ServerFactory::jwks()` returns the public JSON Web Key Set for the selected server. `ServerFactory::signingKey(DateInterval $lifetime)` returns the current signing key and ensures it will be retained for the additional token's lifetime. Both methods use the settings and key selected when issuance began, even if another key activates before the response completes.

These extension points support an OpenID Connect layer without replacing Passport's grant implementation. Passport publishes OAuth server metadata and public keys; an OpenID Connect layer supplies its own discovery, user-info endpoint and request validation.

<a name="testing"></a>
## Testing

`actingAs` and `actingAsClient` default to the `passport` guard. Pass another guard name as their third argument when testing an additional Passport guard.

Pass `resources: ['https://api.example.com/calendar']` to either helper when testing routes protected by `CheckTokenAudience`. Without resources, the audience is the supplied client's ID, or an empty list when `actingAs` has no client.

Tests that issue real tokens need signing keys. With the database store, create a key using `Hypervel\Passport\SigningKey::factory()->create()`. The factory reuses a 2048-bit test key pair within the test process. With the file store, provide a test key pair through `Passport::loadKeysFrom` or the key configuration values.

Passport's `actingAs` method may be used to specify the currently authenticated user as well as its scopes. The first argument given to the `actingAs` method is the user instance and the second is an array of scopes that should be granted to the user's token:

```php
use App\Models\User;
use Hypervel\Passport\Passport;

public function test_orders_can_be_created(): void
{
    Passport::actingAs(
        User::factory()->create(),
        ['orders:create']
    );

    $response = $this->post('/api/orders');

    $response->assertStatus(201);
}
```

Passport's `actingAsClient` method may be used to specify the currently authenticated client as well as its scopes. The first argument given to the `actingAsClient` method is the client instance and the second is an array of scopes that should be granted to the client's token:

```php
use Hypervel\Passport\Client;
use Hypervel\Passport\Passport;

public function test_servers_can_be_retrieved(): void
{
    Passport::actingAsClient(
        Client::factory()->create(),
        ['servers:read']
    );

    $response = $this->get('/api/servers');

    $response->assertStatus(200);
}
```

<a name="credits"></a>
## Credits

Hypervel Passport began as a port of [Laravel Passport](https://github.com/laravel/passport) and has been adapted for Hypervel's framework architecture and coroutine runtime.
