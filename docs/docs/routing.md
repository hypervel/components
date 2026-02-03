# Routing
[[toc]]

## Basic Routing

The most basic Hypervel routes accept a URI and a closure, providing a very simple and expressive method of defining routes and behavior without complicated routing configuration files:

```php
use Hypervel\Support\Facades\Route;

Route::get('/greeting', function () {
    return 'Hello World';
});
```

::: important
Hypervel uses `nikic/fast-route` as the core routing component. And the usage of Hypervel's routing system is similar to routing in Lumen framework.
:::

#### The Default Route Files

All Hypervel routes are defined in your route files, which are located in the `routes` directory. These files are automatically loaded by your application's `App\Providers\RouteServiceProvider`. The `routes/web.php` file defines routes that are for your web interface. These routes are assigned the `web` middleware group. The routes in `routes/api.php` are stateless and are assigned the `api` middleware group.

For most applications, you will begin by defining routes in your `routes/web.php` file. The routes defined in `routes/web.php` may be accessed by entering the defined route's URL in your browser. For example, you may access the following route by navigating to `http://example.com/user` in your browser:

```php
use App\Http\Controllers\UserController;

Route::get('/user', [UserController::class, 'index']);
```

Routes defined in the `routes/api.php` file are nested within a route group by the `RouteServiceProvider`. Within this group, the `/api` URI prefix is automatically applied so you do not need to manually apply it to every route in the file. You may modify the prefix and other route group options by modifying your `RouteServiceProvider` class.

#### Available Router Methods

The router allows you to register routes that respond to any HTTP verb:

```php
Route::get($uri, $callback, $options);
Route::post($uri, $callback, $options);
Route::put($uri, $callback, $options);
Route::patch($uri, $callback, $options);
Route::delete($uri, $callback, $options);
Route::options($uri, $callback, $options);
```

Sometimes you may need to register a route that responds to multiple HTTP verbs. You may do so using the `match` method. Or, you may even register a route that responds to all HTTP verbs using the `any` method:

```php
Route::match(['get', 'post'], '/', function () {
    // ...
});

Route::any('/', function () {
    // ...
});
```

::: important
When defining multiple routes that share the same URI, routes using the `get`, `post`, `put`, `patch`, `delete`, and `options` methods should be defined before routes using the `any` and `match` methods. This ensures the incoming request is matched with the correct route.
:::

#### Dependency Injection

You may type-hint any dependencies required by your route in your route's callback signature. The declared dependencies will automatically be resolved and injected into the callback by the Laravel [service container](/docs/container). For example, you may type-hint the `Hypervel\Http\Request` class to have the current HTTP request automatically injected into your route callback:

```php
use Hypervel\Http\Request;

Route::get('/users', function (Request $request) {
    // ...
});
```

### The Route List

The `route:list` Artisan command can easily provide an overview of all of the routes that are defined by your application:

```shell
php artisan route:list
```

You may also instruct Hypervel to only show routes that begin with a given URI:

```shell
php artisan route:list --path=api
```

## Route Parameters

### Required Parameters

Sometimes you will need to capture segments of the URI within your route. For example, you may need to capture a user's ID from the URL. You may do so by defining route parameters:

```php
Route::get('/user/{id}', function (string $id) {
    return 'User '.$id;
});
```

You may define as many route parameters as required by your route:

```php
Route::get('/posts/{post}/comments/{comment}', function (string $postId, string $commentId) {
    // ...
});
```

Route parameters are always encased within "curly" braces. The parameters will be passed into your route's Closure when the route is executed.

::: warning
Route parameters cannot contain the `-` character. Use an underscore (`_`) instead.
:::

#### Parameters and Dependency Injection

If your route has dependencies that you would like the Hypervel service container to automatically inject into your route's callback, you should list your route parameters after your dependencies:

```php
use Hypervel\Http\Request;

Route::get('/user/{id}', function (Request $request, string $id) {
    return 'User '.$id;
});
```

### Optional Parameters

You may define optional route parameters by enclosing part of the route URI definition in `[...]`. So, for example, `/foo[bar]` will match both `/foo` and `/foobar`. Optional parameters are only supported in a trailing position of the URI. In other words, you may not place an optional parameter in the middle of a route definition:

```php
Route::get('user[/{name}]', function ($name = null) {
    return $name;
});
```

### Regular Expression Constraints

You may constrain the format of your route parameters using the `where` method on a route instance. The `where` method accepts the name of the parameter and a regular expression defining how the parameter should be constrained:

```php
Route::get('user/{name:[A-Za-z]+}', function ($name) {
    //
});
```

## Named Routes

Named routes allow the convenient generation of URLs or redirects for specific routes. You may specify a name for a route using the as array key when defining the route:

```php
Route::get('profile', ['as' => 'profile', function () {
    //
}]);
```

You may also specify route names for controller actions:

```php
Route::get('profile', [
    'as' => 'profile', 'uses' => 'UserController@showProfile'
]);
```

::: important
Route names should always be unique.
:::

#### Generating URLs to Named Routes

Once you have assigned a name to a given route, you may use the route's name when generating URLs or redirects via Laravel's `route` and `redirect` helper functions:

```php
// Generating URLs...
$url = route('profile');
```

If the named route defines parameters, you may pass the parameters as the second argument to the `route` function. The given parameters will automatically be inserted into the URL in their correct positions:

```php
Route::get('user/{id}/profile', ['as' => 'profile', function ($id) {
    //
}]);

$url = route('profile', ['id' => 1]);
```

## Route Groups

Route groups allow you to share route attributes, such as middleware or namespaces, across a large number of routes without needing to define those attributes on each individual route. Shared attributes are specified in an array format as the first parameter to the `Route::group` method.

To learn more about route groups, we'll walk through several common use-cases for the feature.

### Middleware

To assign [middleware](/docs/middleware) to all routes within a group, you may use the `middleware` method before defining the group. Middleware are executed in the order they are listed in the array:

```php
Route::group('api', function () {
    Route::get('/', function () {
        // Uses Auth Middleware
    });

    Route::get('user/profile', function () {
        // Uses Auth Middleware
    });
}, ['middleware' => 'auth']);
```

### Namespaces

Another common use-case for route groups is assigning the same PHP namespace to a group of controllers. You may use the `namespace` parameter in your group attribute array to specify the namespace for all controllers within the group:

```php
Route::group('/api', function () {
    Route::get('/', function () {
        // Using The "App\Http\Controllers\Admin" Namespace...
    });
}, ['namespace' => 'App\Http\Controllers\Admin']);
```

### Route Prefixes

The route in the group definition will serve as prefix for nested routes with a given URI. For example, you may want to prefix all route URIs within the group with `admin`:

```php
Route::group('/admin', function () {
    Route::get('/users', function () {
         // Matches The "/admin/users" URL
    });
});
```

You can also add `prefix` option to the routes:

```php
Route::get('users', function () {
    // Matches The "/api/users" URL
}, ['prefix' => 'api']);

Route::group('/admin', function () {
    Route::get('/users', function () {
         // Matches The "/api/admin/users" URL
    });
}, ['prefix' => 'api']);
```

## Route Model Binding

When injecting a model ID to a route or controller action, you will often query the database to retrieve the model that corresponds to that ID. Hypervel route model binding provides a convenient way to automatically inject the model instances directly into your routes. For example, instead of injecting a user's ID, you can inject the entire `User` model instance that matches the given ID.

### Implicit Binding

Hypervel automatically resolves Eloquent models defined in routes or controller actions whose type-hinted variable names match a route segment name. For example:

```php
use App\Models\User;

Route::get('/users/{user}', function (User $user) {
    return $user->email;
});
```

Since the `$user` variable is type-hinted as the `App\Models\User` Eloquent model and the variable name matches the `{user}` URI segment, Hypervel will automatically inject the model instance that has an ID matching the corresponding value from the request URI. If a matching model instance is not found in the database, a 404 HTTP response will automatically be generated.

Of course, implicit binding is also possible when using controller methods. Again, note the `{user}` URI segment matches the `$user` variable in the controller which contains an `App\Models\User` type-hint:

```php
use App\Http\Controllers\UserController;
use App\Models\User;

// Route definition...
Route::get('/users/{user}', [UserController::class, 'show']);

// Controller method definition...
public function show(User $user)
{
    return view('user.profile', ['user' => $user]);
}
```

::: info
This feature is provided via `Hypervel\Router\Middleware\SubstituteBindings`, and it's disabled by default. You can extend this middleware to customize your resolving behaviors.
:::

### Implicit Enum Binding

PHP 8.1 introduced support for [Enums](https://www.php.net/manual/en/language.enumerations.backed.php). To complement this feature, Laravel allows you to type-hint a [string-backed Enum](https://www.php.net/manual/en/language.enumerations.backed.php) on your route definition and Laravel will only invoke the route if that route segment corresponds to a valid Enum value. Otherwise, a 404 HTTP response will be returned automatically. For example, given the following Enum:

```php
<?php

namespace App\Enums;

enum Category: string
{
    case Fruits = 'fruits';
    case People = 'people';
}
```

You may define a route that will only be invoked if the `{category}` route segment is `fruits` or `people`. Otherwise, Laravel will return a 404 HTTP response:

```php
use App\Enums\Category;
use Hypervel\Support\Facades\Route;

Route::get('/categories/{category}', function (Category $category) {
    return $category->value;
});
```

### Explicit Binding

You are not required to use Hypervel's implicit, convention based model resolution in order to use model binding. You can also explicitly define how route parameters correspond to models. To register an explicit binding, use the router's `model` method to specify the class for a given parameter. You should define your explicit model bindings at the beginning of the `boot` method of your `AppServiceProvider` class:

```php
use App\Models\User;
use Hypervel\Support\Facades\Route;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Route::model('user', User::class);
}
```

Next, define a route that contains a `{user}` parameter:

```php
use App\Models\User;

Route::get('/users/{user}', function (User $user) {
    // ...
});
```

Since we have bound all `{user}` parameters to the `App\Models\User` model, an instance of that class will be injected into the route. So, for example, a request to `users/1` will inject the `User` instance from the database which has an ID of `1`.

If a matching model instance is not found in the database, a 404 HTTP response will be automatically generated.

#### Customizing the Resolution Logic

If you wish to define your own model binding resolution logic, you may use the `Route::bind` method. The closure you pass to the `bind` method will receive the value of the URI segment and should return the instance of the class that should be injected into the route. Again, this customization should take place in the `boot` method of your application's `AppServiceProvider`:

```php
use App\Models\User;
use Hypervel\Support\Facades\Route;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Route::bind('user', function (string $value) {
        return User::where('name', $value)->firstOrFail();
    });
}
```

Alternatively, you may override the `resolveRouteBinding` method on your Eloquent model. This method will receive the value of the URI segment and should return the instance of the class that should be injected into the route:

```php
/**
 * Retrieve the model for a bound value.
 *
 * @param  mixed  $value
 * @param  string|null  $field
 * @return \Hypervel\Database\Eloquent\Model|null
 */
public function resolveRouteBinding($value, $field = null)
{
    return $this->where('name', $value)->firstOrFail();
}
```

## Throttling With Redis

Typically, the `throttle` middleware is mapped to the `Hypervel\Router\Middleware\ThrottleRequests` class. This mapping is defined in your application's HTTP kernel (`App\Http\Kernel`).

```php
'throttle' => \Hypervel\Router\Middleware\ThrottleRequests::class,
```

## Cross-Origin Resource Sharing (CORS)

Hypervel can automatically respond to CORS `OPTIONS` HTTP requests with values that you configure. The `OPTIONS` requests will automatically be handled by the `HandleCors` [middleware]((/docs/middleware)) that is automatically included in your application's global middleware stack.

Sometimes, you may need to customize the CORS configuration values for your application. You may do so by publishing the `cors` configuration file using the `config:publish` Artisan command:

```shell
php artisan vendor:publish hypervel/http
```

This command will place a `cors.php` configuration file within your application's `config` directory.

::: note
For more information on CORS and CORS headers, please consult the [MDN web documentation on CORS.](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS#The_HTTP_response_headers)
:::