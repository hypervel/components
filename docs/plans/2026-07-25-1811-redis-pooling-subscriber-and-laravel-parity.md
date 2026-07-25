# Complete Redis Pooling, Subscriber Transport, and Current Laravel Parity

## Status

Plan signed off after the full Redis package audit, pre-implementation second-opinion consensus, fresh source review, and independent plan review. The owner approved the consensus scope and its additive capabilities, public-surface corrections, behavior changes, and source-proven hot-path costs. During implementation, focused second-opinion loops amended the connection-context, Sentinel endpoint, and subscriber transport requirements after live Redis integration and self-review exposed empty-context and context-implied TLS mismatches. The amendments are recorded in Sections 3, 4, and 7.

This is the authoritative implementation plan for the Redis work unit. After compaction during implementation, re-read `AGENTS.md` and this plan in full before editing. Do not rely on a compacted summary and do not re-read the main framework audit plan unless a newly discovered issue requires its wider routing context. The anti-overengineering, implementation, testing, review, and completion rules needed for this work are repeated here deliberately.

## Scope

Complete the `redis` package audit as one coherent Redis, Reverb, Horizon, Telescope, Sentry, Testing, Support-facade, metadata, and documentation work unit.

The finding table identifies the complete proposed work. The numbered implementation sections are authoritative for behavior, code shape, tests, and rejected alternatives; the architecture and summary sections orient the work without redefining those decisions.

The work must not weaken or remove these Hypervel features:

- `transform: true|false`;
- `withConnection()` and `withPinnedConnection()`;
- immediate callback-form pipeline and transaction release;
- one owner-coroutine terminal defer per pool for raw same-connection state;
- `withoutSerializationOrCompression()`;
- SafeScan and `flushByPattern()`;
- serializer, compression, digest, pack, unpack, and option helpers;
- `evalWithShaCache()`;
- nested per-connection pool, Sentinel, and Cluster configuration;
- explicit concurrency leases;
- exact task and process cleanup.

Hyperf parity is not a goal. Laravel remains the public API and documentation reference where its API fits a phpredis-only coroutine pool. Hypervel's pooled ownership and connection-local topology take priority where Laravel's request-lifetime connector model does not fit.

## Desired final architecture

| Surface | Final owner and lifetime |
|---|---|
| Named Redis access | One worker-lifetime `RedisProxy` per configured name |
| Ordinary commands | One checked-out `RedisConnection` wrapper per proxy operation, returned or discarded after events settle |
| Stateful command sequences | The exact wrapper remains pinned in coroutine or fallback context until its real terminal operation |
| Callback pipeline/transaction | Newly pinned wrapper returns immediately when the callback finishes |
| Terminal deferred cleanup | At most one owner-ID defer per pool and coroutine; copied child context cannot inherit ownership falsely |
| Failed command disposition | `RedisConnection` classifies the local phpredis failure without replaying the command |
| Server error reply | Retain a protocol-synchronized generation unless Sentinel can resolve a different master |
| Transport/protocol failure | Mark the generation invalid for lazy reconnect |
| Subscriber transport | One dedicated hooked PHP stream and exact RESP2 decoder per `Subscriber` |
| Subscriber command routing | `CommandInvoker` owns receive-loop cause retention and result, PING, and message channels |
| Subscriber topology | `RedisProxy` maps the configured standalone, Sentinel, or Cluster connection to a dedicated subscriber endpoint |
| Sentinel discovery | `RedisSentinelFactory` owns shuffled node attempts and one aggregated total-failure exception |
| Reverb publishing | The injected pooled `RedisProxy` publishes independently of subscriber state |
| Redis macros | Static macro registry on `RedisConnection`; proxy remains the event and lease owner |
| Event enablement | Nullable worker-owned override on `RedisConfig`, applied only when a pool assembles its connection config |
| Horizon connection | Full named Hypervel Redis config copied to `database.redis.horizon`; clustered prefixes are hash-tagged |
| Facade metadata | Production proxy constant is the callable-boundary source; tests enforce its inclusion in facade ignores |
| Telescope observability | Recursive scalar-safe formatting and exact batch-opener filtering owned by `RedisWatcher` |
| Static test cleanup | `AfterEachTestSubscriber` flushes `RedisConnection` macros |

## Finding summary

| ID | Category | Severity | Verified failure | Final boundary |
|---|---|---:|---|---|
| `redis-09` | Defect | Critical | A caught `RedisException` reconnects and repeats a command that Redis may already have committed | Never replay; retain synchronized server errors, invalidate unknown transport/protocol state, and invalidate stale Sentinel master replies |
| `redis-10` | Defect | Major | CRLF EOF framing corrupts bulk values containing CRLF, ignores error frames, poisons later acknowledgements after simple PONG, and can leave foreground waits blocked without a cause | Use an exact hooked-stream RESP2 decoder, retain the first receive failure, and bound foreground waits |
| `redis-11` | Defect | Major | Dedicated subscribers drop valid credentials, TLS/context, Unix, Sentinel, and Cluster configuration | Resolve complete subscriber endpoints and credentials from each connection's real topology |
| `reverb-05` | Defect | Major | Reverb gates publishing on the wrong subscriber owner, grows memory without a bound, can report a false empty metrics result, and retains pending metrics state when publishing fails | Publish immediately through the independent pooled proxy, propagate real failures, and clean the registered metric/listener on failure |
| `redis-12` | Defect and upstream defect | Major | A release failure replaces a callback failure in both concurrency-limiter public callback paths | Use two direct primary-failure-preserving blocks and test the full matrix at both sites |
| `redis-13` | Supported current Laravel parity and defects | Major/Minor | Host, scheme, context, option, ACL, prefix, client-name, and Cluster failover behavior is missing, stale, or falsey-zero unsafe | Port current PhpRedis behavior into existing pooled connection classes |
| `redis-14` | Supported current Laravel parity | Improvement | Redis connections lack current Laravel macros | Add Macroable to exact wrappers, register without a Redis checkout, preserve proxy event granularity, and flush static state after tests |
| `redis-15` | API/configuration correction | Major | Telescope and Sentry duplicate destructive config loops, while Hypervel's `extend()` uses Laravel's name for an incompatible named-proxy API | Add boot-only manager event controls through a tri-state config override and delete the false extension API |
| `horizon-01` | Defect and parity defect | Major | `Horizon::use()` reads a dead top-level cluster shape, can reduce a Cluster to one seed, and fails to hash-tag or publish the resolved prefix | Copy the complete named Hypervel config and normalize the Cluster prefix |
| `redis-16` | Defect and intentional difference | Major | Advertised pooled `ssubscribe()` can capture a wrapper indefinitely in native sharded-subscriber mode | Reject it beside subscribe/psubscribe and remove its advertised surface |
| `redis-17` | Metadata defect | Minor | Proxy-bound methods and generated facade exclusions have already drifted apart | Enforce one production-owner subset invariant and derive proxy test cases from the production constant |
| `redis-18` | Dead compatibility and duplicate-normalization defect | Minor | Redis 6.0 source/test compatibility is unreachable at the declared 6.1 floor, and config-name validation bypasses the canonical normalizer | Delete the unsupported branches and route both config paths through one parser |
| `redis-19` | Critical native-boundary defect and intentional difference | Critical | Native RESET is process-fatal in PIPELINE mode and silently leaves a pooled wrapper working against database 0 | Reject pooled RESET before native dispatch, transformation, or queue handling |
| `redis-20` | Defect | Major | Mixed-case commands bypass proxy routing, WATCH bookkeeping, and pooled subscription/RESET guards | Preserve exact macro names, normalize proxy routing once, and normalize wrapper-native dispatch once |
| `telescope-01` | Defect and upstream defect | Major | Redis observability formatting can run `jsonSerialize()` or implicit object conversion and replace a successful command result with a formatter failure | Recursively format arrays and use `get_debug_type()` for every non-scalar leaf |
| `telescope-02` | Defect | Minor | Telescope's dead `transaction` ignore records real `multi` openers, while mixed-case pipeline openers bypass its non-strict case-sensitive filter | Strictly filter the actual `pipeline` and `multi` event names case-insensitively |
| `sentry-01` | Defect | Major | Redis spans read the test-only `db` key and report database 0 for real non-zero `database` configs | Read the canonical normalized key in both success and failure spans and correct the masking test fixture |

## Backing research and fixed assumptions

### Carried Redis ownership invariants

The Database work unit already established and tested:

- exact wrapper ownership in `RedisProxy`;
- one terminal defer per pool and owning coroutine ID;
- no defer outside a coroutine;
- copied coroutine context does not copy defer ownership;
- newly pinned callback-form pipeline/transaction wrappers return immediately;
- native MULTI, PIPELINE, and WATCH state causes terminal discard rather than requeue;
- successful terminal operations settle WATCH correctly;
- Laravel-facing DISCARD reaches phpredis rather than Pool lifecycle teardown;
- event failures cannot skip connection handoff or terminal cleanup;
- task and pre-fork cleanup discard exact inherited wrappers and flush already-resolved pools;
- pool and command durations use monotonic time.

Implementation must preserve those tests and source paths. Do not reintroduce:

- one defer per pin;
- a boolean or object defer marker that copied context can inherit falsely;
- eager-release marker helpers;
- a PHP mirror of phpredis queue mode;
- reconstructed connection identity;
- release during fork cleanup;
- command-listener failure before ownership cleanup.

### Current Laravel workflow

Historical Laravel changes are discovery evidence only. For each accepted current feature, reopen the corresponding current file and its current tests before implementing. The actual source reference is the local default branch:

- framework: `examples/laravel/framework`, commit `23e9e71f382b`;
- docs: `examples/laravel/docs`, commit `ce4a1bf093c2`.

The Redis discovery history is:

| Commit / PR | Discovery surface |
|---|---|
| `6ce9d6f247` / #56941 | Standalone `pack_ignore_numbers`; connector source |
| `26013caaf6` / #59569 | Standalone and Cluster context normalization; connector source and `PhpRedisConnectorTest` |
| `27ddc2e67b` | Redis Connection macros; connection source and `RedisConnectionTest` |
| `d2b2cdc9b4` / #59158 | `tcp_keepalive`; connector source and `RedisConnectorTest` |
| `84d28db0bb` / #49560 | Do not send `CLIENT SETNAME` to Redis Cluster |
| `d12b613ad6` / #35402 | Standalone client name; connector and tests |
| `3e7df8b7ed` / #60237 | Non-empty host and matching scheme; PhpRedis and Predis discovery files and tests |
| `0dc2d8a8f3` / #40282 | Serializer/compression support and related connection tests |
| `e720b86e1c` / #31182 | Current PhpRedis option support |
| `0b6d69917d` / #54191 | Native max-retry and backoff settings |
| `d7d4cc892f` | Manager event enable/disable API |
| `847b196c7b` | Laravel connector-driver `extend()` and `setDriver()` surface, intentionally not ported |

Current files to use during implementation:

- `examples/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php`;
- `examples/laravel/framework/src/Illuminate/Redis/Connections/Connection.php`;
- `examples/laravel/framework/src/Illuminate/Redis/RedisManager.php`;
- `examples/laravel/framework/tests/Redis/PhpRedisConnectorTest.php`;
- `examples/laravel/framework/tests/Redis/RedisConnectorTest.php`;
- `examples/laravel/framework/tests/Redis/RedisConnectionTest.php`;
- `examples/laravel/framework/tests/Redis/RedisManagerExtensionTest.php`;
- `examples/laravel/docs/redis.md`;
- `examples/laravel/horizon/src/Horizon.php`.

Preserve current upstream member order where current Laravel members are merged into Laravel-shaped Hypervel classes. Adapt only the owner, topology, pooling, typing, coroutine, event, and supported-platform boundaries recorded here.

### Verified phpredis failure behavior

The failure discriminator is structural in phpredis:

1. a RESP `-` reply stores its text in the client's last-error slot;
2. `redis_error_throw()` throws from that same stored buffer for error classes it chooses to throw;
3. a thrown synchronized server reply therefore has `getLastError() === $exception->getMessage()`;
4. transport and truncated-protocol failures do not produce that equality;
5. if an older supported extension ever fails to set the slot, inequality degrades conservatively to invalidation.

PhpRedis has an open-ended throw set. It throws for many server replies, including `LOADING`, `MISCONF`, `OOM`, `READONLY`, `MASTERDOWN`, `CROSSSLOT`, and module-defined error codes. Reconnecting on every thrown server error would create reconnect storms for conditions a new generation would hit identically.

The final rule is based on whether a new generation can behave differently:

- unknown transport or protocol state: invalidate;
- synchronized server error against the same endpoint: retain;
- synchronized `READONLY` or `MASTERDOWN` from a Sentinel connection: invalidate because the next generation resolves the current master;
- Cluster redirection: leave to phpredis;
- never repeat the failed command.

Match the exact Redis error-code prefix, not a broad substring.

### Verified RESET behavior

Current phpredis `PHP_METHOD(Redis, reset)`:

- raises `php_error_docref(E_ERROR)` in PIPELINE mode, so PHP terminates before framework code can catch it;
- resets the client status to connected, mode to atomic, selected database to `0`, and native watch state to false;
- causes the Redis server to deauthenticate;
- leaves phpredis's stored auth configuration intact, so the next command transparently authenticates;
- leaves phpredis's stored database as `0`, so the reconnect/open path never reselects the configured non-zero database;
- therefore leaves the client apparently healthy while all later commands use database 0.

This is not a Hypervel flag-sync issue. RESET destroys connection configuration that the pool owns. Reconnecting inside `reset()` would swap the exact socket behind `withConnection()` / `withPinnedConnection()` and could turn a server-successful RESET into a later connect failure. The honest pooled contract is to reject RESET before native dispatch.

The relevant local phpredis references are:

- `examples/phpredis/redis.c`, `PHP_METHOD(Redis, reset)`;
- `examples/phpredis/library.c`, `redis_sock_server_open()` and error-reply handling;
- `examples/phpredis/CHANGELOG.md`, Redis 6.2 `OPT_PACK_IGNORE_NUMBERS`.

The native PIPELINE fatal is a valid upstream phpredis improvement candidate because the same method reports other invalid modes normally. Do not put a framework TODO in Redis source: pooled RESET remains incompatible even if phpredis later throws an exception.

### Verified subscriber transport facts

The current subscriber uses Engine EOF packet framing with `"\r\n"`. A RESP bulk payload containing CRLF is split before its declared length is honored. A NUL-only payload is not by itself corrupted; tests and docs must state the narrower true failure.

Engine cannot be repaired by mixing `recvPacket()` and exact `recvAll()` on the same socket because `recvPacket()` can buffer bytes that `recvAll()` cannot then see. `SocketFactory` also hardcodes an internet socket family and cannot represent Unix sockets.

A hooked PHP stream:

- is coroutine-nonblocking under the same TCP hook already required for pooled phpredis;
- supports `stream_socket_client()`, `fgets()`, exact repeated `fread()`, repeated `fwrite()`, and sibling `fclose()` wakeup;
- supports TCP, TLS, IPv4, IPv6, and Unix endpoints;
- preserves bulk bytes containing CRLF and NUL exactly.

This is a transport correction, not a second networking stack or a new effective runtime requirement. Do not add a public stream factory, transport contract, hook option, or user-facing hook architecture section.

### Subscriber topology facts

- standalone config may use host/port, TLS scheme, IPv6, Unix path, username, scalar/array password, stream context, timeout, and prefix;
- username and password value `"0"` are valid and must not be lost to truthiness;
- Sentinel nodes and Sentinel auth are separate from the resolved master's connection credentials;
- every new Sentinel subscriber must resolve the current master rather than use a pooled wrapper's possibly stale endpoint;
- any current Redis Cluster master is valid for ordinary SUBSCRIBE/PSUBSCRIBE;
- Cluster master discovery may borrow a pooled wrapper briefly, read its local/native master list, then release it before opening the dedicated subscriber;
- sharded Pub/Sub needs slot routing and is deliberately not represented by this ordinary subscriber.

### Event and extension facts

- a Redis pool snapshots its config when it is created;
- changing user config or an override cannot retrofit existing checked-out or idle wrappers;
- a per-command config read would put configuration work on every command;
- `RedisConfig` already owns connection-config assembly and is an auto-singleton;
- a nullable worker-owned override can preserve explicit per-connection config when null and override future pool creation when true or false;
- Telescope and Sentry currently duplicate boot-time config mutation loops;
- current Hypervel `extend($connectionName, callable)` creates a named proxy and is not Laravel's connector-driver `extend($driver, Closure)`;
- the repository has no production consumer of the named-proxy extension surface;
- macros and explicit held raw access cover the useful supported extension needs without adding Predis or connector abstraction.

### Owner-approved gates

The owner has explicitly approved:

1. rejecting pooled `reset()` although current Laravel advertises the native method;
2. adding one static macro-table lookup to every wrapper command;
3. deleting the documented Hypervel automatic replay promise;
4. deleting public Hypervel `extend()` / `forgetExtension()` with no compatibility shim;
5. changing Reverb outage behavior from silent queueing and `0` to an honest thrown publish failure;
6. adding Laravel-shaped boot-only event controls;
7. preserving Hypervel's connection-local topology instead of adding Laravel's top-level clusters tree;
8. rejecting pooled `ssubscribe()` rather than adding sharded Pub/Sub machinery;
9. every other additive capability and current-Laravel parity item in the pre-plan consensus.

The final owner summary must separately call out narrower corrections discovered during the fresh plan review, including proxy and wrapper method-name normalization, exact RESP integer validation, failed Reverb metrics-request cleanup, exact Telescope batch-opener filtering, and `sentry-01`. It must state that Telescope records no entry for callback-form pipelines or transactions because their queued commands and EXEC run directly on the held native client; recording a bare opener would imply command visibility Telescope does not have.

## Embedded implementation rules

These rules are copied here because this plan, not the main audit plan, will be the implementation context after compaction.

### Correctness and ownership

- Require a supported realistic path and meaningful harm before treating a concern as a defect.
- Trace the exact state or resource owner, acquisition, publication, use, handoff, and every terminal path before editing.
- Fix a shared defect at its lowest owning boundary and update every affected consumer in the same change.
- Preserve the earliest operation failure while still attempting independent cleanup.
- Never replay an operation whose commit state is unknown.
- Never requeue a connection with unknown protocol, transaction, watch, subscriber, or session state.
- Keep context ownership exact; never reconstruct a borrowed wrapper from mutable configuration.
- Do not weaken a verified correctness requirement to reduce churn.

### Avoid overengineering

- Prefer direct code and existing PHP, Laravel, Hypervel, phpredis, Channel, and stream primitives.
- Do not add a registry, state machine, retry loop, connector hierarchy, transport abstraction, configurable timeout family, pool retrofit path, generic finalizer, or extension point without a demonstrated need.
- Do not add machinery merely because it sounds robust or future-proof.
- Deliberate raw/native escape hatches remain escape hatches unless the public contract promises framework safety through them.
- Avoiding overengineering never justifies an incomplete fix, stale code, missing security or lifecycle safeguards, or deferred worthwhile work.

### Hot-path discipline

For every change, check:

- allocations;
- static and container lookups;
- locks and atomics;
- context reads/writes;
- hashing and serialization;
- yields, sleeps, polling, and retries;
- network commands and reconnects;
- logging and exception construction;
- retained worker memory.

Any measured or source-proven regression beyond the itemized noise-level costs in Section 19 requires a new owner gate before implementation. The proxy and wrapper name normalization found during the fresh plan review must be called out in the owner summary before implementation. A cold connection-creation, subscriber-construction, boot, failure, teardown, or observability path is not the ordinary command hot path.

### Source and PHPStan discipline

- Read each changed source file in full or consecutive chunks before editing.
- Inspect each touched method and adjacent code for stale guards, wrong types, dead compatibility, and false framework assumptions.
- Do not make runtime code more complex, slower, or less truthful to satisfy PHPStan.
- Fix real native/docblock type defects first; then use a truthful local `@var`; then use a line- or identifier-scoped ignore only when PHPStan cannot model correct code.
- Do not widen contracts with implementation-specific methods to satisfy analysis.
- Do not add global PHPStan ignores without a separate owner decision.

### Testing discipline

- Edit one file at a time.
- Run each changed or new test file immediately before moving to another test file.
- Tests must assert supported public behavior, verified failure paths, exact ownership, and deterministic cleanup.
- Use the existing `InteractsWithRedis` trait for every external Redis/Valkey test.
- Keep assigned `TEST_TOKEN`; never hardcode or overwrite runner-owned worker identity.
- Bind fake servers to port `0`.
- Close every stream, socket, subscriber, channel, and coroutine-owned fixture in exception-safe cleanup.
- Use `ParallelTesting::tempDir()` for Unix socket paths and other scratch files.
- Do not weaken or delete a regression to make source pass.
- Do not add a source abstraction solely to inject test behavior.

### Unexpected findings during implementation

If implementation exposes an unexpected bug, native edge, lower-level contradiction, same-family omission, or design change:

1. stop editing that path;
2. trace the full cause and every sibling;
3. prepare the smallest complete proposed correction, with tests and cost;
4. send it through a focused second-opinion loop;
5. continue only after consensus;
6. amend this plan and the final ledger decision when the accepted design changes.

Straightforward implementation mistakes such as a namespace typo do not need a design loop.

## Implementation order

Implement in the order below. Each section names the owner and the affected tests. Keep each source/test pair green before moving forward.

1. Preserve the carried Redis ownership baseline.
2. Remove command replay and add safe connection disposition.
3. Replace the subscriber transport and RESP parser.
4. Complete subscriber accounting, failures, and topology.
5. Remove Reverb's subscriber-gated publish queue.
6. Correct concurrency-limiter failure precedence.
7. Port current PhpRedis validation and options.
8. Add connection macros and static cleanup.
9. Add manager event controls and remove incompatible extension APIs.
10. Correct Horizon configuration.
11. Reject pooled `ssubscribe()` and enforce facade metadata.
12. Delete dead Sentinel/config normalization paths.
13. Reject pooled RESET.
14. Correct Telescope formatting and filtering, and Sentry database metadata.
15. Complete split metadata, provenance, documentation, and intentional-difference records.
16. Remove every replaced path.
17. Run focused, integration, and full validation.
18. Perform a fresh full-diff self-review and independent code review.

## 1. Preserve the carried ownership baseline

### Files

- `src/redis/src/RedisConnection.php`
- `src/redis/src/RedisProxy.php`
- `src/redis/src/Traits/MultiExec.php`
- `src/redis/src/Listeners/RedisConnectionLifecycleListener.php`
- `src/redis/src/RedisManager.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/MultiExecTest.php`
- `tests/Integration/Redis/RedisConnectionIntegrationTest.php`
- `tests/Integration/Redis/RedisProxyIntegrationTest.php`
- `tests/Integration/Redis/RedisProxyNonCoroutineIntegrationTest.php`

### Required invariant

Before changing command dispatch, macros, RESET, or subscription guards:

1. trace ordinary borrow/release;
2. trace existing-context reuse;
3. trace `multi`, `pipeline`, `select`, and `watch` publication;
4. trace callback-form `MultiExec` immediate release;
5. trace owner-ID defer registration and copied context;
6. trace event failure, release failure, and same-connection handoff precedence;
7. trace native mode and WATCH checks on release;
8. trace task, purge, fork, and worker-start discard.

Do not refactor these paths merely to make later edits easier. Add only the settled command, macro, RESET, and metadata behavior around the existing ownership model.

## 2. Remove unsafe replay and classify connection disposition

### Files

- `src/redis/src/RedisConnection.php`
- `src/redis/src/RedisProxy.php`
- `src/redis/src/PhpRedisConnection.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/Fixtures/PhpRedisConnectionStub.php`
- `tests/Redis/Fixtures/PhpRedisClusterConnectionStub.php`
- `tests/Integration/Redis/RedisConnectionIntegrationTest.php`
- `src/boost/docs/redis.md`

### Command boundary

Delete `retry()` and every test/stub override that exists only for replay.

Use this control-flow shape inside `RedisConnection::__call()`:

```php
public function __call($name, $arguments)
{
    try {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $arguments);
        }

        $name = strtolower($name);
        $result = $this->executeCommand($name, $arguments);
    } catch (RedisException $exception) {
        if ($this->shouldInvalidateAfter($exception)) {
            $this->markInvalid();
        }

        throw $exception;
    }

    if ($name === 'watch' && $result !== false) {
        $this->watching = true;
    } elseif (
        $name === 'exec'
        || ($name === 'unwatch' && $result !== false)
    ) {
        $this->watching = false;
    }

    return $result;
}
```

Section 8 adds Macroable before this final shape is complete. Exact macro lookup intentionally precedes wrapper-native name normalization. The normalization makes WATCH/EXEC/UNWATCH bookkeeping and pooled prohibitions honor PHP's case-insensitive method contract; PHP's case-insensitive method lookup already keeps `prepare*` and `call*` transforms working without it. RESET is deliberately absent from successful WATCH settlement because Section 13 rejects it before native dispatch.

`RedisProxy::__call()` must independently normalize its routing name before it makes any strict comparison:

```php
$command = strtolower($name);
```

Use `$command` for:

- dedicated `subscribe` / `psubscribe` routing;
- `CONNECTION_BOUND_METHODS`;
- the `discardTransaction()` branch;
- `shouldUseSameConnection()`;
- successful `select` database tracking.

Continue forwarding the original `$name` to `RedisConnection` and command events. This preserves exact macro names and the public command name while making proxy ownership decisions case-insensitive. Do not replace the existing fixed comparisons with a command registry or a proxy-side macro lookup.

The one helper is justified because the failure-disposition rule is non-trivial and its name keeps `__call()` readable. Do not split its one-caller classification into smaller helpers:

```php
protected function shouldInvalidateAfter(RedisException $exception): bool
{
    if ($this->connection->getLastError() !== $exception->getMessage()) {
        return true;
    }

    if (! ($this->config['sentinel']['enable'] ?? false)) {
        return false;
    }

    $errorCode = explode(' ', $exception->getMessage(), 2)[0];

    return in_array(
        $errorCode,
        ['READONLY', 'MASTERDOWN'],
        true,
    );
}
```

Use exact first-token/error-code matching. Do not copy Laravel's substring message registry. Do not reconnect immediately. Do not log and do not repeat the command.
Do not add a second `errorCode()` helper, command-error enum, or registry.

Do not add a null-client guard to `shouldInvalidateAfter()`. Pooled wrappers are activated before native dispatch, and native dispatch against a null client raises `Error`, not `RedisException`. A macro that deliberately closes its held native client is an explicit extension escape hatch, not a connection-disposition invariant the framework should preserve with extra machinery.

### Tests

Cover:

- a transport/protocol exception invalidates;
- an equal last-error server reply is retained;
- standalone `READONLY` and `MASTERDOWN` are retained because reconnecting the same host cannot repair them;
- Sentinel `READONLY` and `MASTERDOWN` invalidate;
- `LOADING`, `OOM`, `MISCONF`, and `CROSSSLOT` do not trigger reconnect storms;
- the original exception object remains primary;
- no command is invoked twice;
- Redis and Valkey integration confirms synchronized server-error equality where a stable command can produce it.

Delete replay assertions. In `src/boost/docs/redis.md`, delete only the framework replay sentence. Keep native phpredis `retry_interval`, `max_retries`, and backoff documentation.

## 3. Replace subscriber EOF framing with exact RESP2 decoding

### Files

- `src/redis/src/Subscriber/Connection.php`
- `src/redis/src/Subscriber/Constants.php`
- `src/redis/src/Subscriber/Exceptions/ServerException.php` (new)
- `tests/Redis/Subscriber/ConnectionTest.php`
- `tests/Redis/Subscriber/CommandBuilderTest.php`
- `tests/Redis/Fixtures/RespServer.php` (new, shared test fixture)
- `tests/Redis/Fixtures/Tls/server.crt` (copy of the established local test certificate)
- `tests/Redis/Fixtures/Tls/server.key` (copy of the established local test key)

### Transport ownership

`Subscriber\Connection` owns one hooked PHP stream resource. Remove `SocketFactoryInterface`, `SocketInterface`, `SocketFactory`, `SocketOption`, EOF packet settings, and the test-only socket-factory seam.

Keep `Constants::CRLF` for `CommandBuilder`. Delete `Constants::EOF`.

Build one endpoint from the resolved config:

- absolute Unix host/path or canonical `unix:///...`: one `unix://...` endpoint
  with no port suffix; reject relative Unix URIs;
- TLS: `tls://...`;
- normal TCP: `tcp://...`;
- when no endpoint or configuration scheme is present, a non-empty context
  selects TLS exactly as phpredis does; an explicit scheme remains authoritative;
- recognize an unbracketed raw IPv6 host before URI parsing and bracket it
  before adding the separate port; preserve an already-bracketed IPv6 host;
- retain an already-correct supported scheme rather than duplicating it;
- reject unsupported or conflicting endpoint shapes descriptively at this
  connection boundary, including credentials, paths, queries, fragments,
  unbracketed scheme-carrying IPv6, and a port embedded in the host.

Normalize phpredis single-client context for PHP streams:

```php
$streamOptions = $context['stream'] ?? $context['ssl'] ?? $context;
$streamContext = stream_context_create([
    'ssl' => $streamOptions,
]);
```

Only add the SSL wrapper section when it is relevant. Do not pass phpredis's `['stream' => ...]` shape directly to PHP's stream wrapper.

Open with `stream_socket_client()` and convert native `false` to `SocketException` with the endpoint and native error details. The open timeout is the configured subscriber timeout.

### Full writes

`send()` must loop until every byte is written:

```php
$written = 0;
$length = strlen($data);

while ($written < $length) {
    $bytes = fwrite($this->stream, substr($data, $written));

    if ($bytes === false || $bytes === 0) {
        throw new SocketException('Failed to send data to the Redis subscriber socket.');
    }

    $written += $bytes;
}
```

PHP streams do not expose a source-offset argument for `fwrite()`, so slicing the remaining suffix is the direct correct shape. The invariant is a full write or a named terminal failure.

### RESP2 decoder

Decode exactly:

- `+` simple string;
- `-` server error, as a new `Subscriber\Exceptions\ServerException` extending `RuntimeException`;
- `:` integer;
- `$` bulk string and `$-1` null;
- `*` array and `*-1` null.

No RESP3 types are added. Reject any bulk or array length below `-1` as malformed instead of treating every negative value as null.

Representative shape:

```php
public function receive(): mixed
{
    $line = $this->readLine();
    $prefix = $line[0] ?? throw new SocketException('Received an empty Redis response.');
    $value = substr($line, 1);

    return match ($prefix) {
        '+' => $value,
        '-' => throw new ServerException($value),
        ':' => $this->parseInteger($value),
        '$' => $this->readBulk($this->parseInteger($value)),
        '*' => $this->readArray($this->parseInteger($value)),
        default => throw new SocketException("Unsupported Redis response type [{$prefix}]."),
    };
}
```

`parseInteger()` is shared by integer, bulk-length, and array-length frames. It accepts only a complete signed decimal value representable by PHP's native integer and throws `SocketException` for malformed or overflowing input; never rely on PHP's permissive string-to-integer cast.

`readLine()`:

- uses `fgets()`;
- treats `false` as terminal;
- requires and strips the exact trailing CRLF;
- never treats EOF as an empty valid frame.

`readExact()`:

- loops until the requested number of bytes has been read;
- treats `false`, EOF, and empty/no-progress reads as terminal;
- never spins on an empty read;
- reads the bulk payload plus its trailing CRLF;
- verifies that trailing CRLF before returning the payload bytes.

Array decoding recurses only according to the wire's declared item count. This is a protocol decoder, not a generic command state machine.

### Tests

Replace mocked Engine socket expectations with a test-only in-process RESP server bound to port `0`. The shared fixture is justified only because Connection, CommandInvoker, and Subscriber tests use the same real transport; it must remain under `tests/Redis/Fixtures`, not production.

Cover:

- a large command is written completely and a closed peer fails by exception; do not add a production seam solely to force the native short-write branch;
- deliberately chunked short reads;
- a bulk payload containing CRLF;
- a bulk payload containing CRLF and NUL;
- empty bulk string and null bulk;
- integer, simple string, nested array, and null array;
- error frame becomes `ServerException`;
- truncated line, truncated bulk, missing bulk CRLF, false/EOF, and empty-no-progress reads become `SocketException`;
- malformed/overflowing integers and invalid negative bulk or array lengths become `SocketException`;
- IPv4 and IPv6 endpoint formatting;
- Unix socket operation with a `ParallelTesting::tempDir()` path;
- flat, `ssl`, and `stream` TLS context normalization through a real local TLS stream using the committed test certificate, without adding a production factory.
- schemeless host plus non-empty context reaches TLS, while an explicit TCP
  endpoint remains TCP at the endpoint-formatting boundary.

Close client streams in `finally`. `RespServer::wait()` is the terminal fixture operation and closes
the listener in its own `finally`; it also owns host/port parsing for consumers. The fixture must not
use a fixed port or shared path.

## 4. Make subscriber command routing and topology exact

### Files

- `src/redis/src/Subscriber/CommandInvoker.php`
- `src/redis/src/Subscriber/Subscriber.php`
- `src/redis/src/Subscriber/Message.php`
- `src/redis/src/RedisProxy.php`
- `src/redis/src/RedisManager.php`
- `src/redis/src/RedisServiceProvider.php`
- `src/redis/src/RedisSentinelFactory.php`
- `src/redis/src/PhpRedisConnection.php`
- `tests/Redis/Subscriber/CommandInvokerTest.php`
- `tests/Redis/Subscriber/CommandInvokerCreateFailureTest.php`
- `tests/Redis/Subscriber/SubscriberTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/RedisEventsTest.php`
- `tests/Redis/MultiExecTest.php`
- `tests/Redis/RedisPoolHeartbeatTest.php`
- `tests/Redis/RedisProxyNonCoroutineTest.php`
- `tests/Redis/RedisManagerTest.php`
- `tests/Redis/RedisServiceProviderTest.php`
- `tests/Redis/RedisSentinelFactoryTest.php` (new)
- `tests/Redis/Fixtures/RespServer.php`
- `tests/Integration/Redis/RedisSubscribeIntegrationTest.php`
- `tests/Integration/Redis/Subscriber/SubscriberIntegrationTest.php`

### CommandInvoker failure ownership

Retain the first receive-loop throwable:

```php
private ?Throwable $receiveFailure = null;
```

When receive exits:

1. store the throwable only when receive or routing initiated settlement;
   deliberate interrupt, timeout, shutdown, and prior send-failure wakeups do
   not publish their cleanup-induced read/channel exception;
2. close the result, PING, and message channels;
3. close the connection through idempotent `interrupt()`;
4. retain existing worker-shutdown watching;
5. do not let later close/reporting failure replace the receive failure.

Keep the repository's established `while (true)` receive-loop form with an
identifier-scoped PHPStan ignore explaining that receive or routing failure is
the terminal condition. `for (;;)` would avoid the diagnostic but would be the
only alternate infinite-loop idiom in the source; a mutable flag would add a
second lifecycle authority and a per-message read.

Foreground result and PING waits:

- when the invoker is already interrupted, rethrow the retained receive cause
  or a canonical closed-connection `SocketException` before attempting another
  send;
- command acknowledgement waits use the existing subscriber timeout;
- `Subscriber::ping()` retains its existing per-call timeout argument;
- a positive timeout is bounded;
- zero means unbounded and maps to the Channel's real unbounded form, not a non-blocking poll;
- if `pop()` returns false because the receive side failed or closed, rethrow the retained cause when present;
- a foreground timeout interrupts the connection before throwing, so a late acknowledgement or PONG cannot poison the next command;
- otherwise throw the appropriate named subscriber failure.

Idle receive remains unbounded because a valid subscription can be silent indefinitely.

Route decoded frames by semantic values, not line counts:

- simple `OK` and other non-PONG status replies: result channel, including AUTH;
- subscription acknowledgements: `subscribe`, `unsubscribe`, `psubscribe`, `punsubscribe`;
- messages: `message`, `pmessage`;
- PING: simple `PONG` and Pub/Sub `pong` arrays.

Validate the arity and scalar/null types of every routed array. An unknown or malformed frame is a terminal protocol failure, not a silently ignored packet.
Every result- or PING-channel push must succeed; a closed channel throws its
matching named foreground-channel exception through the common terminal path
rather than allowing another read from the closed stream.

### Message-channel capacity

Delete the per-message `Timer::after()` allocation.

Use:

```php
if ($this->messageChannel->push($message, 30.0)) {
    return;
}

if (! $this->messageChannel->isTimeout()) {
    throw new SocketException('The Redis subscriber message channel was closed.');
}

$exception = new SocketException(...);

try {
    $this->logger?->error(...);
} catch (Throwable) {
    // Reporting does not replace the channel-capacity failure.
}

throw $exception;
```

A concurrent channel close is a named terminal failure without a false “30 seconds full” log, a second read from the closed transport, or recursive interruption. Preserve worker-shutdown interruption.

### Subscriber accounting

`Subscriber` tracks exact prefixed channel and pattern sets. It must:

- reject empty `subscribe()` / `psubscribe()` before sending;
- wait for one acknowledgement per explicit channel/pattern;
- for zero-argument `unsubscribe()` / `punsubscribe()`, wait for `max(1, count(current category set))`;
- update sets from successful acknowledgements;
- leave channel and pattern accounting separate;
- preserve public `prefix`, which Reverb reads;
- keep `Message::$pattern` for pattern messages.

Do not add a generic subscription state machine or command registry.

### Direct Subscriber credentials

Preserve direct `Subscriber` construction while accepting the complete useful credential and endpoint shape:

```php
public function __construct(
    public string $host,
    public int $port = 6379,
    public string|array|null $password = null,
    public float $timeout = 5.0,
    public string $prefix = '',
    public ?string $username = null,
    public ?string $scheme = null,
    public array $context = [],
    protected ?StdoutLoggerInterface $logger = null,
) {
    $this->connect();
}
```

Use the parameter order above. It preserves the existing common host, port, password, timeout, and prefix positions, keeps the added endpoint/ACL fields explicit, and leaves the optional logger last. The semantic requirements are:

- scalar password `"0"` is valid;
- username `"0"` is valid;
- an existing ACL credential array is forwarded without string casting;
- scalar username plus scalar password becomes two-argument AUTH;
- missing credentials do not send AUTH.

Migrate the reflection-created Subscriber fixture in `SubscriberTest` from `password = ''` to the constructor's canonical `null` absence value. Keep explicit empty-string credentials absent as well; the fixture change must not accidentally turn the old sentinel into an AUTH argument.

### Sentinel master resolution

Move shuffled node attempts and master lookup into the existing `RedisSentinelFactory`:

```php
/**
 * Resolve the current master address.
 *
 * @return array{0: string, 1: int}
 */
public function resolveMaster(array $config): array
```

The factory:

- validates each node as a non-empty string through `RedisConfig`, beside the equivalent Cluster seed validation;
- parses schemeless nodes with a temporary `tcp://` prefix but passes a bare host to phpredis so a configured TLS context can still select TLS;
- preserves an explicit node scheme because phpredis treats it as the native transport selector;
- requires bracketed IPv6 and rejects unbracketed forms that PHP can silently split into a different valid host and port;
- rejects credentials, paths, queries, and fragments instead of silently dropping unsupported endpoint components;
- applies a non-empty topology-local `sentinel.context` as phpredis's flat `ssl` option, accepting flat, `ssl`, and `stream` input shapes;
- tries every shuffled configured Sentinel node;
- preserves Sentinel auth including `"0"`;
- performs no per-node logging when a later node succeeds;
- collects node/cause details locally;
- throws one descriptive total-failure exception only after every node fails;
- returns the resolved host and integer port.

`PhpRedisConnection` and `RedisProxy::subscriber()` both call this owner. Do not add a logger callback, resolver interface, or registry.

### Standalone, Sentinel, and Cluster subscriber creation

Inject `RedisSentinelFactory` into `RedisManager` through `RedisServiceProvider`, then pass that container-resolved auto-singleton into every `RedisProxy` it creates. Do not expose the PoolFactory container, resolve from global container state, or make the dependency nullable merely to preserve old test constructors.

`RedisProxy::subscriber()`:

- reads the real connection config from its pool;
- standalone: creates one Subscriber from the normalized host, port, scheme, context, credentials, timeout, and prefix;
- Sentinel: resolves the current master afresh, then creates the Subscriber with the master's endpoint and connection credentials;
- Cluster: briefly borrows a wrapper, activates it, reads `masters()`, releases the exact wrapper before any subscriber dial, and tries masters until a dedicated Subscriber connects using only `cluster.context`, matching the pooled Cluster client;
- if every Cluster master fails, throws one failure retaining useful endpoint causes;
- never keeps the pool wrapper for the subscriber lifetime.

If master discovery and wrapper release both fail, keep the discovery failure primary while still exhausting release. This borrow never publishes coroutine context.

Do not add `SSUBSCRIBE` routing.

### Tests

Cover:

- simple PONG before subscription does not poison the next acknowledgement;
- array PONG after subscription;
- server error propagates to the waiting foreground command;
- receive failure closes every channel and preserves the first cause;
- acknowledgement timeout is bounded while idle receive remains unbounded;
- message-channel timeout logs once and interrupts;
- concurrent close becomes a named terminal failure without logging a capacity failure or retaining a native `TypeError`;
- command and PING after autonomous receive failure rethrow the exact retained
  cause without sending;
- command and PING after deliberate close throw the canonical closed-connection
  failure without sending;
- a foreground acknowledgement wait interrupted while blocked throws the
  acknowledgement-channel closed failure rather than a cleanup-induced stream
  error;
- closed result and PING routing channels terminate without a second receive;
- empty subscribe/psubscribe sends nothing;
- explicit and all-channel unsubscribe/punsubscribe acknowledgement counts;
- exact channel/pattern sets;
- string, array, username/password `"0"` auth;
- standalone TCP, TLS/context, IPv6, and Unix routing;
- Sentinel fresh resolution, node fallback, aggregated total failure, auth `"0"`, TLS/context, explicit TCP/TLS schemes, canonical bracketed IPv6, and rejected unsupported endpoint forms;
- Cluster master-list wrapper release and endpoint fallback;
- schemeless Cluster masters with non-empty `cluster.context` use TLS after the
  discovery wrapper is released, regardless of top-level or seed schemes;
- live Redis and Valkey binary Pub/Sub payloads;
- deterministic close and worker-shutdown interruption;
- spawn failure rollback remains correct.

## 5. Delete Reverb's subscriber-gated publish queue

### Files

- `src/reverb/src/Servers/Hypervel/Scaling/RedisPubSubProvider.php`
- `src/reverb/src/Protocols/Pusher/MetricsHandler.php`
- `tests/Reverb/Servers/Hypervel/Scaling/RedisPubSubProviderTest.php`
- `tests/Reverb/Protocols/Pusher/MetricsHandlerTest.php`

### Final behavior

Delete:

- `$queuedPublishes`;
- its docblock;
- `processQueuedPublishes()`;
- the connect-time drain call;
- `JsonException` import used only for queued payload handling;
- test-only queue exposure and queue assertions.

`publish()` becomes direct:

```php
public function publish(array $payload): int
{
    return (int) $this->redis->publish(
        $this->channel,
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}
```

Subscriber connection state does not gate publishing. JSON and Redis failures propagate.

`MetricsHandler::gatherMetricsFromSubscribers()` currently stores the pending metric and registers its listener before publishing the request. If publishing throws, remove that exact metric and listener before rethrowing. A cleanup failure must not replace the publish failure. Keep this local to the failed-publication branch; do not add a generic finalizer or change the successful response/timeout lifecycle.

Delete the obsolete “Decision 17” source comment. Rewrite the useful “Decision 16e” source and test comments as local WHY comments explaining that responses may arrive before `publish()` returns the subscriber count; do not retain references to an external decision log.

### Tests

Prove:

- publish succeeds when no subscriber exists;
- subscriber reconnect state does not retain outgoing payloads;
- encoding failure propagates;
- Redis outage propagates;
- the actual Redis publish count is returned;
- `MetricsHandler` does not turn a missing subscriber into an immediate false empty result;
- a publish failure removes the pending metric and listener;
- the publish failure remains primary when listener cleanup also fails.

No replacement queue, retry policy, disk spool, capacity key, or publisher state is added.

## 6. Preserve limiter callback failures over release failures

### Files

- `src/redis/src/Limiters/ConcurrencyLimiter.php`
- `src/redis/src/Limiters/ConcurrencyLimiterBuilder.php`
- `tests/Redis/ConcurrencyLimiterTest.php`
- `tests/Redis/ConcurrencyLimiterBuilderTest.php`

### Direct control flow

Keep the two existing public acquisition shapes. Do not add `runWithLease()`, a trait, an acquired flag, or builder delegation through a broad timeout catch.

At both callback sites:

```php
$callbackException = null;

try {
    $result = $callback();
} catch (Throwable $exception) {
    $callbackException = $exception;
}

try {
    $lease->release();
} catch (Throwable $exception) {
    if ($callbackException === null) {
        throw $exception;
    }
}

if ($callbackException !== null) {
    throw $callbackException;
}

return $result;
```

The final implementation may use a shorter direct `try/catch` shape if it:

- always attempts release;
- suppresses release failure only when callback failure already exists;
- propagates release failure after callback success;
- does not risk an uninitialized return value;
- keeps builder acquisition failure handling scoped only to `acquire()`.

A `LimiterTimeoutException` thrown by the user callback is not acquisition failure and must never call the builder's `$failure` callback.

### Tests

At both public sites, cover:

1. callback succeeds, release succeeds;
2. callback succeeds, release fails: release failure propagates;
3. callback fails, release succeeds: callback failure propagates;
4. callback fails, release fails: callback failure remains primary;
5. builder acquisition timeout invokes `$failure`;
6. callback-thrown `LimiterTimeoutException` bypasses `$failure`.

## 7. Port current supported PhpRedis validation and options

### Files

- `src/redis/src/RedisConnection.php`
- `src/redis/src/PhpRedisConnection.php`
- `src/redis/src/PhpRedisClusterConnection.php`
- `src/redis/src/RedisConfig.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/PhpRedisClusterConnectionTest.php`
- `tests/Redis/RedisConfigTest.php`
- `tests/Redis/Fixtures/PhpRedisConnectionStub.php`
- `tests/Redis/Fixtures/PhpRedisClusterConnectionStub.php`
- `tests/Integration/Redis/RedisConnectorTest.php`

### Host and context

Port current Laravel behavior:

- host must be a non-empty string;
- when host already includes a scheme and config also supplies one, schemes must match case-insensitively;
- when host has no scheme, prepend the configured scheme;
- non-empty standalone context accepts flat, `ssl`, or already-normalized `stream` input and sends `['stream' => ...]` to `Redis::connect()`;
- non-empty Cluster context accepts flat, `ssl`, or `stream` input and sends a flat context to `RedisCluster::__construct()`;
- an empty standalone or Cluster context omits the native constructor argument entirely.

The non-empty guards are a deliberate Hypervel adaptation. Current Laravel checks only for null because its shipped config omits the context key. Hypervel merges structural empty context defaults, and its documented examples also permit explicit empty arrays. Normalizing an empty standalone context manufactures `['stream' => []]`, which phpredis interprets as a TLS request against an otherwise plain endpoint; an empty Cluster context similarly enables TLS on every seed and discovered node. Keep Laravel's normalizer bodies unchanged and adapt only the native argument guards.

Do not add persistent connections. Pool owns socket lifetime.

### Topology list validation

`RedisConfig` must require every configured Cluster seed and Sentinel node to be a non-empty string. Structural config errors fail during validation; the Sentinel factory aggregates only well-formed endpoint strings that cannot be parsed, reached, or resolved. Do not repeat the element-type guard in the factory.

### Credentials

Use exact absence checks:

- password `null` or `''`: no AUTH;
- password `"0"`: AUTH;
- username present and not `''`, including `"0"`, plus string password: ACL pair;
- existing array password: pass through;
- Sentinel auth `"0"`: pass through;
- Cluster username `"0"`: preserve.

### Options and precedence

Final supported behavior:

- numeric native option constants remain accepted;
- shared `database.redis.options` apply first;
- connection-local nested `options` override shared options;
- a top-level connection `prefix` overrides both for that connection, matching current Laravel;
- standalone supports prefix, read timeout, scan, serializer, compression, compression level, TCP keepalive, max retries, backoff algorithm/base/cap, and conditional pack-ignore-numbers;
- standalone `name` sends `CLIENT SETNAME` after connection/auth/database setup;
- Cluster supports prefix, scan, serializer, compression, compression level, TCP keepalive, max retries, backoff settings, and `RedisCluster::OPT_SLAVE_FAILOVER`;
- Cluster does not send `CLIENT SETNAME`;
- `pack_ignore_numbers` is standalone-only and remains guarded by `defined()` because the supported 6.1 floor predates the 6.2 constant;
- delete the Hyperf-derived string key `keepalive`; only `tcp_keepalive` remains;
- delete dead `defined()` guards for serializer and compression;
- use `RedisCluster::OPT_SLAVE_FAILOVER`, never a literal fallback.

Do not add a connector hierarchy or Predis.

### Tests

Reopen every current Laravel test found through the discovery commits and port the supported final cases. Merge them into existing Hypervel tests while retaining pool, transform, SafeScan, and strict-type coverage.

Test:

- empty host;
- matching and mismatched schemes;
- standalone and Cluster flat, `ssl`, and `stream` context normalization;
- explicit empty context uses plaintext while one non-empty context reaches TLS at each native constructor boundary;
- Sentinel-resolved masters retain the standalone empty-context rule;
- Cluster seeds and Sentinel nodes reject non-string and empty values during config validation;
- every accepted string option and numeric option;
- shared/local/top-level prefix collisions;
- standalone SETNAME and absence on Cluster;
- all username/password `"0"` paths;
- Sentinel auth `"0"`;
- TCP keepalive spelling and removal of `keepalive`;
- conditional 6.1 pack-ignore behavior;
- Cluster failover constant.

## 8. Add exact-wrapper macros without changing event ownership

### Files

- `src/redis/src/RedisConnection.php`
- `src/redis/src/RedisProxy.php`
- `src/redis/composer.json`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/RedisEventsTest.php`
- `tests/Redis/PackageMetadataTest.php`

### RedisConnection

Add:

```php
use Hypervel\Support\Traits\Macroable;

use Macroable {
    __call as macroCall;
}
```

Use the repository's actual Macroable namespace.

Use the final `__call()` order in Section 2. Macro-first order is current Laravel behavior; wrapper-native normalization keeps WATCH/EXEC/UNWATCH bookkeeping correct and prevents mixed-case RESET or subscription calls from bypassing their pooled guards. The matching proxy normalization keeps ownership and dedicated-subscription routing correct before wrapper dispatch.

On a held wrapper, a macro may deliberately shadow the native `reset`, `subscribe`, `psubscribe`, or `ssubscribe` guards; do not add a macro-name prohibition. `RedisProxy` continues to intercept its public dedicated `subscribe()` and `psubscribe()` methods before wrapper dispatch.

### Registration without Redis availability

Add explicit methods on `RedisProxy` that forward the static Macroable surface without entering `__call()` or checking out Redis:

```php
public function macro(string $name, callable|object $macro): void
{
    RedisConnection::macro($name, $macro);
}

public function mixin(object $mixin, bool $replace = true): void
{
    RedisConnection::mixin($mixin, $replace);
}

public function hasMacro(string $name): bool
{
    return RedisConnection::hasMacro($name);
}

public function flushMacros(): void
{
    RedisConnection::flushMacros();
}
```

This keeps boot-time registration independent of Redis availability and adds no proxy-side work to ordinary commands.

### Event granularity

`RedisProxy` remains the event owner:

- one macro call produces one outer event named for the macro;
- native commands called inside the macro do not emit separate proxy events;
- this matches existing Hypervel behavior for pipeline/transaction callbacks, held raw work, SafeScan, `flushByPattern()`, and SHA-cached helpers;
- moving events into `RedisConnection` would expose internal release `SELECT` and scan-loop traffic.

Do not add mutable “suppress events” state or inner-event replay.

### Static cleanup and metadata

Add `hypervel/macroable` to the split Redis package's direct requirements. Add `RedisConnection::flushMacros()` to the authoritative `AfterEachTestSubscriber` in its alphabetical framework-cleanup position.

Add exact facade annotations for `macro`, `mixin`, `hasMacro`, and `flushMacros`. Their signatures follow the explicit `RedisProxy` methods rather than relying on magic forwarding.

### Tests

Cover:

- registration, mixin, lookup, invocation, and flush;
- every explicit proxy macro method works while Redis is unavailable and performs no pool checkout;
- macro executes on the exact checked-out wrapper;
- mixed-case `multi`, `pipeline`, `select`, and `watch` pin the exact wrapper;
- mixed-case `discard`, `subscribe`, and `psubscribe` take their dedicated proxy routes;
- mixed-case connection-bound methods remain rejected without a checkout;
- mixed-case `exec` and `unwatch` settle wrapper state, while held-wrapper RESET, SUBSCRIBE, PSUBSCRIBE, and SSUBSCRIBE prohibitions remain enforced;
- one outer success/failure event uses the macro name;
- direct native `RedisException` inside a macro uses the settled disposition rule;
- event, command, and cleanup failure precedence remains truthful;
- macro shadowing follows macro-first order;
- static cleanup prevents test leakage;
- no raw/lazy return wrapper machinery is added.

## 9. Add boot-only event controls and remove the false extension API

### Files

- `src/redis/src/RedisConfig.php`
- `src/redis/src/RedisManager.php`
- `src/redis/src/RedisProxy.php`
- `src/telescope/src/Watchers/RedisWatcher.php`
- `src/sentry/src/Features/RedisFeature.php`
- `src/support/src/Facades/Redis.php`
- `tests/Redis/RedisConfigTest.php`
- `tests/Redis/RedisManagerTest.php`
- `tests/Redis/RedisServiceProviderTest.php`
- `tests/Telescope/Watchers/RedisWatcherTest.php`
- `tests/Telescope/Watchers/DisabledWatcherTest.php`
- `tests/Sentry/Features/RedisIntegrationTest.php`

### Tri-state owner

Add one nullable override to `RedisConfig`:

```php
private ?bool $eventsOverride = null;
```

Apply it during `connectionConfig()` assembly:

```php
if ($this->eventsOverride !== null) {
    $connectionConfig['event']['enable'] = $this->eventsOverride;
}
```

Add exact boot-only methods to `RedisConfig`:

```php
public function enableEvents(): void
{
    $this->eventsOverride = true;
}

public function disableEvents(): void
{
    $this->eventsOverride = false;
}
```

Their docblocks warn that the worker-owned override affects every subsequently assembled connection config. Do not add a setter or a method to restore `null`; no supported requirement needs that extra state transition. `null` is the initial state that preserves explicit per-connection config.

`RedisManager::enableEvents()` and `disableEvents()` delegate to this owner. Their docblocks must say:

- Boot-only.
- Existing pools snapshot their config and are not retrofitted.
- Calling the method after pool creation can leave generations with different event behavior.

Add the two manager methods to the Redis facade annotations.

Do not mutate the user's config tree, enumerate current connection names, flush pools, touch checked-out wrappers, or add a per-command config read.

### Telescope and Sentry

Replace both config mutation loops with the manager API during boot. Keep their listener registration unchanged.

Sentry's Redis parameter handling is not the Telescope formatter and does not implicitly convert objects; revalidate it but do not copy the Telescope fix into Sentry.

Tests cover both `RedisConfig` methods, both manager delegations, the two facade annotations, null preservation, future-pool override behavior, and the fact that an already-created pool is not retrofitted.

### Delete incompatible extension state

Delete from `RedisManager`:

- `$customCreators`;
- named-proxy branch in `connection()`;
- `extend()`;
- `forgetExtension()`;
- related imports, facade annotation, and tests.

Rewrite manager release/discard/cache tests around real `RedisProxy` instances and a stubbed `PoolFactory`. Do not delete lifecycle coverage merely because its old injection seam disappears.

### Intentional Laravel omission

Current Laravel connector-driver `extend()` and `setDriver()` are intentionally not added. Hypervel has one phpredis pooled transport and no driver switch. Adding a connector/pool hierarchy for hypothetical clients would be overengineering.

Macros provide conventional connection behavior extension. `withConnection(transform: false)` provides explicit raw held access. Do not claim that container binding replaces the removed named-proxy API.

Record this closed omission at the exact README, source, and test positions specified in Section 15.

## 10. Make Horizon use Hypervel's complete named topology

### Files

- `src/horizon/src/Horizon.php`
- `tests/Integration/Horizon/Feature/RedisPrefixTest.php`

### Final behavior

Delete the dead `database.redis.clusters.<name>.0` branch. Read only the configured named Hypervel connection:

```php
$config = config("database.redis.{$connection}");

if (! is_array($config)) {
    throw new Exception("Redis connection [{$connection}] has not been configured.");
}
```

Resolve the prefix:

```php
$prefix = config('horizon.prefix') ?: 'horizon:';

if (($config['cluster']['enable'] ?? false)
    && ! RedisConnection::hasHashTag($prefix)) {
    $prefix = '{' . $prefix . '}';
}

$config['prefix'] = $prefix;
$config['options']['prefix'] = $prefix;

config([
    'horizon.prefix' => $prefix,
    'database.redis.horizon' => $config,
]);
```

Use the exact existing hash-tag helper behavior and import. Preserve:

- nested `cluster`;
- nested `sentinel`;
- pool settings;
- credentials;
- timeout/context/options;
- every other named connection value.

Publish the resolved prefix at both connection precedence levels. `RedisConfig`
gives the top-level connection key final precedence, so leaving the source
connection's old top-level value intact would override Horizon's nested option.

Do not copy Laravel's top-level clusters model or its `supportsClustering()` version shim.

### Tests

Cover:

- missing connection;
- standalone complete config copy;
- Cluster complete config copy;
- untagged clustered prefix becomes tagged;
- already-tagged prefix remains unchanged;
- resolved prefix is identical in `horizon.prefix` and Redis connection options;
- source top-level prefixes cannot override the resolved standalone or Cluster prefix;
- nested topology/pool values remain intact;
- Horizon multi-key operations remain Cluster-slot safe.

## 11. Reject pooled sharded subscriptions and enforce facade metadata

### Files

- `src/redis/src/RedisConnection.php`
- `src/redis/src/RedisProxy.php`
- `src/support/src/Facades/Redis.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/PackageMetadataTest.php`
- `src/redis/README.md`
- `src/boost/docs/redis.md`

### Pooled command guard

Extend the existing dedicated-subscription rejection to:

- `subscribe`;
- `psubscribe`;
- `ssubscribe`.

Remove `ssubscribe` from RedisConnection and Redis facade annotations. Add it to the facade documenter's ignored methods so reflection cannot restore it.

Keep `unsubscribe`, `punsubscribe`, and `sunsubscribe` as current harmless native calls. Do not add ordinary or sharded Pub/Sub support on pooled wrappers.

The dedicated Subscriber continues to support only ordinary SUBSCRIBE/PSUBSCRIBE. Do not add a sharded-subscription TODO.

### One metadata owner

Reflect the private `RedisProxy::CONNECTION_BOUND_METHODS` in `PackageMetadataTest` and assert it is a subset of the facade's protected ignored list.

Extra facade ignores are valid. They include unsupported native methods such as `ssubscribe` that are not connection-bound commands.

Update `RedisProxyTest::testConnectionBoundMethodsCannotBeCalledThroughProxy()` to derive cases from the production constant instead of maintaining a third hardcoded list.

Add the currently missing internal methods:

- `clearWatchState`;
- `discardTransaction`.

Do not change production constant visibility merely for tests.

### Intentional-difference records

Record the pooled `ssubscribe` omission according to Section 15. Explain there that sharded Pub/Sub needs slot-routed dedicated connections and is not equivalent to ordinary Subscriber routing.

## 12. Delete dead Sentinel compatibility and duplicate config parsing

### Files

- `src/redis/src/RedisSentinelFactory.php`
- `src/redis/src/RedisConfig.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/RedisConfigTest.php`
- `tests/Redis/RedisSentinelFactoryTest.php` (new)
- `tests/Integration/Redis/RedisConnectionIntegrationTest.php`

### Sentinel floor

Delete:

- `$isOlderThan6`;
- its constructor;
- `version_compare()` branch;
- six-positional-argument Sentinel construction.

The declared supported phpredis floor is 6.1. Always use the current options-array `RedisSentinel` constructor.

Delete the matching `< 6.0` properties, setup checks, skips, incomplete branches, and alternate constructor-signature expectations from Redis unit and integration tests. Keep the current signatures covered directly; an unsupported extension version cannot execute the suite.

### Config normalization

`connectionNames()` must call `parseConnectionConfiguration()` before validation, just as `connectionConfig()` does. Do not retain a second direct `ConfigurationUrlParser` flow.

The shared parser remains responsible for:

- URL parsing;
- `driver` to `scheme` translation for TCP/TLS;
- removal of database-style `driver`.

Tests prove both entry points accept and reject the same normalized shapes.

## 13. Reject pooled RESET before native dispatch

### Files

- `src/redis/src/RedisConnection.php`
- `src/support/src/Facades/Redis.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisProxyTest.php`
- `tests/Redis/PackageMetadataTest.php`
- `tests/Integration/Redis/RedisResetIntegrationTest.php` (new)
- `src/redis/README.md`
- `src/boost/docs/redis.md`

### Command boundary

Macro dispatch remains first. For non-macro native dispatch, reject RESET at the start of `executeCommand()`, before:

- `shouldTransform === false`;
- queue-mode preparation;
- native method lookup;
- WATCH settlement;
- any native call.

Use a direct inline rejection, not the subscription message or a one-use helper:

```php
if ($name === 'reset') {
    throw new BadMethodCallException(
        'Cannot call reset() on a pooled Redis connection because it clears '
        . 'the authentication and selected database owned by the pool. '
        . 'Use Redis::discard() for MULTI, Redis::unwatch() for WATCH, '
        . 'and exec() to complete a PIPELINE.'
    );
}
```

Place the concise `REMOVED:` marker at this natural rejection point. The actual message must be technically exact and must not claim every RESET effect has a scoped Hypervel API.

Do not:

- invoke native RESET;
- mark invalid;
- reconnect;
- clear tracked WATCH state;
- add a pipeline-only special case.

Remove RESET from the successful WATCH-terminal list. Remove RESET annotations from RedisConnection and Redis facade. Add RESET to facade-documenter ignores.

### Deliberate escape hatches

Macro-first dispatch means a macro named `reset` shadows the prohibition. Callback-less `Redis::pipeline()` returns native `\Redis`, so explicit `$pipeline->reset()` can still reach the phpredis fatal. These are deliberate raw/native escapes.

Do not add:

- a wrapper around callback-less native pipeline;
- a macro-name prohibition;
- a raw-client proxy;
- a native-state monitor.

### Separate-process regression

Add `tests/Integration/Redis/RedisResetIntegrationTest.php` using:

- `InteractsWithRedis`;
- method-level `#[RunInSeparateProcess]` on the native-fatal regression;
- `protected bool $runTestsInCoroutine = false;`.

Prove:

1. public `Redis::pipeline()` enters native pipeline mode;
2. the next `Redis::reset()` throws the framework `BadMethodCallException`;
3. PHPUnit reports normally rather than losing a ParaTest worker;
4. the native fatal boundary is never reached.

Unit tests prove a direct held-wrapper call:

- does not touch the native client;
- does not clear tracked WATCH state;
- throws with the approved guidance.

### Intentional-difference records

Record the omission according to Section 15. State only:

- native RESET can terminate PHP from PIPELINE mode;
- successful RESET destroys auth/database state owned by the pool;
- therefore pooled RESET is unsupported.

## 14. Make Redis observability truthful and non-throwing

### Files

- `src/telescope/src/Watchers/RedisWatcher.php`
- `tests/Telescope/Watchers/RedisWatcherTest.php`
- `src/sentry/src/Features/RedisFeature.php`
- `tests/Sentry/Features/RedisIntegrationTest.php`

### Formatter

Use a private recursive formatter because recursion is genuine reuse and benefits from a clear name:

```php
private function formatParameter(mixed $parameter): string
{
    if (is_array($parameter)) {
        $values = [];

        foreach ($parameter as $key => $value) {
            $formatted = $this->formatParameter($value);
            $values[] = is_int($key) ? $formatted : "{$key} {$formatted}";
        }

        return implode(' ', $values);
    }

    if ($parameter === null || is_scalar($parameter)) {
        return (string) $parameter;
    }

    return get_debug_type($parameter);
}
```

Use this for top-level parameters while preserving current command/scalar/keyed-array output.

Nested arrays deliberately change from JSON fragments such as `["a","b"]` to recursively formatted space-delimited values such as `a b`. Add an explicit output expectation for this visible result of removing `json_encode()` so the new assertion cannot be mistaken for weakened coverage.

Do not call:

- `json_encode()`;
- `serialize()`;
- `__toString()`;
- `JsonSerializable::jsonSerialize()`;
- a dumper;
- user callbacks.

Do not catch around event dispatch. Do not add recursion-depth or self-reference tracking for unsupported, unrealistic self-referential Redis parameters.

### Batch-opener filtering

Hypervel's `transaction()` opens native MULTI through `RedisProxy::__call('multi', [])`; it never emits an event named `transaction`. Both `multi` and `pipeline` are only outer opener events because their queued native commands and terminal EXEC run on the held native client. Recording one opener while hiding the other is misleading rather than useful command observability.

Delete the dead `transaction` entry and make the existing filter strict and case-insensitive:

```php
private function shouldIgnore(CommandExecuted $event): bool
{
    return in_array(strtolower($event->command), ['pipeline', 'multi'], true);
}
```

The concrete parameter type matches the only caller. Do not add a configurable ignore list or normalize command names in the event object itself.

### Tests

Cover:

- existing scalar formatting;
- numeric and string array keys;
- nested scalar arrays;
- object;
- Closure;
- stream resource;
- a `JsonSerializable` object whose `jsonSerialize()` throws if invoked;
- nested throwing `JsonSerializable`;
- successful Redis result remains successful while Telescope records the event;
- lowercase and mixed-case `pipeline` and `multi` events are ignored;
- an ordinary mixed-case command remains recorded with its original event name.

### Sentry database metadata

Sentry's parameter handling and SDK handoff are distinct from Telescope's formatter and remain unchanged. Correct both Redis span paths to use the canonical normalized connection key:

```php
'db.redis.database_index' => (int) ($config['database'] ?? 0),
```

The existing test fixture writes `db`, a shape no real Hypervel Redis connection uses, and therefore masks the defect. Resolve config through `$this->app->make('config')`, change the fixture key to `database`, retain the non-zero success-span assertion, and add the same non-zero assertion for a failed-command span. Do not add a fallback for the obsolete test-only key.

## 15. Complete metadata, provenance, and user documentation

### Files

- `src/redis/composer.json`
- `src/redis/README.md`
- `src/boost/docs/redis.md`
- `src/support/src/Facades/Redis.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `tests/Redis/PackageMetadataTest.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/RedisManagerTest.php`

### Split metadata

Add direct `hypervel/macroable` to `src/redis/composer.json`.

Retain:

- root `ext-redis: ^6.1`;
- split-package phpredis 6.1 floor advertisement;
- `hypervel/engine`, because `CommandInvoker` still directly uses Engine channels and coroutine primitives after the socket transport changes;
- every other direct dependency still imported at runtime after the final source edits.

After all source edits, recalculate every Redis split dependency from imports. Remove only dependencies with no remaining direct runtime use. Validate the split manifest.

### Package README

Add:

```md
Ported from: https://github.com/hyperf/hyperf/tree/master/src/redis
```

Expand `Differences From Laravel` concisely:

- Hypervel uses phpredis-only pooled connections and connection-local nested topology;
- automatic framework command replay is not performed;
- macros are observed as one proxy operation rather than inner native events;
- connector-driver `extend()` / `setDriver()` are omitted; use macros or held raw access;
- raw pooled `subscribe`, `psubscribe`, `ssubscribe`, and `reset` are not available, with the supported alternatives;
- existing Hypervel limiter differences remain.

Do not turn the README into an architecture document.

### Boost Redis guide

Match the existing task-first layout and language. Update:

- supported connection keys: prefix, scan, name, tcp_keepalive, serializer, compression, compression_level, pack_ignore_numbers where supported, and context shapes;
- username/password `"0"` behavior only if useful to explain ACL configuration;
- native retry/backoff remains phpredis behavior; remove framework command replay;
- Subscriber standalone/TLS/Unix/Sentinel/Cluster routing and exact binary payload behavior;
- Sentinel TLS through node schemes and topology-local context, including one canonical bracketed IPv6 example;
- subscribe/unsubscribe/PING expectations only where users need them;
- macros with one-operation event granularity;
- boot-only `enableEvents()` / `disableEvents()`;
- unsupported pooled RESET and sharded subscription, with alternatives;
- Hypervel connection-local Cluster/Sentinel shape.

Keep internal parser, channel, pool, defer, watcher, and failure-precedence mechanics out of user docs.

### Source and test markers

For every intentional Laravel omission, use all three repository records:

1. README difference;
2. concise `REMOVED:` source marker at the natural insertion/rejection point;
3. concise `REMOVED:` test marker where the omission is verified.

The closed omissions are:

- connector-driver `extend()` / `setDriver()`;
- pooled `reset()`;
- pooled/dedicated `ssubscribe()` support.

Current Laravel has manager extension coverage in `examples/laravel/framework/tests/Redis/RedisManagerExtensionTest.php`; place the connector-driver marker at the corresponding manager-extension position in `tests/Redis/RedisManagerTest.php`. Current Laravel has no behavioral RESET or `ssubscribe()` test to mirror, so place those markers beside the advertised-method assertions in `tests/Redis/PackageMetadataTest.php` rather than inventing an upstream test location.

Ordinary Hypervel adaptations do not receive divergence comments.

## 16. Remove every superseded path

The numbered implementation sections identify every source branch, property, helper, annotation, test assumption, dependency, and documentation claim to delete. Search the whole repository for each of those exact paths after implementation; remaining hits must be deliberate `REMOVED:` records or negative regression assertions.

Do not preserve replaced behavior through compatibility aliases, deprecated wrappers, stale comments, speculative TODOs, or parallel fallback mechanisms.

## 17. Validation cadence

The detailed implementation sections define the complete regression matrix. Run each changed or new test file immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Redis/RedisConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisProxyTest.php
./vendor/bin/phpunit --no-progress tests/Redis/MultiExecTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisPoolHeartbeatTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisProxyNonCoroutineTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisManagerTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisServiceProviderTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisSentinelFactoryTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisConfigTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisEventsTest.php
./vendor/bin/phpunit --no-progress tests/Redis/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Redis/PhpRedisClusterConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Redis/ConcurrencyLimiterTest.php
./vendor/bin/phpunit --no-progress tests/Redis/ConcurrencyLimiterBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Redis/Subscriber/ConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Redis/Subscriber/CommandBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Redis/Subscriber/CommandInvokerTest.php
./vendor/bin/phpunit --no-progress tests/Redis/Subscriber/CommandInvokerCreateFailureTest.php
./vendor/bin/phpunit --no-progress tests/Redis/Subscriber/SubscriberTest.php
./vendor/bin/phpunit --no-progress tests/Reverb/Servers/Hypervel/Scaling/RedisPubSubProviderTest.php
./vendor/bin/phpunit --no-progress tests/Reverb/Protocols/Pusher/MetricsHandlerTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Horizon/Feature/RedisPrefixTest.php
./vendor/bin/phpunit --no-progress tests/Telescope/Watchers/RedisWatcherTest.php
./vendor/bin/phpunit --no-progress tests/Telescope/Watchers/DisabledWatcherTest.php
./vendor/bin/phpunit --no-progress tests/Sentry/Features/RedisIntegrationTest.php
```

Run new or changed integration files immediately with local Redis enabled:

```bash
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisConnectionIntegrationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisConnectorTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisProxyIntegrationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisProxyNonCoroutineIntegrationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisSubscribeIntegrationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisResetIntegrationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/Subscriber/SubscriberIntegrationTest.php
```

Use the configured local `.env` Redis service and the assigned per-worker database from `InteractsWithRedis`. Do not hardcode database numbers.

### Focused groups

After file-level tests:

```bash
./vendor/bin/phpunit --no-progress tests/Redis
./vendor/bin/phpunit --no-progress tests/Reverb/Servers/Hypervel/Scaling tests/Reverb/Protocols/Pusher/MetricsHandlerTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis
```

The existing `.github/workflows/redis.yml` already runs `tests/Integration/Redis` against Redis 8 and Valkey 9. No workflow or env-file change is needed unless implementation introduces a genuinely new external service, which this plan does not.

### Metadata and stale-path checks

Run:

```bash
composer validate --strict src/redis/composer.json
git diff --check
```

Use repository-wide searches for:

- `retry(` in Redis command dispatch;
- `queuedPublishes`;
- `processQueuedPublishes`;
- `Constants::EOF`;
- `SocketFactoryInterface` under Redis Subscriber;
- Redis Manager `customCreators`, `extend`, and `forgetExtension`;
- config mutation loops for `event.enable`;
- Horizon `database.redis.clusters`;
- Sentinel `isOlderThan6`;
- unsupported `< 6.0` phpredis branches under Redis tests;
- string option `keepalive`;
- advertised `reset` / `ssubscribe`;
- Telescope Redis `json_encode`;
- Telescope Redis dead `transaction` ignore;
- stale replay documentation.

Inspect every remaining hit.

After source, tests, metadata, and documentation are green, update `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md` with `redis-09` through `redis-20`, `reverb-05`, `horizon-01`, `telescope-01`, `telescope-02`, and `sentry-01`, including the final decisions and evidence. Update the Redis routing and checklist state in `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md` in the same pass. Do not mark either record complete before the implementation, full validation, self-review, and code review are complete.

### Full gate

After all focused tests and self-contained checks are green:

```bash
composer fix
```

`composer fix` is authoritative. It runs formatting, both PHPStan configurations, the complete parallel suite, the Testbench package suite, and Testbench dogfood. Do not waste time by running fixer and PHPStan separately immediately before it. Do not replace it with package-only tests.

Inspect every failure and skip normally. Fix source defects at their real owner; never weaken tests or add PHPStan-driven runtime branches.

## 18. Fresh post-implementation self-review

After `composer fix` passes, review the complete diff without trusting this plan or the implementation discussion:

1. Re-read every changed source file in full or consecutive chunks.
2. Trace pooled ownership and failure precedence end to end: checkout, macro/native dispatch, events, stateful publication, callback release, terminal defer, task/fork cleanup, release/discard, and close. Prove no uncertain command is replayed or uncertain generation requeued.
3. Trace subscriber construction and operation end to end: topology and credentials, stream lifetime, exact RESP parsing, command routing, channel/pattern accounting, timeouts, retained causes, and deterministic close.
4. Trace the changed cross-package consumers: Reverb publish/metrics cleanup, both limiter paths, Horizon config/prefix publication, Telescope formatting/filtering, and Sentry database metadata.
5. Compare every accepted PhpRedis API, member position, test, facade annotation, and user-facing statement with current Laravel default source and documentation, then verify every approved Hypervel adaptation against the pooled architecture.
6. Recalculate split dependencies, metadata invariants, intentional-difference markers, and static test cleanup from the final code.
7. Search for every removed symbol, branch, stale comment, test assumption, and documentation claim.
8. Inspect ordinary commands and worker-retained state for new lookups, allocations, locks, context work, yields, retries, logs, network calls, reconnects, or unbounded growth.
9. Ask whether any local mechanism duplicates a lower owner, serves only PHPStan or a test seam, or solves no verified supported path; simplify it.
10. Compare README and Boost wording with nearby Hypervel docs for accurate, concise, task-first language.

Fix straightforward omissions and rerun proportionate tests. Any newly discovered design issue goes through the focused second-opinion workflow before implementation continues.

Then request independent code review of source, tests, docs, metadata, ledger changes, validation, API parity, event behavior, subscriber fidelity, hot paths, and overengineering. Continue until sign-off.

## 19. Performance and retained-state assessment

### Successful hot-path effects

| Change | Frequency | Effect |
|---|---|---|
| Macro lookup | Every Redis wrapper command | One static array lookup; owner-approved noise-level current-Laravel capability |
| Proxy and wrapper method normalization | Every ordinary proxy-dispatched native command | Two lowercase operations on the short method name, one for proxy routing and one for wrapper state/guards; proxy-handled routes perform only the former and direct held-wrapper commands only the latter |
| Pooled `reset` / subscription guard | Wrapper dispatch name matching | A few fixed string comparisons folded into the existing prohibited-command boundary; measurement noise |
| Existing queue/watch release validation | Every pooled release | Unchanged local phpredis mode read and wrapper boolean from `redis-04`/`redis-07` |
| Existing deferred owner check | Stateful publication only | Unchanged owner-ID comparison from `redis-03` |

No other new work belongs on a successful ordinary command:

- no config read;
- no container resolution;
- no lock;
- no additional context operation;
- no hash or serialization;
- no allocation family;
- no yield or sleep;
- no retry;
- no log;
- no network command;
- no immediate reconnect.

### Cold and failure paths

| Change | Frequency | Effect |
|---|---|---|
| Failure disposition | `RedisException` only | One local last-error comparison; Sentinel errors add exact code matching |
| PhpRedis option normalization | Native connection creation | Array normalization and option application only |
| Subscriber RESP parser | Dedicated subscriber traffic | PHP parsing remains, now exact; removes line-buffer corruption and one timer coroutine per message |
| Subscriber topology | Subscriber construction | Sentinel/Cluster endpoint discovery only |
| Subscriber context-implied TLS | Subscriber construction | Two local context/scheme defaults while building the dedicated stream endpoint |
| Reverb | Publish calls | Removes an unbounded retained queue; uses the pooled publish that already existed |
| Limiter cleanup | Callback completion/failure | Direct local exception handling; same Redis release command |
| Event override | Worker boot/pool construction | One nullable property read during config assembly |
| Horizon | Boot configuration | One cluster check and optional hash-tag normalization |
| Telescope | Observed Redis command only | One lowercase operation before two fixed strict comparisons, plus bounded recursive traversal of the already-supplied parameter tree; no user code |

### Retained worker memory

- Existing Redis proxy and pool caches remain bounded by configured connection names.
- Existing owner-ID deferred cleanup remains one integer context slot and one closure per pool/coroutine that uses stateful commands.
- Macro registry is worker-static by Laravel design and is cleared authoritatively between tests.
- RedisConfig stores one nullable boolean override.
- Subscriber tracks only active channel and pattern names for that dedicated connection.
- CommandInvoker retains at most the first receive throwable until close.
- Reverb's unbounded queued payload list is removed.
- No error registry, connector registry, protocol state machine, live-pool registry, retry queue, or self-reference set is added.

## 20. Completion criteria

This work unit is complete only when:

- every finding in the summary is implemented at its named owner while the carried Redis ownership baseline and protected Hypervel features remain intact;
- every detailed source, regression, integration, metadata, documentation, removal, and intentional-difference requirement is complete;
- no stale path, misleading claim, compatibility shim, speculative mechanism, unbounded retained state, or meaningful ordinary-command regression remains;
- the focused cadence, external Redis/Valkey coverage, metadata checks, stale-path searches, `git diff --check`, and `composer fix` pass;
- fresh self-review and independent code review sign off on correctness, Laravel API parity, coroutine ownership, failure precedence, performance, retained memory, and overengineering;
- the audit ledger and routing checklist are updated from the validated implementation, followed by the owner summary and commit approval required by the main audit workflow.
