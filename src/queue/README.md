Queue for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/queue)

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Queue

## Differences From Laravel

- The protected `enqueueUsing()` callback receives the queue that owns the operation as its first argument. This lets deferred work borrow a fresh pooled queue connection after a database transaction commits instead of retaining a connection for the lifetime of the transaction.
- Positive per-message delays on SQS FIFO queues throw `LogicException`. Laravel silently omits the delay and sends the job immediately.
- `RateLimited::releaseAfter(0)` requests an immediate retry. Laravel treats zero as absent and uses the limiter's computed retry delay.
