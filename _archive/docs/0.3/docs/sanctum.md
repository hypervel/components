# Hypervel Sanctum
[[toc]]

## Introduction

[Hypervel Sanctum](https://github.com/hypervel/sanctum) provides a featherweight authentication system for SPAs (single page applications), mobile applications, and simple, token based APIs. Sanctum allows each user of your application to generate multiple API tokens for their account. These tokens may be granted abilities / scopes which specify which actions the tokens are allowed to perform.

### How it Works

Hypervel Sanctum exists to solve two separate problems. Let's discuss each before digging deeper into the library.

<a name="how-it-works-api-tokens"></a>
#### API Tokens

First, Sanctum is a simple package you may use to issue API tokens to your users without the complication of OAuth. This feature is inspired by GitHub and other applications which issue "personal access tokens". For example, imagine the "account settings" of your application has a screen where a user may generate an API token for their account. You may use Sanctum to generate and manage those tokens. These tokens typically have a very long expiration time (years), but may be manually revoked by the user anytime.

Hypervel Sanctum offers this feature by storing user API tokens in a single database table and authenticating incoming HTTP requests via the `Authorization` header which should contain a valid API token.

<a name="how-it-works-spa-authentication"></a>
#### SPA Authentication

Second, Sanctum exists to offer a simple way to authenticate single page applications (SPAs) that need to communicate with a Hypervel powered API. These SPAs might exist in the same repository as your Hypervel application or might be an entirely separate repository, such as an SPA created using Next.js or Nuxt.

For this feature, Sanctum does not use tokens of any kind. Instead, Sanctum uses Hypervel's built-in cookie based session authentication services. Typically, Sanctum utilizes Hypervel's `web` authentication guard to accomplish this. This provides the benefits of CSRF protection, session authentication, as well as protects against leakage of the authentication credentials via XSS.

Sanctum will only attempt to authenticate using cookies when the incoming request originates from your own SPA frontend. When Sanctum examines an incoming HTTP request, it will first check for an authentication cookie and, if none is present, Sanctum will then examine the `Authorization` header for a valid API token.

::: note
It is perfectly fine to use Sanctum only for API token authentication or only for SPA authentication. Just because you use Sanctum does not mean you are required to use both features it offers.
:::

## Installation

You may install Hypervel Sanctum via the Composer package manager:

```shell
composer require hypervel/sanctum
```

Next, you should publish the Sanctum configuration and migration files using the `vendor:publish` Artisan command. The sanctum configuration file will be placed in your application's `config` directory:

```shell
php artisan vendor:publish "Hypervel\Sanctum\SanctumServiceProvider"
```

Finally, you should run your database migrations. Sanctum will create one database table in which to store API tokens:

```shell
php artisan migrate
```

Next, if you plan to utilize Sanctum to authenticate a SPA, you should add Sanctum's middleware to your `api` middleware group within your application's `app/Http/Kernel.php` file:

```php
'api' => [
    \Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \Hypervel\Router\Middleware\ThrottleRequests::class . '60,1,api',
    \Hypervel\Router\Middleware\SubstituteBindings::class,
],
```

## Configuration

### Setting Sanctum Guard

Before authenticating with sanctum, you need to configure `sanctum` guard in `config/auth.php`.

```php
'guards' => [
    // ...
    'sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'users',
    ],
],
```

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

## API Token Authentication

::: note
You should not use API tokens to authenticate your own first-party SPA. Instead, use Sanctum's built-in [SPA authentication features](#spa-authentication).
:::

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

### Token Abilities

Sanctum allows you to assign "abilities" to tokens. Abilities serve a similar purpose as OAuth's "scopes". You may pass an array of string abilities as the second argument to the `createToken` method:

```php
return $user->createToken('token-name', ['server:update'])->plainTextToken;
```

When handling an incoming request authenticated by Sanctum, you may determine if the token has a given ability using the `tokenCan` or `tokenCant` methods:

```php
if ($user->tokenCan('server:update')) {
    // ...
}

if ($user->tokenCant('server:update')) {
    // ...
}
```

#### Token Ability Middleware

Sanctum also includes two middleware that may be used to verify that an incoming request is authenticated with a token that has been granted a given ability. To get started, define the following middleware aliases in your application's `app/Http/Kernel.php` file:

```php
use Hypervel\Sanctum\Http\Middleware\CheckAbilities;
use Hypervel\Sanctum\Http\Middleware\CheckForAnyAbility;

protected array $middlewareAliases = [
    'abilities' => CheckAbilities::class,
    'ability' => CheckForAnyAbility::class,
];
```

The `abilities` middleware may be assigned to a route to verify that the incoming request's token has all of the listed abilities:

```php
Route::get('/orders', function () {
    // Token has both "check-status" and "place-orders" abilities...
}, [
    'middleware' => ['auth:sanctum', 'abilities:check-status,place-orders']
]);
```

The `ability` middleware may be assigned to a route to verify that the incoming request's token has *at least one* of the listed abilities:

```php
Route::get('/orders', function () {
    // Token has the "check-status" or "place-orders" ability...
}, [
    'middleware' => ['auth:sanctum', 'ability:check-status,place-orders']
]);
```

#### First-Party UI Initiated Requests

For convenience, the `tokenCan` method will always return `true` if the incoming authenticated request was from your first-party SPA and you are using Sanctum's built-in [SPA authentication](#spa-authentication).

However, this does not necessarily mean that your application has to allow the user to perform the action. Typically, your application's [authorization policies](/docs/authorization#creating-policies) will determine if the token has been granted the permission to perform the abilities as well as check that the user instance itself should be allowed to perform the action.

For example, if we imagine an application that manages servers, this might mean checking that the token is authorized to update servers **and** that the server belongs to the user:

```php
return $request->user()->id === $server->user_id &&
       $request->user()->tokenCan('server:update')
```

At first, allowing the `tokenCan` method to be called and always return `true` for first-party UI initiated requests may seem strange; however, it is convenient to be able to always assume an API token is available and can be inspected via the `tokenCan` method. By taking this approach, you may always call the `tokenCan` method within your application's authorization policies without worrying about whether the request was triggered from your application's UI or was initiated by one of your API's third-party consumers.

### Protecting Routes

To protect routes so that all incoming requests must be authenticated, you should attach the `sanctum` authentication guard to your protected routes within your `routes/web.php` and `routes/api.php` route files. This guard will ensure that incoming requests are authenticated as either stateful, cookie authenticated requests or contain a valid API token header if the request is from a third party.

You may be wondering why we suggest that you authenticate the routes within your application's `routes/web.php` file using the `sanctum` guard. Remember, Sanctum will first attempt to authenticate incoming requests using Hypervel's typical session authentication cookie. If that cookie is not present then Sanctum will attempt to authenticate the request using a token in the request's `Authorization` header. In addition, authenticating all requests using Sanctum ensures that we may always call the `tokenCan` method on the currently authenticated user instance:

```php
use Hypervel\Http\Request;

Route::get('/user', function (Request $request) {
    return $request->user();
}, ['middleware' => 'auth:sanctum']);
```

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

### Token Expiration

By default, Sanctum tokens never expire and may only be invalidated by [revoking the token](#revoking-tokens). However, if you would like to configure an expiration time for your application's API tokens, you may do so via the `expiration` configuration option defined in your application's `sanctum` configuration file. This configuration option defines the number of minutes until an issued token will be considered expired:

```php
'expiration' => 525600,
```

If you would like to specify the expiration time of each token independently, you may do so by providing the expiration time as the third argument to the `createToken` method:

```php
return $user->createToken(
    'token-name', ['*'], now()->addWeek()
)->plainTextToken;
```

If you have configured a token expiration time for your application, you may also wish to [schedule a task](/docs/scheduling) to prune your application's expired tokens. Thankfully, Sanctum includes a `sanctum:prune-expired` Artisan command that you may use to accomplish this. For example, you may configure a scheduled task to delete all expired token database records that have been expired for at least 24 hours:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
```

## SPA Authentication

Sanctum also exists to provide a simple method of authenticating single page applications (SPAs) that need to communicate with a Hypervel powered API. These SPAs might exist in the same repository as your Hypervel application or might be an entirely separate repository.

For this feature, Sanctum does not use tokens of any kind. Instead, Sanctum uses Hypervel's built-in cookie based session authentication services. This approach to authentication provides the benefits of CSRF protection, session authentication, as well as protects against leakage of the authentication credentials via XSS.

::: warning
In order to authenticate, your SPA and API must share the same top-level domain. However, they may be placed on different subdomains. Additionally, you should ensure that you send the `Accept: application/json` header and either the `Referer` or `Origin` header with your request.
:::

### Configuration

#### Configuring Your First-Party Domains

First, you should configure which domains your SPA will be making requests from. You may configure these domains using the `stateful` configuration option in your `sanctum` configuration file. This configuration setting determines which domains will maintain "stateful" authentication using Hypervel session cookies when making requests to your API.

To assist you in setting up your first-party stateful domains, Sanctum provides two helper functions that you can include in the configuration. First, `Sanctum::currentApplicationUrlWithPort()` will return the current application URL from the `APP_URL` environment variable, and `Sanctum::currentRequestHost()` will inject a placeholder into the stateful domain list which, at runtime, will be replaced by the host from the current request so that all requests with the same domain are considered stateful.

::: warning
If you are accessing your application via a URL that includes a port (`127.0.0.1:8000`), you should ensure that you include the port number with the domain.
:::

#### Sanctum Middleware

Next, you should add Sanctum's middleware to your api middleware group within your `app/Http/Kernel.php` file. This middleware is responsible for ensuring that incoming requests from your SPA can authenticate using Hypervel's session cookies, while still allowing requests from third parties or mobile applications to authenticate using API tokens:

```php
'api' => [
    \Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \Hypervel\Router\Middleware\ThrottleRequests::class . ':60,1,api',
    \Hypervel\Router\Middleware\SubstituteBindings::class,
],
```

#### CORS and Cookies

If you are having trouble authenticating with your application from an SPA that executes on a separate subdomain, you have likely misconfigured your CORS (Cross-Origin Resource Sharing) or session cookie settings.

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

#### CSRF Protection

To authenticate your SPA, your SPA's "login" page should first make a request to the `/sanctum/csrf-cookie` endpoint to initialize CSRF protection for the application:

```js
axios.get('/sanctum/csrf-cookie').then(response => {
    // Login...
});
```

During this request, Hypervel will set an `XSRF-TOKEN` cookie containing the current CSRF token. This token should then be URL decoded and passed in an `X-XSRF-TOKEN` header on subsequent requests, which some HTTP client libraries like Axios and the Angular HttpClient will do automatically for you. If your JavaScript HTTP library does not set the value for you, you will need to manually set the `X-XSRF-TOKEN` header to match the URL decoded value of the `XSRF-TOKEN` cookie that is set by this route.

#### Logging In

Once CSRF protection has been initialized, you should make a `POST` request to your Hypervel application's `/login` route. This `/login` route may be [implemented manually](/docs/authentication#manually-authenticating-users).

If the login request is successful, you will be authenticated and subsequent requests to your application's routes will automatically be authenticated via the session cookie that the Hypervel application issued to your client. In addition, since your application already made a request to the `/sanctum/csrf-cookie` route, subsequent requests should automatically receive CSRF protection as long as your JavaScript HTTP client sends the value of the `XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header.

Of course, if your user's session expires due to lack of activity, subsequent requests to the Hypervel application may receive a 401 or 419 HTTP error response. In this case, you should redirect the user to your SPA's login page.

::: warning
You are free to write your own `/login` endpoint; however, you should ensure that it authenticates the user using the standard, [session based authentication services that Hypervel provides](/docs/authentication#authenticating-users). Typically, this means using the `web` authentication guard.
:::

<a name="protecting-spa-routes"></a>
### Protecting Routes

To protect routes so that all incoming requests must be authenticated, you should attach the `sanctum` authentication guard to your API routes within your `routes/api.php` file. This guard will ensure that incoming requests are authenticated as either stateful authenticated requests from your SPA or contain a valid API token header if the request is from a third party:

```php
use Hypervel\Http\Request;

Route::get('/user', function (Request $request) {
    return $request->user();
}, ['middleware' => 'auth:sanctum']);
```

### Authorizing Private Broadcast Channels

If your SPA needs to authenticate with [private / presence broadcast channels](/docs/broadcasting#authorizing-channels), you should place the `Broadcast::routes` method call within your `routes/api.php` file:

```php
Broadcast::routes(['middleware' => ['auth:sanctum']]);
```

Next, in order for Pusher's authorization requests to succeed, you will need to provide a custom Pusher `authorizer` when initializing [Laravel Echo](/docs/broadcasting#client-side-installation). This allows your application to configure Pusher to use the `axios` instance that is [properly configured for cross-domain requests](#cors-and-cookies):

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

## Mobile Application Authentication

You may also use Sanctum tokens to authenticate your mobile application's requests to your API. The process for authenticating mobile application requests is similar to authenticating third-party API requests; however, there are small differences in how you will issue the API tokens.

<a name="issuing-mobile-api-tokens"></a>
### Issuing API Tokens

To get started, create a route that accepts the user's email / username, password, and device name, then exchanges those credentials for a new Sanctum token. The "device name" given to this endpoint is for informational purposes and may be any value you wish. In general, the device name value should be a name the user would recognize, such as "Nuno's iPhone 12".

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

::: note
When issuing tokens for a mobile application, you are also free to specify [token abilities](#token-abilities).
:::

<a name="protecting-mobile-api-routes"></a>
### Protecting Routes

As previously documented, you may protect routes so that all incoming requests must be authenticated by attaching the `sanctum` authentication guard to the routes:

```php
Route::get('/user', function (Request $request) {
    return $request->user();
}, ['middleware' => 'auth:sanctum']);
```

### Revoking Tokens

To allow users to revoke API tokens issued to mobile devices, you may list them by name, along with a "Revoke" button, within an "account settings" portion of your web application's UI. When the user clicks the "Revoke" button, you can delete the token from the database. Remember, you can access a user's API tokens via the `tokens` relationship provided by the `Hypervel\Sanctum\HasApiTokens` trait:

```php
// Revoke all tokens...
$user->tokens()->delete();

// Revoke a specific token...
$user->tokens()->where('id', $tokenId)->delete();
```

## Testing

While testing, the `Sanctum::actingAs` method may be used to authenticate a user and specify which abilities should be granted to their token:

```php
use App\Models\User;
use Hypervel\Sanctum\Sanctum;

public function testTaskListCanBeRetrieved(): void
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