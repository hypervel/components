Scout for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/scout)

Ported from: https://github.com/laravel/scout

Differences From Laravel
---

- Algolia 4 is the only supported Algolia client.
- Numeric values passed to Algolia `where`, `whereIn`, and `whereNotIn` compile as numeric comparisons; numeric-looking strings remain facet values.
- Queue mode supports dedicated connection and queue selection; nonqueued indexing is deferred until after HTTP responses and runs immediately without an active request.
- Command imports use bounded coroutine concurrency.
- Meilisearch requests use bounded retries and sign tenant tokens from an explicit parent-key UID and secret.
- Destructive index deletion requires the configured Scout prefix.
- Boot-time lifecycle callbacks can prepare builders, documents, settings, and model flushes; external engines also support completion-aware filtered deletion.
- `Searchable::removeAllFromSearch()` accepts an optional force flag, which the explicit `scout:flush` command enables.
