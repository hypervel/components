# Hypervel Saloon

Documentation: https://hypervel.org/docs/saloon

## Differences From Laravel

Requests and pending requests use fluent methods in the style of Hypervel's HTTP client, such as `withHeaders`, `withoutHeader`, `withQueryParameters`, `withOptions` and `delay`, and their getters return plain values. Upstream's `ArrayStore` and `IntegerStore` objects (`headers()->add()`, `query()->remove()`, `delay()->set()`) are not available, and upstream's `config()` and `defaultConfig()` are `options()` and `defaultOptions()`. Pending requests change the method and URL with `withMethod` and `withUrl` instead of `setMethod` and `setUrl`, and `uri()` replaces `getUrl()`, including the query string.

Connectors are read-only. Their headers, query parameters, options, body, authenticator and delay come from their `default*` methods and constructor values, because a connector may be shared by concurrent requests. Apply per-operation values to the request, or change connector defaults on the pending request in a hook or middleware. For the same reason, requests and connectors allow absolute endpoints by overriding `allowsBaseUrlOverride()`; upstream's public `$allowBaseUrlOverride` property has no effect. `HasApiVersion` has no `setApiVersion()`: assign `$apiVersion` in the constructor or override `getApiVersion()`.

`createPendingRequest()` takes no mock client. Mock clients are chosen and matched when the request is sent, after request middleware has run and the URL is final, so middleware does not see a mock response and pending requests have no `hasMockClient()` or `getMockClient()`.

A custom response resolver receives the base response, so override `resolveResponseClass(Response $response)` instead of upstream's method without arguments.

Every request supports a body, so there is no `HasBody` contract to implement. The body traits set the default body, `body()` returns its value rather than a repository, and `withBody`, `asJson`, `asForm`, `asMultipart`, `attach` and `withData` change it. `ArrayBodyRepository` is abstract, so use `JsonBodyRepository` or `FormBodyRepository` instead. `MultipartValue` also accepts booleans, `null` and arrays without a filename or headers, which Guzzle sends as fields, so `withData` can add fields to a multipart body. A multipart body's `Content-Type` is added when the body is prepared, after request middleware runs, so it carries the final body's boundary unless you set a content type that declares its own.

The `HasTimeout` plugin's `getConnectTimeout` and `getRequestTimeout` methods return `null` for an undeclared timeout instead of upstream's `Config` defaults, so existing options and the HTTP connection's configured timeouts stay in effect. See [Request Options](https://hypervel.org/docs/saloon#request-options).

The upstream NTLM authenticator is omitted. Integrations requiring NTLM must supply their own authenticator and transport middleware.

Ported from: https://github.com/saloonphp/saloon
