# Hypervel Socialite

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Authentication](#authentication)
    - [Routing](#routing)
    - [Authentication and Storage](#authentication-and-storage)
    - [Access Scopes](#access-scopes)
    - [Slack Bot Scopes](#slack-bot-scopes)
    - [Optional Parameters](#optional-parameters)
    - [Dynamic Provider Configuration](#dynamic-provider-configuration)
    - [PKCE](#pkce)
    - [Custom Providers](#custom-providers)
        - [OAuth 2.0 Providers](#custom-oauth2-providers)
        - [OpenID Connect Providers](#custom-openid-connect-providers)
- [Retrieving User Details](#retrieving-user-details)
    - [Retrieving User Details From a Token](#retrieving-user-details-from-a-token)
    - [Refreshing Access Tokens](#refreshing-access-tokens)
    - [Stateless Authentication](#stateless-authentication)
- [Testing](#testing)

<a name="introduction"></a>
## Introduction

In addition to typical, form based authentication, Hypervel also provides a simple, convenient way to authenticate with OAuth and federated login providers using [Hypervel Socialite](https://github.com/hypervel/socialite). Socialite currently supports authentication via Facebook, X, LinkedIn OpenID Connect, Google, GitHub, GitLab, Bitbucket, Twitch, Slack, and Slack OpenID Connect.

> [!NOTE]
> Hypervel Socialite does not include OAuth 1.0a support; use OAuth 2.0 or OpenID Connect providers for new integrations.

> [!NOTE]
> Adapters published on the community driven [Socialite Providers](https://socialiteproviders.com/) website target `laravel/socialite` and are not drop-in compatible with Hypervel. They can, however, be used as a reference when writing a custom Hypervel provider.

<a name="installation"></a>
## Installation

To get started with Socialite, use the Composer package manager to add the package to your project's dependencies:

```shell
composer require hypervel/socialite
```

<a name="configuration"></a>
## Configuration

Before using Socialite, you will need to add credentials for the OAuth providers your application utilizes. Typically, these credentials may be retrieved by creating a "developer application" within the dashboard of the service you will be authenticating with.

These credentials should be placed in your application's `config/services.php` configuration file, and should use the key `facebook`, `x`, `linkedin-openid`, `google`, `github`, `gitlab`, `bitbucket`, `twitch`, `slack`, or `slack-openid`, depending on the providers your application requires:

```php
'github' => [
    'client_id' => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect' => 'http://example.com/callback-url',
],
```

> [!NOTE]
> If the `redirect` option contains a relative path, it will automatically be resolved to a fully qualified URL.

You may also define default scopes in the provider configuration. These scopes will be merged with the provider's default scopes:

```php
'github' => [
    'client_id' => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect' => 'http://example.com/callback-url',
    'scopes' => ['read:user', 'public_repo'],
],
```

<a name="authentication"></a>
## Authentication

<a name="routing"></a>
### Routing

To authenticate users using an OAuth provider, you will need two routes: one for redirecting the user to the OAuth provider, and another for receiving the callback from the provider after authentication. The example routes below demonstrate the implementation of both routes:

```php
use Hypervel\Socialite\Socialite;

Route::get('/auth/redirect', function () {
    return Socialite::driver('github')->redirect();
});

Route::get('/auth/callback', function () {
    $user = Socialite::driver('github')->user();

    // $user->token
});
```

The `redirect` method provided by the `Socialite` facade takes care of redirecting the user to the OAuth provider, while the `user` method will examine the incoming request and retrieve the user's information from the provider after they have approved the authentication request.

<a name="authentication-and-storage"></a>
### Authentication and Storage

Once the user has been retrieved from the OAuth provider, you may determine if the user exists in your application's database and [authenticate the user](/docs/{{version}}/authentication#authenticate-a-user-instance). If the user does not exist in your application's database, you will typically create a new record in your database to represent the user:

```php
use App\Models\User;
use Hypervel\Support\Facades\Auth;
use Hypervel\Socialite\Socialite;

Route::get('/auth/callback', function () {
    $githubUser = Socialite::driver('github')->user();

    $user = User::updateOrCreate([
        'github_id' => $githubUser->id,
    ], [
        'name' => $githubUser->name,
        'email' => $githubUser->email,
        'github_token' => $githubUser->token,
        'github_refresh_token' => $githubUser->refreshToken,
    ]);

    Auth::login($user);

    return redirect('/dashboard');
});
```

> [!NOTE]
> For more information regarding what user information is available from specific OAuth providers, please consult the documentation on [retrieving user details](#retrieving-user-details).

<a name="access-scopes"></a>
### Access Scopes

Before redirecting the user, you may use the `scopes` method to specify the "scopes" that should be included in the authentication request. This method will merge all previously specified scopes with the scopes that you specify:

```php
use Hypervel\Socialite\Socialite;

return Socialite::driver('github')
    ->scopes(['read:user', 'public_repo'])
    ->redirect();
```

You can overwrite all existing scopes on the authentication request using the `setScopes` method:

```php
return Socialite::driver('github')
    ->setScopes(['read:user', 'public_repo'])
    ->redirect();
```

<a name="slack-bot-scopes"></a>
### Slack Bot Scopes

Slack's API provides [different types of access tokens](https://api.slack.com/authentication/token-types), each with their own set of [permission scopes](https://api.slack.com/scopes). Socialite is compatible with both of the following Slack access tokens types:

<div class="content-list" markdown="1">

- Bot (prefixed with `xoxb-`)
- User (prefixed with `xoxp-`)

</div>

By default, the `slack` driver will generate a `user` token and invoking the driver's `user` method will return the user's details.

Bot tokens are primarily useful if your application will be sending notifications to external Slack workspaces that are owned by your application's users. To generate a bot token, invoke the `asBotUser` method before redirecting the user to Slack for authentication:

```php
return Socialite::driver('slack')
    ->asBotUser()
    ->setScopes(['chat:write', 'chat:write.public', 'chat:write.customize'])
    ->redirect();
```

In addition, you must invoke the `asBotUser` method before invoking the `user` method after Slack redirects the user back to your application after authentication:

```php
$user = Socialite::driver('slack')->asBotUser()->user();
```

When generating a bot token, the `user` method will still return a `Hypervel\Socialite\Two\User` instance; however, only the `token` property will be hydrated. This token may be stored in order to [send notifications to the authenticated user's Slack workspaces](/docs/{{version}}/notifications#notifying-external-slack-workspaces).

<a name="optional-parameters"></a>
### Optional Parameters

A number of OAuth providers support other optional parameters on the redirect request. To include any optional parameters in the request, call the `with` method with an associative array:

```php
use Hypervel\Socialite\Socialite;

return Socialite::driver('google')
    ->with(['hd' => 'example.com'])
    ->redirect();
```

> [!WARNING]
> When using the `with` method, be careful not to pass any reserved keywords such as `state` or `response_type`.

<a name="dynamic-provider-configuration"></a>
### Dynamic Provider Configuration

If your application resolves provider credentials at runtime, you may use the `setConfig` method to override the provider configuration for the current request:

```php
use App\Models\Tenant;
use Hypervel\Socialite\Socialite;

Route::get('/{tenant}/auth/github/redirect', function (Tenant $tenant) {
    return Socialite::driver('github')
        ->setConfig([
            'client_id' => $tenant->github_client_id,
            'client_secret' => $tenant->github_client_secret,
            'redirect' => route('github.callback', ['tenant' => $tenant]),
        ])
        ->redirect();
})->name('github.redirect');

Route::get('/{tenant}/auth/github/callback', function (Tenant $tenant) {
    $user = Socialite::driver('github')
        ->setConfig([
            'client_id' => $tenant->github_client_id,
            'client_secret' => $tenant->github_client_secret,
            'redirect' => route('github.callback', ['tenant' => $tenant]),
        ])
        ->user();

    // ...
})->name('github.callback');
```

Partial overrides preserve the provider's base configuration. For example, you may override only the client ID while continuing to use the configured client secret and redirect URL:

```php
return Socialite::driver('github')
    ->setConfig(['client_id' => $tenant->github_client_id])
    ->redirect();
```

The `setConfig` method stores the override in coroutine-local context, so it is safe to use on cached provider instances. OAuth 2.0 providers understand the `client_id`, `client_secret`, and `redirect` keys. Other keys are also available to custom providers through their provider configuration.

The redirect and callback are separate requests. If you use dynamic credentials, call `setConfig` in both routes so each request receives the same provider configuration.

If you only need to override the callback URL for the current request, you may use the `redirectUrl` method:

```php
return Socialite::driver('github')
    ->redirectUrl(route('github.callback', ['tenant' => $tenant]))
    ->redirect();
```

<a name="pkce"></a>
### PKCE

Some OAuth 2.0 providers support Proof Key for Code Exchange (PKCE). You may enable PKCE for the current authentication flow using the `enablePKCE` method:

```php
return Socialite::driver('github')
    ->enablePKCE()
    ->redirect();
```

You should also call `enablePKCE` before retrieving the user in the callback route:

```php
$user = Socialite::driver('github')
    ->enablePKCE()
    ->user();
```

The `x` driver enables PKCE by default.

<a name="custom-providers"></a>
### Custom Providers

You may register custom providers using the `extend` method. Custom providers should be registered in the `boot` method of one of your application's service providers.

<a name="custom-oauth2-providers"></a>
#### OAuth 2.0 Providers

For OAuth 2.0 providers, extend `Hypervel\Socialite\Two\AbstractProvider` and implement the `Hypervel\Socialite\Two\ProviderInterface` contract. The provider must define its authorization and token endpoints, retrieve the user using an access token, and map the provider's response to a Socialite user:

```php
namespace App\Socialite;

use GuzzleHttp\RequestOptions;
use Hypervel\Socialite\Two\AbstractProvider;
use Hypervel\Socialite\Two\ProviderInterface;
use Hypervel\Socialite\Two\User;
use SensitiveParameter;

class AcmeProvider extends AbstractProvider implements ProviderInterface
{
    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://acme.example.com/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://acme.example.com/oauth/token';
    }

    protected function getUserByToken(#[SensitiveParameter] string $token): array
    {
        $response = $this->getHttpClient()->get('https://acme.example.com/api/user', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $token],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ]);
    }
}
```

You may then use the `buildOAuth2Provider` method to build the provider from your `config/services.php` configuration:

```php
use App\Socialite\AcmeProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Socialite\Socialite;

public function boot(): void
{
    Socialite::extend('acme', function (Container $app) {
        return Socialite::buildOAuth2Provider(
            AcmeProvider::class,
            $app->make('config')->get('services.acme')
        );
    });
}
```

The `buildOAuth2Provider` method requires `client_id`, `client_secret`, and `redirect` configuration keys and will resolve relative redirect URLs to fully qualified URLs. Register custom drivers from a service provider's `boot` method. The manager and the provider's baseline configuration live for the worker lifetime, so `withConfig` is intended for this boot-time setup. Use `setConfig` for request-specific values.

If the provider returns a different token response shape, you may override the protected `parseAccessToken`, `parseRefreshToken`, `parseExpiresIn`, and `parseApprovedScopes` methods. A provider that needs the entire response to retrieve the user may override `getUserByTokenResponse` instead of replacing the `user` method:

```php
protected function getUserByTokenResponse(#[SensitiveParameter] array $response): array
{
    return $this->getUserByToken($response['credentials']['access_token']);
}
```

The protected `getRequest` method returns the request for the current authentication flow. Use it when a provider needs request data beyond the authorization code. The returned `User` instance exposes the complete token response through its `accessTokenResponseBody` property.

<a name="custom-openid-connect-providers"></a>
#### OpenID Connect Providers

For providers that publish OpenID Connect discovery metadata, extend `Hypervel\Socialite\Two\OpenIdProvider`. In addition to mapping the user response, your provider only needs to return the issuer's base URL:

```php
use Hypervel\Socialite\Two\OpenIdProvider;
use Hypervel\Socialite\Two\ProviderInterface;
use Hypervel\Socialite\Two\User;

class AcmeOpenIdProvider extends OpenIdProvider implements ProviderInterface
{
    protected function getBaseUrl(): string
    {
        return 'https://identity.acme.example.com';
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'],
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
        ]);
    }
}
```

The base provider discovers the authorization, token, UserInfo, and JSON Web Key Set endpoints. UserInfo requests use Bearer authorization, and signing keys are reused according to the provider's cache directives and refreshed once when a provider rotates them.

An ID token must include the configured client ID in its audience. If the provider also includes audiences for your APIs or other trusted services, list them using the `trusted_audiences` configuration option:

```php
'acme' => [
    'client_id' => env('ACME_CLIENT_ID'),
    'client_secret' => env('ACME_CLIENT_SECRET'),
    'redirect' => '/auth/acme/callback',
    'trusted_audiences' => ['https://api.acme.example.com'],
],
```

Generic OpenID Connect providers validate a one-time nonce stored in the session. The redirect and callback requests must therefore use the same session.

For other federated login protocols, extend `Hypervel\Socialite\AbstractProvider` and implement the `Hypervel\Socialite\Contracts\Provider` contract. Custom providers must provide `redirect` and `user` methods. The base provider includes coroutine-safe request handling, HTTP client handling, runtime configuration, stateless mode, and custom redirect parameters. When building a custom provider directly, call `withConfig` to seed the provider's baseline configuration:

```php
use App\Socialite\SamlProvider;
use Hypervel\Contracts\Container\Container;
use Hypervel\Socialite\Socialite;

Socialite::extend('saml', function (Container $app) {
    return (new SamlProvider($app->make('request')))
        ->withConfig($app->make('config')->get('services.saml'));
});
```

<a name="retrieving-user-details"></a>
## Retrieving User Details

After the user is redirected back to your application's authentication callback route, you may retrieve the user's details using Socialite's `user` method. The user object returned by the `user` method provides a variety of properties and methods you may use to store information about the user in your own database.

OAuth 2.0 providers will return a `Hypervel\Socialite\Two\User` instance:

```php
use Hypervel\Socialite\Socialite;

Route::get('/auth/callback', function () {
    $user = Socialite::driver('github')->user();

    $token = $user->token;
    $refreshToken = $user->refreshToken;
    $expiresIn = $user->expiresIn;
    $approvedScopes = $user->approvedScopes;
    $accessTokenResponse = $user->accessTokenResponseBody;

    $user->getId();
    $user->getNickname();
    $user->getName();
    $user->getEmail();
    $user->getAvatar();
});
```

The `accessTokenResponseBody` property contains the complete response returned by the token endpoint. It is empty when the user was retrieved directly using `userFromToken` because no token exchange occurred.

<a name="retrieving-user-details-from-a-token"></a>
#### Retrieving User Details From a Token

If you already have a valid access token for a user, you can retrieve their user details using Socialite's `userFromToken` method:

```php
use Hypervel\Socialite\Socialite;

$user = Socialite::driver('github')->userFromToken($token);
```

If you are using Facebook Limited Login via an iOS application, Facebook will return an OIDC token instead of an access token. Like an access token, the OIDC token can be provided to the `userFromToken` method in order to retrieve user details.

Google ID tokens may also be provided to the `google` driver's `userFromToken` method. Hypervel will verify the JWT token before returning the user details:

```php
$user = Socialite::driver('google')->userFromToken($idToken);
```

<a name="refreshing-access-tokens"></a>
#### Refreshing Access Tokens

For providers that issue refresh tokens, you may exchange a refresh token for a new access token using the `refreshToken` method:

```php
use Hypervel\Socialite\Socialite;

$token = Socialite::driver('google')->refreshToken($refreshToken);

$accessToken = $token->token;
$refreshToken = $token->refreshToken;
$expiresIn = $token->expiresIn;
$approvedScopes = $token->approvedScopes;
```

<a name="stateless-authentication"></a>
#### Stateless Authentication

The `stateless` method may be used to disable session state verification. This is useful when adding social authentication to a stateless API that does not utilize cookie based sessions:

```php
use Hypervel\Socialite\Socialite;

return Socialite::driver('google')->stateless()->user();
```

The `stateless` method disables OAuth state validation only. The `x` driver's PKCE verifier and generic OpenID Connect nonce validation are stored in the session, so those flows must keep session continuity between their redirect and callback requests.

<a name="testing"></a>
## Testing

Hypervel Socialite provides a convenient way to test OAuth authentication flows without making actual requests to OAuth providers. The `fake` method allows you to mock the OAuth provider's behavior and define the user data that should be returned.

<a name="faking-the-redirect"></a>
#### Faking the Redirect

To test that your application correctly redirects users to an OAuth provider, you may invoke the `fake` method before making a request to your redirect route. This will cause Socialite to return a redirect to a fake authorization URL instead of redirecting to the actual OAuth provider:

```php
use Hypervel\Socialite\Socialite;

test('user is redirected to github', function () {
    Socialite::fake('github');

    $response = $this->get('/auth/github/redirect');

    $response->assertRedirect();
});
```

<a name="faking-the-callback"></a>
#### Faking the Callback

To test your application's callback route, you may invoke the `fake` method and provide a `User` instance that should be returned when your application requests the user's details from the provider. The `User` instance may be created using the `fake` method:

```php
use Hypervel\Socialite\Socialite;
use Hypervel\Socialite\Two\User;

test('user can login with github', function () {
    Socialite::fake('github', User::fake([
        'id' => 'github-123',
        'name' => 'Jason Beggs',
        'email' => 'jason@example.com',
    ]));

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/dashboard');

    $this->assertDatabaseHas('users', [
        'name' => 'Jason Beggs',
        'email' => 'jason@example.com',
        'github_id' => 'github-123',
    ]);
});
```

By default, the `User` instance includes fake OAuth token values. If needed, you may override these values by passing additional attributes to the `fake` method:

```php
$fakeUser = User::fake([
    'id' => 'github-123',
    'name' => 'Jason Beggs',
    'email' => 'jason@example.com',
    'token' => 'fake-token',
    'refreshToken' => 'fake-refresh-token',
    'expiresIn' => 3600,
    'approvedScopes' => ['read', 'write'],
    'accessTokenResponseBody' => ['access_token' => 'fake-token'],
]);
```

You may also provide a closure to resolve the fake user when the provider's `user` method is called:

```php
Socialite::fake('github', function () {
    return User::fake([
        'id' => 'github-123',
        'name' => 'Jason Beggs',
        'email' => 'jason@example.com',
    ]);
});
```

The fake replaces the `Hypervel\Socialite\Contracts\Factory` binding used by the facade. Code that resolves `Hypervel\Socialite\SocialiteManager` directly continues to use the real manager, so application code should depend on the factory contract when it needs an injectable Socialite manager.
