# API Client

- [Introduction](#introduction)
- [Installation](#installation)
- [Defining API Clients](#defining-api-clients)
    - [Client Configuration](#client-configuration)
    - [Resolving Clients](#resolving-clients)
- [Making Requests](#making-requests)
    - [Request Options](#request-options)
    - [Query Requests](#query-requests)
    - [Request Context](#request-context)
    - [Error Handling](#error-handling)
- [API Resources](#api-resources)
    - [Defining Resources](#defining-resources)
    - [Selecting Resources](#selecting-resources)
    - [Accessing Requests and Responses](#accessing-requests-and-responses)
- [API Middleware](#api-middleware)
    - [Request Middleware](#request-middleware)
    - [Response Middleware](#response-middleware)
    - [Registering Middleware](#registering-middleware)
    - [Middleware Dependencies](#middleware-dependencies)
    - [Disabling Middleware](#disabling-middleware)
- [Mutating Requests and Responses](#mutating-requests-and-responses)
    - [Request Data](#request-data)
    - [Headers and Bodies](#headers-and-bodies)
- [Testing](#testing)

<a name="introduction"></a>
## Introduction

Hypervel's API client provides a small layer on top of the [HTTP client](/docs/{{version}}/http-client) for applications that communicate with external APIs. It allows each integration to define its own defaults, middleware, and response resource while retaining the familiar HTTP client API.

Unlike a general-purpose HTTP request, an API integration usually has a stable base URL, authentication scheme, and response shape. You may collect those concerns in a dedicated client instead of repeating them at every call site:

```php
use App\ApiClients\GitHubClient;

$github = app(GitHubClient::class);

$user = $github->get('/users/hypervel');

return $user->login;
```

API client instances may be safely injected and reused. Each request chain that begins on the client uses a fresh pending request. Apply values that vary between requests, such as tenant credentials or trace identifiers, to the pending request or store them in its [context](#request-context) instead of writing them to the client instance.

<a name="installation"></a>
## Installation

To get started, install the API client package using the Composer package manager:

```shell
composer require hypervel/api-client
```

<a name="defining-api-clients"></a>
## Defining API Clients

To define an API client, extend the `Hypervel\ApiClient\ApiClient` class. The `configurePendingRequest` method may be used to apply the defaults shared by every request made through the client:

```php
<?php

declare(strict_types=1);

namespace App\ApiClients;

use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\PendingRequest;

class GitHubClient extends ApiClient
{
    protected function configurePendingRequest(PendingRequest $request): void
    {
        $request
            ->baseUrl('https://api.github.com')
            ->acceptJson()
            ->withUserAgent('Acme Application')
            ->timeout(10);
    }
}
```

The `baseUrl`, `acceptJson`, `withUserAgent`, and `timeout` methods are provided by Hypervel's HTTP client. You may use its other request configuration methods in the same way.

If you need to customize a new pending request before the client defaults are applied, you may override the `newPendingRequest` method. Most clients should only need to implement `configurePendingRequest`.

<a name="client-configuration"></a>
### Client Configuration

API clients are regular classes, so their dependencies may be injected through the constructor. For example, you may use a typed [data object](/docs/{{version}}/data-objects) to hold the integration's configuration:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Hypervel\Data\Dto;

class GitHubConfig extends Dto
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $token,
    ) {
    }
}
```

The configuration may then be injected into your client:

```php
<?php

declare(strict_types=1);

namespace App\ApiClients;

use App\Data\GitHubConfig;
use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\PendingRequest;

class GitHubClient extends ApiClient
{
    public function __construct(
        private readonly GitHubConfig $config,
    ) {
    }

    protected function configurePendingRequest(PendingRequest $request): void
    {
        $request
            ->baseUrl($this->config->baseUrl)
            ->withToken($this->config->token)
            ->acceptJson();
    }
}
```

Data objects are optional. You may inject any configuration or service your client requires.

<a name="resolving-clients"></a>
### Resolving Clients

API clients may be resolved by Hypervel's [service container](/docs/{{version}}/container). Therefore, you may type-hint them on controllers, jobs, listeners, or other classes resolved by the container:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\ApiClients\GitHubClient;
use Hypervel\Http\Response;

class GitHubUserController extends Controller
{
    public function show(GitHubClient $github, string $username): Response
    {
        $user = $github->get("/users/{$username}");

        return response($user->toArray());
    }
}
```

If your client requires configuration that cannot be resolved automatically, you may define a container binding in one of your application's service providers:

```php
use App\ApiClients\GitHubClient;
use App\Data\GitHubConfig;

$this->app->singleton(GitHubClient::class, function () {
    return new GitHubClient(GitHubConfig::from(
        config()->array('services.github')
    ));
});
```

<a name="making-requests"></a>
## Making Requests

API clients provide the same `head`, `get`, `query`, `post`, `put`, `patch`, `delete`, and `send` methods as Hypervel's HTTP client. Each method returns the API resource configured for the request:

```php
$user = $github->get('/users/hypervel');

$repository = $github->post('/user/repos', [
    'name' => 'example',
    'private' => true,
]);
```

You may call `createPendingRequest` when you would like to build a pending request explicitly:

```php
$request = $github->createPendingRequest()
    ->timeout(30)
    ->retry(3, 100);

$user = $request->get('/users/hypervel');
```

API clients may be reused, but pending requests are mutable and represent one operation. Create a separate pending request for each request that may run concurrently.

<a name="request-options"></a>
### Request Options

You may call familiar HTTP client methods such as `withHeaders`, `withToken`, `timeout`, `retry`, and `withOptions` directly on an API client:

```php
$user = $github
    ->withHeaders(['X-Trace-ID' => $traceId])
    ->timeout(15)
    ->get('/users/hypervel');
```

Fluent HTTP client methods continue the API client chain. Methods that return another value return that value unchanged.

> [!NOTE]
> API clients send requests synchronously. Calling `async(true)` is rejected before a request is dispatched. Calling `async(false)` may be useful when shared request-building code needs to specify synchronous mode explicitly.

Custom Guzzle client instances are not supported because they skip API middleware. To customize the transport for a request, use the `setHandler` method instead:

```php
$user = $github
    ->setHandler($handler)
    ->get('/users/hypervel');
```

<a name="query-requests"></a>
### Query Requests

Query string values may be passed as the second argument to the `get` and `head` methods:

```php
$repositories = $github->get('/search/repositories', [
    'q' => 'framework language:php',
    'sort' => 'stars',
]);
```

If you have already called `withQueryParameters`, omit the second argument to keep those values. Passing `null` explicitly replaces the configured query for that request:

```php
$repositories = $github
    ->withQueryParameters(['sort' => 'stars'])
    ->get('/repositories');
```

The `query` method may be used to issue an HTTP `QUERY` request with structured request data:

```php
$result = $github->query('/search', [
    'filters' => ['status' => 'active'],
]);
```

<a name="request-context"></a>
### Request Context

Sometimes middleware needs information that should not be sent to the remote API. You may attach this information to the request using the `withContext` method:

```php
$user = $github
    ->withContext([
        'tenant' => $tenant,
        'trace_id' => $traceId,
    ])
    ->get('/user');
```

Request middleware may read or update the context using the `context` and `withContext` methods. The final request context is also available to response middleware, allowing both middleware stages to share information about the current request. If a request is retried, the context from the final attempt is used:

```php
$tenant = $request->context('tenant');

$request->withContext('attempted_at', now());
```

Context values belong to one pending request and are not retained by the API client when it creates another pending request.

<a name="error-handling"></a>
### Error Handling

By default, API clients return the selected resource for successful and unsuccessful HTTP responses. This allows response middleware and resources to inspect error payloads:

```php
$user = $github->get('/users/missing');

if ($user->notFound()) {
    // ...
}
```

If you would like an exception to be thrown for client or server errors, invoke the HTTP client's `throw` method before issuing the request:

```php
$user = $github
    ->throw()
    ->get('/users/missing');
```

You may also inspect or throw from the API response after the request has completed:

```php
$response = $user->getResponse();

$response->throw();
```

<a name="api-resources"></a>
## API Resources

API resources provide a dedicated representation for data returned by an external API. They offer array access, property access, JSON serialization, and access to the API request and response.

The base `Hypervel\ApiClient\ApiResource` class returns the decoded JSON response as an array:

```php
$user = $github->get('/users/hypervel');

$user['login'];
$user->login;
$user->toArray();
$user->toJson();
$user->toPrettyJson();
```

Reading a missing field using array or property access returns `null`.

You may call response methods and macros on the resource to inspect status codes and headers directly:

```php
$user->successful();
$user->status();
$user->header('X-RateLimit-Remaining');
```

API resources are intended for JSON objects and arrays. Empty bodies and JSON `null` are converted to an empty array. If an endpoint returns scalar JSON or plain text, array and property access will throw an `InvalidResourceDataException`. You may still access the raw response using the `body` method or `getResponse`:

```php
$result = $github->get('/zen');

$body = $result->body();

$response = $result->getResponse();
```

<a name="defining-resources"></a>
### Defining Resources

To define a resource, extend the `ApiResource` class and implement the `toArray` method:

```php
<?php

declare(strict_types=1);

namespace App\ApiClients\GitHub\Resources;

use Hypervel\ApiClient\ApiResource;

class GitHubUserResource extends ApiResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->response->json('id'),
            'username' => $this->response->json('login'),
            'avatar_url' => $this->response->json('avatar_url'),
        ];
    }
}
```

The resource may be configured as the default for a client using the `$resource` property:

```php
use App\ApiClients\GitHub\Resources\GitHubUserResource;
use Hypervel\ApiClient\ApiClient;

/** @extends ApiClient<GitHubUserResource> */
class GitHubClient extends ApiClient
{
    protected string $resource = GitHubUserResource::class;
}
```

The `$resource` property determines which resource is created. The `@extends` annotation tells static analysis tools which resource is returned by client requests and `createPendingRequest`.

<a name="selecting-resources"></a>
### Selecting Resources

If a client communicates with endpoints that return different resource types, you may select the resource for an individual request using the `withResource` method:

```php
use App\ApiClients\GitHub\Resources\GitHubRepositoryResource;

$repository = $github
    ->withResource(GitHubRepositoryResource::class)
    ->get('/repos/hypervel/components');
```

The base `ApiResource` class may also be selected when no custom transformation is needed:

```php
use Hypervel\ApiClient\ApiResource;

$result = $github
    ->withResource(ApiResource::class)
    ->get('/rate_limit');
```

<a name="accessing-requests-and-responses"></a>
### Accessing Requests and Responses

The request and response used to create a resource are available through the `getRequest` and `getResponse` methods:

```php
$request = $user->getRequest();
$response = $user->getResponse();
```

These methods are useful when an integration needs access to details that are not part of its resource representation, such as the effective request URL or response headers.

<a name="api-middleware"></a>
## API Middleware

API middleware may change a request before it is sent or change a response before a resource is created. This differs from [Guzzle middleware](/docs/{{version}}/http-client#guzzle-middleware), which works directly with PSR-7 requests and responses.

API request middleware runs before the HTTP client's ordinary Guzzle middleware, `beforeSending` callbacks, and `RequestSending` event. This ensures lower-level middleware and observers receive the request after the API pipeline has finished preparing it. Middleware explicitly added with `prependMiddleware` runs before the API bridge and must pass the request onward rather than returning a response early.

Because Guzzle middleware and `beforeSending` callbacks run later, they may overwrite changes made by API request middleware. Place body-dependent work, such as request signing, in `beforeSending` or Guzzle middleware so it uses the final request body.

<a name="request-middleware"></a>
### Request Middleware

Request middleware receives an `ApiRequest` instance and a closure that passes the request to the next middleware:

```php
<?php

declare(strict_types=1);

namespace App\ApiClients\GitHub\Middleware;

use Closure;
use Hypervel\ApiClient\ApiRequest;

class AddGitHubApiVersion
{
    public function handle(ApiRequest $request, Closure $next): ApiRequest
    {
        return $next($request->withHeader(
            'X-GitHub-Api-Version', '2022-11-28'
        ));
    }
}
```

Request middleware may change the URL, method, headers, structured data, body, query string, or context before allowing the request to continue.

<a name="response-middleware"></a>
### Response Middleware

Response middleware receives an `ApiResponse` instance. For example, middleware may record rate-limit information from the remote API:

```php
<?php

declare(strict_types=1);

namespace App\ApiClients\GitHub\Middleware;

use Closure;
use Hypervel\ApiClient\ApiResponse;
use Psr\Log\LoggerInterface;

class LogGitHubRateLimit
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ApiResponse $response, Closure $next): ApiResponse
    {
        $response = $next($response);

        $this->logger->info('GitHub API rate limit', [
            'remaining' => $response->header('X-RateLimit-Remaining'),
            'trace_id' => $response->context('trace_id'),
        ]);

        return $response;
    }
}
```

<a name="registering-middleware"></a>
### Registering Middleware

Default middleware may be registered using the `$requestMiddleware` and `$responseMiddleware` properties on your client:

```php
use App\ApiClients\GitHub\Middleware\AddGitHubApiVersion;
use App\ApiClients\GitHub\Middleware\LogGitHubRateLimit;
use Hypervel\ApiClient\ApiClient;

class GitHubClient extends ApiClient
{
    protected array $requestMiddleware = [
        AddGitHubApiVersion::class,
    ];

    protected array $responseMiddleware = [
        LogGitHubRateLimit::class,
    ];
}
```

You may append one middleware to an individual pending request using the `withApiRequestMiddleware` or `withApiResponseMiddleware` methods:

```php
$user = $github
    ->withApiRequestMiddleware(new AddTenantCredentials($tenant))
    ->withApiResponseMiddleware(new RecordApiUsage($tenant))
    ->get('/user');
```

To replace all default middleware for one pending request, use the `replaceApiRequestMiddleware` or `replaceApiResponseMiddleware` methods:

```php
$user = $github
    ->replaceApiRequestMiddleware([
        new AddTenantCredentials($tenant),
    ])
    ->get('/user');
```

These API-specific methods are separate from the HTTP client's `withRequestMiddleware` and `withResponseMiddleware` methods.

<a name="middleware-dependencies"></a>
### Middleware Dependencies

Middleware registered using a class name is resolved through Hypervel's service container. This allows middleware to receive ordinary application dependencies through its constructor:

```php
class RecordApiUsage
{
    public function __construct(
        private readonly UsageRepository $usage,
    ) {
    }

    // ...
}
```

If middleware requires client-specific or request-specific values, register a configured middleware object or pass the values through request context:

```php
$pendingRequest->withApiRequestMiddleware(
    new SignRequest($clientId, $clientSecret)
);

$pendingRequest->withContext('tenant', $tenant);
```

Closures and other callable objects may also be registered as middleware.

Hypervel reuses unbound middleware classes for the lifetime of the worker, so they should not store request state. If you need a new middleware instance each time the pipeline runs, register it using the container's `bind` method. Scoped and singleton bindings retain their normal container lifetimes.

<a name="disabling-middleware"></a>
### Disabling Middleware

To disable both API middleware pipelines for an individual request, invoke the `withoutApiMiddleware` method:

```php
$user = $github
    ->withoutApiMiddleware()
    ->get('/users/hypervel');
```

Disabling API middleware does not disable HTTP or Guzzle middleware.

<a name="mutating-requests-and-responses"></a>
## Mutating Requests and Responses

API request and response objects provide fluent methods for middleware that needs to change a message. These methods return the same object, allowing you to continue chaining calls.

<a name="request-data"></a>
### Request Data

The `withData` method replaces a request's structured data, while the `mergeData` method merges additional values into the existing data. The `withoutData` method removes one or more values:

```php
$request
    ->withData(['name' => 'Taylor'])
    ->mergeData(['role' => 'Administrator'])
    ->withoutData('role');
```

Structured request data belongs to request bodies. Calling `withData`, `mergeData`, `withoutData`, `asJson`, or `asForm` on a `GET` or `HEAD` request will throw an exception. Use `withQuery` and `withoutQuery` to change the query string instead. The `withBody` method remains available when an API explicitly requires a raw body on one of these methods.

The `asJson` and `asForm` methods may be used to convert structured request data between JSON and form encoding:

```php
$request->asForm();

$request->asJson();
```

JSON and form bodies may also be decoded and changed when they were supplied as raw bytes and have the matching `Content-Type` header. Multipart bodies, bodies with another content type, and non-empty bodies without a content type cannot be converted automatically. Use `withBody` when you need to replace one of these bodies.

The `query`, `withQuery`, and `withoutQuery` methods may be used to inspect or change query string values directly:

```php
$query = $request->query();
$page = $query['page'] ?? 1;

$request
    ->withQuery(['page' => 2])
    ->withoutQuery('cursor');
```

<a name="headers-and-bodies"></a>
### Headers and Bodies

The `ApiRequest` and `ApiResponse` classes provide PSR-style methods for replacing, adding, and removing headers:

```php
$request
    ->withHeader('X-Request-ID', $requestId)
    ->withAddedHeader('X-Debug-Tag', 'api')
    ->withoutHeader('X-Legacy-Header');

$response
    ->withHeader('X-Processed-By', 'application')
    ->withoutHeader('X-Internal-Header');
```

The API request's plural `withHeaders`, `withAddedHeaders`, and `withoutHeaders` methods may be used to update several headers at once. API responses use the singular PSR header methods shown above. The `withBody` method replaces the message body.

<a name="testing"></a>
## Testing

API clients use Hypervel's HTTP client for transport, so they may be tested using the HTTP client's fakes and assertions:

```php
<?php

use App\ApiClients\GitHubClient;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;

Http::fake([
    'api.github.com/users/*' => Http::response([
        'id' => 1,
        'login' => 'hypervel',
    ]),
]);

$user = app(GitHubClient::class)->get('/users/hypervel');

expect($user->login)->toBe('hypervel');

Http::assertSent(function (Request $request) {
    return $request->url() === 'https://api.github.com/users/hypervel'
        && $request->hasHeader('Accept', 'application/json');
});
```

API request and response middleware still run when the HTTP client is faked, allowing you to test middleware behavior without making network requests.
