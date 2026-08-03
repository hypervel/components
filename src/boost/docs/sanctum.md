# Hypervel Sanctum

- [Introduction](#introduction)
    - [How it Works](#how-it-works)
- [Installation](#installation)
- [Configuration](#configuration)
    - [Declaring Trusted Session Guards](#declaring-trusted-session-guards)
    - [Overriding Default Models](#overriding-default-models)
    - [Last Used Timestamps](#last-used-timestamps)
    - [Token Caching](#token-caching)
    - [Token Prefix](#token-prefix)
    - [Custom Token Retrieval and Validation](#custom-token-retrieval-and-validation)
- [API Token Authentication](#api-token-authentication)
    - [Issuing API Tokens](#issuing-api-tokens)
    - [Token Abilities](#token-abilities)
    - [Protecting Routes](#protecting-routes)
    - [Revoking Tokens](#revoking-tokens)
    - [Token Expiration](#token-expiration)
- [SPA Authentication](#spa-authentication)
    - [Configuration](#spa-configuration)
    - [Authenticating](#spa-authenticating)
    - [Protecting Routes](#protecting-spa-routes)
    - [Authorizing Private Broadcast Channels](#authorizing-private-broadcast-channels)
- [Mobile Application Authentication](#mobile-application-authentication)
    - [Issuing API Tokens](#issuing-mobile-api-tokens)
    - [Protecting Routes](#protecting-mobile-api-routes)
    - [Revoking Tokens](#revoking-mobile-api-tokens)
- [Events](#events)
- [Testing](#testing)

<a name="introduction"></a>
## Introduction

[Hypervel Sanctum](https://github.com/hypervel/sanctum) provides a featherweight authentication system for SPAs (single page applications), mobile applications, and simple, token based APIs. Sanctum allows each user of your application to generate multiple API tokens for their account. These tokens may be granted abilities / scopes which specify which actions the tokens are allowed to perform.

<a name="how-it-works"></a>
### How it Works

Hypervel Sanctum exists to solve two separate problems. Let's discuss each before digging deeper into the library.

<a name="how-it-works-api-tokens"></a>
#### API Tokens

First, Sanctum is a simple package you may use to issue API tokens to your users without the complication of OAuth. This feature is inspired by GitHub and other applications which issue "personal access tokens". For example, imagine the "account settings" of your application has a screen where a user may generate an API token for their account. You may use Sanctum to generate and manage those tokens. These tokens typically have a very long expiration time (years), but may be manually revoked by the user anytime.

Hypervel Sanctum offers this feature by storing user API tokens in a single database table and authenticating incoming HTTP requests via the `Authorization` header which should contain a valid API token.

<a name="how-it-works-spa-authentication"></a>
#### SPA Authentication

Second, Sanctum exists to offer a simple way to authenticate single page applications (SPAs) that need to communicate with a Hypervel powered API. These SPAs might exist in the same repository as your Hypervel application or might be an entirely separate repository, such as an SPA created using Next.js, Nuxt or TanStack Start.

For this feature, Sanctum does not use tokens of any kind. Instead, Sanctum uses Hypervel's built-in cookie based session authentication services. Typically, Sanctum utilizes Hypervel's `web` authentication guard to accomplish this. This provides the benefits of CSRF protection, session authentication, as well as protects against leakage of the authentication credentials via XSS.

Sanctum will only attempt to authenticate using cookies when the incoming request originates from your own SPA frontend. When Sanctum examines an incoming HTTP request, it will first check for an authentication cookie and, if none is present, Sanctum will then examine the `Authorization` header for a valid API token.

> [!NOTE]
> It is perfectly fine to use Sanctum only for API token authentication or only for SPA authentication. Just because you use Sanctum does not mean you are required to use both features it offers.

<a name="installation"></a>
## Installation

You may install Hypervel Sanctum via the `install:api` Artisan command:

```shell
php artisan install:api
```

This command installs the `hypervel/sanctum` package, creates a `routes/api.php` file if one does not already exist, publishes Sanctum's personal access token migration when needed, and reminds you to add the `Hypervel\Sanctum\HasApiTokens` trait to your User model.

If you prefer to install Sanctum manually, you may install the package via Composer:

```shell
composer require hypervel/sanctum
```

Then, publish Sanctum's configuration and migration files using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --provider="Hypervel\Sanctum\SanctumServiceProvider"
```

Finally, run your database migrations. Sanctum will create one database table in which to store API tokens:

```shell
php artisan migrate
```

Next, if you plan to utilize Sanctum to authenticate an SPA, please refer to the [SPA Authentication](#spa-authentication) section of this documentation.

<a name="configuration"></a>
## Configuration

<a name="declaring-trusted-session-guards"></a>
### Declaring Trusted Session Guards

Hypervel's default authentication configuration includes a `sanctum` guard. If your application does not already define this guard, add it to your application's `config/auth.php` file:

```php
'guards' => [
    'sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'users',
        'session_guards' => ['web'],
    ],
],
```

The `session_guards` key declares which stateful session guards this Sanctum guard trusts for first-party SPA requests before falling back to bearer-token authentication. A fresh Hypervel application uses `['web']`, meaning the `sanctum` guard accepts a logged-in `web` session or a valid bearer token for the configured provider.

Set `session_guards` to an empty array when a Sanctum guard should accept bearer tokens only:

```php
'guards' => [
    'api' => [
        'driver' => 'sanctum',
        'provider' => 'users',
        'session_guards' => [],
    ],
],
```

Every Sanctum guard must declare `session_guards`; omitting the key will throw a configuration exception that names the guard and the setting to add. Each listed guard must implement Hypervel's `StatefulGuard` contract, such as the built-in `session` driver. If a listed session guard returns a user that does not match the Sanctum guard's provider, Sanctum skips that session guard and continues to the next listed guard, then to bearer-token authentication.

<a name="overriding-default-models"></a>
### Overriding Default Models

Although not typically required, you are free to extend the `PersonalAccessToken` model used internally by Sanctum:

```php
use Hypervel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // ...
}
```

Then, you may instruct Sanctum to use your custom model via the `usePersonalAccessTokenModel` method provided by Sanctum. Typically, you should call this method in the `boot` method of your application's `AppServiceProvider` file:

```php
use App\Models\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
}
```

<a name="last-used-timestamps"></a>
### Last Used Timestamps

By default, Sanctum records the time a personal access token last completed authentication successfully. Rejected or expired tokens do not update the `last_used_at` timestamp, and session authentication is not affected.

You may disable these writes using the `SANCTUM_LAST_USED_AT` environment variable:

```env
SANCTUM_LAST_USED_AT=false
```

When [token caching](#token-caching) is enabled, the `last_used_at_update_interval` option limits how often the timestamp is written.

<a name="token-caching"></a>
### Token Caching

Sanctum can cache personal access token lookups and the tokenable model associated with each token. This reduces database reads for token-authenticated requests. When caching is enabled, Sanctum also throttles `last_used_at` writes so the timestamp is updated at a configured interval instead of after every successful API token authentication.

Token caching is disabled by default. You may enable and configure it in your application's `config/sanctum.php` file:

```php
'cache' => [
    'enabled' => env('SANCTUM_CACHE_ENABLED', false),
    'store' => env('SANCTUM_CACHE_STORE'),
    'ttl' => (int) env('SANCTUM_CACHE_TTL', 300),
    'prefix' => env('SANCTUM_CACHE_PREFIX', 'sanctum'),
    'last_used_at_update_interval' => filter_var(
        env('SANCTUM_LAST_USED_UPDATE_INTERVAL', 300),
        FILTER_VALIDATE_INT,
        FILTER_NULL_ON_FAILURE,
    ),
],
```

When caching is enabled, Sanctum adds the selected personal access token model, Eloquent models used by configured Sanctum guard providers, and Hypervel's standard Eloquent collection and pivot classes to the cache class policy automatically. Declare custom-provider morph targets, nested `$with` relations, custom collections or pivots, and other application-owned objects from a service provider:

```php
use App\Models\Organization;
use App\Models\Team;
use Hypervel\Support\Facades\Cache;

public function boot(): void
{
    Cache::allowSerializableClassesUsing(fn (): array => [
        Organization::class,
        Team::class,
    ]);
}
```

These declarations apply to stores that use PHP serialization. Native Redis serializers preserve model types but bypass this class policy. See [Serializable Cached Objects](/docs/{{version}}/cache#serializable-cached-objects) for more information.

The `store` option determines which cache store is used. When this value is `null`, Sanctum uses your application's default cache store. The `ttl` option controls how long token and tokenable entries remain cached, in seconds, and must be a positive integer. The `prefix` option is prepended to Sanctum's cache keys.

The `last_used_at_update_interval` value must be a non-negative integer. Set this option to `0` to update the timestamp after every successful token authentication.

Redis, database, file, storage, Swoole, and stacks containing only supported stores may be used. Stack layers are validated recursively. Array, worker-array, null, session, and failover stores are rejected. Failover is unsuitable because an unavailable primary can retain a stale identity and serve it after recovery.

For Redis, `SERIALIZER_NONE`, native PHP, and available igbinary serializers preserve model types. Msgpack is accepted only with `msgpack.php_only=1`. JSON, non-PHP msgpack, and unknown modes are rejected because they can return arrays instead of models. Native serializers bypass `cache.serializable_classes`; use `SERIALIZER_NONE` when class-policy enforcement is required.

Sanctum cache settings and `sanctum.last_used_at` are read during process startup and must not be changed while a worker is serving requests.

Sanctum also caches missing token IDs as `null` results for the configured TTL. This protects your database from repeated lookups for the same revoked or unknown token. Missing tokenable models are not cached because their visibility may depend on the current query context. Because token IDs come from request input, use a cache store with bounded memory or an eviction policy when enabling token caching on public endpoints.

The `last_used_at_update_interval` option controls how frequently Sanctum writes a cached token's `last_used_at` timestamp back to the database. The default value is `300`, so the timestamp is updated at most once every five minutes for each token while caching is enabled. The cache TTL should be greater than or equal to this interval so active cached tokens do not expire before the next allowed timestamp write.

Sanctum token caching pairs well with the authentication package's [user lookup cache](/docs/{{version}}/authentication#user-lookup-cache). Token-authenticated routes often need both the personal access token and its user model, so enabling both caches can reduce repeated database reads on hot authenticated endpoints.

The cached token entry never embeds its `tokenable` relation. During authentication, the live token receives the exact tokenable instance used for provider validation before authentication callbacks and events run.

Deleting a personal access token or making an application-visible update clears both cached entries. Sanctum's internal `last_used_at` write clears only the token entry, so it does not defeat the tokenable cache. You may also clear both entries manually using the `clearTokenCache` method:

```php
use Hypervel\Sanctum\PersonalAccessToken;

PersonalAccessToken::clearTokenCache($tokenId);
```

Tokenable model changes do not automatically evict token-ID-keyed entries. The cache TTL is therefore the maximum staleness bound. If a change must be reflected immediately during token authentication, clear the cache for that model's tokens:

```php
use Hypervel\Sanctum\PersonalAccessToken;

$user->tokens()
    ->pluck('id')
    ->each(fn (int|string $tokenId) => PersonalAccessToken::clearTokenCache($tokenId));
```

<a name="token-prefix"></a>
### Token Prefix

Sanctum can prefix newly generated tokens using the `token_prefix` configuration option. This is useful when you want generated tokens to match secret scanning patterns used by platforms such as GitHub:

```php
'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
```

For example, setting `SANCTUM_TOKEN_PREFIX=app_` will generate plain-text tokens beginning with `app_`. The prefix is included in the plain-text token given to the user, while the database still stores only the SHA-256 hash of the generated token.

<a name="custom-token-retrieval-and-validation"></a>
### Custom Token Retrieval and Validation

By default, Sanctum retrieves API tokens from the `Authorization` header as a bearer token. You may customize how Sanctum retrieves the access token from the request using the `getAccessTokenFromRequestUsing` method:

```php
use Hypervel\Http\Request;
use Hypervel\Sanctum\Sanctum;

Sanctum::getAccessTokenFromRequestUsing(function (Request $request): ?string {
    return $request->header('X-Auth-Token');
});
```

You may pass `null` to the `getAccessTokenFromRequestUsing` method to restore Sanctum's default bearer token retrieval.

You may also add custom access token validation using the `authenticateAccessTokensUsing` method. The callback receives the token model and Sanctum's default validity result:

```php
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;

Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $accessToken, bool $isValid): bool {
    return $isValid && $accessToken->tokenable->is_active;
});
```

<a name="api-token-authentication"></a>
## API Token Authentication

> [!NOTE]
> You should not use API tokens to authenticate your own first-party SPA. Instead, use Sanctum's built-in [SPA authentication features](#spa-authentication).

<a name="issuing-api-tokens"></a>
### Issuing API Tokens

Sanctum allows you to issue API tokens / personal access tokens that may be used to authenticate API requests to your application. When making requests using API tokens, the token should be included in the `Authorization` header as a `Bearer` token.

To begin issuing tokens for users, your User model should use the `Hypervel\Sanctum\HasApiTokens` trait:

```php
use Hypervel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

To issue a token, you may use the `createToken` method. The `createToken` method returns a `Hypervel\Sanctum\NewAccessToken` instance. API tokens are hashed using SHA-256 hashing before being stored in your database, but you may access the plain-text value of the token using the `plainTextToken` property of the `NewAccessToken` instance. You should display this value to the user immediately after the token has been created:

```php
use Hypervel\Http\Request;

Route::post('/tokens/create', function (Request $request) {
    $token = $request->user()->createToken($request->token_name);

    return ['token' => $token->plainTextToken];
});
```

You may access all of the user's tokens using the `tokens` Eloquent relationship provided by the `HasApiTokens` trait:

```php
foreach ($user->tokens as $token) {
    // ...
}
```

<a name="token-abilities"></a>
### Token Abilities

Sanctum allows you to assign "abilities" to tokens. Abilities serve a similar purpose as OAuth's "scopes". You may pass an array of string abilities as the second argument to the `createToken` method:

```php
return $user->createToken('token-name', ['server:update'])->plainTextToken;
```

Hypervel also supports PHP enum cases for token abilities:

```php
enum TokenAbility: string
{
    case ServerUpdate = 'server:update';
}

return $user->createToken('token-name', [TokenAbility::ServerUpdate])->plainTextToken;
```

When handling an incoming request authenticated by Sanctum, you may determine if the token has a given ability using the `tokenCan` or `tokenCant` methods:

```php
if ($user->tokenCan('server:update')) {
    // ...
}

if ($user->tokenCant('server:update')) {
    // ...
}

if ($user->tokenCan(TokenAbility::ServerUpdate)) {
    // ...
}
```

<a name="token-ability-middleware"></a>
#### Token Ability Middleware

Sanctum also includes two middleware that may be used to verify that an incoming request is authenticated with a token that has been granted a given ability. To get started, define the following middleware aliases in your application's `bootstrap/app.php` file:

```php
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Sanctum\Http\Middleware\CheckAbilities;
use Hypervel\Sanctum\Http\Middleware\CheckForAnyAbility;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'abilities' => CheckAbilities::class,
        'ability' => CheckForAnyAbility::class,
    ]);
})
```

The `abilities` middleware may be assigned to a route to verify that the incoming request's token has all of the listed abilities:

```php
Route::get('/orders', function () {
    // Token has both "check-status" and "place-orders" abilities...
})->middleware(['auth:sanctum', 'abilities:check-status,place-orders']);
```

The `ability` middleware may be assigned to a route to verify that the incoming request's token has *at least one* of the listed abilities:

```php
Route::get('/orders', function () {
    // Token has the "check-status" or "place-orders" ability...
})->middleware(['auth:sanctum', 'ability:check-status,place-orders']);
```

<a name="first-party-ui-initiated-requests"></a>
#### First-Party UI Initiated Requests

For convenience, the `tokenCan` method will always return `true` if the incoming authenticated request was from your first-party SPA and you are using Sanctum's built-in [SPA authentication](#spa-authentication).

However, this does not necessarily mean that your application has to allow the user to perform the action. Typically, your application's [authorization policies](/docs/{{version}}/authorization#creating-policies) will determine if the token has been granted the permission to perform the abilities as well as check that the user instance itself should be allowed to perform the action.

For example, if we imagine an application that manages servers, this might mean checking that the token is authorized to update servers **and** that the server belongs to the user:

```php
return $request->user()->id === $server->user_id &&
       $request->user()->tokenCan('server:update')
```

At first, allowing the `tokenCan` method to be called and always return `true` for first-party UI initiated requests may seem strange; however, it is convenient to be able to always assume an API token is available and can be inspected via the `tokenCan` method. By taking this approach, you may always call the `tokenCan` method within your application's authorization policies without worrying about whether the request was triggered from your application's UI or was initiated by one of your API's third-party consumers.

<a name="protecting-routes"></a>
### Protecting Routes

To protect routes so that all incoming requests must be authenticated, you should attach the `sanctum` authentication guard to your protected routes within your `routes/web.php` and `routes/api.php` route files. This guard will ensure that incoming requests are authenticated as either stateful, cookie authenticated requests or contain a valid API token header if the request is from a third party.

You may be wondering why we suggest that you authenticate the routes within your application's `routes/web.php` file using the `sanctum` guard. Remember, Sanctum will first attempt to authenticate incoming requests using Hypervel's typical session authentication cookie. If that cookie is not present then Sanctum will attempt to authenticate the request using a token in the request's `Authorization` header. In addition, authenticating all requests using Sanctum ensures that we may always call the `tokenCan` method on the currently authenticated user instance:

```php
use Hypervel\Http\Request;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
```

<a name="revoking-tokens"></a>
### Revoking Tokens

You may "revoke" tokens by deleting them from your database using the `tokens` relationship that is provided by the `Hypervel\Sanctum\HasApiTokens` trait:

```php
// Revoke all tokens...
$user->tokens()->delete();

// Revoke the token that was used to authenticate the current request...
$request->user()->currentAccessToken()->delete();

// Revoke a specific token...
$user->tokens()->where('id', $tokenId)->delete();
```

<a name="token-expiration"></a>
### Token Expiration

By default, Sanctum tokens never expire and may only be invalidated by [revoking the token](#revoking-tokens). However, if you would like to configure an expiration time for your application's API tokens, you may do so via the `expiration` configuration option defined in your application's `sanctum` configuration file. This configuration option defines the number of minutes until an issued token will be considered expired:

```php
'expiration' => 525600,
```

If you would like to specify the expiration time of each token independently, you may do so by providing the expiration time as the third argument to the `createToken` method:

```php
return $user->createToken(
    'token-name', ['*'], now()->plus(weeks: 1)
)->plainTextToken;
```

If you have configured a token expiration time for your application, you may also wish to [schedule a task](/docs/{{version}}/scheduling) to prune your application's expired tokens. Thankfully, Sanctum includes a `sanctum:prune-expired` Artisan command that you may use to accomplish this. For example, you may configure a scheduled task to delete all expired token database records that have been expired for at least 24 hours:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
```

<a name="spa-authentication"></a>
## SPA Authentication

Sanctum also exists to provide a simple method of authenticating single page applications (SPAs) that need to communicate with a Hypervel powered API. These SPAs might exist in the same repository as your Hypervel application or might be an entirely separate repository.

For this feature, Sanctum does not use tokens of any kind. Instead, Sanctum uses Hypervel's built-in cookie based session authentication services. This approach to authentication provides the benefits of CSRF protection, session authentication, as well as protects against leakage of the authentication credentials via XSS.

> [!WARNING]
> In order to authenticate, your SPA and API must share the same top-level domain. However, they may be placed on different subdomains. Additionally, you should ensure that you send the `Accept: application/json` header and either the `Referer` or `Origin` header with your request.

<a name="spa-configuration"></a>
### Configuration

<a name="configuring-your-first-party-domains"></a>
#### Configuring Your First-Party Domains

First, you should configure which domains your SPA will be making requests from. You may configure these domains using the `stateful_domains` configuration option in your `sanctum` configuration file. This configuration setting determines which domains will maintain "stateful" authentication using Hypervel session cookies when making requests to your API.

To assist you in setting up your first-party stateful domains, Sanctum provides two helper methods that you can include in the configuration. First, `Sanctum::currentApplicationUrlWithPort()` will return the current application URL from the `APP_URL` environment variable, and `Sanctum::currentRequestHost()` will inject a placeholder into the stateful domain list which, at runtime, will be replaced by the host from the current request so that all requests with the same domain are considered stateful.

If you need to derive stateful domains from the incoming request, for example to apply per-tenant or per-host stateful API rules, you may register a resolver on Sanctum's stateful middleware from the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use App\Support\TenantResolver;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    EnsureFrontendRequestsAreStateful::resolveStatefulDomainsUsing(function (Request $request): array {
        $tenant = app(TenantResolver::class)->forRequest($request);

        return $tenant ? [$tenant->domain] : config('sanctum.stateful_domains', []);
    });
}
```

When your application needs to make the final stateful decision itself, you may register a request resolver instead:

```php
use App\Support\StatefulRequestResolver;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

EnsureFrontendRequestsAreStateful::resolveStatefulRequestsUsing(
    fn (Request $request): bool => app(StatefulRequestResolver::class)->isStateful($request)
);
```

The request resolver takes precedence over the domain resolver and configured domain list. Register either resolver during application boot because it persists for the worker lifetime; pass `null` to clear it. Framework test cleanup resets both resolver slots automatically.

Domain-list matching is case-sensitive. Normalize any request-derived domains before returning them from `resolveStatefulDomainsUsing()`.

> [!WARNING]
> If you are accessing your application via a URL that includes a port (`127.0.0.1:8000`), you should ensure that you include the port number with the domain.

<a name="sanctum-middleware"></a>
#### Sanctum Middleware

Next, you should instruct Hypervel that incoming requests from your SPA can authenticate using Hypervel's session cookies, while still allowing requests from third parties or mobile applications to authenticate using API tokens. This can be easily accomplished by invoking the `statefulApi` middleware method in your application's `bootstrap/app.php` file:

```php
use Hypervel\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->statefulApi();
})
```

<a name="cors-and-cookies"></a>
#### CORS and Cookies

If you are having trouble authenticating with your application from an SPA that executes on a separate subdomain, you have likely misconfigured your CORS (Cross-Origin Resource Sharing) or session cookie settings.

The `config/cors.php` configuration file is not published by default. If you need to customize Hypervel's CORS options, you should publish the complete `cors` configuration file using the `config:publish` Artisan command:

```shell
php artisan config:publish cors
```

Next, you should ensure that your application's CORS configuration is returning the `Access-Control-Allow-Credentials` header with a value of `True`. This may be accomplished by setting the `supports_credentials` option within your application's `config/cors.php` configuration file to `true`.

In addition, you should enable the `withCredentials` and `withXSRFToken` options on your application's global `axios` instance. Typically, this should be performed in your `resources/js/bootstrap.js` file. If you are not using Axios to make HTTP requests from your frontend, you should perform the equivalent configuration on your own HTTP client:

```js
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
```

Finally, you should ensure your application's session cookie domain configuration supports any subdomain of your root domain. You may accomplish this by prefixing the domain with a leading `.` within your application's `config/session.php` configuration file:

```php
'domain' => '.domain.com',
```

<a name="spa-authenticating"></a>
### Authenticating

<a name="csrf-protection"></a>
#### CSRF Protection

To authenticate your SPA, your SPA's "login" page should first make a request to the `/sanctum/csrf-cookie` endpoint to initialize CSRF protection for the application:

```js
axios.get('/sanctum/csrf-cookie').then(response => {
    // Login...
});
```

During this request, Hypervel will set an `XSRF-TOKEN` cookie containing the current CSRF token. This token should then be URL decoded and passed in an `X-XSRF-TOKEN` header on subsequent requests, which some HTTP client libraries like Axios and the Angular HttpClient will do automatically for you. If your JavaScript HTTP library does not set the value for you, you will need to manually set the `X-XSRF-TOKEN` header to match the URL decoded value of the `XSRF-TOKEN` cookie that is set by this route.

<a name="logging-in"></a>
#### Logging In

Once CSRF protection has been initialized, you should make a `POST` request to your Hypervel application's `/login` route. This `/login` route may be [implemented manually](/docs/{{version}}/authentication#authenticating-users) or using a headless authentication package like [Hypervel Fortify](/docs/{{version}}/fortify).

If the login request is successful, you will be authenticated and subsequent requests to your application's routes will automatically be authenticated via the session cookie that the Hypervel application issued to your client. In addition, since your application already made a request to the `/sanctum/csrf-cookie` route, subsequent requests should automatically receive CSRF protection as long as your JavaScript HTTP client sends the value of the `XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header.

Of course, if your user's session expires due to lack of activity, subsequent requests to the Hypervel application may receive a 401 or 419 HTTP error response. In this case, you should redirect the user to your SPA's login page.

> [!WARNING]
> You are free to write your own `/login` endpoint; however, you should ensure that it authenticates the user using the standard, [session based authentication services that Hypervel provides](/docs/{{version}}/authentication#authenticating-users). Typically, this means using the `web` authentication guard.

<a name="protecting-spa-routes"></a>
### Protecting Routes

To protect routes so that all incoming requests must be authenticated, you should attach the `sanctum` authentication guard to your API routes within your `routes/api.php` file. This guard will ensure that incoming requests are authenticated as either stateful authenticated requests from your SPA or contain a valid API token header if the request is from a third party:

```php
use Hypervel\Http\Request;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
```

<a name="authorizing-private-broadcast-channels"></a>
### Authorizing Private Broadcast Channels

If your SPA needs to authenticate with [private / presence broadcast channels](/docs/{{version}}/broadcasting#authorizing-channels), you should remove the `channels` entry from the `withRouting` method contained in your application's `bootstrap/app.php` file. Instead, you should invoke the `withBroadcasting` method so that you may specify the correct middleware for your application's broadcasting routes:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // ...
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
```

Next, in order for Pusher's authorization requests to succeed, you will need to provide a custom Pusher `authorizer` when initializing [Laravel Echo](/docs/{{version}}/broadcasting#client-side-installation). This allows your application to configure Pusher to use the `axios` instance that is [properly configured for cross-domain requests](#cors-and-cookies):

```js
window.Echo = new Echo({
    broadcaster: "pusher",
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    encrypted: true,
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    authorizer: (channel, options) => {
        return {
            authorize: (socketId, callback) => {
                axios.post('/api/broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channel.name
                })
                .then(response => {
                    callback(false, response.data);
                })
                .catch(error => {
                    callback(true, error);
                });
            }
        };
    },
})
```

<a name="mobile-application-authentication"></a>
## Mobile Application Authentication

You may also use Sanctum tokens to authenticate your mobile application's requests to your API. The process for authenticating mobile application requests is similar to authenticating third-party API requests; however, there are small differences in how you will issue the API tokens.

<a name="issuing-mobile-api-tokens"></a>
### Issuing API Tokens

To get started, create a route that accepts the user's email / username, password, and device name, then exchanges those credentials for a new Sanctum token. The "device name" given to this endpoint is for informational purposes and may be any value you wish. In general, the device name value should be a name the user would recognize, such as "Nuno's iPhone".

Typically, you will make a request to the token endpoint from your mobile application's "login" screen. The endpoint will return the plain-text API token which may then be stored on the mobile device and used to make additional API requests:

```php
use App\Models\User;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Hash;
use Hypervel\Validation\ValidationException;

Route::post('/sanctum/token', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
        'device_name' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    return $user->createToken($request->device_name)->plainTextToken;
});
```

When the mobile application uses the token to make an API request to your application, it should pass the token in the `Authorization` header as a `Bearer` token.

> [!NOTE]
> When issuing tokens for a mobile application, you are also free to specify [token abilities](#token-abilities).

<a name="protecting-mobile-api-routes"></a>
### Protecting Routes

As previously documented, you may protect routes so that all incoming requests must be authenticated by attaching the `sanctum` authentication guard to the routes:

```php
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
```

<a name="revoking-mobile-api-tokens"></a>
### Revoking Tokens

To allow users to revoke API tokens issued to mobile devices, you may list them by name, along with a "Revoke" button, within an "account settings" portion of your web application's UI. When the user clicks the "Revoke" button, you can delete the token from the database. Remember, you can access a user's API tokens via the `tokens` relationship provided by the `Hypervel\Sanctum\HasApiTokens` trait:

```php
// Revoke all tokens...
$user->tokens()->delete();

// Revoke a specific token...
$user->tokens()->where('id', $tokenId)->delete();
```

<a name="events"></a>
## Events

Sanctum dispatches the `Hypervel\Sanctum\Events\TokenAuthenticated` event when a request is successfully authenticated using an API token. The event receives the authenticated `Hypervel\Sanctum\PersonalAccessToken` instance:

```php
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Support\Facades\Event;

Event::listen(function (TokenAuthenticated $event): void {
    // $event->token
});
```

<a name="testing"></a>
## Testing

While testing, the `Sanctum::actingAs` method may be used to authenticate a user and specify which abilities should be granted to their token:

```php tab=Pest
use App\Models\User;
use Hypervel\Sanctum\Sanctum;

test('task list can be retrieved', function () {
    Sanctum::actingAs(
        User::factory()->create(),
        ['view-tasks']
    );

    $response = $this->get('/api/task');

    $response->assertOk();
});
```

```php tab=PHPUnit
use App\Models\User;
use Hypervel\Sanctum\Sanctum;

public function test_task_list_can_be_retrieved(): void
{
    Sanctum::actingAs(
        User::factory()->create(),
        ['view-tasks']
    );

    $response = $this->get('/api/task');

    $response->assertOk();
}
```

If you would like to grant all abilities to the token, you should include `*` in the ability list provided to the `actingAs` method:

```php
Sanctum::actingAs(
    User::factory()->create(),
    ['*']
);
```
