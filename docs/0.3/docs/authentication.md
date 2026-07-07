# Authentication
[[toc]]

## Introduction

Many web applications provide a way for their users to authenticate with the application and "login". Implementing this feature in web applications can be a complex and potentially risky endeavor. For this reason, Hypervel strives to give you the tools you need to implement authentication quickly, securely, and easily.

At its core, Hypervel's authentication facilities are made up of "guards" and "providers". Guards define how users are authenticated for each request. For example, Hypervel ships with a `session` guard which maintains state using session storage and cookies.

Providers define how users are retrieved from your persistent storage. Hypervel ships with support for retrieving users using [Eloquent](/docs/eloquent) and the database query builder. However, you are free to define additional providers as needed for your application.

Your application's authentication configuration file is located at `config/auth.php`. This file contains several well-documented options for tweaking the behavior of Hypervel's authentication services.

::: note
Guards and providers should not be confused with "roles" and "permissions". To learn more about authorizing user actions via permissions, please refer to the [authorization](/docs/authorization) documentation.
:::

### Database Considerations

By default, Hypervel includes an `App\Models\User` [Eloquent model](/docs/eloquent) in your `app/Models` directory. This model may be used with the default Eloquent authentication driver.

If your application is not using Eloquent, you may use the `database` authentication provider which uses the Hypervel query builder.

When building the database schema for the `App\Models\User` model, make sure the password column is at least 60 characters in length. Of course, the `users` table migration that is included in new Hypervel applications already creates a column that exceeds this length.

> `remember_token` is not implemented in Hypervel by default.

## Authentication Quickstart

### Retrieving the Authenticated User

You will often need to interact with the currently authenticated user. While handling an incoming request, you may access the authenticated user via the `Auth` facade's `user` method:

```php
use Hypervel\Support\Facades\Auth;

// Retrieve the currently authenticated user...
$user = Auth::user();

// Retrieve the currently authenticated user's ID...
$id = Auth::id();
```

Alternatively, once a user is authenticated, you may access the authenticated user via an `Hypervel\Http\Request` instance. Remember, type-hinted classes will automatically be injected into your controller methods. By type-hinting the `Hypervel\Http\Request` object, you may gain convenient access to the authenticated user from any controller method in your application via the request's `user` method:

```php
<?php

namespace App\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Hypervel\Http\Request;

class FlightController extends Controller
{
    /**
     * Update the flight information for an existing flight.
     */
    public function update(Request $request): ResponseInterface
    {
        $user = $request->user();

        // ...

        return redirect('/flights');
    }
}
```

#### Determining if the Current User is Authenticated

To determine if the user making the incoming HTTP request is authenticated, you may use the `check` method on the `Auth` facade. This method will return `true` if the user is authenticated:

```php
use Hypervel\Support\Facades\Auth;

if (Auth::check()) {
    // The user is logged in...
}
```

::: note
Even though it is possible to determine if a user is authenticated using the `check` method, you will typically use a middleware to verify that the user is authenticated before allowing the user access to certain routes / controllers. To learn more about this, check out the documentation on [protecting routes](/docs/authentication#protecting-routes).
:::

### Protecting Routes

[Route middleware](/docs/middleware) can be used to only allow authenticated users to access a given route. Hypervel ships with an `auth` middleware, which is a [middleware alias](/docs/middleware#middleware-aliases) for the `Hypervel\Auth\Middleware\Authenticate` class. Since this middleware is already aliased internally by Hypervel, all you need to do is attach the middleware to a route definition:

```php
Route::get('/flights', function () {
    // Only authenticated users may access this route...
}, ['middleware' => 'auth']);
```

#### Handling Unauthenticated Users

When the `auth` middleware detects an unauthenticated user, it will throw `Hypervel\Auth\AuthenticationException`. You may override `unauthenticated` function in your exception handler and customize your next steps, such as redirecting user to another route.

#### Specifying a Guard

When attaching the `auth` middleware to a route, you may also specify which "guard" should be used to authenticate the user. The guard specified should correspond to one of the keys in the `guards` array of your `auth.php` configuration file:

```php
Route::get('/flights', function () {
    // Only authenticated users may access this route...
}, ['middleware' => 'auth:admin']);
```

## Manually Authenticating Users

We will access Hypervel's authentication services via the `Auth` [facade](/docs/facades), so we'll need to make sure to import the `Auth` facade at the top of the class. Next, let's check out the `attempt` method. The `attempt` method is normally used to handle authentication attempts from your application's "login" form. If authentication is successful, you should regenerate the user's [session](/docs/session) to prevent [session fixation](https://en.wikipedia.org/wiki/Session_fixation):

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Hypervel\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Handle an authentication attempt.
     */
    public function authenticate(Request $request): ResponseInterface
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            return redirect('/dashboard');
        }

        return redirect(
            url()->previous()
        );
    }
}
```

The `attempt` method accepts an array of key / value pairs as its first argument. The values in the array will be used to find the user in your database table. So, in the example above, the user will be retrieved by the value of the `email` column. If the user is found, the hashed password stored in the database will be compared with the `password` value passed to the method via the array. You should not hash the incoming request's `password` value, since the framework will automatically hash the value before comparing it to the hashed password in the database. An authenticated session will be started for the user if the two hashed passwords match.

Remember, Hypervel's authentication services will retrieve users from your database based on your authentication guard's "provider" configuration. In the default `config/auth.php` configuration file, the Eloquent user provider is specified and it is instructed to use the `App\Models\User` model when retrieving users. You may change these values within your configuration file based on the needs of your application.

The `attempt` method will return `true` if authentication was successful. Otherwise, `false` will be returned.

#### Specifying Additional Conditions

If you wish, you may also add extra query conditions to the authentication query in addition to the user's email and password. To accomplish this, we may simply add the query conditions to the array passed to the `attempt` method. For example, we may verify that the user is marked as "active":

```php
if (Auth::attempt(['email' => $email, 'password' => $password, 'active' => 1])) {
    // Authentication was successful...
}
```

For complex query conditions, you may provide a closure in your array of credentials. This closure will be invoked with the query instance, allowing you to customize the query based on your application's needs:

```php
use Hyperf\Database\Model\Builder;

if (Auth::attempt([
    'email' => $email,
    'password' => $password,
    fn (Builder $query) => $query->has('activeSubscription'),
])) {
    // Authentication was successful...
}
```

::: warning
In these examples, `email` is not a required option, it is merely used as an example. You should use whatever column name corresponds to a "username" in your database table.
:::

The `attemptWhen` method, which receives a closure as its second argument, may be used to perform more extensive inspection of the potential user before actually authenticating the user. The closure receives the potential user and should return `true` or `false` to indicate if the user may be authenticated:

```php
if (Auth::attemptWhen([
    'email' => $email,
    'password' => $password,
], function (User $user) {
    return $user->isNotBanned();
})) {
    // Authentication was successful...
}
```

#### Accessing Specific Guard Instances

Via the `Auth` facade's `guard` method, you may specify which guard instance you would like to utilize when authenticating the user. This allows you to manage authentication for separate parts of your application using entirely separate authenticatable models or user tables.

The guard name passed to the `guard` method should correspond to one of the guards configured in your `auth.php` configuration file:

```php
if (Auth::guard('admin')->attempt($credentials)) {
    // ...
}
```

### Other Authentication Methods

#### Authenticate a User Instance

If you need to set an existing user instance as the currently authenticated user, you may pass the user instance to the `Auth` facade's `login` method. The given user instance must be an implementation of the `Hypervel\Auth\Contracts\Authenticatable` [contract](/docs/contracts). The `App\Models\User` model included with Hypervel already implements this interface. This method of authentication is useful when you already have a valid user instance, such as directly after a user registers with your application:

```php
use Hypervel\Support\Facades\Auth;

Auth::login($user);
```

You may pass a boolean value as the second argument to the `login` method. This value indicates if "remember me" functionality is desired for the authenticated session. Remember, this means that the session will be authenticated indefinitely or until the user manually logs out of the application:

```php
Auth::login($user, $remember = true);
```

If needed, you may specify an authentication guard before calling the `login` method:

```php
Auth::guard('admin')->login($user);
```

#### Authenticate a User by ID

To authenticate a user using their database record's primary key, you may use the `loginUsingId` method. This method accepts the primary key of the user you wish to authenticate:

```php
Auth::loginUsingId(1);
```

#### Authenticate a User Once

You may use the `once` method to authenticate a user with the application for a single request. No sessions or cookies will be utilized when calling this method:

```php
if (Auth::once($credentials)) {
    // ...
}
```

## Logging Out

To manually log users out of your application, you may use the `logout` method provided by the `Auth` facade. This will remove the authentication information from the user's session so that subsequent requests are not authenticated.

In addition to calling the `logout` method, it is recommended that you invalidate the user's session and regenerate their [CSRF token](/docs/csrf). After logging the user out, you would typically redirect the user to the root of your application:

```php
use Hypervel\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Hypervel\Support\Facades\Auth;

/**
 * Log the user out of the application.
 */
public function logout(Request $request): ResponseInterface
{
    Auth::logout();

    $request->session()->invalidate();

    $request->session()->regenerateToken();

    return redirect('/');
}
```

## Password Confirmation

While building your application, you may occasionally have actions that should require the user to confirm their password before the action is performed or before the user is redirected to a sensitive area of the application. Hypervel includes built-in middleware to make this process a breeze. Implementing this feature will require you to define two routes: one route to display a view asking the user to confirm their password and another route to confirm that the password is valid and redirect the user to their intended destination.

### Configuration

After confirming their password, a user will not be asked to confirm their password again for three hours. However, you may configure the length of time before the user is re-prompted for their password by changing the value of the `password_timeout` configuration value within your application's `config/auth.php` configuration file.

### Routing

#### The Password Confirmation Form

First, we will define a route to display a view that requests the user to confirm their password:

```php
Route::get('/confirm-password', function () {
    return view('auth.confirm-password');
}, ['middleware' => 'auth', 'name' => 'password.confirm']);
```

As you might expect, the view that is returned by this route should have a form containing a `password` field. In addition, feel free to include text within the view that explains that the user is entering a protected area of the application and must confirm their password.

#### Confirming the Password

Next, we will define a route that will handle the form request from the "confirm password" view. This route will be responsible for validating the password:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Hash;

Route::post('/confirm-password', function (Request $request) {
    if (! Hash::check($request->password, $request->user()->password)) {
        // logics for handling unauthorized user
    }

    // logics for passing confirmation
})->middleware(['auth', 'throttle:6,1']);
```

## Adding Custom Guards

You may define your own authentication guards using the `extend` method on the `Auth` facade. You should place your call to the `extend` method within a [service provider](/docs/providers). Since Hypervel already ships with an `AppServiceProvider`, we can place the code in that provider:

```php
<?php

namespace App\Providers;

use App\Services\Auth\JwtGuard;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // ...

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('jwt', function (Application $app, string $name, array $config) {
            // Return an instance of Hypervel\Auth\Contracts\Guard...

            return new JwtGuard(Auth::createUserProvider($config['provider']));
        });
    }
}
```

As you can see in the example above, the callback passed to the `extend` method should return an implementation of `Hypervel\Auth\Contracts\Guard`. This interface contains a few methods you will need to implement to define a custom guard. Once your custom guard has been defined, you may reference the guard in the `guards` configuration of your `auth.php` configuration file:

```php
'guards' => [
    'api' => [
        'driver' => 'jwt',
        'provider' => 'users',
    ],
],
```

### Closure Request Guards

The simplest way to implement a custom, HTTP request based authentication system is by using the `Auth::viaRequest` method. This method allows you to quickly define your authentication process using a single closure.

To get started, call the `Auth::viaRequest` method within the `boot` method of your application's `AppServiceProvider`. The `viaRequest` method accepts an authentication driver name as its first argument. This name can be any string that describes your custom guard. The second argument passed to the method should be a closure that receives the incoming HTTP request and returns a user instance or, if authentication fails, `null`:

```php
use App\Models\User;
use Hypervel\Support\Facades\Auth;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Auth::viaRequest('custom-token', function () {
        return User::where('token', (string) request()->token)->first();
    });
}
```

Once your custom authentication driver has been defined, you may configure it as a driver within the `guards` configuration of your `auth.php` configuration file:

```php
'guards' => [
    'api' => [
        'driver' => 'custom-token',
    ],
],
```

Finally, you may reference the guard when assigning the authentication middleware to a route:

```php
Route::group(function () {
    // ...
}, ['middleware' => 'auth:api']);
```

## Adding Custom User Providers

If you are not using a traditional relational database to store your users, you will need to extend Hypervel with your own authentication user provider. We will use the `provider` method on the `Auth` facade to define a custom user provider. The user provider resolver should return an implementation of `Hypervel\Auth\Contracts\UserProvider`:

```php
<?php

namespace App\Providers;

use App\Extensions\MongoUserProvider;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // ...

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('mongo', function (Application $app, array $config) {
            // Return an instance of Hypervel\Auth\Contracts\UserProvider...

            return new MongoUserProvider($app->make('mongo.connection'));
        });
    }
}
```

After you have registered the provider using the `provider` method, you may switch to the new user provider in your `auth.php` configuration file. First, define a `provider` that uses your new driver:

```php
'providers' => [
    'users' => [
        'driver' => 'mongo',
    ],
],
```

Finally, you may reference this provider in your `guards` configuration:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],
```

### The User Provider Contract

`Hypervel\Auth\Contracts\UserProvider` implementations are responsible for fetching an `Hypervel\Auth\Contracts\Authenticatable` implementation out of a persistent storage system, such as MySQL, MongoDB, etc. These two interfaces allow the Hypervel authentication mechanisms to continue functioning regardless of how the user data is stored or what type of class is used to represent the authenticated user:

Let's take a look at the `Hypervel\Auth\Contracts\UserProvider` contract:

```php
<?php

namespace Hypervel\Auth\Contracts;

interface UserProvider
{
    public function retrieveById($identifier): ?Authenticatable;
    public function retrieveByCredentials(array $credentials): ?Authenticatable;
    public function validateCredentials(Authenticatable $user, array $credentials): bool;
}
```

The `retrieveById` function typically receives a key representing the user, such as an auto-incrementing ID from a MySQL database. The `Authenticatable` implementation matching the ID should be retrieved and returned by the method.

The `retrieveByCredentials` method receives the array of credentials passed to the `Auth::attempt` method when attempting to authenticate with an application. The method should then "query" the underlying persistent storage for the user matching those credentials. Typically, this method will run a query with a "where" condition that searches for a user record with a "username" matching the value of `$credentials['username']`. The method should return an implementation of `Authenticatable`. **This method should not attempt to do any password validation or authentication.**

The `validateCredentials` method should compare the given `$user` with the `$credentials` to authenticate the user. For example, this method will typically use the `Hash::check` method to compare the value of `$user->getAuthPassword()` to the value of `$credentials['password']`. This method should return `true` or `false` indicating whether the password is valid.

### The Authenticatable Contract

Now that we have explored each of the methods on the `UserProvider`, let's take a look at the `Authenticatable` contract. Remember, user providers should return implementations of this interface from the `retrieveById` and `retrieveByCredentials` methods:

```php
<?php

namespace Hypervel\Auth\Contracts;

interface Authenticatable
{
    public function getAuthIdentifierName(): string
    public function getAuthIdentifier(): mixed;
    public function getAuthPassword(): string;
}
```

This interface is simple. The `getAuthIdentifierName` method should return the name of the "primary key" column for the user and the `getAuthIdentifier` method should return the "primary key" of the user. When using a MySQL back-end, this would likely be the auto-incrementing primary key assigned to the user record. The `getAuthPassword` method should return the user's hashed password.

This interface allows the authentication system to work with any "user" class, regardless of what ORM or storage abstraction layer you are using. By default, Hypervel includes an `App\Models\User` class in the `app/Models` directory which implements this interface.
