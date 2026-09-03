# Saloon

- [Introduction](#introduction)
- [Installation](#installation)
- [Generating Integrations](#generating-integrations)
- [Defining Connectors](#defining-connectors)
    - [Connector Configuration](#connector-configuration)
    - [Connector Defaults](#connector-defaults)
    - [Organizing SDKs](#organizing-sdks)
    - [HTTP Connections](#http-connections)
- [Defining Requests](#defining-requests)
    - [Request Methods](#request-methods)
    - [Sending Requests](#sending-requests)
    - [Standalone Requests](#standalone-requests)
    - [Request Defaults](#request-defaults)
- [Configuring Requests](#configuring-requests)
    - [Headers](#headers)
    - [Query Parameters](#query-parameters)
    - [Authentication](#authentication)
    - [Request Bodies](#request-bodies)
    - [Multipart Requests](#multipart-requests)
    - [Custom Body Repositories](#custom-body-repositories)
    - [Request Options](#request-options)
- [Middleware](#middleware)
    - [Request Middleware](#request-middleware)
    - [Response Middleware](#response-middleware)
    - [Fatal Exception Middleware](#fatal-exception-middleware)
    - [Global Middleware](#global-middleware)
    - [PSR-7 Request Hooks](#psr-request-hooks)
    - [Plugins](#plugins)
- [Responses](#responses)
    - [Inspecting Responses](#inspecting-responses)
    - [XML and HTML Responses](#xml-and-html-responses)
    - [Saving Response Bodies](#saving-response-bodies)
    - [Data Objects](#data-objects)
    - [Custom Responses](#custom-responses)
    - [Error Handling](#error-handling)
    - [Retries](#retries)
    - [Debugging](#debugging)
- [OAuth 2](#oauth2)
    - [Authorization Code Grant](#authorization-code-grant)
    - [Client Credentials Grant](#client-credentials-grant)
    - [Refreshing Access Tokens](#refreshing-access-tokens)
    - [Customizing OAuth Requests](#customizing-oauth-requests)
- [Concurrent Requests](#concurrent-requests)
    - [Collecting Responses](#collecting-responses)
    - [Processing Large Iterables](#processing-large-iterables)
    - [Handling Pool Failures](#handling-pool-failures)
- [Caching](#caching)
    - [Caching Connector Responses](#caching-connector-responses)
    - [Request Cache Controls](#request-cache-controls)
    - [Custom Cache Keys](#custom-cache-keys)
    - [Cache Scopes](#cache-scopes)
- [API Pagination](#api-pagination)
    - [Page Pagination](#page-pagination)
    - [Offset and Cursor Pagination](#offset-and-cursor-pagination)
    - [Pooled Pagination](#pooled-pagination)
- [Rate Limiting](#rate-limiting)
    - [Defining Policies](#defining-policies)
    - [Tenant and Service Limits](#tenant-and-service-limits)
    - [Server Cooldowns](#server-cooldowns)
    - [Queued Requests](#queued-requests)
- [Multi-Tenant Integrations](#multi-tenant-integrations)
- [Telescope](#telescope)
- [Events](#events)
- [Macros](#macros)
- [Testing](#testing)
    - [Faking Responses](#faking-responses)
    - [Partial Fakes](#partial-fakes)
    - [Response Sequences](#response-sequences)
    - [Assertions](#assertions)
    - [Fixtures](#fixtures)
- [Publishing Configuration and Stubs](#publishing-configuration-and-stubs)
- [Differences From Saloon](#differences-from-saloon)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Saloon provides an expressive, class-based approach to building integrations with external APIs. A connector describes an integration's stable configuration, while request classes describe individual endpoints. This makes integrations easier to reuse, test, and distribute as SDKs.

Hypervel's Saloon package uses the [HTTP client](/docs/{{version}}/http-client) directly. As a result, Saloon requests share Hypervel's response API, named HTTP connections, fakes, events, Telescope support, and coroutine-native networking:

```php
use App\Http\Integrations\GitHub\GitHubConnector;
use App\Http\Integrations\GitHub\Requests\GetUser;

$github = app(GitHubConnector::class);

$response = $github->send(new GetUser('hypervel'));

return $response->json('name');
```

Connectors are reusable definitions and may safely be injected into worker-shared services when their constructor values are stable. Each request and pending request belongs to one operation. Values that vary between requests, such as tenant credentials, tracing headers, cache controls, and retry behavior, should be applied to the request or to an explicitly constructed connector for that operation.

<a name="installation"></a>
## Installation

You may install Saloon using the Composer package manager:

```shell
composer require hypervel/saloon
```

Saloon's service provider and facade are discovered automatically.

<a name="generating-integrations"></a>
## Generating Integrations

Saloon includes Artisan commands for generating connectors, requests, authenticators, plugins, and custom responses. Generated integrations are stored in `app/Http/Integrations` by default:

```shell
php artisan saloon:connector GitHub GitHubConnector
php artisan saloon:request GitHub GetUser --method=GET
php artisan saloon:auth GitHub GitHubAuthenticator
php artisan saloon:plugin GitHub AddsRequestId
php artisan saloon:response GitHub GitHubResponse
```

The connector generator's `--oauth` option generates an OAuth 2 authorization-code connector:

```shell
php artisan saloon:connector GitHub GitHubConnector --oauth
```

Like Hypervel's other generator commands, each generator supports `--target-path` and `--target-namespace`. You may inspect the generated integrations using the `saloon:list` command:

```shell
php artisan saloon:list
```

<a name="defining-connectors"></a>
## Defining Connectors

Each integration begins with a connector. At a minimum, your connector must extend `Hypervel\Saloon\Http\Connector` and define its base URL:

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\GitHub;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class GitHubConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        return 'https://api.github.com';
    }
}
```

The `AcceptsJson` plugin adds an `Accept: application/json` header to each operation unless the request already provides one.

<a name="connector-configuration"></a>
### Connector Configuration

Connectors are regular classes, so fixed integration configuration may be supplied through constructor injection. For example, a connector may receive a token from your application's services configuration:

```php
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;

class GitHubConnector extends Connector
{
    public function __construct(
        private readonly string $token,
    ) {
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.github.com';
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }
}
```

You may bind the configured connector in a service provider:

```php
use App\Http\Integrations\GitHub\GitHubConnector;

$this->app->singleton(GitHubConnector::class, function () {
    return new GitHubConnector(config()->string('services.github.token'));
});
```

Do not change a reused connector while handling a request. If a credential or endpoint belongs to the current tenant, construct a connector with that tenant's values for the operation instead. [Multi-tenant integrations](#multi-tenant-integrations) are discussed later in this guide.

<a name="connector-defaults"></a>
### Connector Defaults

You may define default headers, query parameters, Guzzle options, authentication, delays, and request bodies using protected connector methods:

```php
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;

protected function defaultHeaders(): array
{
    return ['X-Api-Version' => '2026-01-01'];
}

protected function defaultQuery(): array
{
    return ['locale' => 'en'];
}

protected function defaultOptions(): array
{
    return ['allow_redirects' => false];
}

protected function defaultAuth(): ?Authenticator
{
    return new TokenAuthenticator($this->token);
}
```

For defaults that require the complete operation, override the connector's `boot` method. The method receives a fresh pending request for each send:

```php
use Hypervel\Saloon\Http\PendingRequest;

public function boot(PendingRequest $pendingRequest): void
{
    $pendingRequest
        ->withHeader('X-Request-Source', 'billing')
        ->timeout(15)
        ->withTelescopeTags(['github']);
}
```

<a name="organizing-sdks"></a>
### Organizing SDKs

When an integration contains many endpoints, you may group related requests behind resource classes. Type the concrete connector in each resource so its integration-specific methods and response types remain visible:

```php
use Hypervel\Saloon\Http\Response;

class RepositoryResource
{
    public function __construct(
        private readonly GitHubConnector $connector,
    ) {
    }

    public function get(string $owner, string $repository): Response
    {
        return $this->connector->send(new GetRepository($owner, $repository));
    }
}
```

Expose the resource from your connector:

```php
public function repositories(): RepositoryResource
{
    return new RepositoryResource($this);
}
```

You may then call the resource from your application:

```php
$response = $github->repositories()->get('hypervel', 'components');
```

Unlike Saloon's `BaseResource`, a normal resource class keeps the concrete connector type instead of narrowing it to the abstract connector.

<a name="http-connections"></a>
### HTTP Connections

Saloon registers one named Hypervel HTTP connection, named `saloon` by default. Synchronous requests through this connection reuse the low-level transport, allowing cURL to retain keep-alive sockets, DNS information, and TLS sessions. Each operation still receives its own pending request, client, middleware stack, and cookie jar.

You may publish the configuration file to change the default connection options. A connector may also select another connection that your application registered during worker boot:

```php
public function resolveHttpConnection(): ?string
{
    return 'github';
}
```

Connection names must come from a fixed, bounded set. Do not create a connection name from a tenant ID, hostname, or credential, since registered connections and their handlers remain in memory for the worker lifetime. One fixed connection may safely send requests to many tenant-selected hosts.

Connection options do not provide an aggregate connection cap. The unsafe Guzzle `max_host_connections` and `max_total_connections` options are rejected. Bound each concurrent operation with a [Saloon pool](#concurrent-requests), and use [rate limits](#rate-limiting) to control request rates. Saloon does not use object pooling because each request needs its own client, middleware stack, and cookie jar.

<a name="defining-requests"></a>
## Defining Requests

A request class defines an API endpoint and HTTP method:

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\GitHub\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class GetUser extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $username,
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/users/{$this->username}";
    }
}
```

Requests are mutable while you prepare one operation. You may create them with `new` or the convenient `make` method:

```php
$request = GetUser::make('hypervel');
```

No-argument requests may also be resolved from the service container and are created fresh on each resolution. Requests that require caller-supplied values should be constructed directly, created with `make`, or given an explicit transient container binding.

<a name="request-methods"></a>
### Request Methods

The `Method` enum provides `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `CONNECT`, `TRACE`, and `QUERY` cases. `QUERY` requests may contain a body and are useful for safe, idempotent queries that are too large for a URI:

```php
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Traits\Body\HasJsonBody;

class SearchUsers extends Request
{
    use HasJsonBody;

    protected Method $method = Method::QUERY;

    public function resolveEndpoint(): string
    {
        return '/users/search';
    }
}
```

<a name="sending-requests"></a>
### Sending Requests

Pass the request to a connector's `send` method:

```php
$response = $github->send(
    GetUser::make('hypervel')->withHeader('X-Trace-ID', $traceId),
);
```

The returned `Hypervel\Saloon\Http\Response` extends Hypervel's normal HTTP response, so the same JSON, header, status, exception, and PSR-7 methods are available.

<a name="standalone-requests"></a>
### Standalone Requests

For a one-off request that does not need an integration connector, extend `SoloRequest` and return an absolute HTTP or HTTPS endpoint:

```php
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\SoloRequest;

class GetStatus extends SoloRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'https://status.example.com/api/status';
    }
}

$response = (new GetStatus)->send();
```

Normal connector requests accept relative endpoints. An absolute endpoint is rejected unless the request or connector explicitly opts into base URL replacement. This protects credentials from being sent to an unexpected host.

Override `allowsBaseUrlOverride` on a request to return `true` when that request may use an application-controlled absolute endpoint. The request method returns `null` by default, which inherits the connector's decision:

```php
public function allowsBaseUrlOverride(): ?bool
{
    return true;
}
```

To allow absolute endpoints for every request sent by a connector, override the connector's `allowsBaseUrlOverride` method instead. The connector method returns `bool` and defaults to `false`.

Only enable this behavior for endpoints your application trusts.

<a name="request-defaults"></a>
### Request Defaults

Request classes may define the same protected defaults as connectors. Request values take precedence when both classes provide the same setting:

```php
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;

protected function defaultHeaders(): array
{
    return ['X-Api-Version' => '2026-01-01'];
}

protected function defaultQuery(): array
{
    return ['include' => 'profile'];
}

protected function defaultOptions(): array
{
    return ['allow_redirects' => false];
}

protected function defaultAuth(): ?Authenticator
{
    return new TokenAuthenticator($this->token);
}

protected function defaultDelay(): ?int
{
    return 100;
}
```

Body traits add a `defaultBody` method for their body type. Use `boot(PendingRequest $pendingRequest)` when a default depends on the complete operation rather than the request constructor alone.

<a name="configuring-requests"></a>
## Configuring Requests

Requests and pending requests provide fluent methods that mirror Hypervel's HTTP client. You may apply these methods before sending a request or from connector, request, middleware, and plugin hooks.

<a name="headers"></a>
### Headers

Use `withHeader` to add one header or `withHeaders` to merge several headers. The `replaceHeaders` method replaces matching header names without removing unrelated headers. Header names are matched without regard to casing:

```php
$request
    ->withHeader('X-Trace-ID', $traceId)
    ->withHeaders([
        'X-Account' => $accountId,
        'X-Feature' => 'reports',
    ])
    ->acceptJson()
    ->withUserAgent('Acme Application/1.0');
```

<a name="query-parameters"></a>
### Query Parameters

Query parameters may be supplied using `withQueryParameters`:

```php
$request->withQueryParameters([
    'page' => 2,
    'per_page' => 50,
]);
```

Request values replace connector values with the same key. Values added later by middleware replace earlier values. Query parameters already present in the connector base URL or request endpoint are preserved unless the request contains the same top-level key.

<a name="authentication"></a>
### Authentication

Saloon provides the same familiar authentication methods as Hypervel's HTTP client:

```php
$request->withToken($token);
$request->withBasicAuth($username, $password);
$request->withDigestAuth($username, $password);
$request->withNtlmAuth($username, $password);
```

You may create a reusable authenticator by implementing `Hypervel\Saloon\Contracts\Authenticator`:

```php
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;

class ApiKeyAuthenticator implements Authenticator
{
    public function __construct(
        private readonly string $key,
    ) {
    }

    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withHeader('X-Api-Key', $this->key);
    }
}
```

Apply a custom authenticator using `authenticate`, or return it from a connector's `defaultAuth` method:

```php
$request->authenticate(new ApiKeyAuthenticator($key));
```

Saloon also includes header, query, token, basic, digest, NTLM, certificate, access-token, and multi-authenticator implementations under `Hypervel\Saloon\Http\Auth`.

Add the `RequiresAuth` trait to a request that must never be sent without an authenticator:

```php
use Hypervel\Saloon\Traits\Auth\RequiresAuth;

class GetPrivateReport extends Request
{
    use RequiresAuth;

    // ...
}
```

You may also add the trait to a connector to require authentication for every request in that integration. Saloon throws `MissingAuthenticatorException` before sending an unauthenticated request. You may override `getRequiresAuthMessage(PendingRequest $pendingRequest): string` when the integration needs a more specific message.

<a name="request-bodies"></a>
### Request Bodies

The `HasJsonBody`, `HasFormBody`, `HasMultipartBody`, `HasStringBody`, `HasStreamBody`, and `HasXmlBody` traits declare the default body type for a request. For example, a JSON request may define its default values using the `defaultBody` method:

```php
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasJsonBody;

class CreateIssue extends Request
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $title,
    ) {
    }

    public function resolveEndpoint(): string
    {
        return '/issues';
    }

    protected function defaultBody(): array
    {
        return ['title' => $this->title];
    }
}
```

The JSON, form, XML, and multipart traits supply the appropriate content type unless you already defined one. String and stream bodies do not assume a content type, so specify one using `contentType` or `defaultHeaders`. Body traits may also be used on connectors when every request shares the same body format; request body values take precedence over connector values.

When both a connector and request define a body, their body repositories must be the same type. For example, a request using `HasFormBody` cannot be sent through a connector using `HasJsonBody`. Saloon throws a `PendingRequestException` when the body types do not match.

You may select or replace the body fluently:

```php
$request->asJson(['name' => 'Taylor']);
$request->asForm(['name' => 'Taylor']);
$request->withBody($stream, 'application/octet-stream');
$request->withBody($stream, null);
```

The `withData` method merges structured values into the current JSON or form body. If the request does not yet have a structured body, the method creates a JSON body:

```php
$request->withData(['active' => true]);
```

Laravel `Arrayable` objects, `JsonSerializable` objects, and stringable values are normalized recursively in JSON, form, and query data.

JSON bodies use `JSON_THROW_ON_ERROR` by default. To use other encoding flags, return a configured `JsonBodyRepository` from `defaultBodyRepository`:

```php
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;

protected function defaultBodyRepository(): ?BodyRepository
{
    return (new JsonBodyRepository($this->defaultBody()))
        ->setJsonFlags(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
```

The request may still use `HasJsonBody` so Saloon supplies the JSON content type. Invalid JSON values throw a `BodyException` instead of being sent as an empty body.

<a name="multipart-requests"></a>
### Multipart Requests

Use the `attach` method to add multipart values or files. The optional filename and headers are applied to that part:

```php
$request
    ->asMultipart()
    ->attach('document', fopen($path, 'rb'), 'report.pdf', [
        'Content-Type' => 'application/pdf',
    ])
    ->attach('description', 'Quarterly report');
```

Saloon preserves multipart streams, filenames, headers, order, and the generated boundary. Caller-owned streams are not buffered automatically.

<a name="custom-body-repositories"></a>
### Custom Body Repositories

If an API requires another body format, you may implement `Hypervel\Saloon\Contracts\Body\BodyRepository`. A repository stores the logical value and converts it into a PSR-7 stream when the operation is finalized:

```php
use GuzzleHttp\Psr7\Utils;
use Hypervel\Saloon\Contracts\Body\MergeableBody;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

class NdjsonBodyRepository implements MergeableBody
{
    public function __construct(
        private array $records = [],
    ) {
    }

    public function set(mixed $value): static
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The NDJSON body must be an array of records.');
        }

        $this->records = $value;

        return $this;
    }

    public function all(): array
    {
        return $this->records;
    }

    public function merge(array ...$arrays): static
    {
        $this->records = array_merge($this->records, ...$arrays);

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function toStream(): StreamInterface
    {
        $body = implode("\n", array_map(
            fn (mixed $record) => json_encode($record, JSON_THROW_ON_ERROR),
            $this->records,
        ));

        return Utils::streamFor($body);
    }
}
```

Implement `MergeableBody` when connector values should be merged with request values. A repository that implements only `BodyRepository` is replaced as a complete value instead. The `withData` method is reserved for the built-in JSON and form repositories; calling it on a custom repository selects a JSON body.

Return the repository from the request or connector's `defaultBodyRepository` method and set the appropriate content type during `boot`:

```php
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Http\PendingRequest;

protected function defaultBodyRepository(): ?BodyRepository
{
    return new NdjsonBodyRepository($this->records);
}

public function boot(PendingRequest $pendingRequest): void
{
    $pendingRequest->contentType('application/x-ndjson');
}
```

<a name="request-options"></a>
### Request Options

You may configure request timeouts, redirects, TLS verification, cookies, response sinks, delays, retries, and other supported Guzzle options:

```php
$request
    ->timeout(30)
    ->connectTimeout(5)
    ->maxRedirects(3)
    ->withoutVerifying()
    ->withCookies(['locale' => 'en'], '.example.com')
    ->delay(100)
    ->withOptions(['proxy' => $proxy]);
```

The `timeout` and `connectTimeout` methods accept seconds, while `delay` accepts milliseconds.

Request-shaping options such as `headers`, `query`, `cookies`, `body`, `json`, `form_params`, `multipart`, `auth`, `delay`, and `http_errors` must be configured through Saloon's dedicated methods. Transport sharing belongs to a fixed Hypervel HTTP connection, while request handlers, object pools, and connection caps are not accepted through `withOptions`.

<a name="middleware"></a>
## Middleware

Middleware may inspect or change the pending request, response, or fatal transport exception. You may register middleware from a connector or request's `boot` method, through a reusable plugin, or globally during application boot.

<a name="request-middleware"></a>
### Request Middleware

Register request middleware using the pending request's middleware pipeline:

```php
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Support\Str;

public function boot(PendingRequest $pendingRequest): void
{
    $pendingRequest->middleware()->onRequest(
        function (PendingRequest $pendingRequest): void {
            $pendingRequest->withHeader('X-Request-ID', (string) Str::uuid());
        },
        name: 'request-id',
    );
}
```

Request middleware may return a `MockResponse` or another implementation of `FakeResponse` to short-circuit the network request.

For reusable middleware, create an invokable class that implements `RequestMiddleware`:

```php
use Hypervel\Saloon\Contracts\FakeResponse;
use Hypervel\Saloon\Contracts\RequestMiddleware;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Support\Str;

class AddRequestId implements RequestMiddleware
{
    public function __invoke(PendingRequest $pendingRequest): ?FakeResponse
    {
        $pendingRequest->withHeader('X-Request-ID', (string) Str::uuid());

        return null;
    }
}
```

Register the class in the same way as a closure: `$pendingRequest->middleware()->onRequest(new AddRequestId)`.

Middleware runs in registration order. You may pass `PipeOrder::First` or `PipeOrder::Last` using the `order` argument when a middleware must run before or after the normal group. Named middleware must have a unique name within its pipeline.

<a name="response-middleware"></a>
### Response Middleware

Response middleware runs after a response is received or supplied by a Saloon fake or cache hit:

```php
use Hypervel\Saloon\Http\Response;

$pendingRequest->middleware()->onResponse(
    function (Response $response): void {
        logger()->info('API request completed', [
            'status' => $response->status(),
        ]);
    },
);
```

A response middleware callback may return another Saloon response to replace the current response.

Invokable response middleware classes may implement `ResponseMiddleware`. Their `__invoke` method returns a replacement response or `null`:

```php
use Hypervel\Saloon\Contracts\ResponseMiddleware;
use Hypervel\Saloon\Http\Response;

class RecordResponse implements ResponseMiddleware
{
    public function __invoke(Response $response): ?Response
    {
        logger()->info('API request completed', ['status' => $response->status()]);

        return null;
    }
}
```

<a name="fatal-exception-middleware"></a>
### Fatal Exception Middleware

Fatal exception middleware receives connection and transport failures after they have been wrapped in a `FatalRequestException`:

```php
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;

$pendingRequest->middleware()->onFatalException(
    function (FatalRequestException $exception): void {
        report($exception);
    },
);
```

<a name="global-middleware"></a>
### Global Middleware

You may register middleware for every Saloon request through the facade. Global middleware persists for the worker lifetime and should be registered only during application boot:

```php
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\PendingRequest;

public function boot(): void
{
    $applicationName = config()->string('app.name');

    Saloon::middleware()->onRequest(
        function (PendingRequest $request) use ($applicationName): void {
            $request->withHeader('X-Application', $applicationName);
        },
    );
}
```

<a name="psr-request-hooks"></a>
### PSR-7 Request Hooks

For signing schemes or libraries that require the final PSR-7 request, override `handlePsrRequest` on a connector or request:

```php
use Hypervel\Saloon\Http\PendingRequest;
use Psr\Http\Message\RequestInterface;

public function handlePsrRequest(
    RequestInterface $request,
    PendingRequest $pendingRequest,
): RequestInterface {
    return $request->withHeader('X-Signature', $this->sign($request));
}
```

The connector hook runs before the request hook. Each hook runs once on the final PSR-7 request for network, fake, and cache-hit responses.

> [!WARNING]
> Saloon's default cache key is created before lower-level Hypervel HTTP middleware or a custom PSR-7 hook can change the response identity. If either layer changes the URI, headers, body, authentication, or another response-affecting value, define a matching [custom cache key](#custom-cache-keys).

<a name="plugins"></a>
### Plugins

Plugins are traits with a boot method named after the trait. They allow connector and request behavior to be shared without another service layer:

```php
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Support\Str;

trait AddsRequestId
{
    public function bootAddsRequestId(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withHeader('X-Request-ID', (string) Str::uuid());
    }
}
```

Use the trait on any connector or request. Saloon discovers plugin boot methods once per concrete class and invokes them for each operation.

<a name="responses"></a>
## Responses

<a name="inspecting-responses"></a>
### Inspecting Responses

Saloon responses inherit the complete [Hypervel HTTP response API](/docs/{{version}}/http-client#making-requests). For example, you may inspect JSON data, headers, status codes, transfer information, and the underlying PSR response:

```php
$response->body();
$response->json('user.name');
$response->object();
$response->collect('items');
$response->status();
$response->successful();
$response->clientError();
$response->serverError();
$response->header('X-RateLimit-Remaining');
$response->toPsrResponse();
$response->dataUrl();
```

The `dataUrl` method returns the response body as a base64 data URL using its `Content-Type` header.

Saloon also provides access to the integration objects and final request:

```php
$response->connector();
$response->request();
$response->pendingRequest();
$response->toPsrRequest();
$response->isCached();
$response->isMocked();
$response->isFaked();
```

<a name="xml-and-html-responses"></a>
### XML and HTML Responses

The `xml` method converts the response body into a `SimpleXMLElement`, while `dom` returns a Symfony DOM crawler:

```php
$xml = $response->xml();
$name = $xml === false ? null : (string) $xml->user->name;

$title = $response->dom()->filter('title')->text();
```

<a name="saving-response-bodies"></a>
### Saving Response Bodies

Use `saveBodyToFile` to copy the response body to a path or writable resource:

```php
$response->saveBodyToFile(storage_path('app/report.pdf'));

$stream = fopen('php://temp', 'wb+');
$response->saveBodyToFile($stream, closeResource: false);
```

The `getRawStream` method returns a temporary PHP resource containing the response body. The `stream` method returns the underlying PSR-7 stream. Saloon preserves the position of seekable response streams. A non-seekable response stream is copied from its current position.

<a name="data-objects"></a>
### Data Objects

A request or connector may convert responses into any value using `createDtoFromResponse`. Your data object may be a plain class, a Hypervel data object, or an object from another DTO package:

```php
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Request;
use Hypervel\Data\Data;

class GitHubUserData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $login,
    ) {
    }
}

/** @extends Request<GitHubUserData> */
class GetUser extends Request
{
    // ...

    public function createDtoFromResponse(Response $response): GitHubUserData
    {
        return GitHubUserData::from($response->json());
    }
}
```

Call `dto` to create the configured value. The `dtoOrFail` method refuses to create it when the integration considers the response failed:

```php
$user = $github->send(new GetUser('hypervel'))->dtoOrFail();
```

The generic `@extends Request<GitHubUserData>` annotation allows static analysis to infer the return type through `send`, `dto`, and `dtoOrFail`. No runtime DTO interface is required. A data object may implement `Hypervel\Saloon\Contracts\DataObjects\WithResponse` when it needs access to the response that created it.

Saloon's `HasResponse` trait implements this contract for you:

```php
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Traits\Responses\HasResponse;
use Hypervel\Data\Data;

class GitHubUserData extends Data implements WithResponse
{
    use HasResponse;

    // ...
}

$response = $user->getResponse();
```

<a name="custom-responses"></a>
### Custom Responses

To add integration-specific response methods, extend `Hypervel\Saloon\Http\Response` and set the request or connector's `$response` property:

```php
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Request;

class GitHubResponse extends Response
{
    public function requestId(): ?string
    {
        return $this->header('X-GitHub-Request-Id') ?: null;
    }
}

class GetUser extends Request
{
    protected ?string $response = GitHubResponse::class;

    // ...
}
```

If the response class depends on response data, override `resolveResponseClass(Response $response)` and return a Saloon response class or `null`. A request resolver takes precedence over a connector resolver.

<a name="error-handling"></a>
### Error Handling

Saloon does not throw automatically for HTTP error responses. You may inspect the response or call `throw`:

```php
if ($response->failed()) {
    // ...
}

$response->throw();
```

By default, 4xx responses throw `ClientException`, 5xx responses throw `ServerException`, and other integration-defined failures throw `RequestException`. Transport failures throw `FatalRequestException`.

You may define provider-specific failure behavior on a request or connector:

```php
public function hasRequestFailed(Response $response): ?bool
{
    return $response->json('ok') === false ? true : null;
}

public function shouldThrowRequestException(Response $response): bool
{
    return $response->status() !== 404 && $response->failed();
}
```

In this example, the integration treats a 404 response as an empty result instead of an exception.

A non-null request failure decision takes precedence over the connector decision, which takes precedence over the status-code fallback. You may return a custom Saloon request exception from `getRequestException`. The `AlwaysThrowOnErrors` plugin calls `throw` automatically after each response.

<a name="retries"></a>
### Retries

Use `retry` to specify the number of attempts and delay between attempts:

```php
$request->retry(3, 100);
```

You may supply an array of delays or calculate the delay using a closure:

```php
$request->retry([100, 250, 500]);

$request->retry(
    4,
    fn (int $attempt) => $attempt * 100,
    when: fn ($exception, $pendingRequest) => $exception->getCode() !== 401,
    throw: false,
);
```

Each attempt receives a fresh pending request, so middleware and authentication run again. Seekable request bodies are restored before another attempt. Saloon throws a `BodyException` rather than retrying a consumed non-seekable body.

<a name="debugging"></a>
### Debugging

Use `debugRequest`, `debugResponse`, or `debug` on a request to inspect one operation:

```php
$request->debug();
$request->debugRequest();
$request->debugResponse();
```

The `debugRequest` and `debugResponse` methods accept a custom callback. All three methods accept `die: true`, which terminates the current worker process and is intended only for local debugging.

> [!WARNING]
> Debug output contains raw headers and bodies and may expose credentials or other sensitive values. Custom callbacks are also responsible for any stream reads they perform.

<a name="oauth2"></a>
## OAuth 2

Saloon includes support for the OAuth 2 authorization code, client credentials, and client credentials with HTTP Basic authentication grants. OAuth configuration is immutable so a connector may be reused safely by concurrent requests.

<a name="authorization-code-grant"></a>
### Authorization Code Grant

To use the authorization code grant, add the `AuthorizationCodeGrant` trait to your connector and define its OAuth configuration:

```php
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;

class GitHubConnector extends Connector
{
    use AuthorizationCodeGrant;

    // ...

    protected function defaultOAuthConfig(): OAuthConfig
    {
        return new OAuthConfig(
            clientId: config()->string('services.github.client_id'),
            clientSecret: config()->string('services.github.client_secret'),
            redirectUri: route('github.callback'),
            authorizeEndpoint: 'https://github.com/login/oauth/authorize',
            tokenEndpoint: 'https://github.com/login/oauth/access_token',
            userEndpoint: '/user',
            defaultScopes: ['read:user'],
            allowBaseUrlOverride: true,
        );
    }
}
```

The `allowBaseUrlOverride` option is required here because GitHub's trusted authorization and token endpoints use a different host from the connector's API base URL. Leave this option disabled when the OAuth endpoints are relative to the connector base URL.

The `authorizationUrl` method returns the URL together with its generated state. Store the state for the current authorization attempt before redirecting the user:

```php
$authorization = $github->authorizationUrl(['user:email']);

session(['github_oauth_state' => $authorization->state]);

return redirect((string) $authorization);
```

After the provider redirects to your application, exchange the code and validate the returned state:

```php
$authenticator = $github->getAccessToken(
    code: $request->string('code')->toString(),
    state: $request->string('state')->toString(),
    expectedState: session('github_oauth_state'),
);

$response = $github->send(
    (new GetUser('hypervel'))->authenticate($authenticator),
);
```

If either state value is supplied, both values must be non-empty and equal. Keeping the state beside each authorization attempt allows one connector to support concurrent tabs and users without shared mutable state.

You may pass a PKCE code challenge to `authorizationUrl` and the corresponding verifier to `getAccessToken`:

```php
$authorization = $github->authorizationUrl(
    codeChallenge: $challenge,
    codeChallengeMethod: 'S256',
);

$authenticator = $github->getAccessToken(
    code: $code,
    state: $returnedState,
    expectedState: $expectedState,
    codeVerifier: $verifier,
);
```

The `refreshAccessToken` method accepts either a refresh-token string or an OAuth authenticator containing a refresh token. The `getUser` method sends the configured user request with an OAuth authenticator.

<a name="client-credentials-grant"></a>
### Client Credentials Grant

For machine-to-machine integrations, use the `ClientCredentialsGrant` trait:

```php
use Hypervel\Saloon\Traits\OAuth2\ClientCredentialsGrant;

class ServiceConnector extends Connector
{
    use ClientCredentialsGrant;

    // Define resolveBaseUrl and defaultOAuthConfig...
}

$authenticator = $connector->getAccessToken(['reports:read']);
```

Use `ClientCredentialsBasicAuthGrant` when the provider requires the client credentials in an HTTP Basic authentication header.

OAuth token grant methods return an OAuth authenticator by default. Pass `returnResponse: true` when you need the original Saloon response instead:

```php
$response = $connector->getAccessToken(
    scopes: ['reports:read'],
    returnResponse: true,
);
```

<a name="refreshing-access-tokens"></a>
### Refreshing Access Tokens

An `AccessTokenAuthenticator` exposes its access token, optional refresh token, expiry, and expiration helpers:

```php
if ($authenticator->hasExpired() && $authenticator->isRefreshable()) {
    $authenticator = $connector->refreshAccessToken($authenticator);
}
```

OAuth token expiry values use immutable dates. Saloon rejects negative or unrepresentable expiry durations instead of creating an already-expired token.

<a name="customizing-oauth-requests"></a>
### Customizing OAuth Requests

Use the OAuth configuration's `requestModifier` callback to customize every token and user request:

```php
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Request;

return new OAuthConfig(
    clientId: $this->clientId,
    clientSecret: $this->clientSecret,
    redirectUri: $this->redirectUri,
    requestModifier: function (Request $request): void {
        $request->withHeader('X-Application', 'billing');
    },
);
```

The `getAccessToken`, `refreshAccessToken`, and `getUser` methods also accept a `requestModifier` callback for one operation:

```php
$authenticator = $connector->getAccessToken(
    code: $code,
    requestModifier: function (Request $request): void {
        $request->withQueryParameters(['access_type' => 'offline']);
    },
);
```

For providers that require a different request body or token response format, override the protected `resolveAccessTokenRequest`, `resolveRefreshTokenRequest`, `resolveUserRequest`, `createOAuthAuthenticatorFromResponse`, or `createOAuthAuthenticator` method on the connector. These methods let you adapt the provider protocol without replacing the OAuth flow.

<a name="concurrent-requests"></a>
## Concurrent Requests

Saloon pools send requests through bounded Hypervel coroutines. They replace promise-based asynchronous APIs and preserve the current application context in each child coroutine.

<a name="collecting-responses"></a>
### Collecting Responses

Create a pool through a connector and call `send` to collect successful responses. The iterable's keys are preserved, and the returned array follows input order rather than completion order:

```php
$pool = $github->pool([
    'taylor' => new GetUser('taylorotwell'),
    'hypervel' => new GetUser('hypervel'),
], concurrency: 5);

$responses = $pool->send();

$responses['hypervel']->throw();
```

The pool also accepts a lazy iterable or a producer callback that receives the connector:

```php
$responses = $github->pool(function (Connector $connector) use ($usernames) {
    foreach ($usernames as $username) {
        yield $username => new GetUser($username);
    }
}, concurrency: 10)->send();
```

The concurrency value must be a positive integer. Scheduling blocks when the bound is full, so a lazy iterable does not create an unbounded queue of child coroutines.

You may also create an empty pool and configure it fluently before sending:

```php
$responses = $github->pool()
    ->setRequests($requests)
    ->setConcurrency(10)
    ->withResponseHandler($responseHandler)
    ->withExceptionHandler($exceptionHandler)
    ->send();
```

<a name="processing-large-iterables"></a>
### Processing Large Iterables

If a response handler owns each result and you do not need a response array, call `process`. Successful responses are not retained:

```php
$github->pool(
    requests: $requests,
    concurrency: 10,
    responseHandler: function (Response $response, string $key): void {
        ProcessImportedUser::dispatch($key, $response->json());
    },
)->process();
```

Response and exception handlers run inside the request's child coroutine. Services used by those handlers must therefore be coroutine-safe.

<a name="handling-pool-failures"></a>
### Handling Pool Failures

You may handle request failures while allowing the remaining requests to finish:

```php
$responses = $github->pool(
    requests: $requests,
    exceptionHandler: function (Throwable $exception, string $key): void {
        report($exception);
    },
)->send();
```

A handled failure is omitted from the returned responses. Without an exception handler, or when a response or exception callback fails, Saloon waits for every started child and then throws `PoolException`. The exception provides `orchestrationFailure`, `failures`, `callbackFailures`, and `responses` methods so no completed work or cause is lost.

<a name="caching"></a>
## Caching

Saloon can cache successful responses using Hypervel's cache stores. Caching is opt-in and may be declared by a connector or request.

<a name="caching-connector-responses"></a>
### Caching Connector Responses

To cache an integration's responses, implement `Cacheable` on its connector:

```php
use DateInterval;
use DateTimeInterface;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use UnitEnum;

class GitHubConnector extends Connector implements Cacheable
{
    // ...

    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 300;
    }

    public function cacheStore(): UnitEnum|string|null
    {
        return 'redis';
    }
}
```

A null store uses `saloon.cache.store`, which itself falls back to Hypervel's default cache store. A request that implements `Cacheable` supplies its duration and store before the connector.

An integer cache duration is measured in seconds. You may also return a `DateInterval` or `DateTimeInterface` instance.

GET, HEAD, OPTIONS, and QUERY requests are cacheable by default. Only successful network responses are written. Saloon fakes do not read or populate the response cache.

The default cache key includes the connector and request classes, method, final URI, headers, cookies, authentication and certificate state, prepared body, and response-affecting transport options. This means a QUERY request's body is part of its identity. Responses cached from streaming bodies are buffered because the cache must retain their bytes.

Caching cannot be combined with Guzzle's `sink` option. A cache hit could not reproduce the sink's file or resource side effect. Export the returned response explicitly when you need both behaviors.

<a name="request-cache-controls"></a>
### Request Cache Controls

Add the `HasCaching` trait to a request when callers should be able to enable, bypass, or refresh caching for one operation:

```php
use Hypervel\Saloon\Cache\Traits\HasCaching;

class GetUser extends Request
{
    use HasCaching;

    // ...
}
```

The trait provides the following fluent methods:

```php
$request->enableCaching();
$request->disableCaching();
$request->invalidateCache();
```

The request or its connector must implement `Cacheable` before these controls are used. `invalidateCache` removes the matching value and refreshes it from the network.

<a name="custom-cache-keys"></a>
### Custom Cache Keys

A connector may define a custom logical key by overriding `cacheKey`. A request may define one after adding the `HasCaching` trait:

```php
use Hypervel\Saloon\Http\PendingRequest;

protected function cacheKey(PendingRequest $pendingRequest): ?string
{
    return 'github-user:' . $this->username;
}
```

Saloon hashes custom keys before passing them to the cache backend, so raw credentials and tenant identifiers are not exposed in backend key names. A custom key must include every value that can change the successful response. It is required when a non-seekable request body is cached or when lower-level PSR or HTTP middleware changes response identity after Saloon finalizes the operation.

You may override `cacheableMethods` to change which methods are eligible. A request override takes precedence over the connector's list.

<a name="cache-scopes"></a>
### Cache Scopes

Applications that require an explicit tenant, account, or workspace partition may register a cache-scope resolver during application boot:

```php
use App\Http\Integrations\Reports\ReportsConnector;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Support\Facades\Context;

public function boot(): void
{
    Saloon::resolveCacheScopeUsing(
        function (PendingRequest $pendingRequest): ?string {
            if (! $pendingRequest->connector() instanceof ReportsConnector) {
                return null;
            }

            $tenantId = Context::get('tenant_id');

            return is_string($tenantId) ? $tenantId : null;
        },
    );
}
```

The callback runs when a cacheable operation resolves its key. It must resolve the current tenant at invocation time; do not capture one tenant while the worker boots. Returning null deliberately allows sharing for connectors where a separate scope is not needed.

Authentication state already separates tenant-owned credentials, and the final URI separates tenant-specific endpoints. A cache scope expresses an additional application policy, such as preventing tenants with shared platform credentials from sharing provider responses. It is applied to reads, writes, invalidation, and custom keys.

<a name="api-pagination"></a>
## API Pagination

Saloon pagination iterates through pages returned by a remote API. It is separate from Hypervel's [application pagination](/docs/{{version}}/pagination), which prepares local data for views and JSON responses.

A paginated request must implement the `Paginatable` marker contract. Create a paginator class for the remote API's pagination format.

<a name="page-pagination"></a>
### Page Pagination

For page-number pagination, extend `PagedPaginator` and describe how to find the items and last page:

```php
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Contracts\HasPagination;
use Hypervel\Saloon\Pagination\Contracts\HasRequestPagination;
use Hypervel\Saloon\Pagination\Contracts\Paginatable;
use Hypervel\Saloon\Pagination\PagedPaginator;
use Hypervel\Saloon\Pagination\Paginator;

class ListUsers extends Request implements Paginatable
{
    // Define the request method and endpoint...
}

class GitHubPaginator extends PagedPaginator
{
    protected function isLastPage(Response $response): bool
    {
        return $response->json('page') >= $response->json('pages');
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json('data');
    }

    protected function getTotalPages(Response $response): int
    {
        return (int) $response->json('pages');
    }
}

class GitHubConnector extends Connector implements HasPagination
{
    // Define the connector base URL and defaults...

    public function paginate(Request $request): Paginator
    {
        if ($request instanceof HasRequestPagination) {
            return $request->paginate($this);
        }

        return new GitHubPaginator($this, $request);
    }
}
```

Call the connector's `paginate` method, then iterate over responses or items:

```php
$paginator = $github->paginate(new ListUsers)
    ->perPageLimit(100)
    ->maxPages(10);

foreach ($paginator->items() as $user) {
    // ...
}

$users = $paginator->collect();
```

The `HasPagination` contract provides the conventional connector entry point. A request that needs its own paginator may implement `HasRequestPagination` and define `paginate(Connector $connector): Paginator`; the connector can delegate to it as shown above.

The `collect(false)` method returns a lazy collection of page responses instead of items. You may also inspect `totalResults`, `request`, and the zero-based iterator position returned by `currentPage`. Use `startPage` to configure the first remote page number.

Calling `count($paginator)` counts remote pages by requesting each page. It is not a metadata-only operation.

`PagedPaginator`, `OffsetPaginator`, and `CursorPaginator` use the conventional `page`, `per_page`, `limit`, `offset`, and `cursor` query names. Override `applyPagination` when an API uses different parameters:

```php
protected function applyPagination(Request $request): Request
{
    $parameters = ['currentPage' => $this->pageNumber];

    if ($this->perPageLimit !== null) {
        $parameters['pageSize'] = $this->perPageLimit;
    }

    return $request->withQueryParameters($parameters);
}
```

If a request implements `MapPaginatedResponseItems`, its `mapPaginatedResponseItems` method takes precedence over the paginator's item mapping.

<a name="offset-and-cursor-pagination"></a>
### Offset and Cursor Pagination

Extend `OffsetPaginator` for APIs that use `limit` and `offset`. A per-page limit must be configured before iteration. Extend `CursorPaginator` for APIs where each response supplies the next cursor, and implement `getNextCursor`.

Cursor pagination is always sequential because a later request depends on the previous response. Rewinding a paginator clears its iterator state and begins again at the configured start page.

<a name="pooled-pagination"></a>
### Pooled Pagination

Page and offset paginators may send independently addressable pages through a bounded coroutine pool when `getTotalPages` is implemented:

```php
$responses = $paginator->pool(
    concurrency: 5,
    responseHandler: function (Response $response, int $position): void {
        // ...
    },
);
```

The first page is sent before the remaining range is scheduled. Response keys and callback positions use the paginator's zero-based iterator position. `maxPages` is honored, and pool failures retain the first response and all other completed work.

<a name="rate-limiting"></a>
## Rate Limiting

Saloon uses Hypervel's [rate limiter](/docs/{{version}}/rate-limiting) for atomic admission policies and provider-directed cooldowns. Policies may be declared by a connector, request, or both.

<a name="defining-policies"></a>
### Defining Policies

Add `HasRateLimits` and return one or more immutable admission policies:

```php
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Limit;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;

class GitHubConnector extends Connector
{
    use HasRateLimits;

    /** @return list<AdmissionPolicy> */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [
            Limit::perMinute(60)->by('github'),
        ];
    }
}
```

By default, a denied policy throws `RateLimitReachedException` before reaching the network. Override `waitForRateLimits` to return true when the operation should sleep until capacity is available:

```php
protected function waitForRateLimits(): bool
{
    return true;
}
```

You may select another configured rate-limiter store by overriding `resolveRateLimitStore`. Fakes and cache hits do not consume capacity.

When several policies apply, Saloon consumes them in declaration order. If a later policy denies the operation, earlier successful reservations remain consumed because the policies may live under different atomic keys or stores. Place broad, inexpensive policies first.

<a name="tenant-and-service-limits"></a>
### Tenant and Service Limits

The pending request allows policies to use operation and tenant context. A single operation may consume a tenant-plan policy and a service-wide provider policy:

```php
protected function resolveRateLimits(PendingRequest $pendingRequest): array
{
    $tenant = tenant();

    return [
        Limit::perMinute($tenant->plan->apiRequestsPerMinute)
            ->by('github'),
        Limit::perMinute(5_000)
            ->by('github-service')
            ->globally(),
    ];
}
```

Register a named key-scope resolver during application boot to partition Saloon's non-global policies by the current tenant:

```php
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\RateLimiter;

RateLimiter::resolveKeyScopeUsing(function (string $limiter): ?string {
    if (! str_starts_with($limiter, 'saloon:')) {
        return null;
    }

    $tenantId = Context::get('tenant_id');

    return is_string($tenantId) ? $tenantId : null;
});
```

Saloon limiter names always begin with `saloon:`. Restricting the callback prevents an integration-specific tenant scope from changing unrelated route or queue limiter keys. Policies marked `globally` deliberately bypass named key scopes, allowing the whole application to share a provider limit across tenants, workers, and servers when the selected store is shared.

<a name="server-cooldowns"></a>
### Server Cooldowns

When a rate-limited connector or request receives a 429 response with a valid `Retry-After` header, Saloon records the provider's cooldown before response middleware runs. A later operation checks this cooldown before consuming its configured admission policies.

By default, the connector or request class identifies the cooldown. If a provider applies cooldowns per account or credential, override `resolveRateLimitCooldownKey` with that stable provider identity:

```php
protected function resolveRateLimitCooldownKey(PendingRequest $pendingRequest): string
{
    return static::class . ':account:' . $this->providerAccountId;
}
```

Do not infer this key from a hostname or assume every provider limits by token. Use the boundary documented by the provider.

You may override `resolveRateLimitCooldown` to support a provider-specific response or clamp a provider-defined maximum:

```php
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Limit;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;

class GitHubConnector extends Connector
{
    use HasRateLimits {
        resolveRateLimitCooldown as protected resolveDefaultRateLimitCooldown;
    }

    /** @return list<AdmissionPolicy> */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(60)->by('github')];
    }

    protected function resolveRateLimitCooldown(Response $response): ?int
    {
        $seconds = $this->resolveDefaultRateLimitCooldown($response);

        return $seconds === null ? null : min($seconds, 3_600);
    }
}
```

A 429 response is returned through normal error handling. Recording a cooldown never resends the response recursively. If the normal retry policy requests another attempt, that attempt must first pass the recorded cooldown.

<a name="queued-requests"></a>
### Queued Requests

Queued jobs may use `ReleaseOnRateLimit` to release themselves for the delay reported by a denied admission policy or server cooldown:

```php
use Hypervel\Saloon\RateLimit\Queue\ReleaseOnRateLimit;

public function middleware(): array
{
    return [new ReleaseOnRateLimit];
}
```

The middleware catches only `RateLimitReachedException`. Other exceptions continue through the normal queue failure path. Each release consumes a job attempt, so configure the job's `$tries` or `retryUntil` method for the amount of time it may continue waiting on a limited API.

<a name="multi-tenant-integrations"></a>
## Multi-Tenant Integrations

The appropriate connector lifetime depends on who owns the remote integration configuration.

When every tenant uses the same platform-owned credential and endpoint, bind one stable connector and apply tenant-specific request values at the operation boundary:

```php
$response = $github->send(
    (new CreateReport($report))->withHeader('X-Tenant', $tenant->externalId),
);
```

When each tenant supplies its own credentials or endpoint, construct a connector from that tenant's immutable values:

```php
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Connector;

class TenantCrmConnector extends Connector
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }
}

$crm = new TenantCrmConnector(
    baseUrl: $tenant->crm_url,
    token: $tenant->crm_token,
);

$response = $crm->send(new ListContacts);
```

> [!WARNING]
> Validate tenant-supplied base URLs before storing them. Saloon requires an HTTP or HTTPS URL, but your application must decide which external hosts it trusts.

Do not mutate application config or a worker-shared connector during request handling. The connector's authentication and final URI become part of the default cache identity, so different BYO credentials and tenant endpoints are isolated without extra cache configuration.

For shared credentials, decide whether provider responses may be shared. Register a [cache scope](#cache-scopes) only when your application requires another tenant boundary. Likewise, use a [rate-limiter key scope](#tenant-and-service-limits) for tenant-plan limits and a `globally` policy for provider-wide capacity.

<a name="telescope"></a>
## Telescope

Real Saloon network requests flow through Hypervel's HTTP client and are recorded by the [Telescope HTTP watcher](/docs/{{version}}/telescope). Every recorded request includes the `saloon` tag.

Use `withTelescopeTags` to add operation-specific tags, or `withoutTelescope` to suppress one request:

```php
$request->withTelescopeTags(['billing', 'stripe']);
$request->withoutTelescope();
```

A connector may apply either method from `boot` when the setting belongs to every operation:

```php
public function boot(PendingRequest $pendingRequest): void
{
    $pendingRequest->withTelescopeTags(['github']);
}
```

Saloon fakes and cache hits do not reach the HTTP watcher and are therefore not recorded as network requests. These methods remain safe when Telescope is disabled or not installed.

<a name="events"></a>
## Events

Saloon dispatches `SendingSaloonRequest` after ordinary request middleware and `SentSaloonRequest` before ordinary response middleware. The events expose the pending request, and the sent event also exposes the response:

```php
use Hypervel\Saloon\Events\SentSaloonRequest;

class RecordProviderUsage
{
    public function handle(SentSaloonRequest $event): void
    {
        // $event->pendingRequest
        // $event->response
    }
}
```

These domain events are dispatched for network responses, Saloon fakes, and cache hits. Hypervel HTTP events and Telescope recording apply only when the request reaches the HTTP client.

<a name="macros"></a>
## Macros

Connectors, requests, pending requests, and responses support macros. Register macros during application boot:

```php
use Hypervel\Saloon\Http\Request;

Request::macro('forTenant', function (string $tenantId) {
    return $this->withHeader('X-Tenant', $tenantId);
});
```

Macros remain registered for the worker lifetime. Hypervel automatically clears Saloon macros between tests.

<a name="testing"></a>
## Testing

Saloon provides a strict mock client, response sequences, fixtures, request recording, and PHPUnit assertions. Saloon fakes operate at the integration layer, before caching, admission policies, and Hypervel HTTP transport.

<a name="faking-responses"></a>
### Faking Responses

Use the `Saloon` facade to replace responses for the current test application:

```php
use App\Http\Integrations\GitHub\Requests\GetUser;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockResponse;

Saloon::fake([
    GetUser::class => MockResponse::make([
        'id' => 1,
        'login' => 'hypervel',
    ]),
]);
```

Responses may be matched by request class, connector class, or wildcard URL. Request matches take precedence over connector matches, followed by URL matches and sequence responses:

```php
Saloon::fake([
    GetUser::class => MockResponse::make(['login' => 'hypervel']),
    GitHubConnector::class => MockResponse::make(['ok' => true]),
    'https://api.github.com/repos/*' => MockResponse::make([], 404),
]);
```

Each matching value may also be a callback that receives the pending request and returns a mock response or fixture. A lower-level `Http::fake()` still prevents a network request after the Saloon lifecycle reaches Hypervel's HTTP client. The response is recorded by the HTTP client and `isMocked()` returns false. When no Saloon mock client is active, Saloon facade assertions do not include the response.

You may attach a mock client to one request using `withMockClient`, or pass it as the second argument to `Connector::send`. An explicitly supplied client takes precedence over a request client, which takes precedence over the facade's global test client.

Mock clients are strict by default. An unmatched request throws `NoMockResponseFoundException` instead of reaching the network.

To prevent every unmocked HTTP request from reaching the network, including a Saloon request sent without a mock client, use Hypervel HTTP's application-wide guard:

```php
use Hypervel\Support\Facades\Http;

Http::preventStrayRequests();
```

`Http::preventStrayRequests()` guards the transport even when a Saloon mock client allows an unmatched request to continue. A Saloon mock client's `preventStrayRequests` and `allowStrayRequests` methods only decide whether unmatched requests may reach that transport boundary.

<a name="partial-fakes"></a>
### Partial Fakes

Use `allowStrayRequests` when selected unmatched requests should continue through the normal cache, rate-limit, and network lifecycle:

```php
$client = Saloon::fake([
    GetUser::class => MockResponse::make(['login' => 'hypervel']),
]);

$client->allowStrayRequests([
    'https://api.github.com/rate_limit*',
]);
```

Calling `allowStrayRequests()` without an array permits every unmatched request. Use `preventStrayRequests()` to restore strict behavior.

<a name="response-sequences"></a>
### Response Sequences

Numeric response entries are consumed in registration order:

```php
Saloon::fake([
    MockResponse::make(['page' => 1]),
    MockResponse::make(['page' => 2]),
]);
```

When no class or URL response matches, Saloon consumes the next sequence response. An exhausted strict mock client throws an exception.

<a name="assertions"></a>
### Assertions

The facade provides assertions for recorded Saloon requests:

```php
Saloon::assertSent(GetUser::class);
Saloon::assertSent('https://api.github.com/users/*');
Saloon::assertNotSent(DeleteUser::class);
Saloon::assertSentCount(1);
Saloon::assertNothingSent();
```

You may inspect the request and response with a closure. A request type or union filters the recorded requests before the closure is invoked:

```php
Saloon::assertSent(function (GetUser $request, Response $response): bool {
    return $request->username === 'hypervel'
        && $response->successful();
});
```

The `MockClient` also provides `assertSentInOrder`, `recorded`, `lastRequest`, `lastPendingRequest`, and `lastResponse`.

<a name="fixtures"></a>
### Fixtures

Fixtures record a real response on first use and replay it on later requests:

```php
use Hypervel\Saloon\Http\Faking\Fixture;

Saloon::fake([
    GetUser::class => new Fixture('github/users/hypervel'),
]);
```

By default, fixtures are stored under `tests/Fixtures/Saloon`. Fixture names use portable path segments separated by forward slashes. Absolute paths, backslashes, empty segments, and `.` or `..` segments are rejected.

To redact sensitive response data, extend `Fixture` and define header, JSON, or regular-expression replacement rules:

```php
class GitHubFixture extends Fixture
{
    protected function defineSensitiveHeaders(): array
    {
        return ['set-cookie' => '[redacted]'];
    }

    protected function defineSensitiveJsonParameters(): array
    {
        return ['token' => '[redacted]'];
    }

    protected function defineSensitiveRegexPatterns(): array
    {
        return ['/secret=[^&]+/' => 'secret=[redacted]'];
    }
}
```

Invalid or failed regular expressions prevent the fixture from being written. You may use `merge` or `through` to adjust a recorded JSON object or array during replay, and `withContext` to store additional fixture metadata.

Tests may override the fixture directory and missing-fixture behavior:

```php
Saloon::fixturePath(base_path('tests/Fixtures/Api'));
Saloon::throwOnMissingFixtures();
```

These are tests-only settings and are cleared when the test application is destroyed.

<a name="publishing-configuration-and-stubs"></a>
## Publishing Configuration and Stubs

To publish Saloon's configuration file, use the `saloon-config` tag:

```shell
php artisan vendor:publish --tag=saloon-config
```

The configuration file contains the fixed HTTP connection, default cache and rate-limiter stores, fixture settings, and generated integration path and namespace.

You may publish the generator stubs using the `saloon-stubs` tag:

```shell
php artisan vendor:publish --tag=saloon-stubs
```

Set `saloon.integrations_path` to change where generated files are written. Set `saloon.integrations_namespace` when that path uses a custom Composer namespace. The path and namespace are configured independently; Saloon does not guess a namespace from an arbitrary filesystem path.

<a name="differences-from-saloon"></a>
## Differences From Saloon

Hypervel Saloon keeps the connector, request, middleware, authentication, response, testing, OAuth 2, caching, pagination, and rate-limit concepts of Saloon while using Hypervel's framework services directly.

- Hypervel HTTP is the only transport. Sender factories, configurable Guzzle senders, and process-global Saloon configuration are not included.
- Request customization uses Hypervel HTTP names such as `withHeader`, `withQueryParameters`, `withOptions`, `timeout`, `withToken`, and `retry`.
- Connectors contain stable defaults. Operation-specific authentication, caching, debugging, middleware, retries, and mocks belong to requests and pending requests.
- Coroutine-native pools replace promises and `sendAsync`. Paginator concurrency uses the same bounded pool.
- Cache, pagination, and rate limiting are included in `hypervel/saloon` and use Hypervel's cache, collections, coroutine, and rate-limiter services.
- Rate limits use Hypervel `AdmissionPolicy` instances instead of Saloon's mutable limit and store abstractions.
- OAuth 2 configuration is immutable, authorization URLs return their paired state, and OAuth 1 is not included.
- Saloon responses extend Hypervel HTTP responses rather than forwarding a selected subset of methods.
- Test fixture settings are configured through the `Saloon` facade instead of a process-global mock configuration object.
- Application-wide stray-request protection uses `Http::preventStrayRequests()`. Saloon mock clients separately control unmatched requests while they are active.
- Saloon's `BaseResource` is not included. Use a normal resource class typed to the concrete connector so integration-specific methods and DTO types remain available.
- The optional `xmlReader` response extension is not included. Use the built-in `xml` or `dom` methods instead.

These differences remove framework-neutral adapter layers while retaining the public concepts needed to build complete integrations and reusable SDKs for Hypervel.

<a name="credits"></a>
## Credits

Hypervel Saloon began as a port of [Saloon](https://github.com/saloonphp/saloon) and has been adapted for Hypervel's framework architecture and coroutine runtime.
