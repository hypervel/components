# Coroutine channel inspection

Give callers a small, working inspection API on `Hypervel\Contracts\Engine\ChannelInterface`. Hypervel owns this contract. Its implementation wraps Swoole in `Hypervel\Engine\Channel`. The same class remains the place to compare future Hyperf improvements.

## Public contract

| Call | Meaning and implementation |
| --- | --- |
| `push($data, $timeout)`, `pop($timeout)`, `close()` | Keep signatures, return values, timeout units, channel element types and suspension behavior. Closing prevents further pushes and wakes waiters. Buffered values can still be popped. |
| `length()`, `capacity()` | Return the current buffered count and the maximum, respectively. Declare native `length(): int` on the interface. Return the existing Swoole capacity property from `capacity(): int`. Remove the two `get*` names. |
| `isEmpty()`, `isFull()`, `isClosed()` | Use the inherited occupancy methods. Rename `isClosing()` to `isClosed()` and read the wrapper’s closed flag. Set it before native close resumes waiting coroutines. A later close returns false. |
| `hasWaitingProducers()`, `hasWaitingConsumers()` | Report whether a coroutine is blocked pushing or popping. Inspect the corresponding native queue on demand, including while close is waking other waiters. |
| `timedOut()`, `wasCanceled()` | Inspect the native error immediately after a failed push or pop, before yielding. A successful call can leave an earlier error in place. |

The native waiter counts are exposed by Swoole’s `ext-src/swoole_channel_coro.cc`. Its `src/coroutine/channel.cc` removes each waiter before resuming it. While one resumed consumer is running, another can still be waiting on the same closed channel. The implementation is one native stats read per explicit query:

```php
public function hasWaitingProducers(): bool
{
    return $this->stats()['producer_num'] > 0;
}

public function hasWaitingConsumers(): bool
{
    return $this->stats()['consumer_num'] > 0;
}
```

Remove the four throwing stubs and their exception import. Drop `isAvailable()`, which was simply the inverse of closed. Occupancy, closure and waiting answer different questions. All three are snapshots. Push and pop stay free of stats reads, added counters and error resets. Retain the pool’s own waiter count for its release path.

## Consumer migration

- Trace receivers before renaming calls, overrides and test-double expectations throughout components, including its package source, tests, fixtures, skeletons and guides. The coordinator’s shutdown state, a keepalive connection’s timeout, coroutine cancellation and unrelated streams or queues have their own APIs.
- Use `length()` for counts and `isEmpty()` for occupancy. Drop a guard around a repeated close when the return value is ignored and the receiver is the engine channel. Retain predicates that control other work. Preserve existing cancellation, retry, timeout and cleanup responsibilities at each call site.
- Cover engine sockets, coroutine primitives, AI streams, gRPC, Redis subscribers, connection pools, the watcher and server-process tests. Check the analyzer and its fixtures for method-name strings. Its suspension rules key on push, pop and close.
- Update the Channels section of the coroutine guide with the new names, buffered reads after closure, and immediate inspection after failure. Keep the guidance to wrap a false payload when the caller needs to distinguish it from a failed pop.

## Cancellation in framework consumers

- Redis subscriber command acknowledgements and PONGs share reusable reply channels. Once a command has been sent, abandoning its wait leaves a reply for the next command. Several acknowledgements can also fill the buffer and block the receiver. Use a single private reply helper for both command paths. On a failed pop, preserve the receiver's terminal failure, recognize native cancellation or timeout, and otherwise report closure. On any exception from the wait, interrupt the invoker and rethrow the original exception even if cleanup fails. Both thrown and non-throwing coroutine cancellation must close the transport before another send.
- A canceled enqueue on a full SafeSocket send channel throws `CanceledException` in either socket error mode. The background sender retains its socket and queued writes.
- On native socket reads, `SOCKET_ECANCELED` plus the current coroutine's cancellation flag identifies owner cancellation, even when `recvAll()` has received part of the requested bytes. A different coroutine can interrupt the socket without canceling the reader. Return partial data on ordinary timeouts. Classify a timeout only for native false: a subsequent empty EOF can retain the previous timeout code. An empty `recvAll()` means EOF; an empty framed `recvPacket()` can also carry a current protocol error. Keep both receive methods' normal data and error-mode semantics.
- Exercise both Redis cancellation modes and both reply entry points with the existing controlled connection. Verify the owner sees cancellation and subsequent sends are rejected. Use local socket pairs for canceled enqueues and reads, foreign interruption, and a timeout followed by EOF. Reuse the existing close and timeout tests.

## Verification and delivery

1. Exercise both sides of a real capacity-one channel. Fill it, block a producer, pop and observe the producer queue clear. Block a consumer on an empty channel, wake it and observe the consumer queue clear. Include the closed-channel wakeup case, checking the state from the resumed coroutine while another waiter is still queued. Use bounded waits and close in cleanup.
2. Adapt existing tests for counts, close/drain, repeat close, failed pushes, timeouts and cancellation. Check the failure reason immediately after the failed operation. Exercise the callers’ existing tests for cancellation, shutdown, queue occupancy and Redis response handling.
3. Run touched test files as they change. At the verification checkpoint run components’ CS Fixer, PHPStan and targeted tests in order. Review the full diff and search for stale names, including method names passed as strings.
4. Obtain review across the contract, implementation and framework consumers. After signoff remove the completed channel item from `docs/todo.md` and commit on the framework branch.
