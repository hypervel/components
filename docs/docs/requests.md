# Requests
[[toc]]

## Introduction

Hypervel's `Hypervel\Http\Request` class provides an object-oriented way to interact with the current HTTP request being handled by your application as well as retrieve the input, cookies, and files that were submitted with the request.

## Interacting With The Request

### Accessing the Request

To obtain an instance of the current HTTP request via dependency injection, you should type-hint the `Hypervel\Http\Request` class on your route closure or controller method. The incoming request instance will automatically be injected by the Hypervel [service container](/docs/container):

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Http\Request;

class UserController extends Controller
{
    /**
     * Store a new user.
     */
    public function store(Request $request): array
    {
        $name = $request->input('name');

        // Store the user...

        return ['success' => true];
    }
}
```

As mentioned, you may also type-hint the `Hypervel\Http\Request` class on a route closure. The service container will automatically inject the incoming request into the closure when it is executed:

```php
use Hypervel\Http\Request;

Route::get('/', function (Request $request) {
    // ...
});
```

::: info
Remember everything injected by container is singleton? Then injecting `Hypervel\Http\Request` is safe here? Yes,it is. Request itself is a proxy class which fetches the real request data from context.
:::

#### Dependency Injection and Route Parameters

If your controller method is also expecting input from a route parameter you should list your route parameters after your other dependencies. For example, if your route is defined like so:

```php
use App\Http\Controllers\UserController;

Route::put('/user/{id}', [UserController::class, 'update']);
```

You may still type-hint the `Hypervel\Http\Request` and access your `id` route parameter by defining your controller method as follows:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Http\Request;

class UserController extends Controller
{
    /**
     * Update the specified user.
     */
    public function update(Request $request, string $id): array
    {
        // Update the user...

        return ['success' => true];
    }
}
```

### Request Path, Host, and Method

The `Hypervel\Http\Request` instance provides a variety of methods for examining the incoming HTTP request. We will discuss a few of the most important methods below.

::: warning
Unlike `Illuminate\Http\Request` in Laravel, `Hypervel\Http\Request` doesn't extend `Symfony\Component\HttpFoundation\Request`. Some methods available in Laravel's Request object might not be present in Hypervel's implementation.
:::

#### Retrieving the Request Path

The `path` method returns the request's path information. So, if the incoming request is targeted at `http://example.com/foo/bar`, the `path` method will return `foo/bar`:

```php
$uri = $request->path();
```

#### Inspecting the Request Path / Route

The `is` method allows you to verify that the incoming request path matches a given pattern. You may use the `*` character as a wildcard when utilizing this method:

```php
if ($request->is('admin/*')) {
    // ...
}
```

#### Retrieving the Request URL

To retrieve the full URL for the incoming request you may use the `url` or `fullUrl` methods. The `url` method will return the URL without the query string, while the `fullUrl` method includes the query string:

```php
$url = $request->url();

$urlWithQueryString = $request->fullUrl();
```

If you would like to append query string data to the current URL, you may call the `fullUrlWithQuery` method. This method merges the given array of query string variables with the current query string:

```php
$request->fullUrlWithQuery(['type' => 'phone']);
```

If you would like to get the current URL without a given query string parameter, you may utilize the `fullUrlWithoutQuery` method:

```php
$request->fullUrlWithoutQuery(['type']);
```

#### Retrieving the Request Host

You may retrieve the "host" of the incoming request via the `host`, `httpHost`, and `schemeAndHttpHost` methods:

```php
$request->host();
$request->httpHost();
$request->schemeAndHttpHost();
```

#### Retrieving the Request Method

The `method` method will return the HTTP verb for the request. You may use the `isMethod` method to verify that the HTTP verb matches a given string:

```php
$method = $request->method();

if ($request->isMethod('post')) {
    // ...
}
```

### Request Headers

You may retrieve a request header from the `Hypervel\Http\Request` instance using the `header` method. If the header is not present on the request, `null` will be returned. However, the `header` method accepts an optional second argument that will be returned if the header is not present on the request:

```php
$value = $request->header('X-Header-Name');

$value = $request->header('X-Header-Name', 'default');
```

The `hasHeader` method may be used to determine if the request contains a given header:

```php
if ($request->hasHeader('X-Header-Name')) {
    // ...
}
```

For convenience, the `bearerToken` method may be used to retrieve a bearer token from the `Authorization` header. If no such header is present, an empty string will be returned:

```php
$token = $request->bearerToken();
```

### Content Negotiation

Hypervel provides several methods for inspecting the incoming request's requested content types via the `Accept` header. First, the `getAcceptableContentTypes` method will return an array containing all of the content types accepted by the request:

```php
$contentTypes = $request->getAcceptableContentTypes();
```

The `accepts` method accepts an array of content types and returns `true` if any of the content types are accepted by the request. Otherwise, `false` will be returned:

```php
if ($request->accepts(['text/html', 'application/json'])) {
    // ...
}
```

You may use the `prefers` method to determine which content type out of a given array of content types is most preferred by the request. If none of the provided content types are accepted by the request, `null` will be returned:

```php
$preferred = $request->prefers(['text/html', 'application/json']);
```

Since many applications only serve HTML or JSON, you may use the `expectsJson` method to quickly determine if the incoming request expects a JSON response:

```php
if ($request->expectsJson()) {
    // ...
}
```

### PSR-7 Requests

The [PSR-7 standard](https://www.php-fig.org/psr/psr-7/) specifies interfaces for HTTP messages, including requests and responses. Requests and responses in Hypervel adhere to the PSR-7 standard by default.

When injecting a request object, if it is declared as the PSR-7 standard interface `Psr\Http\Message\ServerRequestInterface`, Hypervel will automatically convert it to an equivalent `Hypervel\Http\Request` object. This `Hypervel\Http\Request` object implements the `Hyperf\HttpServer\Contract\RequestInterface`.

::: tip
You can use `Hypervel\Http\Request` for injection so that you can get the IDE's auto-completion reminder support for exclusive methods.
:::

## Input

### Retrieving Input

#### Retrieving All Input Data

You may retrieve all of the incoming request's input data as an `array` using the `all` method. This method may be used regardless of whether the incoming request is from an HTML form or is an XHR request:

```php
$input = $request->all();
```

Using the `collect` method, you may retrieve all of the incoming request's input data as a [collection](/docs/collections):

```php
$input = $request->collect();
```

The `collect` method also allows you to retrieve a subset of the incoming request's input as a collection:

```php
$request->collect('users')->each(function (string $user) {
    // ...
});
```

#### Retrieving an Input Value

Using a few simple methods, you may access all of the user input from your `Hypervel\Http\Request` instance without worrying about which HTTP verb was used for the request. Regardless of the HTTP verb, the `input` method may be used to retrieve user input:

```php
$name = $request->input('name');
```

You may pass a default value as the second argument to the `input` method. This value will be returned if the requested input value is not present on the request:

```php
$name = $request->input('name', 'Sally');
```

When working with forms that contain array inputs, use "dot" notation to access the arrays:

```php
$name = $request->input('products.0.name');

$names = $request->input('products.*.name');
```

#### Retrieving Input From the Query String

While the `input` method retrieves values from the entire request payload (including the query string), the `query` method will only retrieve values from the query string:

```php
$name = $request->query('name');
```

If the requested query string value data is not present, the second argument to this method will be returned:

```php
$name = $request->query('name', 'Helen');
```

You may call the `query` method without any arguments in order to retrieve all of the query string values as an associative array:

```php
$query = $request->query();
```

#### Retrieving JSON Input Values

When sending JSON requests to your application, you may access the JSON data via the `input` method as long as the `Content-Type` header of the request is properly set to `application/json`. You may even use "dot" syntax to retrieve values that are nested within JSON arrays / objects:

```php
$name = $request->input('user.name');
```

#### Retrieving Stringable Input Values

Instead of retrieving the request's input data as a primitive `string`, you may use the `string` method to retrieve the request data as an instance of [`Hypervel\Support\Str`](/docs/helpers#fluent-strings):

```php
$name = $request->string('name')->trim();
```

#### Retrieving Boolean Input Values

When dealing with HTML elements like checkboxes, your application may receive "truthy" values that are actually strings. For example, "true" or "on". For convenience, you may use the `boolean` method to retrieve these values as booleans. The `boolean` method returns `true` for 1, "1", true, "true", "on", and "yes". All other values will return `false`:

```php
$archived = $request->boolean('archived');
```

#### Retrieving Date Input Values

For convenience, input values containing dates / times may be retrieved as Carbon instances using the `date` method. If the request does not contain an input value with the given name, `null` will be returned:

```php
$birthday = $request->date('birthday');
```

The second and third arguments accepted by the `date` method may be used to specify the date's format and timezone, respectively:

```php
$elapsed = $request->date('elapsed', '!H:i', 'Europe/Madrid');
```

If the input value is present but has an invalid format, an `Carbon\Exceptions\InvalidFormatException` will be thrown; therefore, it is recommended that you validate the input before invoking the `date` method.

#### Retrieving Enum Input Values

Input values that correspond to [PHP enums](https://www.php.net/manual/en/language.types.enumerations.php) may also be retrieved from the request. If the request does not contain an input value with the given name or the enum does not have a backing value that matches the input value, `null` will be returned. The `enum` method accepts the name of the input value and the enum class as its first and second arguments:

```php
use App\Enums\Status;

$status = $request->enum('status', Status::class);
```

#### Request Context via Dynamic Properties

Unlike dynamic properties in Laravel's request, dynamic properties are used to store contexts. You can store and retrieve request contexts via dynamic properties like so:

```php
$request->name = 'Foo';

$request->name;
```

::: important
You won't get `name`'s value via `$request->input('name')`.
:::

#### Retrieving a Portion of the Input Data

If you need to retrieve a subset of the input data, you may use the `only` and `except` methods. Both of these methods accept a single `array` or a dynamic list of arguments:

```php
$input = $request->only(['username', 'password']);

$input = $request->only('username', 'password');

$input = $request->except(['credit_card']);

$input = $request->except('credit_card');
```

::: warning
The `only` method returns all of the key / value pairs that you request; however, it will not return key / value pairs that are not present on the request.
:::

### Input Presence

You may use the `has` method to determine if a value is present on the request. The `has` method returns `true` if the value is present on the request:

```php
if ($request->has('name')) {
    // ...
}
```

When given an array, the `has` method will determine if all of the specified values are present:

```php
if ($request->has(['name', 'email'])) {
    // ...
}
```

The `hasAny` method returns `true` if any of the specified values are present:

```php
if ($request->hasAny(['name', 'email'])) {
    // ...
}
```

The `whenHas` method will execute the given closure if a value is present on the request:

```php
$request->whenHas('name', function (string $input) {
    // ...
});
```

A second closure may be passed to the `whenHas` method that will be executed if the specified value is not present on the request:

```php
$request->whenHas('name', function (string $input) {
    // The "name" value is present...
}, function () {
    // The "name" value is not present...
});
```

If you would like to determine if a value is present on the request and is not an empty string, you may use the `filled` method:

```php
if ($request->filled('name')) {
    // ...
}
```

The `anyFilled` method returns `true` if any of the specified values is not an empty string:

```php
if ($request->anyFilled(['name', 'email'])) {
    // ...
}
```

The `whenFilled` method will execute the given closure if a value is present on the request and is not an empty string:

```php
$request->whenFilled('name', function (string $input) {
    // ...
});
```

A second closure may be passed to the `whenFilled` method that will be executed if the specified value is not "filled":

```php
$request->whenFilled('name', function (string $input) {
    // The "name" value is filled...
}, function () {
    // The "name" value is not filled...
});
```

To determine if a given key is absent from the request, you may use the `missing` and `whenMissing` methods:

```php
if ($request->missing('name')) {
    // ...
}

$request->whenMissing('name', function (array $input) {
    // The "name" value is missing...
}, function () {
    // The "name" value is present...
});
```

### Merging Additional Input

Sometimes you may need to manually merge additional input into the request's existing input data. To accomplish this, you may use the `merge` method. If a given input key already exists on the request, it will be overwritten by the data provided to the `merge` method:

```php
$request->merge(['votes' => 0]);
```

The `mergeIfMissing` method may be used to merge input into the request if the corresponding keys do not already exist within the request's input data:

```php
$request->mergeIfMissing(['votes' => 0]);
```

### Cookies

#### Retrieving Cookies From Requests

All cookies created by the Hypervel is plaintext instead of encrypted data like in Laravel. To retrieve a cookie value from the request, use the `cookie` method on an `Hypervel\Http\Request` instance:

```php
$value = $request->cookie('name');
```

## Input Trimming and Normalization

By default, Hypervel includes the `App\Http\Middleware\TrimStrings` and `App\Http\Middleware\ConvertEmptyStringsToNull` middleware in your application's global middleware stack. These middleware are listed in the global middleware stack by the `App\Http\Kernel` class but disabled by default. These middleware will automatically trim all incoming string fields on the request, as well as convert any empty string fields to `null`. This allows you to not have to worry about these normalization concerns in your routes and controllers.

#### Disabling Input Normalization

If you would like to disable this behavior for all requests, you may remove the two middleware from your application's middleware stack by removing them from the `$middleware` property of your `App\Http\Kernel` class.

If you would like to disable string trimming and empty string conversion for a subset of requests to your application, you may use the `skipWhen` method offered by both middleware. This method accepts a closure which should return `true` or `false` to indicate if input normalization should be skipped. Typically, the `skipWhen` method should be invoked in the `boot` method of your application's `AppServiceProvider`.

```php
use App\Http\Middleware\ConvertEmptyStringsToNull;
use App\Http\Middleware\TrimStrings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    TrimStrings::skipWhen(function (ServerRequestInterface $request) {
        return ! str_starts_with($request->getUri()->getPath(), '/admin/');
    });

    ConvertEmptyStringsToNull::skipWhen(function (ServerRequestInterface $request) {
        // ...
    });
}
```

## Files

### Retrieving Uploaded Files

You may retrieve uploaded files from an `Hypervel\Http\Request` instance using the `file` method. The `file` method returns an instance of the `Hyperf\HttpMessage\Upload\UploadedFile` class, which extends the PHP `SplFileInfo` class and provides a variety of methods for interacting with the file:

```php
$file = $request->file('photo');
```

You may determine if a file is present on the request using the `hasFile` method:

```php
if ($request->hasFile('photo')) {
    // ...
}
```

#### Validating Successful Uploads

In addition to checking if the file is present, you may verify that there were no problems uploading the file via the `isValid` method:

```php
if ($request->file('photo')->isValid()) {
    // ...
}
```

#### File Paths and Extensions

The `UploadedFile` class also contains methods for accessing the file's fully-qualified path and its extension. The `extension` method will attempt to guess the file's extension based on its contents. This extension may be different from the extension that was supplied by the client:

```php
$path = $request->file('photo')->getPath();

$extension = $request->file('photo')->getExtension();
```

#### Other File Methods

There are a variety of other methods available on `UploadedFile` instances. Check out the [API documentation for the class](https://github.com/hyperf/hyperf/blob/3.1/src/http-message/src/Upload/UploadedFile.php) for more information regarding these methods.

### Storing Uploaded Files

To store an uploaded file, you will typically use `moveTo` in the `UploadedFile` class. It can help you move an uploaded file to your local filesystem.

The `moveTo` method accepts the path where the file should be stored to. And the path should contain a filename.

```php
$path = $request->file('photo')->moveTo('/images/foo.jpg');
```
