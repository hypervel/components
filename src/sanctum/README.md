Sanctum for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/sanctum)

Ported from: https://github.com/laravel/sanctum

## Differences From Laravel

- Hypervel only supports the `id|token` format returned by `createToken()`. Laravel's legacy plain-token lookup is omitted because Hypervel's token cache and invalidation paths are keyed by token ID.
- Hypervel includes optional token and tokenable lookup caching for Swoole workers. Missing token IDs and missing tokenable models are cached as `null` for the configured TTL to avoid repeated database reads.
