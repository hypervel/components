# Reverb Connection, Shared-State, and Current-Parity Lifecycles

## Status and scope

**Status:** Complete.

This work completes the Reverb package audit against:

- Hypervel baseline `860b04378ba8c8ae44a37276425842669256b479`;
- Laravel Reverb `b8d4433e9903a0f93f1cf05204ab17ec5a0e127b`;
- Swoole 6.2.2;
- the completed WebSocket Server, Redis, Cache, Queue, Horizon, Foundation, and HTTP Server work units.

The implementation must preserve:

- the Pusher-compatible public protocol and HTTP API;
- Hypervel's integrated Swoole server, route isolation, TLS, control frames, and graceful drain;
- worker-local channel objects with immutable application-scoped proxies;
- pre-fork Swoole shared state for one multi-worker Reverb instance;
- Redis subscriber/publisher separation for multi-instance scaling;
- global connection limits, presence transitions, cache channels, rate limiting, webhooks, batching, smoothing, Telescope events, and existing real-server topologies.

Do not port Laravel Reverb's React server, certificate discovery, Pulse recorders, or Laravel development-command integration. Hypervel already owns those concerns through its Swoole server and Telescope.

## Design constraints

These rules govern implementation and post-compaction rereads:

- Require a supported path and meaningful harm before adding machinery. A complete source-proven coroutine schedule counts; a merely imaginable state does not.
- Fix the lowest owner completely. Do not add caller workarounds around incorrect shared state, transport lifecycle, or package contracts.
- Preserve Laravel public APIs, configuration structure, documentation, named arguments, and extension patterns unless a concrete Hypervel benefit is owner-approved.
- Make resource ownership explicit. Publish complete objects transactionally, retain exact handles, clean up independently, and preserve the earliest failure.
- Bound waits only when progress depends on another worker, process, socket, or external service that can disappear.
- Audit allocations, container resolution, locks, hashing, serialization, yields, retries, logging, network calls, and retained worker memory on every hot path.
- A measured or source-proven hot-path regression requires explicit owner approval before implementation.
- Do not add registries, retries, options, extension points, state machines, or recovery systems without a demonstrated need. Do not add machinery for deliberate framework bypasses.
- Delete superseded properties, helpers, branches, comments, tests, and documentation. Do not leave compatibility shims or temporary wording.
- Tests must reproduce the old failure, use deterministic barriers for interleavings, and release every coroutine, channel, listener, process, socket, timer, and external-service resource.
- Avoiding overengineering never permits an incomplete correctness, security, isolation, or cleanup fix.

The following are deliberately rejected:

- a reverse connection-to-channel registry;
- channel-wide subscription locks;
- a generic WebSocket callback-ordering framework;
- a per-worker lease, heartbeat, fencing, or reconciliation system for abrupt worker death;
- storing arbitrary presence payloads in fixed-width Swoole rows;
- a generic distributed-query abstraction;
- a second Redis subscriber supervisor, timer registry, or configurable retry policy;
- a second webhook retry/recovery layer;
- per-client failed-auth registries or channel quotas;
- a replacement sender result API.

## Owner gates before implementation

| Gate | Decision required | Cost and reason |
|---|---|---|
| `reverb-07` | Approve per-connection lifecycle serialization. | One local coroutine-channel acquire/release per open, message, and close. Different sockets remain concurrent. Same-socket callbacks are intentionally ordered; in Redis mode, concurrent initial subscriptions on that socket become sequential Redis operations. This fixes proven open/close and subscription races. |
| `reverb-32` | Approve node-wide presence snapshots and unscaled HTTP metrics. | Single-worker mode remains local. Multi-worker presence joins add one bounded worker gather: Swoole pipe request/responses without Redis, or the existing Redis metrics request/responses while scaling. The existing 10-second metrics deadline is retained without a new option; a missing worker can therefore delay a presence acknowledgement and hold that connection's lifecycle token until the deadline. Public presence-channel `data()` becomes a distributed call that may block or throw. |
| `reverb-33` | Approve truthful unscaled termination failure. | A rejected sibling `sendMessage()` produces HTTP 500 after every sibling was attempted instead of the upstream unconditional 200. Worker recycling makes this a reachable production failure. This intentional response divergence prevents a security-relevant partial user revocation from being reported as success; no acknowledgement protocol is added. Scaled Redis publication retains upstream success semantics. |
| `reverb-34` | Approve exact distributed `user_count` merge data. | Only a multi-worker/scaled HTTP request asking for `user_count` carries unique user IDs instead of one integer per slice. Payload size becomes linear in presence users; ordinary messages, subscriptions, and metrics without that field are unchanged. This is the minimum exact fix without an always-on distributed member registry. |
| `reverb-35` | Approve membership-operation reservations on worker-local channels. | Each subscribe/unsubscribe adds two integer mutations and local checks; no lock, allocation, yield, or network call is added. This prevents one connection from removing a channel while another connection is publishing membership through yielding shared state. |
| `reverb-36` | Approve rejecting Redis Cluster for pub/sub scaling. | Redis Cluster `PUBLISH` reports subscribers only on the publishing node, so exact metrics cannot know when all instances replied. Waiting for the deadline would add ten seconds to every presence subscription. Fail during provider registration when scaling is enabled with a Cluster connection. Cluster remains supported for webhook buffering when scaling is disabled. |
| `reverb-18` | Approve non-blocking subscriber startup and persistent recovery. | A worker no longer blocks startup for up to 60 seconds and no longer permanently descales. Until its subscriber joins, distributed metrics may transiently omit that worker even if it has begun serving. Exact gathering resumes after subscription; an atomic startup barrier would make Redis recovery a worker-liveness dependency. |
| `reverb-23` | Approve a small scoped channel-manager contract. | This is an additive Laravel-shaped extension contract that makes conditional custom manager bindings genuinely substitutable. |
| `reverb-28` | Approve deletion of `Concerns\SerializesConnections`. | The public namespaced trait is orphaned, untested, absent from current use, and describes a removed connection design. No shim is retained. |
| `reverb-31` | Approve the repository-prohibited bulk edit for mechanical test `: void` additions. | There are 522 affected unit/integration test methods across 47 files. Only test method declarations may change; helpers and behavior remain untouched. |

All other accepted items are defect fixes, current-upstream ports, typing corrections, metadata corrections, or removals of dead/default-duplicating code with no source-proven hot-path regression.

## Findings and disposition

| ID | Result | Owning boundary |
|---|---|---|
| `reverb-03` | Revalidate exact presence user ID `"0"` handling. | Existing presence and user-termination boundaries |
| `reverb-04` | Replace deferred-webhook timing races with injected exact timer ownership. | `DeferredWebhookManagerTest` |
| `reverb-05` | Publish independently of subscriber state and release pending metric/listener state on failure. | Redis publication and `MetricsHandler` |
| `reverb-06` | Drain one final child result after reap. | Reverb multi-process test harness |
| `reverb-07` | Serialize each connection's protocol lifecycle with an entry-owned token; do not use global `Mutex` keys. | `Servers\Hypervel\WebSocketHandler` and a small owned lifecycle entry |
| `reverb-08` | Make membership exact and idempotent; commit shared state immediately before local state. | `Protocols\Pusher\Channels\Channel` |
| `reverb-09` | Remove channel-held operation results and verify protected subscriptions once. | Channel protected operations and private/presence concerns |
| `reverb-10` | Make rejected opens terminal and close/drain exhaustive. | Pusher `Server`, `WebSocketHandler`, provider drain |
| `reverb-11` | Remove only newly-created, still-empty channels after authorization failure. | `EventHandler` |
| `reverb-12` | Canonicalize logical keys; hash Swoole physical keys; correct capacity failures. | Both `SharedState` implementations |
| `reverb-13` | Publish presence channel/member mutations atomically. | Redis Lua and existing Swoole stripes |
| `reverb-14` | Clear initialized per-connection limiter state on close. | Pusher `Server` |
| `reverb-15` | Give webhook keys one Redis Cluster tag and release a newly-owned scheduling lock when queue dispatch fails. | `WebhookBatchBuffer` and `HttpWebhookDispatcher` |
| `reverb-16` | Prune and ping from the connection owner; continue independent cleanup. | Reverb connection jobs and `WebSocketHandler` |
| `reverb-17` | Always remove pending metrics and exact temporary listeners. | `MetricsHandler` |
| `reverb-18` | Replace recursive terminal descaling with one owned subscriber lifecycle that retries until intentional disconnect. | `RedisPubSubProvider` |
| `reverb-19` | Record abrupt-worker count drift as an accepted rare limitation; add no recovery machinery. | Documentation and ledger only |
| `reverb-20` | Carry raw socket ID through HTTP dispatch and pub/sub. | Current Laravel Reverb parity |
| `reverb-21` | Use guarded `hash_equals()` for HTTP signatures. | Current Laravel Reverb parity |
| `reverb-22` | Replace repeated map copying and add direct socket lookup. | Channel manager |
| `reverb-23` | Preserve custom channel, connection, and logger bindings and type the scoped surface by contract. | Provider and manager contracts |
| `reverb-24` | Add the Reverb path to Foundation's broadcasting client options. | Foundation broadcasting config |
| `reverb-25` | Remove duplicate owned defaults and correct config diagnostics. | Config consumers |
| `reverb-26` | Flush the entire channel repository directly. | `ArrayChannelManager` |
| `reverb-27` | Correct split-package direct dependencies and add metadata coverage. | `src/reverb/composer.json` |
| `reverb-28` | Delete the orphan serialization trait. | Dead source |
| `reverb-29` | Record upstream and closed architecture differences concisely. | Reverb README, source/test sync points, Boost docs |
| `reverb-30` | Use strict comparisons at known-type boundaries. | Redis state and Pusher membership checks |
| `reverb-31` | Add required `void` test method types after owner approval. | Reverb tests only |
| `reverb-32` | Gather complete node-wide presence snapshots and constant-size connection counts through serializable transport payloads. | `MetricsHandler` with Redis or Swoole pipe transport |
| `reverb-33` | Terminate matching user connections on every worker in unscaled multi-worker mode. | User termination action, controller, and Swoole pipe transport |
| `reverb-34` | Deduplicate presence users when merging `user_count` across workers or nodes. | `MetricsHandler` |
| `reverb-35` | Prevent empty-channel removal while another membership operation is in flight. | `Channel` and exact manager removal |
| `reverb-36` | Reject Redis Cluster for pub/sub scaling while retaining it for webhook buffering. | `HypervelServerProvider` configuration validation |
| `reverb-37` | Document that Swoole's global request counter also recycles Reverb workers. | Reverb operations documentation |
| `reverb-38` | Distinguish malformed Pusher input from internal failures and report only the latter. | Pusher protocol validation and error handling |
| `reverb-39` | Preserve the original request failure if Reverb's isolated HTTP exception handling or response emission fails. | `Servers\Hypervel\HttpServer` |
| `websocket-server-13` | Preserve the original handshake failure when the base exception handler fails. | Completed WebSocket Server exception boundary |
| `testbench-02` | Preserve the original bootstrap or command failure when Testbench exception handling fails. | Testbench Commander exception boundary |
| `support-27` | Preserve `SafeCaller`'s default response when exception reporting fails. | Completed Support exception boundary |
| `cache-11` | Retain successful native cache timer IDs for the worker lifetime and clear them on worker exit. | Cache timer listener and provider lifecycle |
| `cache-20` | Type Cache's striped-lock constants without breaking its late-bound deterministic test seam. | Cache Swoole shared-state lock |
| `server-10` | Contain pipe callback failures at the native Server event boundary. | Completed Server package registration boundary |
| `grpc-01` | Make fake HTTP/2 stream state change when a response is observed, not queued, and preserve local half-close. | gRPC test fixtures |

## Implementation design

### 1. Ordered connection lifecycle and terminal cleanup (`reverb-07`, `10`, `14`, `16`)

Replace the registry value with an owned entry containing the Reverb connection, an entry-scoped coroutine channel, and terminal state. The channel is not keyed by fd in a static global mutex map: its lifetime is exactly the connection entry plus callbacks that already captured it.

```php
final class ConnectionLifecycle
{
    private readonly CoroutineChannel $token;

    private bool $closing = false;

    private ?Connection $connection = null;

    public function __construct(
        public readonly int $fd,
    ) {
        $this->token = new CoroutineChannel(1);
        $this->token->push(true);
    }

    public function attach(Connection $connection): void
    {
        // Attach exactly once while this entry's token is held.
    }

    public function run(callable $callback): mixed
    {
        // Return without invoking or releasing when pop() reports a closed token.
        // Otherwise recheck closing, invoke, and release in finally.
    }

    public function close(callable $callback): mixed
    {
        // Mark closing before acquiring, run terminal cleanup as the owner,
        // then close the token so captured waiters wake without doing work.
    }
}
```

`WebSocketHandler::onOpen()` will:

1. construct, seed, and publish the fd-owned lifecycle entry as its first non-yielding operation;
2. under that entry, resolve the application, construct the transport/Reverb connection, and attach it exactly once;
3. run protocol open through the same entry;
4. on lookup, construction, or protocol rejection, remove only that exact entry, finish any attached slot/error cleanup, and close the transport.

The early fd-owned reservation is necessary because `ApplicationProvider` is a public extension point and its `findByKey()` implementation may yield. Publishing after application resolution would retain the original missed-close race for a valid provider. The lifecycle entry is the transactional owner while its Reverb connection is being constructed; it is not visible as an active connection until attachment and establishment succeed.

`onMessage()` captures the entry and acquires its token. After acquiring it checks terminal/disconnecting and attached state; a callback captured before close therefore exits without protocol work. `onClose()` atomically removes the exact entry and marks it closing before waiting, then uses the entry's distinct terminal-owner path to perform protocol close under the same token. Ordinary callbacks never close the token. The terminal owner closes it in `finally` so captured waiters wake and exit even when cleanup throws. Registry removal accepts the expected entry identity, preventing a late callback for an old fd from removing a replacement connection.

`Channel::pop()` returning `false` means no token was acquired: the callback is not invoked and `finally` does not push. Terminal close deliberately waits without a Reverb-owned timeout for the current internal callback to release the token. External operations inside that callback retain their own bounds, while Swoole's `max_wait_time` remains the worker-shutdown backstop; do not add an arbitrary join timeout. A distributed presence acknowledgement is the longest package-owned hold: a missing worker may retain the fd entry, connection slot, and channel membership under the same token for the existing 10-second metrics deadline.

This fixes four source-proven schedules:

- close can otherwise return before a yielding open publishes or finishes, leaving a ghost connection and slot;
- subscribe followed by unsubscribe can decrement/delete before the increment commits;
- a duplicate subscribe can acknowledge while the first shared publication later fails;
- a client event can observe local membership before the shared subscribe commits.

Protocol open catches `Throwable`, not only `Exception`. Missing Origin under restricted origins must become a normal `InvalidOrigin` rejection, never `parse_url(null)` `TypeError`.

Add established state to the existing connection contract. `Server::open(Connection): void` marks the connection established only after the slot check, origin check, acknowledgement send, logging, and optional `ConnectionEstablished` event all succeed. Its rejection path retains the existing non-throwing public signature, attempts slot rollback and the Pusher error independently, keeps the slot flag when rollback fails, and leaves the connection unestablished. `WebSocketHandler::onOpen()` checks that state in `finally`; an unestablished connection is exact-taken from the registry and sent through terminal cleanup. `Server::close()` runs slot/limiter cleanup for every connection, but channel unsubscribe and `ConnectionClosed` publication only for a connection that was established. This gives rejected opens a concrete terminal owner without a return-type or exception-contract divergence from Laravel.

Open rejection and close use direct fixed `try/catch` blocks:

```php
$exception = $operationFailure;

try {
    $this->cleanupChannels($connection);
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

try {
    $this->releaseConnectionSlot($connection);
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}
```

Continue through slot release, initialized limiter clear, logging, and optional events. Keep the acquired-slot flag if release fails so terminal close may retry it. A rejected open sends its Pusher error where possible and always attempts transport termination. A connection-limit rejection is terminal.

`ReverbServiceProvider::drainConnections()` separately attempts protocol cleanup and transport disconnect for every exact taken entry, preserves the earliest failure, and continues the batch. The shutdown callback independently attempts connection drain, subscriber disconnect, and webhook flush. One package-owned reporter handles every contained Reverb failure through the framework exception handler; reporting failure falls back to stderr with both the original and reporting throwables and never prevents later cleanup or worker-exit listeners.

`RateLimiter::clear('reverb:message:'.$connection->id())` runs only when the limiter was initialized and the app uses rate limiting. Default-disabled traffic receives no new cache work.

Both periodic jobs iterate the lifecycle entries owned by `WebSocketHandler` rather than the union of channel membership, and run each attached established connection through its existing entry token. This includes zero-subscription sockets without bypassing close serialization. Pruning independently attempts the error send, disconnect, log, and optional event for each stale connection; it preserves the first failure while continuing the pass. Ping retains its existing EventHandler behavior and gains no retry system.

### 2. Exact, transactional channel membership (`reverb-08`, `09`, `11`)

Keep the Laravel-shaped public `void` methods and route them through protected operations returning an operation-local `SubscriptionResult`:

```php
protected function subscribeToChannel(
    Connection $connection,
    ?string $data,
    ?string $userId = null,
): ?SubscriptionResult {
    if ($this->subscribed($connection)) {
        return null;
    }

    $attributes = $this->decodeSubscriptionData($data);
    $result = app(SharedState::class)->subscribe(
        $connection->app()->id(),
        $this->name,
        $userId,
    );
    $this->connections->add($connection, $attributes); // immediate commit

    return $result;
}
```

The shared subscription is published immediately before the worker-local connection is committed. This ordering depends on `ChannelConnectionManager::add()` synchronously accepting every decoded subscription array without throwing; document that extension contract and its shared-state reason at the two owning sites rather than adding rollback machinery for an invalid implementation.

Unsubscribe first confirms local membership, publishes the shared decrement, and immediately removes local membership. A shared-state failure retains local ownership for retry/close. Duplicate subscribe and nonmember unsubscribe do nothing: no count, webhook, or transition changes.

Remove `$lastSubscriptionResult`. Pass the returned result directly to presence and subscription-count work. Remove a worker-local channel whenever its local connection manager becomes empty, regardless of the global count; global vacated events, shared lock cleanup, smoothing, and webhooks still depend on `channelVacated`.

Different connections may yield in shared-state publication on the same channel. Each protected subscribe/unsubscribe operation therefore reserves one local in-flight membership operation before its first possible yield and releases it in `finally`. Every release re-runs finalization; the channel is removed only when both the local connection manager and the reservation count are empty. `ArrayChannelManager::removeChannel()` removes only when the current entry is the same channel instance. These non-yielding checks prevent an unsubscribe from orphaning a channel another connection is concurrently joining, and reclaim the channel when that deferred join fails, without serializing unrelated connections or adding a reverse registry.

`handleChannelVacated()` no longer removes the channel. It retains only globally vacated side effects: shared lock cleanup, smoothing, events, and webhooks. Local-idle finalization is the single normal removal trigger.

`InteractsWithPrivateChannels` and `InteractsWithPresenceChannels` each verify once, then invoke the protected operation directly. Presence decodes `user_id` before publication and passes the operation result directly to member-event logic. `PresenceCacheChannel` must remain protected; no hierarchy-dependent `parent::subscribe()` call may bypass or duplicate verification.

`EventHandler::subscribe()` records whether a channel existed. On `ConnectionUnauthorized`, it removes only a newly-created channel that is still empty and rethrows. It does not remove on arbitrary yielded failures because another connection may have joined.

### 3. Canonical shared-state identity and atomic presence (`reverb-12`, `13`, `30`)

Logical keys use type-tagged, length-prefixed tuples so values containing `:` or braces cannot alias:

```php
private function logicalKey(string $type, string ...$parts): string
{
    return $type . '|' . implode('|', array_map(
        static fn (string $part): string => strlen($part) . ':' . $part,
        $parts,
    ));
}
```

Swoole physical keys are a short type prefix plus seeded `xxh128`, following `SwooleStore`:

```php
return $prefix . hash('xxh128', $logicalKey, false, [
    'seed' => $this->hashSeed,
]);
```

The seed is created before fork and inherited by every worker. This structurally keeps all physical keys below Swoole's native limit.

The Swoole 6.2.2 probe established:

- a 63-byte key succeeds normally;
- 64/65-byte `Table::set()` writes the row and then warns, which Hypervel converts to `ErrorException`;
- those long rows remain separately readable in the current runtime;
- a full table warns/throws `unable to allocate memory`; missing-key `incr`/`decr` does likewise.

Therefore the current long-key path can mutate state and then misreport table exhaustion. Once keys are bounded, `ErrorException` at allocation is a capacity failure without warning-text inspection. Remove the unreachable `$result === false` and `ensureOperationSucceeded()` branches. Give create, increment, and decrement allocation failures the real config path:

```text
reverb.servers.reverb.swoole_shared_state.rows
reverb.servers.reverb.swoole_shared_state.lock_rows
```

This native key limit is not classified as a Swoole bug. No Swoole PR note is required from current evidence.

Redis uses the same canonical logical tuples. Presence channel and user keys use the ordinary canonical key builder because pub/sub scaling rejects Redis Cluster; adding hash-tag machinery for an unsupported topology would add hot-path hashing without benefit. Subscribe and unsubscribe each become one Lua round trip rather than two.

Swoole presence acquires the two existing stripe locks once in deterministic order, or once when both keys share a stripe. It validates/creates both rows before either counter changes, then mutates both while held. If creating the second row fails, it deletes a first row created by that operation before releasing the locks; pre-existing rows remain untouched. Ordinary channel operations retain one stripe and one counter.

Keep the typed lock timeout and spin-count constants late-bound so deterministic test subclasses can shorten them; retain `self::` for the fixed stripe count. Apply the same typing and concise late-binding explanation to Cache's identical lock design and its existing test override (`cache-20`); runtime behavior does not change.

Use `=== true` for transformed Redis `SET` results. Add strict mode to the eight known-type `in_array()` calls in Pusher channel information, client-event policy, and origin checks. Lua comparisons are unchanged.

### 4. Complete worker/node metrics, presence, and user termination (`reverb-17`, `32`–`34`)

`MetricsHandler` remains the one gather/merge owner:

- one worker: return local data before allocating a pending metric or channel;
- Redis scaling: use the existing pub/sub request-response path and exact standalone/Sentinel subscriber count for early completion; the current worker is already one subscriber, so do not append its slice twice;
- unscaled multi-worker: append the local slice once, send one bounded Swoole pipe request to each sibling, and receive one response;
- proven enqueue failure: attempt every target, preserve the earliest false result or exception, then throw;
- timeout: preserve the existing 10-second metrics deadline, log one warning with the metric type and received/expected slice counts, and merge every response received; the unscaled pipe path always retains its local slice.

Correct the `publish()` docblock so its integer is described as Redis' subscriber count rather than a package-owned acknowledgement.

Add narrowly named metrics request/response pipe DTOs rather than a generic distributed-query protocol. The request contains the existing pending-metric scalar fields; `OnPipeMessage::fromWorkerId` identifies the response target. The response contains only the request ID and a JSON-native local metric slice. `ReverbServiceProvider` delegates those two message types to `MetricsHandler`; broadcast pipe handling remains separate.

```php
$pending->append($this->get($metric)); // unscaled pipe path only

foreach ($this->siblingWorkerIds() as $workerId) {
    $server->sendMessage(new MetricsRequestPipeMessage(/* scalar request */), $workerId);
}

try {
    return $this->waitForMetric($pending);
} finally {
    $this->stopListening($pending);
}
```

The connection metric is a count envelope, never a transported connection list:

```php
protected function connections(): array
{
    return ['count' => count($this->channels->connections())];
}
```

Merge connection slices by summing `count`, and have `ConnectionsController` read that field. The previous live connection objects retain native server ownership and cannot cross a worker pipe. Projecting socket IDs would make a counted metric linear in active connections without serving a consumer need.

Add one internal presence snapshot metric containing local existence/type flags and complete `user_id` / `user_info` rows. `MetricsHandler` owns that local extraction and uses it for presence subscription data, the channel-users API, and distributed `user_count` merge metadata. Delete the superseded `ChannelUsers` metric and its tests. Distributed slices carry IDs in a distinct internal field, merge and deduplicate that field, remove it, and emit the public integer `user_count`; never overload one field with list and integer values or sum per-worker user counts.

`InteractsWithPresenceChannels::data()` remains the public Laravel-shaped formatter and the actual `EventHandler` path. With no local connection it returns the existing empty presence payload without gathering. Otherwise it obtains the application from its newly committed local channel connection, gathers the internal presence snapshot, and formats the existing Pusher payload (`count`, `ids`, `hash`). `EventHandler::afterSubscribe()` remains `$channel->data()`. This leaves one member extractor and one payload formatter without changing the public signature.

`ChannelUsersController` removes its worker-local existence/type precheck and uses the merged snapshot to preserve `404` for an absent channel, `400` for a non-presence channel, and `200` with node-wide users. Otherwise an HTTP request routed to a worker without a local copy would still reject a valid remote presence channel before gathering.

The pending registry and exact temporary listener are always removed in `finally` on success, timeout, cancellation, local-slice construction failure, publish/send failure, or merge failure. Construct the waiter before publishing either registry entry so the registry exposes only complete pending state. Cleanup failure cannot replace the primary operation failure.

Do not store user payloads in Swoole Table, require Redis for one-instance scaling, or add an always-on member registry. The gather is the minimum mechanism compatible with arbitrary Pusher presence data and the chosen multi-worker architecture.

Extract the existing `user_id` scan into one stateless `UserConnectionTerminator` used by the HTTP controller, Redis handler, and pipe listener. In unscaled mode the controller terminates local matches and sends a narrowly named terminate message to every sibling worker. It attempts every sibling send, preserves the earliest false result or exception, and throws only after the fan-out, producing HTTP 500; no acknowledgement protocol is added. In scaled mode Redis already delivers one terminate event to every worker on every node, so the controller publishes only to Redis and does not pipe-fan-out. A successful Redis publication retains the upstream empty `200` regardless of its topology-dependent subscriber count; transport exceptions still fail normally.

The completed Server package owns the native inter-worker terminal boundary. `Server::registerSwooleEvents()` wraps `Event::ON_PIPE_MESSAGE` and `Event::ON_FINISH`: cancellation returns quietly; any other `Throwable` is reported through the framework exception handler with a fallback containing both the original and reporting failures; nothing escapes to kill a healthy serving worker. `Event::ON_TASK`, startup, and teardown remain fail-fast. Reverb does not duplicate that guard locally.

Swoole 6.2.2 has a separate confirmed defect: when PHP serialization throws in `Server::sendMessage()` or task packing/finish, native code can transmit its partially-built serialization buffer instead of aborting. The Reverb count payload is the correct long-term metric representation independently of that upstream fix. Record the upstream defect for a Swoole PR; do not add a Reverb workaround.

### 5. Redis scaling topology and subscriber ownership (`reverb-18`, `36`)

Before binding Redis shared state or pub/sub, `HypervelServerProvider::register()` validates the selected connection through `RedisConfig::connectionConfig()`. It throws a descriptive configuration exception when both `reverb.servers.reverb.scaling.enabled` and the selected connection's `database.redis.<name>.cluster.enable` are true, naming the scaling flag and connection setting.

Do not call `RedisProxy::isCluster()` during registration: although it performs no Redis command, it creates the pool and can register its heartbeat timer before fork. Reading `RedisConfig` validates the same merged configuration without creating a pool, timer, connection, or network call. Scaling disabled does not run this check, so Redis Cluster remains supported by `WebhookBatchBuffer`; `reverb-15` keeps its Cluster-safe keys.

`RedisPubSubProvider::connect()` reserves one running-owner flag before creating one lifecycle coroutine. Spawn failure rolls the flag back and propagates. Repeated `connect()` calls while that owner exists are idempotent. The callback returns after publishing that owner rather than waiting for Redis: blocking worker startup until an unavailable external service recovers would make the worker unavailable too, while all Redis-backed channel and connection operations already fail truthfully during the outage. Distributed metrics gather from established subscribers, so they may transiently omit a worker that begins serving before its subscription commits. Exact gathering resumes when that worker joins; do not add an atomic startup barrier.

That coroutine owns subscriber construction, subscription, publication, message consumption, cleanup, and retry:

```php
$workerExit = CoordinatorManager::until(Constants::WORKER_EXIT);

while ($this->shouldRetry()) {
    $subscriber = null;

    try {
        $subscriber = $this->subscribe();
        $this->consumeMessages($subscriber);
    } catch (Throwable $exception) {
        // Report the first failure and each minute of continued outage.
    } finally {
        $this->clearAndCloseSubscriber($subscriber);
    }

    if ($this->shouldRetry() && $workerExit->yield(1)) {
        break;
    }
}
```

Keep the subscriber local until `subscribe()` succeeds and retry remains enabled, then commit the exact object and prefixed channel. Message-handler failures stay contained without replacing the subscriber. Transport failure clears and closes only that committed subscriber before the next attempt.

`disconnect()` disables retry, exact-takes and closes the committed subscriber, and thereby wakes a blocked channel pop. Retry delay uses the existing worker-exit coordinator with a one-second timeout, so normal retries keep the current cadence while worker exit wakes the owner immediately. The lifecycle finalizer clears the running-owner flag; no recursive calls, timer, second supervisor, server injection, or worker stop is added. A sustained outage therefore retains one waiting coroutine and no subscriber or Redis lease between attempts. When Redis returns, the worker rejoins scaling without dropping its clients or stranding shared counters.

Reuse the existing consecutive-failure counter to report the first failure and one status update per minute rather than logging twice per second for the duration of an outage. Reset it after a successful subscription.

### 6. Release native Cache timers on worker exit (`cache-11`)

The successful Cache timer IDs currently exist only in a local registration array. Worker 0 is the sole timer owner, so its native 10-second eviction and 1-second interval-refresh ticks outlive `OnWorkerExit`. Swoole correctly retains that worker's reactor while recurring native timers exist: worker 0 burns the full `max_wait_time`, emits the forced-termination warning, and cannot exit cleanly during recycle, restart, or reload. The timers die with the process and do not leak into its replacement. This is a Cache ownership defect, not a Swoole defect.

`CreateSwooleTimers` remains the owner. Register into a local list, roll that list back on partial failure, and commit the complete list to the listener instance only after successful registration. Add an idempotent `stop()` that exact-takes the retained IDs, attempts every clear in reverse order, treats native `false` as a failed clear, and preserves the earliest failure. `CacheServiceProvider` resolves that same auto-singleton listener from a new `OnWorkerExit` callback. It reports a terminal cleanup failure through the framework exception handler, falls back to stderr if reporting itself fails, and never lets either failure prevent later worker-exit listeners. Do not call `Timer::clearAll()`, add a coordinator coroutine, or move Cache timers into Reverb.

### 7. Cluster-safe, durable webhook batching (`reverb-15`)

Build the buffer, flush-lock, and processing keys from one braces-safe stable app hash tag:

```php
$tag = hash('xxh128', $this->canonicalAppIdentity($appId));

$bufferKey = "reverb:webhook:{{$tag}}:buffer";
$lockKey = "reverb:webhook:{{$tag}}:flush";
$processingKey = "reverb:webhook:{{$tag}}:processing";
```

Every existing two-key Lua script then remains in one Redis Cluster slot. App IDs containing braces or delimiters cannot control the tag.

When `appendAndCheckSchedule()` reports that this call newly acquired the flush lock and queue dispatch throws, clear that owned lock and rethrow. The durable appended event remains. Do not clear when another call owns the lock, add a retry loop, or acknowledge buffered data.

### 8. Current Laravel parity, extension contracts, and HTTP diagnostics (`reverb-20`–`26`, `reverb-39`)

Port current upstream behavior, adapted only for Hypervel's immutable scope:

- HTTP `EventsController` and `EventsBatchController` pass optional raw `socket_id` through `EventDispatcher` even when it is not local.
- Pub/sub and same-node pipe delivery exclude that ID where it is locally present. One nullable scalar is added to existing payloads; no lookup or round trip is added.
- HTTP signature verification uses `is_string($authSignature) && hash_equals(...)`.
- Full connection-map merging uses in-place union. Direct `findConnection()` replaces full-map materialization only at the four socket-ID exclusion sites: the Reverb pipe listener, Redis broadcast receiver, `EventsController`, and `EventsBatchController`. User termination still scans `user_id`.
- Register default `ChannelManager`, `ChannelConnectionManager`, and Reverb `Logger` only if unbound.
- Add `ScopedChannelManager` contract with only the existing scoped surface: `app`, `all`, `exists`, `find`, `findOrCreate`, `connections`, `findConnection`, `unsubscribeFromAll`, and `remove`. The root contract returns it; `VerifiedRequestContext` consumes it.
- `ArrayChannelManager::flush()` assigns `[]` directly. It has no repository caller today; this corrects the public contract and intentionally differs from upstream's application-provider loop.

Fan-out preserves the failure boundary:

- one local loop resolves the excluded socket ID once; a per-recipient send or `MessageSent` listener failure is retained, every selected local connection is attempted, the earliest failure is reported once, and the broadcast returns normally;
- a cache-lock failure, sibling pipe enqueue failure, or Redis publication failure loses a whole population, so independent populations are still attempted and the earliest failure propagates;
- post-commit presence publication through `dispatchInternalToChannel()` attempts local and remote delivery, reports the earliest failure, and does not falsely reject an already-live subscription;
- the Redis receiver's synchronous internal dispatch attempts every channel before propagating to its contained subscriber boundary.

This adds only local `try/catch` blocks around existing sends on the success path. It adds no allocation, yield, retry, or network operation.

Foundation's Reverb broadcasting connection gains:

```php
'path' => env('REVERB_SERVER_PATH', ''),
```

inside client `options`, where the Pusher SDK consumes it. Reverb's server-side `path` setting remains the route owner.

Remove owned default duplication:

- consume required merged Reverb values without repeated fallback in `ReverbServiceProvider`, `ApplicationManager`, `ServerProviderManager`, and `HypervelServerProvider`;
- preserve fallbacks inside replace-whole application entries where omission is intentional;
- initialize `HttpServer::$maxRequestSize` only during `bootstrapForServer()`;
- delete `DEFAULT_MAX_REQUEST_SIZE`, the property initializer, and the config getter's default argument;
- correct every shared-table diagnostic and document that max-connection-enabled apps also consume a main-table row.

Reverb's isolated `HttpServer` keeps a caught request failure in flight while resolving the exception handler, reporting, rendering, and emitting the error response. A failure at any of those boundaries therefore carries the request failure in its previous exception chain, matching Foundation's HTTP and WebSocket kernels. Successful handling still emits one response and suppresses the original; ordinary responses retain one emission call.

Apply the same native chaining pattern to the completed WebSocket Server's base handshake handler and Testbench's Commander. The former otherwise lets `SafeCaller` report only a secondary handler failure; the latter otherwise replaces the actionable bootstrap or command failure. Both corrections run only after an operation has already failed and preserve existing response/status behavior.

`SafeCaller` contains an exception-handler resolution or reporting failure, writes both failures to PHP's error log through a no-throw fallback, and still returns its documented default. Do not contain the caller-supplied default or add a shared reporter abstraction.

### 9. Truthful Pusher error classification (`reverb-38`)

Add one `InvalidMessageFormat` Pusher exception with client code 4200. `PusherException::payload()` obtains its client text through a protected method; only `InvalidMessageFormat` overrides it so constructor messages retain the specific Reverb diagnostic while clients continue to receive `Invalid message format`.

Classify malformed input at its existing owner:

- `Server` converts top-level or nested JSON failures, invalid event names, and non-array Pusher data into `InvalidMessageFormat`;
- `EventHandler` validates only the channel, auth, and channel-data types its existing typed methods require, decodes channel data once to require an array before channel construction, and uses the exception for unknown Pusher events;
- `ClientEvent` uses the exception for its existing field validation.

The channel-data check closes an unauthenticated public-channel path that published shared membership before failing the array-typed local commit. Repetition could permanently inflate counts, suppress `channel_vacated`, exhaust unscaled shared-table rows, or strand scaled Redis state across restarts. Retain the raw JSON string for HMAC verification and the local commit; the subscribe-only second decode is cheaper than changing Laravel-shaped signatures.

Do not add channel-name, size, or speculative schema validation, and do not convert `ClientEvent`'s existing inline policy rejections into exception classes. Application-signed nested non-scalar or boolean `user_id` values remain developer errors: they fail before shared publication and the original trace reaches the internal reporter. After client-controlled paths are explicit, the generic `Server::error()` fallback keeps the existing 4200 response and reports the original unexpected throwable through the framework exception handler. No import-only behavioral test is added: the old fallback made the missing PHP `Exception` import client-visible only through static analysis.

### 10. Metadata, dead source, records, and test typing (`reverb-27`–`31`)

The split manifest must declare direct source dependencies:

```text
ext-swoole
hypervel/api-client
hypervel/bus
hypervel/console
hypervel/context
hypervel/core
hypervel/coroutine
hypervel/filesystem
hypervel/http-server
hypervel/log
hypervel/prompts
hypervel/queue
hypervel/routing
hypervel/validation
symfony/console
symfony/http-foundation
symfony/http-kernel
```

Retain existing genuinely used dependencies and remove unused direct `hypervel/broadcasting`. Facade namespaces still require their owning packages (`Http` → API client, `File` → filesystem, `Log` → log, `Validator` → validation). Add a package metadata regression following existing `PackageMetadataTest` conventions.

Delete `Concerns\SerializesConnections` after the owner gate and a whole-repository zero-reference check. Do not add a deprecation shim.

Expand the README only enough to state the current upstream and user-relevant closed differences: integrated Swoole lifecycle/TLS, Swoole shared-memory plus Redis scaling, Telescope instead of Pulse, and the existing payload API. Add concise source/test omission comments only at natural future-sync locations required by the porting policy. Update Boost documentation only where behavior/configuration changes, using nearby Laravel-style language.

At `ArrayChannelConnectionManager::for()`, record concisely that Hypervel's per-channel binding makes upstream's otherwise-unused stored channel name unnecessary.

Record the abrupt-worker limitation accurately: worker exit attempts to drain state, but a process-level crash or forced termination before that drain completes can strand shared counters. A full server restart recreates unscaled Swoole shared memory, while scaled Redis state survives application restarts and requires operational cleanup. Do not promise automatic worker-respawn repair and do not add reconciliation machinery.

Document the observable distributed boundaries concisely:

- a missing worker can delay `subscription_succeeded` for up to the existing ten-second metrics deadline and can fail that subscribe;
- unscaled multi-worker user termination returns HTTP 500 when a sibling cannot be reached;
- unscaled multi-worker event publication returns HTTP 500 when an entire sibling population cannot be reached, even if other populations already received the event.

Document the shared Swoole `max_request` behavior without changing it. Incoming WebSocket frames increment the same worker-global counter as HTTP requests. Recommend `SERVER_MAX_REQUESTS=0` for a dedicated Reverb deployment so long-lived clients are not periodically disconnected. A mixed HTTP/Reverb server may keep a nonzero value when periodic recycling is an intentional memory bound; Swoole's per-worker random grace of up to half the threshold staggers those disconnects.

Do not promise that every drain finishes inside `server.settings.max_wait_time`. A local scaled probe with no webhooks or connection limit drained 5,000 clients across four public and one presence channel in 218 ms, but the path is serial and remote Redis latency, configured connection slots, and more memberships add work. The webhook smoothing path is deliberately disabled while draining, so enabled lifecycle webhooks also run in full. An isolated 1,000-call profile measured complete `HttpWebhookDispatcher` plus `QueueFake` dispatch at about 15 microseconds per call; an earlier six-second real-server result was therefore not attributable to the webhook dispatcher and is discarded rather than used as evidence. Operators retaining a nonzero request limit must size the shutdown bound for their topology. Do not silently override the application's global safety valve, add reconciliation machinery, or classify this intentional Swoole behavior as a Swoole defect.

Complete carried finding `reverb-04` by using the existing injected `Timer` boundary in every deferred-webhook delay test. Capture and invoke the registered callback directly, and assert exact timer-ID cancellation where cancellation owns the behavior. Remove the four real-time sleeps; do not add a production seam.

Correct the two gRPC client fakes exposed by the full gate (`grpc-01`). Queueing a terminal response must not delete a stream before `recv()` returns that response, and a request-side `end` flag must leave the locally half-closed stream open for its terminal response. Keep production gRPC source unchanged and add direct fixture-contract and connection regressions.

After explicit bulk-edit approval, add `: void` to the 522 test methods missing it across 47 files in `tests/Reverb` and `tests/Integration/Reverb`. Do not alter helpers, data providers, fixtures, test behavior, or source files in that mechanical pass.

## Test plan

### Focused unit and coroutine regressions

| Area | Required proof |
|---|---|
| Connection gate | A yielding custom application lookup/open/subscribe cannot be overtaken by same-fd message/close; callbacks for different fds still overlap; captured-before-close work exits after terminal mark; periodic jobs use the same entry; entry/token state is released. |
| Open/close/drain | Null/invalid Origin, quota rejection, send/slot/listener failures, unsubscribe failure, limiter cleanup, exact entry removal, earliest-failure precedence, transport disconnect, and batch continuation. |
| Membership | Duplicate subscribe, nonmember unsubscribe/close, one connection across channels, last-local leave with remote count, shared subscribe/unsubscribe failure, a concurrent last-leave/new-join interleaving that keeps the exact channel discoverable, and failed deferred join release reclaiming the now-idle channel. |
| Protected channels | Exactly one verification for private, presence, private-cache, and presence-cache; unauthenticated presence-cache throws; failed unique channel names do not accumulate; pre-existing channels survive. |
| Swoole shared state | Over-63-byte logical names succeed through bounded physical keys; delimiter/cross-family collisions are distinct; capacity errors name real config; same-stripe/opposite-order atomic presence; second-row failure publishes neither count. |
| Redis shared state | One-script presence transitions, collision-safe canonical keys without rejected-topology hash tags, exact transition counts, strict `SET` results. |
| Metrics/presence | Single-worker fast path allocates no pending gather; pipe/Redis multi-worker merge; standalone/Sentinel exact-count early return; timeout includes received slices; cross-worker duplicate user IDs count once and response stays integer; empty/public presence data; one presence extractor/formatter path; remote-only channel-users status; listener/registry cleanup on success, timeout, cancellation, local-slice construction failure, publish/send failure, and cleanup failure. |
| Metric transport | Populate every metric family and recursively prove every local payload is composed only of arrays and JSON-native scalar/null values; assert the connection slice carries an integer `count`; prove enqueue fan-out continues after false/throw and one timeout warning records received/expected slices before partial merge. |
| User termination | Unscaled multi-worker termination reaches local and sibling workers; false and throwing pipe sends produce HTTP 500 only after all siblings are attempted; scaled Redis delivery performs no duplicate pipe fan-out and retains publication-success HTTP 200. |
| Server inter-worker boundary | Registered pipe and task-finish callbacks report ordinary failures with a both-error fallback and ignore cancellation; task, message, startup, and teardown callbacks retain their intended semantics. |
| Shutdown | Earliest connection failure is retained after every fd is attempted; drain, subscriber disconnect, and webhook flush remain independent; reporting failure cannot escape or suppress later worker-exit listeners. |
| Subscriber | One owner across duplicate connect calls; spawn rollback; construction/subscribe/consume failure cleanup; recovery after more than 60 failures; bounded failure logging; disconnect during subscription and consumption; retry delay wakes immediately on worker exit; no successor after intentional disconnect. |
| Cache timers | Successful worker-0 registration retains every exact ID; partial failure rolls back only newly registered timers; worker exit attempts every retained clear including native-`false` failures, contains reporting failure so later listeners run, and a repeated stop is a no-op. |
| Deferred webhooks | Every delayed delivery and suppression branch invokes a captured timer callback deterministically; exact cancellation IDs are cleared once; no live timer or sleep remains. |
| gRPC harness | A queued terminal response closes the fake stream only when received; a request half-close remains open until trailers settle the call. |
| Jobs | Zero-subscription clients are pinged/pruned; send/disconnect/event failures do not abandon later sockets; primary failure is retained. |
| Broadcast fan-out | A failing local recipient does not abandon later recipients or escape; whole-population failures attempt independent populations before propagating; post-commit presence publication reports instead of falsely rejecting membership. |
| Webhook batching | Cluster slot equality for hostile app IDs; durable append plus scheduling failure clears only the newly-owned lock; later scheduling succeeds. |
| Pusher errors | Existing malformed-shape responses remain byte-identical and are not reported; an injected internal handler failure sends the same 4200 payload and reports the identical original throwable; a public scalar-channel-data subscription leaves both shared count and local channel absent. |
| Reverb HTTP errors | Successful handling emits one response; reporting and error-response emission failures preserve the original request failure in their previous chain. |
| Related exception boundaries | WebSocket Server handler failure retains the handshake failure; Testbench reporting failure retains the bootstrap or command failure; SafeCaller reporter failure logs both failures and returns the exact default. |
| Parity/contracts | Raw socket exclusion locally and remotely, batch/null cases, custom manager and logger bindings, direct lookup, full-map union, signature arrays/non-strings, path-bearing config. |
| Config/metadata | Required bootstrap initialization, no duplicate defaults, Cluster scaling rejected without pool creation, Cluster webhook buffering retained, complete/minimal split dependencies, orphan trait absent, complete flush. |

### Real integration coverage

Extend `tests/Integration/Reverb` rather than inventing a second harness:

- unscaled multi-worker presence subscribe receives the complete pre-existing roster from sibling workers;
- unscaled multi-worker HTTP channel/users/connections metrics merge worker slices;
- unscaled multi-worker user termination disconnects matches on a sibling worker;
- scaled independent servers preserve `toOthers()` when the HTTP request reaches a different node;
- Redis atomic presence and collision-safe shared state use `InteractsWithRedis` isolation;
- webhook Cluster tags are asserted structurally and the normal Redis buffer lifecycle still works;
- Redis Cluster is rejected when scaling is enabled but remains valid for webhook buffering when scaling is disabled;
- rejected handshakes and terminal drain close real sockets without retaining registry/slot state.
- a focused native subprocess proves worker 0 clears Cache timers during `max_request` recycle without reaching Swoole's forced-exit warning.

The existing integration workflow already exercises unscaled single-worker, scaled single-worker, unscaled multi-worker, two scaled servers, and scaled multi-worker topologies. Add cases to those servers; do not create another topology.

### Validation order

1. Run each changed/new test file immediately.
2. Run all `tests/Reverb` files.
3. Run configured `tests/Integration/Reverb` Redis and real-server cases.
4. Run PHPStan through the repository configuration.
5. Run PHP CS Fixer.
6. Run `composer fix`, including the full parallel suite and Testbench gates.
7. Freshly trace the entire diff, callers/callees, resource ownership, API/config/docs, hot paths, retained memory, and dead code.
8. Request complete code review and loop until sign-off.

## Performance and compatibility result

- Public signatures, request shapes, and Pusher payloads remain compatible. The scoped-manager contract is additive. Internal `MetricsHandler::gather(..., 'connections')` returns a count envelope instead of live connection objects; the only first-party consumer is the HTTP controller and the public response remains `{"connections": N}`. Four observable behaviors intentionally differ: public presence `data()` gathers node-wide state and may block or throw, an unscaled sibling termination send failure returns HTTP 500 instead of an unconditional 200, an unscaled sibling event-publication failure returns HTTP 500 after independent populations are attempted, and Redis Cluster is rejected for pub/sub scaling.
- Single-worker presence and metrics stay local.
- Multi-worker presence correctness has an approved-gate communication cost described above; no extra serialization or persistent member store is added.
- Connection metrics carry one integer per worker/node instead of retaining or serializing connection objects; merge cost and transport size are constant per slice.
- Public presence `data()` may block or throw through that gather. Redis Cluster fails at configuration time instead of imposing an unusable deadline on every presence subscription.
- The connection gate adds one local synchronization pair per callback and no cross-connection contention.
- Membership reservations add two integer mutations and local checks per subscribe/unsubscribe; they add no lock, allocation, yield, or network call.
- Ordinary shared-state channels retain one operation. Redis presence improves from two round trips to one.
- Duplicate/nonmember operations avoid shared-state and webhook work.
- Direct socket lookup and in-place map union remove scaled broadcast allocations.
- Local broadcasts add only exception frames around existing sends; failures are reported once per broadcast after every selected recipient is attempted.
- Key hashing is local linear work over identifiers already in memory and prevents native key-boundary corruption.
- Webhook success paths add no round trip; only a queue-dispatch failure clears an owned lock.
- Default-disabled rate limiting adds no close cache call.
- Cross-worker termination reuses the existing topology: one pipe message per sibling when unscaled or one existing Redis publish when scaled.
- Worker request recycling retains the application's existing global setting and gains documentation only; no hot-path work or boot warning is added. Dedicated deployments receive a clear recommendation, while mixed deployments are told that Redis and webhook drain cost is topology-dependent.
- Redis subscriber recovery retains one coroutine, one optional subscriber, and one worker-exit-aware timed wait only while disconnected; ordinary connected operation gains no polling, timer, or extra Redis command.
- Cache timer retention adds one small worker-0-lifetime ID list and cold worker-exit clears; it adds no request or message work.
- Pusher validation adds only direct local type checks that replace implicit PHP errors; exception-handler resolution occurs only for unexpected failures.
- Isolated HTTP requests add no success-path work; the exception-preservation frame exists only after request handling fails.
- WebSocket Server and Testbench exception preservation adds work only after the primary operation has failed.
- SafeCaller containment adds work only when both the protected operation and its exception reporter fail.
- No unbounded registry, heartbeat, lease, reconciliation system, or recovery job is introduced.

## Audit records

After implementation and review:

- add the compact final Reverb work-unit entry to the companion ledger;
- record important rejected concerns, especially abrupt-worker reconciliation and channel-wide/reverse-index designs;
- record the accepted transient metrics window during asynchronous subscriber startup and why no startup barrier is added;
- record the Redis Cluster scaling rejection, continued Cluster webhook support, and shared `max_request` behavior;
- amend completed `cache-11` to state that the recorded successful-ID retention never shipped, link it to this Reverb work unit, and add the dependency-index row with Reverb revalidation complete;
- link this Reverb work unit back to the `cache-11` amendment;
- record `ArrayChannelManager::flush()`'s intentional whole-repository reset divergence so a future upstream sync does not restore the broken per-app loop;
- record the approved unscaled user-termination HTTP 500 divergence and distributed behavior of public presence `data()`;
- add a cross-package dependency row for `server-10`, amend the completed Server entry, and record the confirmed Swoole partial-serialization defect without a package workaround;
- add cross-package dependency rows for `websocket-server-13` and `testbench-02`, amend the completed WebSocket Server entry, and route Testbench for later full-audit revalidation;
- add a cross-package dependency row for `support-27`, amend the completed Support entry, and revalidate WebSocket Server while confirming Reverb remains unaffected through Foundation's override;
- amend completed package entries only where this work changes their assumptions;
- update the routing index, cross-package dependency index, and Reverb checklist state;
- record Laravel-facing result, validation, code-review sign-off, and explicit owner approvals for the gates;
- remove superseded plan wording rather than appending decision history.
