Scout for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/scout)

Documentation: https://hypervel.org/docs/scout

## Differences From Laravel

- Algolia 4 is the only supported Algolia client.
- Numeric values passed to Algolia `where`, `whereIn`, and `whereNotIn` compile as numeric comparisons; numeric-looking strings remain facet values.
- Without a queue, indexing is deferred until after the HTTP response is sent, and runs immediately outside a request.
- Pausing search syncing with `withoutSyncingToSearch()` or `disableSearchSyncing()` applies only to the current coroutine, so other requests keep indexing. See [Pausing Indexing](https://hypervel.org/docs/scout#pausing-indexing).
- `MeilisearchEngine::generateTenantToken()` takes the search rules, the parent key's UID, the key itself and an optional expiry. Laravel's engine forwards the call to the Meilisearch client, whose method takes the UID, the search rules and an options array. See [Tenant Tokens](https://hypervel.org/docs/scout#meilisearch-tenant-tokens).
- `scout:delete-all-indexes` refuses to run without a configured Scout prefix unless you pass `--force`.
- Scout cannot generate embeddings with the Laravel AI SDK, so semantic and hybrid search use engine-native embeddings or precomputed vectors, and the database engine does not support them. See [Semantic Search](https://hypervel.org/docs/scout#semantic-search).

Ported from: https://github.com/laravel/scout
