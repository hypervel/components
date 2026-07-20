Bus for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/bus)

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Bus

## Differences From Laravel

Hypervel does not include Laravel's DynamoDB batch repository because DynamoDB is not a supported database backend.

`DatabaseBatchRepository::setConnection()` is intentionally omitted. The repository is shared for the worker lifetime, so mutating its connection would race across coroutines. Configure `queue.batching.database` instead; each repository operation resolves that connection when it runs.
