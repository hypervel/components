Scout for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/scout)

Ported from: https://github.com/laravel/scout

Differences From Laravel
---

- Algolia 4 is the only supported Algolia client.
- Queue mode supports dedicated connection and queue selection; nonqueued indexing runs after the response in a coroutine.
- Command imports use bounded coroutine concurrency.
- Meilisearch requests use bounded retries and sign tenant tokens from an explicit parent-key UID and secret.
- Destructive index deletion requires the configured Scout prefix.
- Boot-time lifecycle callbacks can prepare builders, documents, settings, and model flushes; external engines also support completion-aware filtered deletion.
- `Searchable::removeAllFromSearch()` accepts an optional force flag, which the explicit `scout:flush` command enables.
