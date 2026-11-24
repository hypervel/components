Bus for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/bus)

Documentation: https://hypervel.org/docs/queues

## Differences From Laravel

Hypervel does not include Laravel's DynamoDB batch repository because DynamoDB is not a supported database backend.

`DatabaseBatchRepository::setConnection()` is intentionally omitted. The repository is shared for the worker lifetime, so mutating its connection would race across coroutines. Configure `queue.batching.database` instead; each repository operation resolves that connection when it runs.

Batch repositories count by logical job ID. A failure carries its delivery UUID as `$retryId` through `recordFailedJob()` and `incrementFailedJobs()` when it differs from the counting ID. Positive `incrementTotalJobs()` calls on a missing batch throw `BatchNotFoundException`. Implement `getFailedJobIds()` for retry lookup IDs and `hasFailedJob()` for membership by logical ID. Batch constructors and factories accept null pending and failed counts for the built-in Redis repository. Other repositories pass integer counts. A null failed-ID list enables [lazy loading](https://hypervel.org/docs/queues#inspecting-batches).

Overrides of `Batch::invokeCallbacks()` must accept the optional trailing `?int $expectedTotalJobs` argument. It skips a completion callback group when the snapshot it would receive includes jobs added since the accounting write.

Subclasses customizing `DatabaseBatchRepository` accounting should override the counting methods. Laravel's protected `updateAtomicValues()` hook is omitted.

`BatchRepository` resolves to a repository that reads each batch and routes it to the `DatabaseBatchRepository` or `RedisBatchRepository` binding for its results driver. Rebinding one of those classes customizes that driver's hydration, accounting and cleanup, including for batches returned by `Bus::findBatch()`. Lookups through `BatchRepository` do not call that class's `find()` or `get()`; to customize them, bind your repository to `BatchRepository` instead. That replacement owns hydration, accounting and pruning for every batch, so batches are no longer routed to the two driver bindings unless it does so itself.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Bus
