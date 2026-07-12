Routing for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/routing)

## Differences From Laravel

Laravel's deprecated `UrlGenerator::forceRootUrl()` alias is intentionally not ported. Use `useOrigin()` to override the generated URL origin for the current request without mutating sibling requests.
