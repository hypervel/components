Routing for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/routing)

Documentation: https://hypervel.org/docs/routing

## Differences From Laravel

Laravel's deprecated `UrlGenerator::forceRootUrl()` alias is intentionally not ported. Use `useOrigin()` to override the generated URL origin for the current request without mutating sibling requests.

Rebinding the container's `request` service does not update the URL generator. Hypervel resolves the current request from coroutine-local request context instead.

Hypervel checks integer primary keys during route model binding, including non-incrementing keys. Models with string primary keys must declare their [key type](https://hypervel.org/docs/eloquent#primary-keys).

Ported from: https://github.com/laravel/framework
