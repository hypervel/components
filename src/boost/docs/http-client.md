# HTTP Client

- [Introduction](#introduction)
- [Making Requests](#making-requests)
    - [Request Data](#request-data)
    - [Headers](#headers)
    - [Authentication](#authentication)
    - [Timeout](#timeout)
    - [Retries](#retries)
    - [Error Handling](#error-handling)
    - [Guzzle Middleware](#guzzle-middleware)
    - [Guzzle Options](#guzzle-options)
    - [Telescope Recording](#telescope-recording)
- [Concurrent Requests](#concurrent-requests)
    - [Dispatching Concurrent Requests](#dispatching-concurrent-requests)
    - [Limiting Concurrency](#limiting-concurrency)
    - [Handling Failures](#handling-failures)
    - [Per-Request Callbacks](#per-request-callbacks)
    - [Running Concurrent Requests After the Response](#running-concurrent-requests-after-the-response)
- [Connections](#connections)
- [Macros](#macros)
- [Testing](#testing)
    - [Faking Responses](#faking-responses)
    - [Inspecting Requests](#inspecting-requests)
    - [Preventing Stray Requests](#preventing-stray-requests)
- [Events](#events)

<a name="introduction"></a>
## Introduction

Hypervel provides an expressive, minimal API around the [Guzzle HTTP client](http://docs.guzzlephp.org/en/stable/), allowing you to quickly make outgoing HTTP requests to communicate with other web applications. Hypervel's wrapper around Guzzle is focused on its most common use cases and a wonderful developer experience.

<a name="making-requests"></a>
## Making Requests

To make requests, you may use the `head`, `get`, `post`, `put`, `patch`, and `delete` methods provided by the `Http` facade. First, let's examine how to make a basic `GET` request to another URL:

```php
use Hypervel\Support\Facades\Http;

$response = Http::get('http://example.com');
```

The `get` method returns an instance of `Hypervel\Http\Client\Response`, which provides a variety of methods that may be used to inspect the response:

```php
$response->body() : string;
$response->json($key = null, $default = null) : mixed;
$response->object() : object;
$response->collect($key = null) : Hypervel\Support\Collection;
$response->resource() : resource;
$response->status() : int;
$response->successful() : bool;
$response->redirect(): bool;
$response->failed() : bool;
$response->clientError() : bool;
$response->header($header) : string;
$response->headers() : array;
```

The `Hypervel\Http\Client\Response` object also implements the PHP `ArrayAccess` interface, allowing you to access JSON response data directly on the response:

```php
return Http::get('http://example.com/users/1')['name'];
```

In addition to the response methods listed above, the following methods may be used to determine if the response has a specific status code:

```php
$response->ok() : bool;                  // 200 OK
$response->created() : bool;             // 201 Created
$response->accepted() : bool;            // 202 Accepted
$response->noContent() : bool;           // 204 No Content
$response->movedPermanently() : bool;    // 301 Moved Permanently
$response->found() : bool;               // 302 Found
$response->notModified() : bool;         // 304 Not Modified
$response->badRequest() : bool;          // 400 Bad Request
$response->unauthorized() : bool;        // 401 Unauthorized
$response->paymentRequired() : bool;     // 402 Payment Required
$response->forbidden() : bool;           // 403 Forbidden
$response->notFound() : bool;            // 404 Not Found
$response->requestTimeout() : bool;      // 408 Request Timeout
$response->conflict() : bool;            // 409 Conflict
$response->unprocessableEntity() : bool; // 422 Unprocessable Entity
$response->tooManyRequests() : bool;     // 429 Too Many Requests
$response->serverError() : bool;         // 500 Internal Server Error
```

<a name="uri-templates"></a>
#### URI Templates

The HTTP client also allows you to construct request URLs using the [URI template specification](https://www.rfc-editor.org/rfc/rfc6570). To define the URL parameters that can be expanded by your URI template, you may use the `withUrlParameters` method:

```php
Http::withUrlParameters([
    'endpoint' => 'https://hypervel.org',
    'page' => 'docs',
    'version' => '13.x',
    'topic' => 'validation',
])->get('{+endpoint}/{page}/{version}/{topic}');
```

<a name="dumping-requests"></a>
#### Dumping Requests

If you would like to dump the outgoing request instance before it is sent and terminate the script's execution, you may add the `dd` method to the beginning of your request definition:

```php
return Http::dd()->get('http://example.com');
```

<a name="request-data"></a>
### Request Data

Of course, it is common when making `POST`, `PUT`, and `PATCH` requests to send additional data with your request, so these methods accept an array of data as their second argument. By default, data will be sent using the `application/json` content type:

```php
use Hypervel\Support\Facades\Http;

$response = Http::post('http://example.com/users', [
    'name' => 'Steve',
    'role' => 'Network Administrator',
]);
```

<a name="get-request-query-parameters"></a>
#### GET Request Query Parameters

When making `GET` requests, you may either append a query string to the URL directly or pass an array of key / value pairs as the second argument to the `get` method:

```php
$response = Http::get('http://example.com/users', [
    'name' => 'Taylor',
    'page' => 1,
]);
```

Alternatively, the `withQueryParameters` method may be used:

```php
Http::retry(3, 100)->withQueryParameters([
    'name' => 'Taylor',
    'page' => 1,
])->get('http://example.com/users');
```

<a name="sending-form-url-encoded-requests"></a>
#### Sending Form URL Encoded Requests

If you would like to send data using the `application/x-www-form-urlencoded` content type, you should call the `asForm` method before making your request:

```php
$response = Http::asForm()->post('http://example.com/users', [
    'name' => 'Sara',
    'role' => 'Privacy Consultant',
]);
```

<a name="sending-a-raw-request-body"></a>
#### Sending a Raw Request Body

You may use the `withBody` method if you would like to provide a raw request body when making a request. The content type may be provided via the method's second argument:

```php
$response = Http::withBody(
    base64_encode($photo), 'image/jpeg'
)->post('http://example.com/photo');
```

<a name="multi-part-requests"></a>
#### Multi-Part Requests

If you would like to send files as multi-part requests, you should call the `attach` method before making your request. This method accepts the name of the file and its contents. If needed, you may provide a third argument which will be considered the file's filename, while a fourth argument may be used to provide headers associated with the file:

```php
$response = Http::attach(
    'attachment', file_get_contents('photo.jpg'), 'photo.jpg', ['Content-Type' => 'image/jpeg']
)->post('http://example.com/attachments');
```

Instead of passing the raw contents of a file, you may pass a stream resource:

```php
$photo = fopen('photo.jpg', 'r');

$response = Http::attach(
    'attachment', $photo, 'photo.jpg'
)->post('http://example.com/attachments');
```

<a name="headers"></a>
### Headers

Headers may be added to requests using the `withHeaders` method. This `withHeaders` method accepts an array of key / value pairs:

```php
$response = Http::withHeaders([
    'X-First' => 'foo',
    'X-Second' => 'bar'
])->post('http://example.com/users', [
    'name' => 'Taylor',
]);
```

You may use the `accept` method to specify the content type that your application is expecting in response to your request:

```php
$response = Http::accept('application/json')->get('http://example.com/users');
```

For convenience, you may use the `acceptJson` method to quickly specify that your application expects the `application/json` content type in response to your request:

```php
$response = Http::acceptJson()->get('http://example.com/users');
```

The `withHeaders` method merges new headers into the request's existing headers. If needed, you may replace all of the headers entirely using the `replaceHeaders` method:

```php
$response = Http::withHeaders([
    'X-Original' => 'foo',
])->replaceHeaders([
    'X-Replacement' => 'bar',
])->post('http://example.com/users', [
    'name' => 'Taylor',
]);
```

<a name="authentication"></a>
### Authentication

You may specify basic and digest authentication credentials using the `withBasicAuth` and `withDigestAuth` methods, respectively:

```php
// Basic authentication...
$response = Http::withBasicAuth('johndoe@example.com', 'secret')->post(/* ... */);

// Digest authentication...
$response = Http::withDigestAuth('johndoe@example.com', 'secret')->post(/* ... */);
```

<a name="bearer-tokens"></a>
#### Bearer Tokens

If you would like to quickly add a bearer token to the request's `Authorization` header, you may use the `withToken` method:

```php
$response = Http::withToken('token')->post(/* ... */);
```

<a name="timeout"></a>
### Timeout

The `timeout` method may be used to specify the maximum number of seconds to wait for a response. By default, the HTTP client will timeout after 30 seconds:

```php
$response = Http::timeout(3)->get(/* ... */);
```

If the given timeout is exceeded, an instance of `Hypervel\Http\Client\ConnectionException` will be thrown.

You may specify the maximum number of seconds to wait while trying to connect to a server using the `connectTimeout` method. The default is 10 seconds:

```php
$response = Http::connectTimeout(3)->get(/* ... */);
```

<a name="retries"></a>
### Retries

If you would like the HTTP client to automatically retry the request if a client or server error occurs, you may use the `retry` method. The `retry` method accepts the maximum number of times the request should be attempted and the number of milliseconds that Hypervel should wait in between attempts:

```php
$response = Http::retry(3, 100)->post(/* ... */);
```

If you would like to manually calculate the number of milliseconds to sleep between attempts, you may pass a closure as the second argument to the `retry` method:

```php
use Exception;

$response = Http::retry(3, function (int $attempt, Exception $exception) {
    return $attempt * 100;
})->post(/* ... */);
```

For convenience, you may also provide an array as the first argument to the `retry` method. This array will be used to determine how many milliseconds to sleep between subsequent attempts:

```php
$response = Http::retry([100, 200])->post(/* ... */);
```

If needed, you may pass a third argument to the `retry` method. The third argument should be a callable that determines if the retries should actually be attempted. For example, you may wish to only retry the request if the initial request encounters an `ConnectionException`:

```php
use Hypervel\Http\Client\PendingRequest;
use Throwable;

$response = Http::retry(3, 100, function (Throwable $exception, PendingRequest $request) {
    return $exception instanceof ConnectionException;
})->post(/* ... */);
```

If a request attempt fails, you may wish to make a change to the request before a new attempt is made. You can achieve this by modifying the request argument provided to the callable you provided to the `retry` method. For example, you might want to retry the request with a new authorization token if the first attempt returned an authentication error:

```php
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\RequestException;
use Throwable;

$response = Http::withToken($this->getToken())->retry(2, 0, function (Throwable $exception, PendingRequest $request) {
    if (! $exception instanceof RequestException || $exception->response->status() !== 401) {
        return false;
    }

    $request->withToken($this->getNewToken());

    return true;
})->post(/* ... */);
```

If all of the requests fail, an instance of `Hypervel\Http\Client\RequestException` will be thrown. If you would like to disable this behavior, you may provide a `throw` argument with a value of `false`. When disabled, the last response received by the client will be returned after all retries have been attempted:

```php
$response = Http::retry(3, 100, throw: false)->post(/* ... */);
```

> [!WARNING]
> If all of the requests fail because of a connection issue, a `Hypervel\Http\Client\ConnectionException` will still be thrown even when the `throw` argument is set to `false`.

<a name="error-handling"></a>
### Error Handling

Unlike Guzzle's default behavior, Hypervel's HTTP client wrapper does not throw exceptions on client or server errors (`400` and `500` level responses from servers). You may determine if one of these errors was returned using the `successful`, `clientError`, or `serverError` methods:

```php
// Determine if the status code is >= 200 and < 300...
$response->successful();

// Determine if the status code is >= 400...
$response->failed();

// Determine if the response has a 400 level status code...
$response->clientError();

// Determine if the response has a 500 level status code...
$response->serverError();

// Immediately execute the given callback if there was a client or server error...
$response->onError(callable $callback);
```

<a name="throwing-exceptions"></a>
#### Throwing Exceptions

If you have a response instance and would like to throw an instance of `Hypervel\Http\Client\RequestException` if the response status code indicates a client or server error, you may use the `throw` or `throwIf` methods:

```php
use Hypervel\Http\Client\Response;

$response = Http::post(/* ... */);

// Throw an exception if a client or server error occurred...
$response->throw();

// Throw an exception if an error occurred and the given condition is true...
$response->throwIf($condition);

// Throw an exception if an error occurred and the given closure resolves to true...
$response->throwIf(fn (Response $response) => true);

// Throw an exception if an error occurred and the given condition is false...
$response->throwUnless($condition);

// Throw an exception if an error occurred and the given closure resolves to false...
$response->throwUnless(fn (Response $response) => false);

// Throw an exception if the response has a specific status code...
$response->throwIfStatus(403);

// Throw an exception unless the response has a specific status code...
$response->throwUnlessStatus(200);

// Throw an exception if a server error occurred (status >500)...
$response->throwIfServerError();

// Throw an exception if a client error occurred (status >400 and <500)...
$response->throwIfClientError();

return $response['user']['id'];
```

The `Hypervel\Http\Client\RequestException` instance has a public `$response` property which will allow you to inspect the returned response.

The `throw` method returns the response instance if no error occurred, allowing you to chain other operations onto the `throw` method:

```php
return Http::post(/* ... */)->throw()->json();
```

If you would like to perform some additional logic before the exception is thrown, you may pass a closure to the `throw` method. The exception will be thrown automatically after the closure is invoked, so you do not need to re-throw the exception from within the closure:

```php
use Hypervel\Http\Client\Response;
use Hypervel\Http\Client\RequestException;

return Http::post(/* ... */)->throw(function (Response $response, RequestException $e) {
    // ...
})->json();
```

By default, `RequestException` messages are truncated to 120 characters when logged or reported. To customize or disable this behavior, you may utilize the `truncateAt` and `dontTruncate` methods when configuring your application's registered behavior in your `bootstrap/app.php` file:

```php
use Hypervel\Http\Client\RequestException;

->registered(function (): void {
    // Truncate request exception messages to 240 characters...
    RequestException::truncateAt(240);

    // Disable request exception message truncation...
    RequestException::dontTruncate();
})
```

Alternatively, you may customize the exception truncation behavior per request using the `truncateExceptionsAt` method:

```php
return Http::truncateExceptionsAt(240)->post(/* ... */);
```

<a name="guzzle-middleware"></a>
### Guzzle Middleware

Since Hypervel's HTTP client is powered by Guzzle, you may take advantage of [Guzzle Middleware](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html) to manipulate the outgoing request or inspect the incoming response. To manipulate the outgoing request, register a Guzzle middleware via the `withRequestMiddleware` method:

```php
use Hypervel\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

$response = Http::withRequestMiddleware(
    function (RequestInterface $request) {
        return $request->withHeader('X-Example', 'Value');
    }
)->get('http://example.com');
```

Likewise, you can inspect the incoming HTTP response by registering a middleware via the `withResponseMiddleware` method:

```php
use Hypervel\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

$response = Http::withResponseMiddleware(
    function (ResponseInterface $response) {
        $header = $response->getHeader('X-Example');

        // ...

        return $response;
    }
)->get('http://example.com');
```

<a name="global-middleware"></a>
#### Global Middleware

Sometimes, you may want to register a middleware that applies to every outgoing request and incoming response. To accomplish this, you may use the `globalRequestMiddleware` and `globalResponseMiddleware` methods. Typically, these methods should be invoked in the `boot` method of your application's `AppServiceProvider`:

```php
use Hypervel\Support\Facades\Http;

Http::globalRequestMiddleware(fn ($request) => $request->withHeader(
    'User-Agent', 'Example Application/1.0'
));

Http::globalResponseMiddleware(fn ($response) => $response->withHeader(
    'X-Finished-At', now()->toDateTimeString()
));
```

<a name="guzzle-options"></a>
### Guzzle Options

You may specify additional [Guzzle request options](http://docs.guzzlephp.org/en/stable/request-options.html) for an outgoing request using the `withOptions` method. The `withOptions` method accepts an array of key / value pairs:

```php
$response = Http::withOptions([
    'debug' => true,
])->get('http://example.com/users');
```

<a name="global-options"></a>
#### Global Options

To configure default options for every outgoing request, you may utilize the `globalOptions` method. Typically, this method should be invoked from the `boot` method of your application's `AppServiceProvider`:

```php
use Hypervel\Support\Facades\Http;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Http::globalOptions([
        'allow_redirects' => false,
    ]);
}
```

<a name="telescope-recording"></a>
### Telescope Recording

If your application uses [Telescope](/docs/{{version}}/telescope), HTTP client requests are automatically recorded by the HTTP client watcher. You may exclude an individual request from being recorded by chaining the `withoutTelescope` method:

```php
$response = Http::withoutTelescope()->get('http://example.com');
```

To attach tags to a recorded request — for example, to identify which downstream service or feature initiated the call — use the `withTelescopeTags` method:

```php
$response = Http::withTelescopeTags(['billing', 'stripe'])->get('http://example.com');
```

These methods are safe to call regardless of Telescope's state. They simply set Guzzle option keys that the Telescope watcher reads when present; if Telescope is disabled or not installed, the keys are ignored and the request runs as normal with no overhead.

<a name="concurrent-requests"></a>
## Concurrent Requests

Sometimes, you may wish to make multiple HTTP requests concurrently. In other words, you want several requests to be dispatched at the same time instead of issuing the requests sequentially. This can lead to substantial performance improvements when interacting with slow HTTP APIs.

<a name="dispatching-concurrent-requests"></a>
### Dispatching Concurrent Requests

In Hypervel, the simplest way to dispatch multiple HTTP requests concurrently is the `parallel` helper from `Hypervel\Coroutine`. It accepts an array of closures, runs each in its own coroutine, and returns the results once all of them have completed:

```php
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

$responses = parallel([
    fn () => Http::get('http://localhost/first'),
    fn () => Http::get('http://localhost/second'),
    fn () => Http::get('http://localhost/third'),
]);

return $responses[0]->ok() &&
       $responses[1]->ok() &&
       $responses[2]->ok();
```

Each response can be accessed based on the order it was added to the array. If you wish, you can name the requests using array keys, which allows you to access the corresponding responses by name:

```php
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

$responses = parallel([
    'first' => fn () => Http::get('http://localhost/first'),
    'second' => fn () => Http::get('http://localhost/second'),
    'third' => fn () => Http::get('http://localhost/third'),
]);

return $responses['first']->ok();
```

Each closure may use the full `Http` API, including chained methods like `withHeaders`, `withToken`, and `timeout`:

```php
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

$responses = parallel([
    fn () => Http::withToken($token)->get('http://localhost/first'),
    fn () => Http::withHeaders(['X-Trace' => $id])->get('http://localhost/second'),
]);
```

<a name="limiting-concurrency"></a>
### Limiting Concurrency

By default, `parallel` dispatches every closure simultaneously. If you need to cap how many requests are in flight at once — for example, to avoid overwhelming a downstream service — pass a maximum to the second argument:

```php
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

$responses = parallel([
    fn () => Http::get('http://localhost/first'),
    fn () => Http::get('http://localhost/second'),
    // ...
], concurrent: 5);
```

For finer control — such as collecting partial results when some requests fail without throwing — instantiate `Parallel` directly and call `wait(throw: false)`. After the call, the returned array holds the successful results, and the failures may be inspected via `getThrowables()`, `hasFailures()`, and `failedCount()` on the same instance:

```php
use Hypervel\Coroutine\Parallel;
use Hypervel\Support\Facades\Http;

$parallel = new Parallel(concurrent: 5);

foreach ($urls as $key => $url) {
    $parallel->add(fn () => Http::get($url), $key);
}

$results = $parallel->wait(throw: false);

if ($parallel->hasFailures()) {
    foreach ($parallel->getThrowables() as $key => $error) {
        $logger->error('Request failed', ['key' => $key, 'error' => $error->getMessage()]);
    }
}
```

<a name="handling-failures"></a>
### Handling Failures

When you dispatch concurrent requests with `parallel`, any closure may throw — typically a `ConnectionException` for a network failure, or a `RequestException` raised by calling `throw()` on the response. If at least one closure throws, `parallel` collects every failure and throws a single `Hypervel\Coroutine\Exceptions\ParallelExecutionException`. The exception exposes the successful results via `getResults()` and the failures via `getThrowables()`, both keyed by your closure keys:

```php
use Hypervel\Coroutine\Exceptions\ParallelExecutionException;
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

try {
    $responses = parallel([
        'github' => fn () => Http::get('https://api.github.com/user'),
        'gitlab' => fn () => Http::get('https://gitlab.com/api/v4/user'),
    ]);
} catch (ParallelExecutionException $e) {
    foreach ($e->getThrowables() as $key => $error) {
        $logger->error('Request failed', ['key' => $key, 'error' => $error->getMessage()]);
    }

    // Successful responses from the same call are still available...
    $partial = $e->getResults();
}
```

If you would rather always receive partial results without an exception being thrown, instantiate `Parallel` directly and call `wait(throw: false)`. The non-throwing path exposes failures via `getThrowables()` / `hasFailures()` / `failedCount()` on the `Parallel` instance, as shown in [Limiting Concurrency](#limiting-concurrency).

<a name="per-request-callbacks"></a>
### Per-Request Callbacks

To run logic as each individual request completes — for example, to log progress or update a counter — place that logic at the end of the closure for that request:

```php
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

$responses = parallel([
    'github' => function () use ($logger) {
        $response = Http::get('https://api.github.com/user');
        $logger->info('Completed', ['key' => 'github', 'status' => $response->status()]);
        return $response;
    },
    'gitlab' => function () use ($logger) {
        $response = Http::get('https://gitlab.com/api/v4/user');
        $logger->info('Completed', ['key' => 'gitlab', 'status' => $response->status()]);
        return $response;
    },
]);
```

If the same logic should run after every request completes, factor it out into a wrapper closure that takes the request key and the underlying request callable:

```php
use Closure;
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

$track = fn (string $key, Closure $request) => function () use ($key, $request, $logger) {
    $response = $request();
    $logger->info('Completed', ['key' => $key, 'status' => $response->status()]);
    return $response;
};

$responses = parallel([
    'github' => $track('github', fn () => Http::get('https://api.github.com/user')),
    'gitlab' => $track('gitlab', fn () => Http::get('https://gitlab.com/api/v4/user')),
]);
```

<a name="running-concurrent-requests-after-the-response"></a>
### Running Concurrent Requests After the Response

To dispatch a batch of HTTP requests after the current HTTP response has been sent to the user, wrap the call in Hypervel's `defer` helper. This keeps the response feeling fast while the requests run in the background:

```php
use Hypervel\Support\Facades\Http;

use function Hypervel\Coroutine\parallel;

defer(function () {
    parallel([
        fn () => Http::get('http://localhost/first'),
        fn () => Http::get('http://localhost/second'),
        fn () => Http::get('http://localhost/third'),
    ]);
});
```

By default, deferred callbacks only run when the response is successful (a status code below 400). If you want the requests to run even when the response indicates an error, chain the `always` method:

```php
defer(function () {
    parallel([
        fn () => Http::get('http://localhost/first'),
        fn () => Http::get('http://localhost/second'),
    ]);
})->always();
```

<a name="connections"></a>
## Connections

Hypervel's HTTP client can maintain named, pooled Guzzle clients for the services your application talks to most often. Connections let Hypervel reuse the same HTTP client for repeated calls to the same service, reducing per-request setup work and improving performance. This means Hypervel does not need to rebuild the client and its request pipeline every time your application calls that API, and repeated calls can also reuse an existing keep-alive connection instead of opening a new TCP / TLS connection each time if the remote server supports it.

To register a connection, typically in the `boot` method of your application's `AppServiceProvider`, call the `registerConnection` method:

```php
use Hypervel\Support\Facades\Http;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Http::registerConnection('github', [
        'min_objects' => 1,
        'max_objects' => 10,
    ]);
}
```

The second argument accepts pool options such as `min_objects`, `max_objects`, `wait_timeout`, and `max_lifetime`. Defaults are sensible, so the array may be omitted entirely. See the [object pool documentation](/docs/{{version}}/object-pool) for the full list of options.

Once registered, select a connection for a request by chaining the `connection` method:

```php
$response = Http::connection('github')
    ->withToken($token)
    ->get('https://api.github.com/user');
```

Requests made without `connection(...)` continue to use a fresh, non-pooled Guzzle client — there is no pooling unless you opt in by registering and selecting a connection.

<a name="macros"></a>
## Macros

The Hypervel HTTP client allows you to define "macros", which can serve as a fluent, expressive mechanism to configure common request paths and headers when interacting with services throughout your application. To get started, you may define the macro within the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use Hypervel\Support\Facades\Http;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Http::macro('github', function () {
        return Http::withHeaders([
            'X-Example' => 'example',
        ])->baseUrl('https://github.com');
    });
}
```

Once your macro has been configured, you may invoke it from anywhere in your application to create a pending request with the specified configuration:

```php
$response = Http::github()->get('/');
```

<a name="testing"></a>
## Testing

Many Hypervel services provide functionality to help you easily and expressively write tests, and Hypervel's HTTP client is no exception. The `Http` facade's `fake` method allows you to instruct the HTTP client to return stubbed / dummy responses when requests are made.

<a name="faking-responses"></a>
### Faking Responses

For example, to instruct the HTTP client to return empty, `200` status code responses for every request, you may call the `fake` method with no arguments:

```php
use Hypervel\Support\Facades\Http;

Http::fake();

$response = Http::post(/* ... */);
```

<a name="faking-specific-urls"></a>
#### Faking Specific URLs

Alternatively, you may pass an array to the `fake` method. The array's keys should represent URL patterns that you wish to fake and their associated responses. The `*` character may be used as a wildcard character. You may use the `Http` facade's `response` method to construct stub / fake responses for these endpoints:

```php
Http::fake([
    // Stub a JSON response for GitHub endpoints...
    'github.com/*' => Http::response(['foo' => 'bar'], 200, $headers),

    // Stub a string response for Google endpoints...
    'google.com/*' => Http::response('Hello World', 200, $headers),
]);
```

Any requests made to URLs that have not been faked will actually be executed. If you would like to specify a fallback URL pattern that will stub all unmatched URLs, you may use a single `*` character:

```php
Http::fake([
    // Stub a JSON response for GitHub endpoints...
    'github.com/*' => Http::response(['foo' => 'bar'], 200, ['Headers']),

    // Stub a string response for all other endpoints...
    '*' => Http::response('Hello World', 200, ['Headers']),
]);
```

For convenience, simple string, JSON, and empty responses may be generated by providing a string, array, or integer as the response:

```php
Http::fake([
    'google.com/*' => 'Hello World',
    'github.com/*' => ['foo' => 'bar'],
    'chatgpt.com/*' => 200,
]);
```

<a name="faking-connection-exceptions"></a>
#### Faking Exceptions

Sometimes you may need to test your application's behavior if the HTTP client encounters an `Hypervel\Http\Client\ConnectionException` when attempting to make a request. You can instruct the HTTP client to throw a connection exception using the `failedConnection` method:

```php
Http::fake([
    'github.com/*' => Http::failedConnection(),
]);
```

To test your application's behavior if a `Hypervel\Http\Client\RequestException` is thrown, you may use the `failedRequest` method:

```php
$this->mock(GithubService::class);
    ->shouldReceive('getUser')
    ->andThrow(
        Http::failedRequest(['code' => 'not_found'], 404)
    );
```

<a name="faking-response-sequences"></a>
#### Faking Response Sequences

Sometimes you may need to specify that a single URL should return a series of fake responses in a specific order. You may accomplish this using the `Http::sequence` method to build the responses:

```php
Http::fake([
    // Stub a series of responses for GitHub endpoints...
    'github.com/*' => Http::sequence()
        ->push('Hello World', 200)
        ->push(['foo' => 'bar'], 200)
        ->pushStatus(404),
]);
```

When all the responses in a response sequence have been consumed, any further requests will cause the response sequence to throw an exception. If you would like to specify a default response that should be returned when a sequence is empty, you may use the `whenEmpty` method:

```php
Http::fake([
    // Stub a series of responses for GitHub endpoints...
    'github.com/*' => Http::sequence()
        ->push('Hello World', 200)
        ->push(['foo' => 'bar'], 200)
        ->whenEmpty(Http::response()),
]);
```

If you would like to fake a sequence of responses but do not need to specify a specific URL pattern that should be faked, you may use the `Http::fakeSequence` method:

```php
Http::fakeSequence()
    ->push('Hello World', 200)
    ->whenEmpty(Http::response());
```

<a name="fake-callback"></a>
#### Fake Callback

If you require more complicated logic to determine what responses to return for certain endpoints, you may pass a closure to the `fake` method. This closure will receive an instance of `Hypervel\Http\Client\Request` and should return a response instance. Within your closure, you may perform whatever logic is necessary to determine what type of response to return:

```php
use Hypervel\Http\Client\Request;

Http::fake(function (Request $request) {
    return Http::response('Hello World', 200);
});
```

<a name="inspecting-requests"></a>
### Inspecting Requests

When faking responses, you may occasionally wish to inspect the requests the client receives in order to make sure your application is sending the correct data or headers. You may accomplish this by calling the `Http::assertSent` method after calling `Http::fake`.

The `assertSent` method accepts a closure which will receive an `Hypervel\Http\Client\Request` instance and should return a boolean value indicating if the request matches your expectations. In order for the test to pass, at least one request must have been issued matching the given expectations:

```php
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;

Http::fake();

Http::withHeaders([
    'X-First' => 'foo',
])->post('http://example.com/users', [
    'name' => 'Taylor',
    'role' => 'Developer',
]);

Http::assertSent(function (Request $request) {
    return $request->hasHeader('X-First', 'foo') &&
           $request->url() == 'http://example.com/users' &&
           $request['name'] == 'Taylor' &&
           $request['role'] == 'Developer';
});
```

If needed, you may assert that a specific request was not sent using the `assertNotSent` method:

```php
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;

Http::fake();

Http::post('http://example.com/users', [
    'name' => 'Taylor',
    'role' => 'Developer',
]);

Http::assertNotSent(function (Request $request) {
    return $request->url() === 'http://example.com/posts';
});
```

You may use the `assertSentCount` method to assert how many requests were "sent" during the test:

```php
Http::fake();

Http::assertSentCount(5);
```

Or, you may use the `assertNothingSent` method to assert that no requests were sent during the test:

```php
Http::fake();

Http::assertNothingSent();
```

<a name="recording-requests-and-responses"></a>
#### Recording Requests / Responses

You may use the `recorded` method to gather all requests and their corresponding responses. The `recorded` method returns a collection of arrays that contains instances of `Hypervel\Http\Client\Request` and `Hypervel\Http\Client\Response`:

```php
Http::fake([
    'https://hypervel.org' => Http::response(status: 500),
    'https://api.hypervel.org/' => Http::response(),
]);

Http::get('https://hypervel.org');
Http::get('https://api.hypervel.org/');

$recorded = Http::recorded();

[$request, $response] = $recorded[0];
```

Additionally, the `recorded` method accepts a closure which will receive an instance of `Hypervel\Http\Client\Request` and `Hypervel\Http\Client\Response` and may be used to filter request / response pairs based on your expectations:

```php
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\Response;

Http::fake([
    'https://hypervel.org' => Http::response(status: 500),
    'https://api.hypervel.org/' => Http::response(),
]);

Http::get('https://hypervel.org');
Http::get('https://api.hypervel.org/');

$recorded = Http::recorded(function (Request $request, Response $response) {
    return $request->url() !== 'https://hypervel.org' &&
           $response->successful();
});
```

<a name="preventing-stray-requests"></a>
### Preventing Stray Requests

If you would like to ensure that all requests sent via the HTTP client have been faked throughout your individual test or complete test suite, you can call the `preventStrayRequests` method. After calling this method, any requests that do not have a corresponding fake response will throw an exception rather than making the actual HTTP request:

```php
use Hypervel\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'github.com/*' => Http::response('ok'),
]);

// An "ok" response is returned...
Http::get('https://github.com/hypervel/components');

// An exception is thrown...
Http::get('https://hypervel.org');
```

Sometimes, you may wish to prevent most stray requests while still allowing specific requests to execute. To accomplish this, you may pass an array of URL patterns to the `allowStrayRequests` method. Any request matching one of the given patterns will be allowed, while all other requests will continue to throw an exception:

```php
use Hypervel\Support\Facades\Http;

Http::preventStrayRequests();

Http::allowStrayRequests([
    'http://127.0.0.1:5000/*',
]);

// This request is executed...
Http::get('http://127.0.0.1:5000/generate');

// An exception is thrown...
Http::get('https://hypervel.org');
```

<a name="events"></a>
## Events

Hypervel fires three events during the process of sending HTTP requests. The `RequestSending` event is fired prior to a request being sent, while the `ResponseReceived` event is fired after a response is received for a given request. The `ConnectionFailed` event is fired if no response is received for a given request.

The `RequestSending` and `ConnectionFailed` events both contain a public `$request` property that you may use to inspect the `Hypervel\Http\Client\Request` instance. Likewise, the `ResponseReceived` event contains a `$request` property as well as a `$response` property which may be used to inspect the `Hypervel\Http\Client\Response` instance. You may create [event listeners](/docs/{{version}}/events) for these events within your application:

```php
use Hypervel\Http\Client\Events\RequestSending;

class LogRequest
{
    /**
     * Handle the event.
     */
    public function handle(RequestSending $event): void
    {
        // $event->request ...
    }
}
```
