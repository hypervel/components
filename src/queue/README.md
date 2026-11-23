Queue for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/queue)

Documentation: https://hypervel.org/docs/queues

## Differences From Laravel

- Daemon jobs own their database routing, log context and scoped-container state. Laravel's `Worker::$flushStickyConnections` setting and the worker constructor's `$resetScope` callback are omitted; a worker-wide reset could affect concurrent jobs. See [porting queues](https://hypervel.org/docs/porting-from-laravel#queues).
- Laravel's DynamoDB failed-job provider is not provided. Use database or file storage for failed jobs.
- Custom job implementations must support `JobLifecycleCallbacks` to use `CountCrashesAsExceptions`, so cleanup completes before a concurrent retry starts. The base `Jobs\Job` already implements it; the `Job` contract is unchanged. See [counting worker crashes](https://hypervel.org/docs/queues#counting-worker-crashes).
- Timeouts unwind yielding job code with Swoole's `CanceledException`, starting its `catch` and `finally` blocks. Use `finally` for cleanup and rethrow cancellation if caught. The worker stops after timeout handling by default, with a bounded wait for cleanup; `Worker::$killOnTimeout = false` keeps other jobs running. See [worker timeouts](https://hypervel.org/docs/queues#worker-timeouts) for cleanup and blocked-loop recovery.
- The protected `enqueueUsing()` callback receives the queue that owns the operation as its first argument. This lets deferred work borrow a fresh pooled queue connection after a database transaction commits instead of retaining a connection for the lifetime of the transaction.
- Positive per-message delays on SQS FIFO queues throw `LogicException`. Laravel silently omits the delay and sends the job immediately.
- `RateLimited::releaseAfter(0)` requests an immediate retry. Laravel treats zero as absent and uses the limiter's computed retry delay.
- `RedisQueue::scanQueueKeys()` is intentionally omitted. Queue discovery streams keys on a held connection instead of materializing a physical-key array; override `allQueueNames()` to customize discovery.
- Redis bulk dispatch uses `enqueueBatch()` and `LuaScripts::bulk()` for both standalone Redis and Cluster, storing immediate and delayed jobs together with an exact completion count. Laravel's `RedisQueue::bulkOnClusterConnection()` and `LuaScripts::bulkPush()` helpers are intentionally omitted.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Queue
