Queue for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/queue)

Documentation: https://hypervel.org/docs/queues

## Differences From Laravel

- The protected `enqueueUsing()` callback receives the queue that owns the operation as its first argument. This lets deferred work borrow a fresh pooled queue connection after a database transaction commits instead of retaining a connection for the lifetime of the transaction.
- Positive per-message delays on SQS FIFO queues throw `LogicException`. Laravel silently omits the delay and sends the job immediately.
- `RateLimited::releaseAfter(0)` requests an immediate retry. Laravel treats zero as absent and uses the limiter's computed retry delay.
- Redis bulk dispatch uses one same-slot Lua call, keeping Cluster dispatch to one round trip and giving every Redis topology the same exact completion count. Laravel uses a nested transaction and pipeline.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Queue
