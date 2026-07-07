Sanctum for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/sanctum)

Ported from: https://github.com/laravel/sanctum

## Differences From Laravel

- Hypervel only supports the `id|token` format returned by `createToken()`. Laravel's legacy plain-token lookup is omitted because Hypervel's token cache and invalidation paths are keyed by token ID.
- Hypervel includes optional token and tokenable lookup caching for Swoole workers. Missing token IDs and missing tokenable models are cached as `null` for the configured TTL to avoid repeated database reads.
- The global `sanctum.guard` accept-list is removed. Each sanctum-driver guard declares its trusted session guards with `auth.guards.{guard}.session_guards`; `[]` means bearer tokens only, and a missing key is a config error. Stateful session users must also match the sanctum guard's provider; Laravel returns any listed guard's user unchecked.
- `sanctum.stateful` is renamed `sanctum.stateful_domains`, matching the `SANCTUM_STATEFUL_DOMAINS` environment variable and the key's actual contents.
- Sanctum's session password-hash artifacts are HMAC-only. Laravel's raw-hash fallback for legacy sessions is intentionally omitted because Hypervel 0.4 has no released legacy sessions.
