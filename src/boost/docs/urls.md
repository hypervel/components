# URL Generation

- [Introduction](#introduction)
- [The Basics](#the-basics)
    - [Generating URLs](#generating-urls)
    - [Accessing the Current URL](#accessing-the-current-url)
    - [Customizing URL Origins](#customizing-url-origins)
- [URLs for Named Routes](#urls-for-named-routes)
    - [Signed URLs](#signed-urls)
- [URLs for Controller Actions](#urls-for-controller-actions)
- [Fluent URI Objects](#fluent-uri-objects)
- [Default Values](#default-values)

<a name="introduction"></a>
## Introduction

Hypervel provides several helpers to assist you in generating URLs for your application. These helpers are primarily helpful when building links in your templates and API responses, or when generating redirect responses to another part of your application.

<a name="the-basics"></a>
## The Basics

<a name="generating-urls"></a>
### Generating URLs

The `url` helper may be used to generate arbitrary URLs for your application. The generated URL will automatically use the scheme (HTTP or HTTPS) and host from the current request being handled by the application:

```php
$post = App\Models\Post::find(1);

echo url("/posts/{$post->id}");

// http://example.com/posts/1
```

To generate a URL with query string parameters, you may use the `query` method:

```php
echo url()->query('/posts', ['search' => 'Hypervel']);

// https://example.com/posts?search=Hypervel

echo url()->query('/posts?sort=latest', ['search' => 'Hypervel']);

// http://example.com/posts?sort=latest&search=Hypervel
```

Providing query string parameters that already exist in the path will overwrite their existing value:

```php
echo url()->query('/posts?sort=latest', ['sort' => 'oldest']);

// http://example.com/posts?sort=oldest
```

Arrays of values may also be passed as query parameters. These values will be properly keyed and encoded in the generated URL:

```php
echo $url = url()->query('/posts', ['columns' => ['title', 'body']]);

// http://example.com/posts?columns%5B0%5D=title&columns%5B1%5D=body

echo urldecode($url);

// http://example.com/posts?columns[0]=title&columns[1]=body
```

<a name="accessing-the-current-url"></a>
### Accessing the Current URL

If no path is provided to the `url` helper, a `Hypervel\Routing\UrlGenerator` instance is returned, allowing you to access information about the current URL:

```php
// Get the current URL without the query string...
echo url()->current();

// Get the current URL including the query string...
echo url()->full();
```

Each of these methods may also be accessed via the `URL` [facade](/docs/{{version}}/facades):

```php
use Hypervel\Support\Facades\URL;

echo URL::current();
```

<a name="accessing-the-previous-url"></a>
#### Accessing the Previous URL

Sometimes it is helpful to know the previous URL that the user is visiting from. You can access the previous URL via the `url` helper's `previous` and `previousPath` methods:

```php
// Get the full URL for the previous request...
echo url()->previous();

// Get the path for the previous request...
echo url()->previousPath();
```

Or, via the [session](/docs/{{version}}/session), you may access the previous URL as a [fluent URI](#fluent-uri-objects) instance:

```php
use Hypervel\Http\Request;

Route::post('/users', function (Request $request) {
    $previousUri = $request->session()->previousUri();

    // ...
});
```

It is also possible to retrieve the route name for the previously visited URL via the session:

```php
$previousRoute = $request->session()->previousRoute();
```

<a name="customizing-url-origins"></a>
### Customizing URL Origins

By default, generated URLs use the scheme and host from the current request. If you need to override the origin used for generated URLs during the current request, you may use the `useOrigin` method:

```php
use Hypervel\Support\Facades\URL;

URL::useOrigin('https://tenant.example.com');
```

You may force all generated URLs to use a given scheme using the `forceScheme` method:

```php
URL::forceScheme('https');
```

To force HTTPS for all generated URLs, you may also set the `FORCE_HTTPS` environment variable to `true`.

If all generated asset URLs should use a separate origin, such as a CDN, configure the `ASSET_URL` environment variable:

```env
ASSET_URL=https://cdn.example.com
```

If you need to override the asset origin during the current request, you may use the `useAssetOrigin` method:

```php
URL::useAssetOrigin('https://cdn.example.com');
```

The `useOrigin` and `useAssetOrigin` methods are isolated to the current request, job, or command. The `forceScheme` method configures the URL generator and is typically called from a service provider.

If your application determines its URL origin from the current context, you may register an origin resolver in a service provider:

```php
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\URL;

URL::resolveOriginUsing(
    fn () => Context::get('application_origin'),
);
```

The resolver runs when Hypervel generates a URL. An origin set with `useOrigin` takes priority, followed by the resolver and then the current request. Routes that declare their own domain continue to use that domain.

The resolver does not apply to asset URLs. Use the `ASSET_URL` environment variable or `useAssetOrigin` method to customize asset origins.

Register the resolver only during application boot. The registration is shared by the worker and should read the current request, job, or command context when it runs.

<a name="urls-for-named-routes"></a>
## URLs for Named Routes

The `route` helper may be used to generate URLs to [named routes](/docs/{{version}}/routing#named-routes). Named routes allow you to generate URLs without being coupled to the actual URL defined on the route. Therefore, if the route's URL changes, no changes need to be made to your calls to the `route` function. For example, imagine your application contains a route defined like the following:

```php
Route::get('/post/{post}', function (Post $post) {
    // ...
})->name('post.show');
```

To generate a URL to this route, you may use the `route` helper like so:

```php
echo route('post.show', ['post' => 1]);

// http://example.com/post/1
```

Of course, the `route` helper may also be used to generate URLs for routes with multiple parameters:

```php
Route::get('/post/{post}/comment/{comment}', function (Post $post, Comment $comment) {
    // ...
})->name('comment.show');

echo route('comment.show', ['post' => 1, 'comment' => 3]);

// http://example.com/post/1/comment/3
```

Any additional array elements that do not correspond to the route's definition parameters will be added to the URL's query string:

```php
echo route('post.show', ['post' => 1, 'search' => 'rocket']);

// http://example.com/post/1?search=rocket
```

<a name="eloquent-models"></a>
#### Eloquent Models

You will often be generating URLs using the route key (typically the primary key) of [Eloquent models](/docs/{{version}}/eloquent). For this reason, you may pass Eloquent models as parameter values. The `route` helper will automatically extract the model's route key:

```php
echo route('post.show', ['post' => $post]);
```

<a name="signed-urls"></a>
### Signed URLs

Hypervel allows you to easily create "signed" URLs to named routes. These URLs have a "signature" hash appended to the query string which allows Hypervel to verify that the URL has not been modified since it was created. Signed URLs are especially useful for routes that are publicly accessible yet need a layer of protection against URL manipulation.

For example, you might use signed URLs to implement a public "unsubscribe" link that is emailed to your customers. To create a signed URL to a named route, use the `signedRoute` method of the `URL` facade:

```php
use Hypervel\Support\Facades\URL;

return URL::signedRoute('unsubscribe', ['user' => 1]);
```

You may exclude the domain from the signed URL hash by providing the `absolute` argument to the `signedRoute` method:

```php
return URL::signedRoute('unsubscribe', ['user' => 1], absolute: false);
```

If you would like to generate a temporary signed route URL that expires after a specified amount of time, you may use the `temporarySignedRoute` method. When Hypervel validates a temporary signed route URL, it will ensure that the expiration timestamp that is encoded into the signed URL has not elapsed:

```php
use Hypervel\Support\Facades\URL;

return URL::temporarySignedRoute(
    'unsubscribe', now()->plus(minutes: 30), ['user' => 1]
);
```

<a name="validating-signed-route-requests"></a>
#### Validating Signed Route Requests

To verify that an incoming request has a valid signature, you should call the `hasValidSignature` method on the incoming `Hypervel\Http\Request` instance:

```php
use Hypervel\Http\Request;

Route::get('/unsubscribe/{user}', function (Request $request) {
    if (! $request->hasValidSignature()) {
        abort(401);
    }

    // ...
})->name('unsubscribe');
```

Sometimes, you may need to allow your application's frontend to append data to a signed URL, such as when performing client-side pagination. Therefore, you can specify request query parameters that should be ignored when validating a signed URL using the `hasValidSignatureWhileIgnoring` method. Remember, ignoring parameters allows anyone to modify those parameters on the request:

```php
if (! $request->hasValidSignatureWhileIgnoring(['page', 'order'])) {
    abort(401);
}
```

You may also provide a closure to determine which query parameters should be ignored:

```php
if (! $request->hasValidSignatureWhileIgnoring(fn (string $parameter) => $parameter === 'page')) {
    abort(401);
}
```

If your signed URLs were generated without the domain in the URL hash, you should validate the request using the `hasValidRelativeSignature` or `hasValidRelativeSignatureWhileIgnoring` methods:

```php
if (! $request->hasValidRelativeSignature()) {
    abort(401);
}
```

Instead of validating signed URLs using the incoming request instance, you may assign the `signed` (`Hypervel\Routing\Middleware\ValidateSignature`) [middleware](/docs/{{version}}/middleware) to the route. If the incoming request does not have a valid signature, the middleware will automatically return a `403` HTTP response:

```php
Route::post('/unsubscribe/{user}', function (Request $request) {
    // ...
})->name('unsubscribe')->middleware('signed');
```

If your signed URLs do not include the domain in the URL hash, you should provide the `relative` argument to the middleware:

```php
Route::post('/unsubscribe/{user}', function (Request $request) {
    // ...
})->name('unsubscribe')->middleware('signed:relative');
```

If you prefer class-based middleware definitions, you may use the `absolute` and `relative` methods provided by the `ValidateSignature` middleware:

```php
use Hypervel\Routing\Middleware\ValidateSignature;

Route::post('/unsubscribe/{user}', function (Request $request) {
    // ...
})->name('unsubscribe')->middleware(ValidateSignature::relative());
```

Both methods accept query parameters that should be ignored when validating the signature:

```php
Route::post('/unsubscribe/{user}', function (Request $request) {
    // ...
})->name('unsubscribe')->middleware(ValidateSignature::absolute(['page', 'order']));
```

If your application always needs to ignore certain query parameters when validating signed URLs, you may call the `except` method from a service provider:

```php
use Hypervel\Routing\Middleware\ValidateSignature;

ValidateSignature::except(['page']);
```

The `except` method stores the ignored parameters for the worker lifetime, so it should be called during application boot, not from request-specific code.

<a name="responding-to-invalid-signed-routes"></a>
#### Responding to Invalid Signed Routes

When someone visits a signed URL that has expired, they will receive a generic error page for the `403` HTTP status code. However, you can customize this behavior by defining a custom "render" closure for the `InvalidSignatureException` exception in your application's `bootstrap/app.php` file:

```php
use Hypervel\Routing\Exceptions\InvalidSignatureException;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (InvalidSignatureException $e) {
        return response()->view('errors.link-expired', status: 403);
    });
})
```

<a name="urls-for-controller-actions"></a>
## URLs for Controller Actions

The `action` function generates a URL for the given controller action:

```php
use App\Http\Controllers\HomeController;

$url = action([HomeController::class, 'index']);
```

If the controller method accepts route parameters, you may pass an associative array of route parameters as the second argument to the function:

```php
$url = action([UserController::class, 'profile'], ['id' => 1]);
```

<a name="fluent-uri-objects"></a>
## Fluent URI Objects

Hypervel's `Uri` class provides a convenient and fluent interface for creating and manipulating URIs via objects. This class wraps the functionality provided by the underlying League URI package and integrates seamlessly with Hypervel's routing system.

You can create a `Uri` instance easily using static methods:

```php
use App\Http\Controllers\UserController;
use App\Http\Controllers\InvokableController;
use Hypervel\Support\Uri;

// Generate a URI instance from the given string...
$uri = Uri::of('https://example.com/path');

// Generate URI instances to paths, named routes, or controller actions...
$uri = Uri::to('/dashboard');
$uri = Uri::route('users.show', ['user' => 1]);
$uri = Uri::signedRoute('users.show', ['user' => 1]);
$uri = Uri::temporarySignedRoute('user.index', now()->plus(minutes: 5));
$uri = Uri::action([UserController::class, 'index']);
$uri = Uri::action(InvokableController::class);

// Generate a URI instance from the current request URL...
$uri = $request->uri();

// Generate a URI instance from the previous request URL...
$uri = $request->session()->previousUri();
```

The `uri` helper may also be used to create fluent URI instances from strings, named routes, or controller actions:

```php
use App\Http\Controllers\UserController;

$uri = uri('/dashboard');

$uri = uri('users.show', ['user' => 1]);

$uri = uri([UserController::class, 'index']);
```

Once you have a URI instance, you can fluently modify it:

```php
$uri = Uri::of('https://example.com')
    ->withScheme('http')
    ->withHost('test.com')
    ->withPort(8000)
    ->withPath('/users')
    ->withQuery(['page' => 2])
    ->withFragment('section-1');
```

For more information on working with fluent URI objects, consult the [URI documentation](/docs/{{version}}/helpers#uri).

<a name="default-values"></a>
## Default Values

For some applications, you may wish to specify request-wide default values for certain URL parameters. For example, imagine many of your routes define a `{locale}` parameter:

```php
Route::get('/{locale}/posts', function () {
    // ...
})->name('post.index');
```

It is cumbersome to always pass the `locale` every time you call the `route` helper. So, you may use the `URL::defaults` method to define a default value for this parameter that will always be applied during the current request. You may wish to call this method from a [route middleware](/docs/{{version}}/middleware#assigning-middleware-to-routes) so that you have access to the current request:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetDefaultLocaleForUrls
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Hypervel\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        URL::defaults(['locale' => $request->user()->locale]);

        return $next($request);
    }
}
```

Once the default value for the `locale` parameter has been set, you are no longer required to pass its value when generating URLs via the `route` helper.

If your application determines URL defaults from the current context, you may register a resolver in a service provider:

```php
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\URL;

URL::resolveDefaultsUsing(fn () => [
    'locale' => Context::get('locale'),
]);
```

Register the resolver only during application boot. It runs each time a route URL is generated and should read the current request, job, or command context.

You may also replace the defaults for the current request, job, or command using the `useDefaults` method:

```php
URL::useDefaults(['locale' => 'fr']);
```

Calling `useDefaults` again replaces the previous values. Pass `null` to clear them.

Defaults registered with `URL::defaults` are applied first, followed by resolved defaults and values set with `useDefaults`. Parameters passed directly to the `route` helper always take priority. During a request, `URL::defaults` merges values for that request. Outside a request, it changes the defaults for the entire worker, so jobs and commands should use `useDefaults` for temporary values.

<a name="url-defaults-middleware-priority"></a>
#### URL Defaults and Middleware Priority

Setting URL default values can interfere with Hypervel's handling of implicit model bindings. Therefore, you should [prioritize your middleware](/docs/{{version}}/middleware#sorting-middleware) that set URL defaults to be executed before Hypervel's own `SubstituteBindings` middleware. You can accomplish this using the `priority` middleware method in your application's `bootstrap/app.php` file:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->prependToPriorityList(
        before: \Hypervel\Routing\Middleware\SubstituteBindings::class,
        prepend: \App\Http\Middleware\SetDefaultLocaleForUrls::class,
    );
})
```
