# Responses
[[toc]]

## Creating Responses

#### Strings and Arrays

All routes and controllers should return a response to be sent back to the user's browser. Hypervel provides several different ways to return responses. The most basic response is returning a string from a route or controller. The framework will automatically convert the string into a full HTTP response:

```php
Route::get('/', function () {
    return 'Hello World';
});
```

In addition to returning strings from your routes and controllers, you may also return arrays. The framework will automatically convert the array into a JSON response:

```php
Route::get('/', function () {
    return [1, 2, 3];
});
```

::: tip
Did you know you can also return [Eloquent collections](/docs/eloquent-collections) from your routes or controllers? They will automatically be converted to JSON. Give it a shot!
:::

#### Response Objects

Typically, you won't just be returning simple strings or arrays from your route actions. Instead, you will be returning full `Hypervel\Http\Response` instances or [views](/docs/views).

Returning a full `Response` instance allows you to customize the response's HTTP status code and headers. A `Response` instance inherits from the `Hypervel\Http\Response` class, which provides a variety of methods for building HTTP responses:

```php
Route::get('/home', function () {
    return response('Hello World', 200)
        ->withHeader('Content-Type', 'text/plain');
});
```

::: warning
Unlike `Illuminate\Http\Response` in Laravel, `Hypervel\Http\Response` doesn't extend `Symfony\Component\HttpFoundation\Response`. Some methods available in Laravel's Response object might not be present in Hyperf's implementation.
:::

#### Eloquent Models and Collections

You may also return [Eloquent ORM](/docs/eloquent) models and collections directly from your routes and controllers. When you do, Hypervel will automatically convert the models and collections to JSON responses while respecting the model's [hidden attributes](/docs/eloquent-serialization#hiding-attributes-from-json):

```php
use App\Models\User;

Route::get('/user/{user}', function (User $user) {
    return $user;
});
```

### Attaching Headers to Responses

Keep in mind that most response methods are chainable, allowing for the fluent construction of response instances. For example, you may use the `header` method to add a series of headers to the response before sending it back to the user:

```php
return response($content)
    ->withHeader('Content-Type', $type)
    ->withHeader('X-Header-One', 'Header Value')
    ->withHeader('X-Header-Two', 'Header Value');
```

### Attaching Cookies to Responses

You may attach a cookie to an outgoing `Psr\Http\Message\ResponseInterface` instance using the `withCookie` method. You should pass the name, value, and the number of minutes the cookie should be considered valid to this method:

```php
use Hypervel\Support\Facades\Cookie;

return response('Hello World')
    ->withCookie(Cookie::make('name', 'value', $minutes));
);
```

The `withCookie` method also accepts a few more arguments which are used less frequently. Generally, these arguments have the same purpose and meaning as the arguments that would be given to PHP's native [setcookie](https://secure.php.net/manual/en/function.setcookie.php) method:

```php
use Hypervel\Support\Facades\Cookie;

return response('Hello World')
    ->withCookie(
        Cookie::make('name', 'value', $minutes, $path, $domain, $secure, $httpOnly)
    );
```

If you would like to ensure that a cookie is sent with the outgoing response but you do not yet have an instance of that response, you can use the `Cookie` facade to "queue" cookies for attachment to the response when it is sent. The `queue` method accepts the arguments needed to create a cookie instance. These cookies will be attached to the outgoing response before it is sent to the browser:

```php
use Hypervel\Support\Facades\Cookie;

Cookie::queue('name', 'value', $minutes);
```

::: note
You need to enable `Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class` middleware in your `app/Http/Kernel.php` file before using this feature.

#### Generating Cookie Instances

If you would like to generate a `Hypervel\Cookie\Cookie` instance that can be attached to a response instance at a later time, you may use the global `cookie` helper. This cookie will not be sent back to the client unless it is attached to a response instance:

```php
$cookie = cookie('name', 'value', $minutes);

return response('Hello World')->withCookie($cookie);
```

#### Expiring Cookies Early

You may use the `Cookie` facade's `expire` method to expire a cookie:

```php
use Hypervel\Support\Facades\Cookie;

Cookie::expire('name');
```

## Redirects

Redirect responses are instances of the `Psr\Http\Message\ResponseInterface` class, and contain the proper headers needed to redirect the user to another URL. There are several ways to generate a redirect response. The simplest method is to use the global `redirect` helper:

```php
Route::get('/dashboard', function () {
    return redirect('home/dashboard');
});
```

## Other Response Types

The `response` helper may be used to generate other types of response instances. When the `response` helper is called without arguments, an implementation of the `Hypervel\Http\Contracts\ResponseContract` [contract](/docs/contracts) is returned. This contract provides several helpful methods for generating responses.

### View Responses

If you need control over the response's status and headers but also need to return a [view](/docs/views) as the response's content, you should use the `view` method:

```php
return response()
    ->view('hello', $data, 200)
    ->withHeader('Content-Type', $type);
```

Of course, if you do not need to pass a custom HTTP status code or custom headers, you may use the global `view` helper function.

### JSON Responses

The `json` method will automatically set the `Content-Type` header to `application/json`, as well as convert the given array to JSON using the `json_encode` PHP function:

```php
return response()->json([
    'name' => 'Abigail',
    'state' => 'CA',
], 200, ['X-Header-One' => 'Header Value']);
```

### File Downloads

The `download` method may be used to generate a response that forces the user's browser to download the file at the given path. The `download` method accepts a filename as the second argument to the method, which will determine the filename that is seen by the user downloading the file. Finally, you may pass an array of HTTP headers as the third argument to the method:

```php
return response()->download($pathToFile);

return response()->download($pathToFile, $name);
```

#### Streamed Downloads

Sometimes you may wish to turn the string response of a given operation into a downloadable response without having to write the contents of the operation to disk. You may use the `streamDownload` method in this scenario. This method accepts a callback, filename, and an optional array of headers as its arguments:

```php
use App\Services\GitHub;

return response()->streamDownload(function () {
    return GitHub::api('repo')
        ->contents()
        ->readme('laravel', 'laravel')['contents'];
}, 'laravel-readme.md');
```

In large data processing, you should chunk your output to avoid memory overflow. In this case, you can use the `write` method to write the data to the response:

```php
use Hypervel\Http\StreamOutput;

return response()->streamDownload(function (StreamOutput $output) {
    $response->write('large-data-chunk-1');
    // ...
    $response->write('large-data-chunk-5');
}, 'large-data.txt', $headers);
```

#### Server Side Events

The `stream` method may be used to stream a response to the client. This method is useful for streaming large data or for implementing server-side events. The `stream` method accepts a callback as its first argument, which will be called repeatedly to stream the response to the client. The callback will receive the `StreamOutput` instance as its only argument:

```php
use Hypervel\Http\StreamOutput;

return response()->stream(function (StreamOutput $output) {
    $output->write('Hello World');
    // ...
    $output->write('Hypervel is awesome!');
}, $headers);
```

### File Responses

The `file` method may be used to display a file, such as an image or PDF, directly in the user's browser instead of initiating a download. This method accepts the absolute path to the file:

```php
return response()->file($pathToFile);
```

## Response Macros

If you would like to define a custom response that you can re-use in a variety of your routes and controllers, you may use the `macro` method on the `Response` facade. Typically, you should call this method from the `boot` method of one of your application's [service providers](/docs/providers), such as the `App\Providers\AppServiceProvider` service provider:

```php
<?php

namespace App\Providers;

use Hypervel\Support\Facades\Response;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Response::macro('caps', function (string $value) {
            return Response::make(strtoupper($value));
        });
    }
}
```

The `macro` function accepts a name as its first argument and a closure as its second argument. The macro's closure will be executed when calling the macro name from a `ResponseFactory` implementation or the `response` helper:

```php
return response()->caps('foo');
```