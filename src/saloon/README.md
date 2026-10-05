# Hypervel Saloon

Documentation: https://hypervel.org/docs/saloon

## Differences From Laravel

Requests and pending requests use fluent methods in the style of Hypervel's HTTP client, such as `withHeaders`, `withoutHeader`, `withQueryParameters`, `withOptions` and `delay`, and their getters return plain values. Upstream's `ArrayStore` and `IntegerStore` objects (`headers()->add()`, `query()->remove()`, `delay()->set()`) are not available, and upstream's `config()` and `defaultConfig()` are `options()` and `defaultOptions()`.

Connectors are read-only. Their headers, query parameters, options, authenticator and delay come from their `default*` methods and constructor values, because a connector may be shared by concurrent requests. Apply per-operation values to the request, or change connector defaults on the pending request in a hook or middleware.

The `HasTimeout` plugin's `getConnectTimeout` and `getRequestTimeout` methods return `null` for an undeclared timeout instead of upstream's `Config` defaults, so existing options and the HTTP connection's configured timeouts stay in effect. See [Request Options](https://hypervel.org/docs/saloon#request-options).

The upstream NTLM authenticator is omitted. Integrations requiring NTLM must supply their own authenticator and transport middleware.

Ported from: https://github.com/saloonphp/saloon
