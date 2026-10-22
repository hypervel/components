# Hypervel Transactional Queue Outbox

## Status and outcome

Implement an optional transactional outbox in `hypervel/queue`. A job explicitly or conventionally routed through the outbox is serialized once, stored with the business write on the authoritative database transaction, and relayed to its existing queue connection after commit. This closes the database-commit / broker-publish crash window without replacing Queue, Bus, Horizon, queue drivers, or application job APIs.

The completed feature must provide:

- Laravel-style `viaOutbox()` / `withoutOutbox()` ergonomics plus a `ShouldQueueViaOutbox` marker and per-connection default;
- correct behavior when applications switch among Redis, SQS, Beanstalkd, database, sync, background, deferred, null, fake, and pooled connections;
- set-based outbox inserts for `Bus::bulk()` / batch work, using only the chunks required by documented database binding limits;
- horizontally scalable, lease-based relay workers with database-engine-specific claim paths;
- a staging/relay pipeline that preserves the original payload UUID, delay, SQS FIFO options, SQS overflow behavior, normal dispatch lifecycle, and Horizon metadata/events;
- an explicit at-least-once guarantee, including safe treatment of ambiguous transport outcomes;
- no outbox database work, service resolution, payload re-encoding, or additional outbox event allocation when the feature is not selected;
- production operations for monitoring, retrying, and deliberately forgetting pending rows;
- no dependency on Horizon and no third-party dependency.

The result is a queue feature, not a generic event bus, inbox/deduplication system, workflow engine, CDC pipeline, or completed-message archive. Those concerns must not be smuggled into the implementation through aggregate metadata, status histories, a second dead-letter table, container swapping, or transport-specific application APIs.

## Verified baseline

### Hypervel

- `Queue::enqueueUsing()` is the common single-job seam. `enqueueNow()` guards payload finalization, `JobQueueing`, and the driver callback as one attempt; accepts dispatch locks only after storage/transport acceptance; and raises `JobQueued` afterwards. Non-cancellation attempt failures raise `JobQueueingFailed`, while `PendingDispatch` owns final cleanup before a queue accepts the job.
- `DispatchLockContext` is the existing object-keyed `WeakMap` for dispatch-owned unique/debounce lock provenance. It carries the live cache repository needed for exact cleanup only while the job object remains live, exposes Laravel-compatible scalar unique metadata to payload hooks, and is cleared on acceptance/release. `Queueable::$uniqueLockOwner` is initialized to Laravel's empty-string default and owner-capable locks release through `UniqueLock::releaseOwned()`.
- Database, Redis, and SQS have optimized `bulk()` implementations; the base implementation loops through `push()` / `later()`. `Bus::bulk()` groups jobs by queue connection and queue name. `Batch::add()` already calls queue `bulk()` inside the batch repository transaction. Database and Redis bulk paths already provide bounded, atomic storage/publication.
- `DatabaseTransactionsManager::callbackApplicableTransactions()` exposes coroutine-local transaction records. Its current base-name indexing conflates distinct role connections; complete the exact-owner prerequisite below before using these records to select outbox storage. Matching host/database configuration never proves atomicity.
- `PdoConnection::lockForPopping()` and `maxBindings()` own live database capabilities and cache them only for the current physical session. MySQL/MariaDB/Vitess/PlanetScale, PostgreSQL, and SQLite supply their correct version-dependent values. `DatabaseQueue` already consumes both, and `Concerns\InsertsDatabaseRows` provides binding-safe, transactionally atomic chunked inserts.
- A database queue insert already participates atomically when its queue database connection is the same active connection as the business transaction. Adding a second outbox table in that case would add latency without improving the guarantee.
- `Queueable` already carries tri-state `afterCommit`, connection, queue, delay, chain, SQS message-group/deduplication, unique, and debounce state. `PendingDispatch`, `PendingBatch`, and `PendingChain` are the expected fluent surfaces. A valid batched job is only required to use `Batchable`, so batch-wide outbox state cannot assume that every job also uses `Queueable`.
- Final queue payloads already contain a stable job UUID. Hidden payload context carries the unique-job cache store, key, and owner; debounce ownership remains on the serialized job. Existing rollback cleanup depends on an unaccepted dispatch record; accepted transactional storage needs an independent release snapshot.
- `QueuePoolProxy` reacquires a pooled queue for after-commit dispatch rather than retaining a borrowed network client across a transaction. Raw outbox publication must likewise borrow once per batch and release immediately.
- `ConnectionResolver::releaseIdleConnections()` can return idle sessions early, but preserves pinned and certain multi-server write sessions and does not cover all custom drivers or listener-owned resources. Keep separate short-lived relay phase coroutines for guaranteed cleanup; committing a transaction alone does not provide that guarantee.
- SQS bulk delivery already chunks at 10 entries and the request byte cap, handles explicit per-entry success/failure, preserves FIFO options, and retains overflow pointers for ambiguous SDK failures. Reuse those rules rather than building a parallel SQS implementation.
- `JobQueued::$id` already matches the Queue contract's `mixed` result, and raw Sync/Deferred/Background publication executes through each queue's intended timing behavior.
- Horizon already separates `JobPayload::prepareForQueueing()` from `markAsPushed()` while retaining `prepare()` as their composition. Raw retry preserves stored classification, and delayed publication stamps `pushedAt` at the actual push. Combined preparation currently encodes twice; remove that redundancy in the prerequisite below.
- Generic Worker lifecycle callbacks and deferred signal callbacks already run through short-lived coroutine contexts, and worker delays use fakeable `Sleep`. The outbox worker must follow the same resource-ownership pattern.
- Queue, Bus, and Horizon READMEs already follow the required order, and the application skeleton's queue configuration matches Foundation. Reuse the existing foundation instead of repeating completed cleanup.

### Reference findings

- Reference checkouts live under `/home/binaryfire/workspace/references/`. `laravel-outbox` demonstrates bulk storage and `SKIP LOCKED`, but its request-wide mutable collector and temporary replacement of container `events` / `queue` bindings are unsafe under concurrent Swoole coroutines. Its aggregate IDs, payload HMAC, status/history fields, completed-row retention, second dead-letter table, and processor-job bootstrap are unnecessary for a native queue outbox.
- Laravel framework (`laravel/framework`) and Horizon (`laravel/horizon`) provide the public queue/batch/chain naming baseline; Hypervel's optimized bulk and transaction behavior is authoritative where it differs.
- Current Laravel's `Queueable`, `UniqueLock`, and hidden unique-job context carry the cache lock owner. Reusing that upstream behavior is safer and simpler than inventing outbox-only ownership state.
- MassTransit and NServiceBus persist the finalized outgoing transport operation and relay it later. Both confirm that the broker-send / outbox-delete boundary remains at-least-once and receivers must be idempotent.
- Watermill Forwarder / SQL confirms the small two-part shape: a transactional SQL publisher plus a daemon that forwards the stored envelope; its SQL publisher accepts multiple messages in one insert.
- Gruelbox Transaction Outbox validates bounded batches, retry scheduling, and skip-locked claiming. Eventuate validates CDC as an alternative deployment architecture, but CDC/Kafka infrastructure is unjustified for a framework queue primitive.
- TickerQ does support batch additions; it is not evidence against bulk APIs. Its scheduler concerns do not belong in this feature.
- PostgreSQL and MySQL both explicitly document `SKIP LOCKED` as suitable for multiple consumers of queue-like tables. SQLite remains a correct serialized fallback.

Primary upstream reading:

- <https://www.postgresql.org/docs/17/sql-select.html>
- <https://www.postgresql.org/docs/current/protocol-message-formats.html>
- <https://dev.mysql.com/doc/refman/8.4/en/select.html>
- <https://dev.mysql.com/worklog/task/?id=1803>
- <https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/select>
- <https://www.sqlite.org/limits.html>
- <https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-message-timers.html>
- <https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html>
- <https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/quotas-messages.html>
- <https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_SendMessageBatch.html>
- <https://masstransit.io/documentation/patterns/transactional-outbox>
- <https://docs.particular.net/nservicebus/outbox/>
- <https://watermill.io/advanced/forwarder/>

## Framework changes

### Independently useful prerequisites

Implement these at their owning boundary, before outbox integration. If already completed when implementation starts, verify their contracts and reuse them rather than repeating the work.

1. **Exact transaction identity.** Use the resolved connection object's `getNameWithReadWriteType()` consistently for transaction begin/commit/rollback/reset, applicable-transaction checks and connection-bound callbacks. Do not change `getName()` or derive identity from the caller's requested suffix: shared in-memory SQLite aliases return one owner object. Update `ManagesTransactions`, `Connection::resetTransactionState()`, Foundation testing's wrapping-transaction lookup and `RequestContextSynchronizer`, Pennant's transaction-record matching, and other consumers comparing record names. Distinct base/`::write` sessions must not consume each other's records or callbacks. **Owner approval before implementation:** role-qualified transaction-record names change values observable by extensions compared with Laravel's base-name records. Explain the correction and required exact-owner comparison in database compatibility documentation; preserve public signatures. Plan review does not replace approval for this or any other Laravel API departure.
2. **Context-safe dispatch-lock cleanup.** Add the supported boot registration method `DispatchLockContext::captureReleaseContextUsing(?callable $callback): void`, normalized to closures; registration appends, `null` clears. Its callbacks return a release wrapper or null, capturing only the context they need. Internal `captureReleaseContext(): ?Closure` composes wrappers in registration order, first outermost. Call it in `UniqueLock::acquireForDispatch()` and `DebounceLock::acquireForDispatch()` **before acquiring anything**, then pass its result into their internal registration methods. A throwing capture callback leaves no acquired lock/token. Unique and debounce acquisition are mutually exclusive, so capture once per acquisition. Records and snapshots retain the composed wrapper; release runs both existing owner-aware cleanup operations inside it, preserving existing error handling. Wrappers invoke the operation once and restore their prior context in `finally`. Without registrations return null immediately; without a captured wrapper run existing release code without allocating a release closure. Keep all record/capture operations internal, document the registration method's boot lifetime and callback contract in PHPDoc, and clear callbacks in the existing `flushState()`/test-subscriber path. Do not add a contextual-store interface, wrapper traversal, full coroutine-context snapshot, or cache implementation changes.
3. **Transactional storage rollback.** Immediately after a successful database-queue/outbox insert and before accepting dispatch locks, register an independent `DispatchLockContext::snapshot()` on the exact storage connection if its `transactionLevel() > 0`. Rollback calls `releaseSnapshot()` even after acceptance erases the live record. Apply to applicable single/bulk paths; after chunk-insertion savepoints complete, attach to the remaining enclosing transaction that can still undo the rows. Failed inserts and already-durable no-transaction staging register nothing. Existing deferred ownership remains intact; never release broker-accepted locks because a business transaction rolls back. Complete identity and capture-context fixes first.
4. **SQS batch lifecycle.** Move payload finalization and `JobQueueing` into the guarded unaccepted-attempt lifecycle in `sendBatchedMessages()`. Finish producer state for affected unaccepted messages on non-cancellation failure; preserve already confirmed chunks and never turn a later `JobQueued` listener error into rejection of accepted work. Follow the established Redis/database batch and cancellation behavior.
5. **Horizon combined preparation.** Make `JobPayload::prepare()` perform one encoding operation for classification plus `pushedAt`, preserving `prepareForQueueing()`, `markAsPushed()`, stored classification on raw retries, and supported public/protected customization. Do not alter ordinary publication timing.

### Outbox integration

Keep the new mechanism under `Hypervel\Queue\Outbox`; use small integration points in existing packages:

- Bus/Foundation: add the marker, shared fluent state, pending-dispatch/batch methods, and wrapper-job propagation described below.
- Queue: add selection and payload/option hooks, outbox services and commands, config, and the migration generator. Stage through the existing guarded enqueue lifecycle and reuse `DispatchLockContext`, `PdoConnection` capabilities, and `InsertsDatabaseRows`.
- Queue drivers/proxies: implement the fast paths and raw-batch capability below; select/partition outbox work before SQS's direct FIFO-delay validation. Preserve direct validation, existing raw publication APIs, and direct overflow retention; isolate outbox overflow pointers by claim attempt.
- Horizon: classify staged payloads through the outbox-only hook and stamp them at relay publication through the existing split `JobPayload` lifecycle. The outbox path must not use Horizon's direct-push `CoroutineContext` handoff.
- OpenTelemetry: consume guarded outbox delivery events without adding work to ordinary dispatch.
- Foundation/application skeleton/docs: add matching outbox configuration and migrations, then add the canonical guide and concise links without revisiting completed baseline cleanup.
- Doctor remains unchanged: do not copy runtime selection rules into a second setup detector or add relay-heartbeat machinery. Explicit setup, migrations, native storage failures, and outbox commands provide the required operating surface.

## Decisions

### Ownership and guarantee

- Implement under `src/queue/src/Outbox`; the migration stub/command live with Queue, while the shipped application defaults live at their existing owner, `src/foundation/config/queue.php`. Horizon only adapts actual Redis publication. The feature works with Redis whether Horizon is installed or not.
- Outbox atomicity covers only writes on the selected database transaction. It cannot atomically cover another database, an email, filesystem/object-store write, HTTP call, or other external effect.
- Relay is at-least-once. If the target accepts a job and the relay crashes or cannot delete the row, the same job payload can be published again. Stable job UUIDs are retained; application handlers that cannot tolerate duplicates must be idempotent.
- Dispatch order is best-effort: concurrent claims, delays and retries can reorder publication. SQS FIFO preserves broker-arrival order within a message group, not the order application transactions staged their jobs. Do not add per-group sequencing machinery.
- An outbox is not an inbox. Do not claim exactly-once processing or add receiver deduplication to this work.
- Successful rows are deleted. Failed/ambiguous rows remain in the same table for retry. Do not retain completed rows, introduce statuses that duplicate nullable lease state, or add an automatic terminal dead-letter cutoff that can silently abandon a committed business action.

### Selection and precedence

Add the marker as an ordinary queued-job contract. Put the fluent state in a small `InteractsWithQueueOutbox` trait used by both `Queueable` and `Batchable`, because Laravel-compatible batches do not require `Queueable`:

```php
interface ShouldQueueViaOutbox extends ShouldQueue
{
}

// InteractsWithQueueOutbox; intentionally uninitialized until explicitly set.
public ?bool $viaOutbox;
public ?string $outboxDatabase;

public function viaOutbox(): static;
public function withoutOutbox(): static;
public function onOutboxDatabase(UnitEnum|string $connection): static;
```

Framework code reads explicit state with `$job->viaOutbox ?? null` and `$job->outboxDatabase ?? null`. PHP serialization omits uninitialized typed properties, so ordinary jobs gain no payload fields or bytes. `onOutboxDatabase()` requires a non-empty resolved enum/string and also selects outbox delivery; `withoutOutbox()` unsets any selected database. Keep this state in `InteractsWithQueueOutbox`, used by `Queueable` and `Batchable`, so Laravel-compatible batch jobs that use only `Batchable` can participate. Do not add chain-specific outbox properties or `all*` methods: follow-up chain dispatch happens after the current handler completes, outside the business transaction, and the current queued job already supplies durable retry if that dispatch fails. Explicit Laravel-shaped properties are easier for jobs, wrappers, serialization, and agents to understand than a framework-only associative state bag.

Selection precedence is:

1. explicit `withoutOutbox()` / `viaOutbox()` on the job;
2. `ShouldQueueViaOutbox`;
3. the target queue connection's `outbox` boolean;
4. direct existing queue behavior.

`afterCommit()` / `beforeCommit()` and outbox selection remain independent. They control when a direct queue publish occurs; an outbox-selected dispatch inserts its durable row immediately inside the owning transaction, and delivery necessarily follows commit. `withoutOutbox()` is the explicit opt-out when a marker or connection default would select the outbox. No combination throws merely because an application changed queue drivers or commit defaults.

Mirror the current-job fluent methods on `PendingDispatch`. Add `viaOutbox()`, `withoutOutbox()`, and `onOutboxDatabase()` to `PendingBatch`; these are aggregate defaults and never overwrite a job whose corresponding property is initialized. Persist only the exact `viaOutbox` / `outboxDatabase` option keys on a batch. `Batch::add()` applies them to every job it is about to pass to `bulk()`, including jobs added after initial dispatch. Do not add outbox methods to `PendingChain`, propagate outbox state to later chain members, or add a second `bulkViaOutbox()` API. Normal `Bus::bulk()` honors job/batch/connection selection and is the optimized path.

Examples:

```php
ChargeInvoice::dispatch($invoice)->viaOutbox();

Bus::batch($jobs)
    ->onConnection('redis')
    ->viaOutbox()
    ->dispatch();
```

The dispatch return value for a staged single job is the finalized payload's job UUID, also indexed on the outbox row for operator lookup. Existing direct drivers retain their existing return values; bulk remains `null`.

### Laravel-compatible surface

- Do not change signatures or ordinary semantics of `dispatch`, `push`, `later`, `bulk`, `afterCommit`, `beforeCommit`, batch, chain, Queue/Bus fakes, or the `Hypervel\Contracts\Queue\Queue` contract. `pushRaw()` remains a direct, non-staging escape hatch even when a connection default enables outbox; the relay uses the separate raw-batch SPI when available and `pushRaw()` as its generic fallback, avoiding recursion and preserving existing raw-payload behavior.
- Add fluent methods to the same traits/builders that own their neighboring Laravel APIs; do not add an Outbox facade, transaction wrapper, alternate dispatcher, or environment-specific call site.
- Propagate outbox selection and the selected database from queued listeners, mailables, notifications, and broadcasts into their existing queue wrapper jobs with one assignment each beside the independent `afterCommit` propagation. Copy initialized tri-state state exactly; otherwise promote `ShouldQueueViaOutbox` to wrapper `viaOutbox = true`. Do not add producer-specific outbox APIs.
- Keep application-visible names short and intention-revealing: `viaOutbox`, `withoutOutbox`, `onOutboxDatabase`, `ShouldQueueViaOutbox`, `queue:relay`, and the `queue:outbox` operator command family.
- Add protected enqueue/destination/options hooks on the base queue and an optional raw-batch contract for optimized durable drivers. Database/local/failover queues override the smallest cohesive hook for their fast path; null already bypasses enqueueing. Do not build a central driver-name/type switch. Custom drivers retain existing behavior while base-lifecycle drivers gain staging and may opt into optimized publication.
- Preserve Horizon's existing public `JobPayload::prepare($job)` method as the composition of its classification and actual-push operations. Use the narrower existing operations for outbox staging/publication without breaking the Laravel-compatible method.

### Driver behavior

| Target at dispatch time | Selected outbox behavior |
|---|---|
| Redis, SQS, Beanstalkd, custom durable driver using the base enqueue lifecycle | Stage on the owning SQL database, then relay |
| Database queue, no active business transaction | Push directly; the jobs table is already durable |
| Database queue on the sole or explicitly selected active owning connection/session | Push directly inside that transaction; already atomic |
| Database queue on another connection while a business transaction is active | Stage on the business database, then relay to the database queue |
| Sync, background, deferred | Preserve local semantics; execute after successful commit when one exists, otherwise normally; an explicit database selects that exact active transaction; document that no crash durability is possible |
| Null | Preserve the no-op behavior and release only this dispatch's owner-token-matched unique/debounce locks |
| Queue/Bus fake | Record the job in the fake only; never touch an outbox table |
| Failover | Preserve normal failover, but defer the whole attempt until applicable transactions commit when outbox is selected; document that this degradation cannot provide crash durability |

Failover and outbox solve different policies, and failover may cross heterogeneous drivers whose wire payload/options are created only in the selected child. Do not invent a multi-destination outbox envelope. Instead, selected outbox behavior degrades to failover's existing all-applicable-transactions after-commit path, then performs ordinary child attempts. `FailoverQueue` always invokes children inside a coroutine-scoped outbox-suppression context, so a child's own connection default cannot stage successfully and prevent later fallbacks from being tried. The failover connection's explicit/marker/config selection is authoritative; child outbox defaults still apply when those children are used directly. The base selection path consults this context only after it would otherwise select outbox, so ordinary unselected queue dispatch pays no context lookup.

Driver switching never causes an exception merely because the selected driver is local, failover, or already durable. There are no environment checks; behavior follows runtime capability. Local/failover drivers register against the explicitly selected active transaction when supplied and reject only a false claim of atomic ownership (an explicit owner while transactions exist only on other connections); without an explicit owner they preserve their existing transaction-selection rules.

A selected outbox keeps an SQS FIFO job staged until `deliver_at`, then publishes it without the forbidden per-message `DelaySeconds`. In `later()` and `bulk()`, select/partition outbox work before applying the existing FIFO checks to direct work. Capture `getQueueableOptions()` without a delay argument at staging; preserve its direct-call validation. No-transaction staging has the same delayed-publication behavior.

A custom durable driver participates when it routes `push()` / `later()` / `bulk()` through the base enqueue hooks. The relay uses the optional raw-batch publication contract below when available and otherwise publishes due rows sequentially through the existing `pushRaw()` contract. Existing custom queues that override the public enqueue methods and bypass the base lifecycle retain their existing direct behavior; intercepting arbitrary overrides would require a permanent decorator and hot-path overhead for every queue operation. Document this extension contract instead of adding that machinery.

Evaluate fake/local/failover and already-atomic database fast paths before resolving outbox services or touching the outbox table. These paths need no outbox migration merely because a job carries the marker; local/failover drivers consult active transactions for their documented after-commit fallback. `NullQueue` already bypasses enqueueing and `PendingDispatch` releases its unaccepted locks; add no null-driver hook.

### Database ownership

Configure each database source whose outbox table the relay drains; use configured connection names, not both a base name and its `::write` alias:

```php
'outbox' => [
    'databases' => [
        env('QUEUE_OUTBOX_DATABASE', env('DB_CONNECTION', 'sqlite')),
    ],
    'table' => env('QUEUE_OUTBOX_TABLE', 'job_outbox'),
    'retry_after' => (int) env('QUEUE_OUTBOX_RETRY_AFTER', 300),
],
```

Every shipped connection record includes `'outbox' => false`. Base `Queue::setConfig()` caches the optional, already-cast value in a typed flag, using `false` when an existing custom record omits it; `QueueManager` continues passing configuration once. On lazy service resolution, inject typed config values into their owning services through the provider, following the existing batch/failed-job providers; no `OutboxConfiguration` class. Validate a non-empty database-source list and positive `retry_after`. Native failures cover invalid table/connection configuration; do not add duplicate-name validation. Relay batch size is a worker option, not duplicate config. Retry delays are fixed at `OutboxRelay::RETRY_DELAYS = [1, 5, 15, 30, 60, 300, 600]`; staging chunks follow database binding limits, not another knob.

Resolve the owning database as follows:

- one applicable active transaction: use that connection; an explicit different database is an error;
- multiple active transaction connections: require `onOutboxDatabase()` naming one of them; otherwise throw an exception that lists the active connections and names the required method. Document that only the explicitly selected transaction is atomic with the job;
- no active transaction: use the explicit database or the first configured database;
- whenever SQL staging is required, its source must be covered by `databases`, so the relay cannot silently ignore the rows. For base/`::write` transactions, compare the resolved owner's `getName()` with the configured source names, while inserting and registering rollback on the exact `getNameWithReadWriteType()` session.

Nested records on one resolved connection count as one owner. Explicit selection must identify an applicable owner when transactions exist; otherwise it selects configured storage. Resolve transaction identities from objects, not requested suffixes or host/database comparisons. `onOutboxDatabase('mysql::write')` selects that exact active session while `databases => ['mysql']` covers draining its table. Do not list both as sources: that would duplicate polls/counts and make UUID lookup falsely ambiguous. Shared in-memory SQLite aliases remain one owner. Do not borrow an unrelated base connection merely to inspect an alias. Install the migration on each configured source; avoid listing different names for the same physical outbox table rather than adding topology detection. Workers visit sources round-robin or pin one source through their database argument.

SQL outbox staging is writer-owned. During preflight, before finalization or insertion, reject a resolved owner for which `ConnectionName::parse($owner->getNameWithReadWriteType())->isRead()` is true, including explicit no-transaction selection. Name that connection in the error and direct the caller to its base/`::write` connection; existing pre-acceptance cleanup releases dispatch locks. Do not probe endpoints or exempt read aliases without separate read configuration. Shared in-memory SQLite resolves to its base owner and remains supported. This rule governs SQL staging, not local after-commit fallback or ordinary unselected queue APIs.

Do not add a dynamic shard/source registry. Configured database connections are the complete, explicit set of sources the relay drains.

## Storage model

Generate with `make:queue-outbox-table` (alias `queue:outbox-table`) using the configured table name. `OutboxTableCommand` extends `MigrationGeneratorCommand` with normal duplicate-table detection; no combined migration needs an override. Commit the same schema in `src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php` and `/home/binaryfire/workspace/contrib/hypervel/hypervel/database/migrations/0001_01_01_000009_create_job_outbox_table.php`; do not maintain a third test-only migration copy.

```php
Schema::create('{{table}}', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('connection');
    $table->string('queue')->nullable();
    $table->longText('payload');
    $table->text('options')->nullable();
    $table->unsignedBigInteger('deliver_at')->nullable();
    $table->unsignedInteger('attempts')->default(0);
    $table->unsignedBigInteger('available_at');
    $table->uuid('lease_token')->nullable();
    $table->text('last_error')->nullable();
    $table->unsignedBigInteger('created_at');

    $table->index(['available_at', 'id']);
});
```

- Bigint `id` keeps the hot index and range/order operations compact. `uuid` repeats the finalized payload's existing job UUID for indexed logs and operator commands; do not generate a second outbox identity. A fresh `lease_token` identifies only one claim attempt.
- All three time columns are integer Unix seconds supplied from the framework clock, following queue/cache operational deadline conventions. Bigint avoids PostgreSQL's signed 32-bit epoch limit. No database-clock default or timezone-dependent parsing: SQL session timezone and later `app.timezone` changes cannot move a staged instant. Keep message fields as `int`/`?int`, converting only for display. Preserve `InteractsWithTime::availableAt()`'s future ceil-to-second; already-due input becomes `deliver_at = null` with initial eligibility now.
- `deliver_at` is immutable intended delivery; `available_at` is next eligibility, including lease expiry and retry backoff. Optimized drivers compute non-negative remaining delay immediately before each transport chunk. Redis/database initially qualify now; standard SQS at `max(now, deliver_at - 900)`, with `DelaySeconds = min(900, max(0, remaining))`; FIFO and generic raw fallback qualify at `deliver_at ?? now`. Claim moves `available_at` to its lease deadline; retain the pre-claim value in the in-memory message as `eligibleAt` for ordering/latency. No separate reservation timestamp.
- `queue` stores the unforwarded logical name with the connection's default expanded. Publication applies queue-name forwarding once through the normal storage path. Changing the default cannot reroute the row; changing a queue-name forward can. Connection forwarding is already resolved at staging and is not rerun. A queue-less custom driver may store null. Drain before changing captured connection routing or forwarding between incompatible FIFO/standard destinations.
- `payload` is the finalized opaque envelope after payload hooks and `JobPayloadFinalizing`, not a job to redispatch through Bus. `longText` preserves its bytes; a database JSON type could normalize them. Adapters may add only delivery-time metadata such as Horizon's `pushedAt` or an SQS overflow pointer; SQL bytes, UUID and serialized command stay unchanged. Opaque `options` likewise uses text and is decoded only for publication.
- `options` is null when empty, otherwise JSON containing only namespaced transport options needed after the object is gone, notably `{"transport":{"sqs":{}}}` for precomputed group/deduplication. Do not duplicate payload lock metadata or add an unused format version; migrate in-flight rows if a future format changes. Adapters use their own option members; native transport errors retain/back off rows. Do not add a driver-kind compatibility registry or business fields.
- No tenant or shard column: this is an operational table like `jobs`, `failed_jobs`, and `job_batches`; application context already travels inside the finalized payload.
- No `updated_at`, `status`, `processed_at`, history, payload hash, or stack trace. Eligibility is exactly `available_at <= now`; a live lease has a non-null token and `available_at > now`. Full exceptions are reported once; `last_error` is bounded to 4,096 characters.

Use Query Builder/raw SQL, never Eloquent, for this hot operational table. Raw claim SQL wraps configured identifiers through the active grammar and binds every value. A one-statement bulk relies on that statement's atomicity; a bulk requiring multiple binding-safe insert chunks runs inside `Connection::transaction()` (a savepoint when already nested), so even a caller that catches the exception cannot commit a partial owner group. Reuse `InsertsDatabaseRows`; do not add a second chunk calculation or expose another configuration knob. Claim and settlement sizes may be lower than the requested worker batch when an engine's binding/expression limit requires it.

## Internal architecture

Keep public surface small and group the cohesive implementation under `Hypervel\Queue\Outbox`:

```text
src/contracts/src/Queue/PublishesOutboxMessages.php
src/contracts/src/Queue/ShouldQueueViaOutbox.php
src/bus/src/InteractsWithQueueOutbox.php
src/bus/src/Queueable.php
src/bus/src/Batchable.php
src/bus/src/Batch.php
src/bus/src/PendingBatch.php
src/foundation/src/Bus/PendingDispatch.php
src/queue/src/Concerns/InsertsDatabaseRows.php
src/queue/src/Outbox/
  Concerns/PublishesOutboxMessagesSequentially.php
  DatabaseOutboxRepository.php
  OutboxBatchResult.php
  OutboxDispatcher.php
  OutboxMessage.php
  OutboxRelay.php
  OutboxWorker.php
  OutboxWorkerOptions.php
src/queue/src/Console/
  ForgetOutboxCommand.php
  ListOutboxCommand.php
  MonitorOutboxCommand.php
  OutboxTableCommand.php
  RelayCommand.php
  RetryOutboxCommand.php
  stubs/outbox.stub
src/queue/src/Events/
  OutboxBatchDelivered.php
  OutboxBatchDeliveryFailed.php
  OutboxBusy.php
```

The tree lists the new mechanism and primary API surfaces, not every modified file. Integration also touches the existing queue drivers, `FailoverQueue`, pool proxies, Events `Dispatcher`/listener wrappers, `SendQueuedMailable`, `SendQueuedNotifications`, `BroadcastEvent`, Horizon `RedisQueue`/`JobPayload`, OpenTelemetry `QueueInstrumentation`, and the prerequisite files identified above.

Do not create a listed type if it would only forward one call. Do not combine routing, SQL claims, network publication and daemon lifecycle into one god class. Responsibilities are:

- base `Queue` plus small protected hooks: selection precedence, local/database fast paths, normal lifecycle events, default-expanded logical destination and driver option capture;
- `InsertsDatabaseRows`: reuse the existing binding-safe, transactionally atomic chunk operation and its existing queued-job insertion error;
- `OutboxDispatcher`: transaction/database ownership, preflight validation, staged single/bulk coordination, and rollback registration without containing SQL claim or broker publication logic;
- `DatabaseOutboxRepository`: multi-row insert, engine-specific claim, token-guarded delete/release, retry/forget/list/monitor queries;
- `PublishesOutboxMessagesSequentially`: the one shared generic fallback used by the relay and pooled proxy, partitioning future rows and returning accepted-prefix/deferred/failure outcomes without duplicating the algorithm;
- `OutboxRelay`: group claimed rows by target connection, choose optimized raw-batch publication or the shared generic raw fallback, and settle only explicit outcomes;
- `OutboxWorker`: stateless daemon loop, bounded task admission, round-robin database fairness, restart/signal/memory/time/empty rules, and ownership of the short-lived child-coroutine boundaries described below;
- command classes: input/output only.

Container-cached outbox services remain immutable/stateless. Database capability detection stays on the live connection that owns the capability, without another service or cache. `OutboxMessage`, `OutboxBatchResult`, and `OutboxWorkerOptions` are immutable per-operation values created directly rather than container-resolved. Loop counters, current claims, stop reasons, and per-delivery data stay method-local. The outer concurrency task may retain only scalar/immutable values and result data: never a database connection, query builder, resolved queue instance, pool lease, or transport client.

Resource ownership is a hard invariant: **no relay database or transport connection remains borrowed while the relay performs work that does not require it**. Explicit idle release does not cover retained write sessions, every custom driver, or arbitrary callbacks. Use fresh child-coroutine boundaries for (1) source claim, (2) each target-connection publication group, and (3) that group's settlement. `Waiter(-1)` leaves phase duration to configured database/transport timeouts and daemon cancellation, not its unrelated ten-second default. Each child uses a fresh context, returns materialized values and ends before the next phase. Request-side staging instead uses the exact already-borrowed business connection for atomicity.

Reuse the prerequisite `DispatchLockContext` capture/snapshot lifecycle and existing hidden scalar unique metadata. Never persist live cache provenance or captured release closures, or create an outbox-owned lock registry.

### Queue integration seam

Refactor the single and bulk enqueue paths so the cheap outbox-selection decision happens before ordinary after-commit deferral. Read the connection default from the typed boolean cached when the queue receives its immutable config; when neither job nor connection selects outbox, do not resolve an outbox service. Once selected, complete these steps inside the existing guarded enqueue attempt:

1. resolve/validate selection, database ownership, driver fast-path behavior, and logical destination before finalization;
2. retain dispatch ownership until storage succeeds; any preparatory snapshot is data only, not a registered rollback callback;
3. finalize the payload exactly once while the original job object exists, inside the guarded enqueue attempt;
4. run the narrow outbox-payload hook, decode and validate the finalized envelope and its UUID string consistently on every database engine, and compute absolute `deliver_at` plus target-specific opaque options in that same guarded attempt;
5. raise normal `JobQueueing`;
6. insert on the selected connection; immediately after success, register independent snapshot rollback cleanup only if that exact connection still has an active transaction;
7. let the existing enqueue lifecycle accept the dispatch locks and raise `JobQueued` with the job UUID after insertion, or raise `JobQueueingFailed` for every non-cancellation exception within the guarded attempt.

The existing `DispatchLockContext` / `PendingDispatch` lifecycle owns failures before queue acceptance, including payload serialization and queue resolution. Reuse it rather than adding an outbox cleanup path. A failure before a valid payload exists rethrows without fabricating a queue lifecycle event; a failure inside the guarded enqueue attempt follows the existing `JobQueueingFailed` and cancellation rules. Do not report an accepted job as failed when a later `JobQueued` listener throws.

`JobQueued` means the configured logical queue accepted the job into its durable path; like a database-queue insert inside a caller transaction, that acceptance can still roll back with the transaction. It must not be delayed to another process. This preserves the original job object for listeners and completes OpenTelemetry producer state in the dispatching coroutine. Relay must not emit a second normal enqueue lifecycle.

Provide additive protected queue hooks such as:

```php
protected function resolveOutboxQueue(?string $queue): ?string;
protected function preparePayloadForOutbox(
    object|string $job,
    string $payload,
): string;
protected function prepareOutboxOptions(
    object|string $job,
    ?string $queue,
    string $payload,
): array;

protected function availableAtForOutbox(
    ?string $queue,
    ?int $deliverAt,
): int;
```

First-party drivers expand missing queues to their configured logical default without forwarding or deriving physical keys/URLs. The base options hook returns an empty array; initial availability is `deliver_at ?? now` because raw publication has no cross-driver delay contract. Redis/database become eligible immediately. SQS captures group/deduplication while the object exists, without relative `DelaySeconds`, and computes initial eligibility using the effective destination's standard/FIFO behavior. These inspections do not replace the stored logical name. At publication, normal driver routing resolves that name once. Register independent storage-rollback snapshots through the existing connection-aware transaction callbacks; do not reuse the live-record lookup callback after acceptance.

`Queue::setConfig()` caches the optional connection `outbox` boolean alongside the already retained immutable configuration; `QueueManager` already calls this once for every resolved concrete connection. Do not add another constructor argument to every driver/connector. Missing `outbox` remains `false` for existing custom configuration. Selection computes explicit state, marker, and the cached flag first; only a positive result checks the failover suppression context. `FailoverQueue` scopes that context with exception-safe restore around all child calls, including raw calls, so nested failover queues remain correct.

Base `Queue::bulk()` must preflight and partition outbox-selected jobs itself, stage its outbox partition with set-based owner-group inserts, then loop only the direct remainder. Beanstalkd and custom durable drivers that inherit the base method therefore get bulk staging without a driver override. Database, Redis, and SQS keep their optimized overrides but apply the same partition before their direct paths.

Every implementation preserves order within each partition and groups staged rows by owning database. This prevents a later configuration error from partially staging an otherwise valid group. There is deliberately no ordering or atomicity guarantee between direct and outbox partitions, just as existing cross-connection bulk has none; callers requiring one atomic unit use one outbox owner. Finalize every owner group before its first insert, emit existing per-job queue lifecycle events, and use set-based storage. Each owner group is atomic across its insert chunks; distinct owner databases necessarily commit independently. A `PendingBatch` repository transaction provides one owner and rejects conflicting per-job choices. Preserve current `Bus::bulk()` semantics: it does not suddenly acquire unique/debounce locks or run `PreparesForDispatch`.

### Raw-batch publication SPI

`PublishesOutboxMessages` is a narrow optional capability in `Hypervel\Contracts\Queue`, beside `ClearableQueue` and `IndexAwareQueue`, not a new application API. Its signature may reference the queue-owned `OutboxMessage` and `OutboxBatchResult` value types under the repository's lazy contract-signature dependency convention.

```php
interface PublishesOutboxMessages
{
    /** @param list<OutboxMessage> $messages */
    public function publishOutboxMessages(array $messages): OutboxBatchResult;
}
```

`OutboxMessage` carries row ID/job UUID/lease token/attempt, finalized payload, unforwarded logical queue, integer `deliverAt` (nullable), `eligibleAt` (pre-claim availability), `createdAt`, and opaque options. `OutboxBatchResult` contains unique lists of accepted and deferred row IDs plus an optional failure. Accepted and deferred IDs must be disjoint subsets of the input; a clean result accounts for every input through their union. Explicit transport rejects return confirmed IDs plus one aggregate exception containing the reject count, first reason and full response context; a later-chunk exception returns earlier confirmed IDs plus that exception. Cancellation is rethrown. Optimized implementations return no deferred IDs; sequential fallback uses them for future rows. Failed/unattempted rows are input minus accepted minus deferred and receive the same bounded failure text. Foreign/overlapping IDs or unaccounted-for input without failure are a broken contract: report a `LogicException` and settle nothing from that call. Transport response IDs stay inside the driver.

Use typed message/result boundaries and existing driver option handling; do not add a second transport validator. The generic fallback passes stored options through `pushRaw()`. Native publication errors and partial/ambiguous outcomes use the result contract above.

Implement the SPI where it provides a real batch/resource benefit:

- Redis: reuse `LuaScripts::bulk()` and its exact stored-count confirmation once per resolved destination, combining immediate/delayed entries and retaining existing Cluster-safe keys/script handling; preserve confirmed earlier destinations if a later call fails;
- database: one target-database transaction containing parameter-safe multi-row jobs-table inserts across logical queues, with each remaining delay converted to `available_at`; report success only after commit;
- SQS: reuse the 10-entry and 1,048,576-byte request limits, FIFO fields, SDK retry assumptions, and per-entry response mapping; derive overflow pointers from job UUID plus lease token so separate publication attempts never share cleanup ownership;
- pooled proxy: borrow once for the whole invocation, delegate when the borrowed queue implements the SPI, and otherwise invoke the shared sequential fallback on that same borrowed queue; never retain the lease;
- Horizon Redis: decorate the Redis batch path with `JobPending` before attempts and `JobPushed` only for accepted payloads.

Do not add raw-delay methods to the general `Queue` contract. Custom durable drivers opt into staging through the documented base enqueue hooks; implementing the SPI is an optional publication optimization, and their connector remains otherwise unchanged.

## Relay algorithm and failure handling

### Claim

- The concurrency-owning relay task calls claim through a fresh `Waiter` child with no copied request/transaction context. That child alone resolves the source database, commits the claim, returns immutable `OutboxMessage` values, and ends so `ConnectionResolver` releases the database lease before any broker work starts.
- Claim at most the validated worker batch limit eligible rows ordered by `available_at`, then `id`.
- Eligibility is `available_at <= now`. A claim sets one fresh UUID lease token, `available_at = now + retry_after`, and increments attempts in a short transaction. This moves live leases out of the hot eligibility range without a second timestamp/filter. Never hold SQL locks during broker calls.
- PostgreSQL: one CTE using `FOR UPDATE SKIP LOCKED` plus `UPDATE ... RETURNING`, returning the selected pre-update availability as `eligibleAt` with the updated token/attempt. Updating the indexed timestamp trades HOT-update eligibility for avoiding repeated live-lease scans; validate this tradeoff in the approved benchmark.
- Supported MySQL/MariaDB/Vitess: `SELECT ... FOR UPDATE SKIP LOCKED`, one guarded bulk update, and return the already selected rows with token/time/attempt fields reflected in memory rather than issuing another select.
- SQLite/engines without `SKIP LOCKED`: select an ordered, limited list of candidate IDs and their availability, perform one atomic eligibility-guarded token/expiry update restricted to those IDs, then load full rows restricted to those IDs bearing that token. Preserve original eligibility from the candidates. The second read distinguishes rows actually won; another claimant can cause an empty/partial result, not a double claim. SQLite serializes writers, so additional relays improve availability rather than write throughput.
- Normalize every returned claim to its pre-claim `(eligibleAt, id)` order; `UPDATE ... RETURNING` and `WHERE IN` do not promise order, and the newly shared lease deadline is not the original eligibility.
- Never return a connection, builder, cursor, lazy collection, or pool-backed object from the claim child. Materialize and validate the bounded result before it ends.
- Reuse `PdoConnection::lockForPopping()` and `maxBindings()` for PDO-backed connections. Keep base connection types driver-neutral and follow DatabaseQueue's non-PDO lock/binding fallback; custom drivers are not required to expose PDO methods. Existing live-session capabilities cover engine variants; do not add another detector, contract or cache.

### Publish and settle

- Group a claim by target connection, preserving order within each group. The driver partitions for existing Redis destination scripts, SQS URL/count/bytes, or database binding limits. This permits multi-row database inserts across queues and one pooled Beanstalk lease per connection batch without replacing existing driver mechanisms.
- Publish each group in its own fresh child. Only that child resolves the queue, borrows its transport and returns a materialized result. Use the optional raw-batch contract or shared sequential fallback. Like `queue:retry`, stored rows honor the recorded connection's current configuration, including an operator deliberately changing it to sync/null; add no driver-kind rejection. Document draining before configuration changes that discard durability or invalidate stored transport options. Native send errors retain/back off affected rows.
- The shared fallback moves rows whose `deliver_at` is still in the future into the result's deferred set without calling `pushRaw()`, then publishes the due rows in order and returns the accepted prefix plus any exception that stopped it. `QueuePoolProxy` invokes this same operation while its one borrowed queue is live when that queue lacks the SPI. Settlement releases deferred rows token-safely with `available_at = deliver_at`, restores the claim's attempt increment, and leaves `last_error` untouched. Deferral is not a publication failure: it does not occupy the task's failure-sleep slot or emit `OutboxBatchDeliveryFailed`.
- The driver's existing publish result is the acknowledgment—Redis/database replies, SQS success/failure entries, and Beanstalk IDs. Add no probe or extra broker round trip. Validate the returned accepted row IDs before settlement; an invalid result retains every row in that call and reports the driver-contract failure.
- Settle each completed group immediately in a fresh child and short source transaction: delete accepted rows using `id IN (...)` plus `lease_token`; clear deferred tokens, restore availability to `deliver_at`, undo the claim's attempt increment and preserve `last_error`; release failed/unattempted rows with one token-guarded update per binding-safe chunk. Choose one jittered deadline per capped retry bucket using `RETRY_DELAYS`, random 50%-100% jitter and a minimum one-second delay. A fixed `CASE WHEN attempts <= 1 THEN ? ... ELSE ? END` binds seven deadlines, not per-row cases; clear the token and bind one bounded error text. Only ID lists require size-dependent chunking. If fewer rows match, retain successful settlement and leave newer owners untouched; report ownership loss once. End the child before the next group.
- A failed connection group does not prevent publication/settlement of the other groups in the same claim. Collect and report their errors after all non-cancelled groups have been attempted, identifying affected row IDs, job UUIDs, and payload display names; decode display names only on failure so successful relay batches pay no diagnostic parsing cost. Cancellation stops further publication and leaves unsettled leases to expire.
- After any result failure, keep that relay task's concurrency slot occupied for its positive `--sleep` interval. Row backoff alone cannot protect a misconfigured/unavailable target from a large backlog of first-attempt rows; bounded occupied slots throttle further claims without distributed circuit-breaker state.
- If broker acceptance succeeds but delete fails or the process crashes, lease expiry makes the row retryable; a duplicate is correct. If the network outcome is ambiguous, retain and retry.
- Guard all settlement by lease token so a stale worker cannot delete/release rows reclaimed by another worker.
- Require `retry_after` to exceed the maximum expected lifetime of one claimed batch, including every connection-group publication and SDK retry. Do not add a heartbeat coroutine or callback machinery; an exceptionally slow cycle may produce a duplicate after lease expiry but cannot lose the row.
- On graceful stop, stop claiming new work and let active completed calls settle before joining them. Cancellation is never converted to an ordinary failure; an unsettled lease expires naturally.
- Parent cancellation while waiting for a phase propagates as `CanceledException`; a child that independently cancels while its parent remains active follows `Waiter`'s existing `ChildCancellationException` contract and is reported as a phase failure. Do not catch either merely to log and continue claiming.
- Continue retries indefinitely. Backoff prevents poison rows or outages from hot-looping, ordering plus `SKIP LOCKED` prevents one row from blocking later work, and monitor alerts surface permanent failures.

### Lock cleanup

Transactional rollback uses the prerequisite independent snapshot, including its captured release context. Existing deferred-dispatch callbacks retain their normal ownership lifecycle. Successful publication leaves locks for normal job processing.

`queue:outbox-forget` reads existing hidden cache-store/key/owner scalars with object deserialization disabled; never hydrate arbitrary context or unserialize the queued command. Delete the non-live row first, then release the owner-matched unique lock. Keep this cleanup in a protected command method receiving the stored envelope so integrations can establish a context-dependent cache namespace without copying SQL or changing the relay. Commands already resolve through the container; no new registry is needed. Cache and SQL cannot commit atomically: malformed metadata/post-delete cache failure is reported and may require manual cleanup, but never restores or republishes deliberately forgotten work. Debounce tokens have bounded TTL and need no explicit forget cleanup. No outbox lock abstraction or persisted release closure.

## Worker and operations

Register these commands in `QueueServiceProvider`:

- `queue:relay {database?}` — long-running relay plus `--once`, `--stop-when-empty`, `--batch=100`, `--sleep=1`, `--concurrency=1`, `--max-jobs=0`, `--max-time=0`, and `--memory=128`; validate batch 1–1,000 and positive sleep/concurrency. Sleep accepts fractions like `queue:work`. `--once` makes one synchronous round-robin pass, at most one bounded claim per configured/pinned database, with no empty/outage sleep. `--stop-when-empty` joins active tasks and exits after a complete source pass finds no eligible row; delayed/backoff rows do not keep it alive. `--max-jobs` counts claimed rows regardless of result; cap every final claim to the remaining allowance;
- `queue:outbox {database?}` — pending-row diagnostics by configured database, ID, UUID, job display name read from the bounded payload result, target, attempts, lease state, age, and last error, ordered by ID and bounded by validated `--limit=100` (maximum 1,000), with `--json` matching `queue:failed`; otherwise visit all configured sources; never render payloads, transport options, context, or lock-owner tokens;
- `queue:outbox-monitor {database?}` — total, eligible, delayed, leased, and retrying counts plus the oldest currently eligible row's age, with explicit `--max` / `--age` alert thresholds and `--json` output for one/all configured databases; use one portable conditional-aggregate query plus one indexed `(available_at, id)` head lookup per database, and emit `OutboxBusy` like the existing `queue:monitor` command without another index or payload scan. Eligibility uses the claim predicate, and age starts at `available_at`, so intentionally delayed jobs do not create false alerts;
- `queue:outbox-retry {uuid?*}` — targets only rows with `attempts > 0`, clears errors/leases, and makes selected rows (or explicit `all`) immediately available without erasing attempt history; accept `--database=` to pin a configured source, otherwise visit all sources. `all` snapshots each source's maximum ID among retryable rows and updates through that ceiling in bounded primary-key cursor chunks; an explicit UUID found on multiple unpinned sources fails rather than updating an ambiguous match. A never-attempted row waiting for its intended delivery time is not retryable; a previously failed row remains retryable regardless of its current backoff. Always reject live leases;
- `queue:outbox-forget {uuid}` — confirmation, live-lease rejection, token-safe delete, and owner-matched unique-lock cleanup from the payload metadata; accept `--database=` to pin a configured source and fail rather than guess if an unpinned UUID exists on more than one source. There is no force path because an in-flight publication cannot be recalled;
- `make:queue-outbox-table` / `queue:outbox-table` — migration generation matching existing queue table commands.

The worker follows the relevant `queue:work` operational conventions: `queue:restart`, bounded coroutine concurrency, POSIX signals, memory/max-time exits, exception reporting, and no swallowed cancellation. It does not consult maintenance mode: relaying only moves committed jobs to their queue, while normal workers already control whether those jobs execute. One coordinator admits work in configured-database round-robin order only when `WaitConcurrent` has a free slot, so counters, source fairness, stop decisions, and the remaining `--max-jobs` allowance stay local and exact.

Retry/forget load mutation targets from the writer using existing query-builder support, not replica reads or a separately borrowed connection. Recheck token/live-lease predicates in the modifying statement so a concurrent claim cannot be reset/deleted. A live lease is `lease_token IS NOT NULL AND available_at > now`; expired tokens do not prevent recovery. Monitor counts distinguish those live leases from tokenless scheduled/backoff rows and use `available_at <= now` for eligibility.

Each admitted task owns no pooled resource. It uses a fresh child for claim, each publication and settlement, passing only immutable messages/results. Guarded delivery events/reporting also run in a short-lived child because application callbacks may resolve clients. A listener failure is reported once; a reporter failure propagates. Neither alters settled rows, emits the opposite delivery event or republishes accepted work. Use fakeable `Sleep::usleep()` for fractional empty/failure delays and the existing capacity-wait primitive instead of polling busy slots. Graceful stop ceases admission and joins tasks so completed publication can settle. Supervise this dedicated process; scheduled `--once` is also supported. Size source/transport pools for relay concurrency and any other users, allowing a short-lived extra slot for restart-cache/event/reporting work on the same pool. Never make the relay a queued job dependent on the broker it must recover.

Horizon continues supervising and visualizing Redis jobs only after relay publishes them. Do not add relational outbox polling, migrations, or supervisor machinery to Horizon.

Expose one guarded event per completed connection publication batch, with source database, target connection, accepted/failed counts, publish duration, oldest eligible age, and an optional failure, but no payload bodies: `OutboxBatchDelivered` only when every attempted row was published and settled, otherwise `OutboxBatchDeliveryFailed` for a result failure, partial acceptance, invalid result, lease-ownership loss, or settlement failure. Rows deferred before the generic fallback are excluded; a group containing only future rows emits no delivery event. The OpenTelemetry component consumes these events in the same change and records delivery count, failure count, eligible-to-publish latency, and publish duration. Rely on the monitor command for backlog/eligible-age gauges so ordinary dispatch does not issue count queries.

## Horizon outbox integration

Use the existing `JobPayload` lifecycle through its two semantic operations:

- `prepareForQueueing($job)` while the object exists: type, tags, and silenced state;
- `markAsPushed()` at actual Redis publication: `pushedAt` only.

Preserve `JobPayload::prepare($job)` as a single-pass combination of both operations. Direct immediate/delayed Horizon dispatch calls it once inside the actual publish callback, so after-commit timing is correct and the direct path gains no second JSON decode/encode. External `pushRaw()` uses the same single-pass method with `null`, preserving already-stored classification and refreshing only `pushedAt`.

Add a narrow protected `preparePayloadForOutbox()` hook to base Queue that is called only after generic payload finalization on selected outbox paths and is otherwise a no-op. Horizon Redis overrides it to call `prepareForQueueing($job)` once before storage. Its raw-batch publication calls `markAsPushed()` once per payload immediately before Redis publication. Preserve job `uuid` / Horizon `id`; emit `JobPending` before each attempted publication and `JobPushed` only for explicit successes. The outbox path receives the original job through this hook; it must not reuse Horizon's direct-push coroutine handoff or classify with `null`.

## Documentation cleanup

Update in the same change so no stale behavior survives:

- `src/docs/queue-outbox.md`: canonical setup, configuration, API precedence, transaction ownership, driver/custom-driver matrix, bulk/batches, `dispatchSync`/after-response limitations, delays, UUIDs, connection capture versus publication-time queue forwarding, deliberate driver changes, at-least-once/idempotency and best-effort dispatch ordering, SQS overflow attempt ownership/orphans, worker deployment/pool sizing, operations, Horizon and database fast paths;
- `src/docs/documentation.md`: add the new guide to navigation;
- `src/docs/queues.md`: add only a concise transactional-outbox introduction, when it is useful, one basic `viaOutbox()` example, and a link to `queue-outbox.md`; do not duplicate its setup, guarantees, driver matrix, or operations reference;
- `src/docs/events.md`, `mail.md`, `notifications.md`, and `broadcasting.md`: beside their existing after-commit guidance, add one concise example/cross-link showing that the producer's marker or fluent selection is propagated to its wrapper job; link to `queue-outbox.md` for guarantees rather than duplicating them;
- `src/docs/porting-from-laravel.md` and database README: explain exact resolved transaction identity for extensions observing role-qualified records. Do not add outbox catalogue entries: its opt-in APIs require no Laravel adaptation. Preserve Queue/Bus/Horizon README structure without adding additive-feature differences;
- `/home/binaryfire/workspace/contrib/hypervel/hypervel`: match Foundation's outbox configuration and the Testbench schema in the next application migration;
- configuration comments and migration command help: exact guarantees and required worker/table setup.

Never imply exactly-once delivery, broker participation in the SQL transaction, or durable behavior for local drivers. Remove a comment only when the outbox implementation makes it stale; final comments explain only non-obvious invariants.

## Testing plan

### Framework prerequisites

- Distinct base/`::write` sessions with simultaneous transactions and independent commit/rollback callbacks; nested savepoints, shared in-memory SQLite aliases, Foundation wrapping/context synchronization and Pennant rollback invalidation use resolved ownership. Do not use shared in-memory SQLite as proof of separate-session behavior.
- Context-dependent cache fixture backed by `WorkerArrayStore`: acquire in context A, release record/snapshot in B, remove A's key and preserve B's same logical key. Cover unique and debounce separately, with a second registered wrapper proving composition order and restoration. A throwing capture callback acquires neither lock nor token. One real CoroutineQueue closing-timer regression covers release outside the acquiring coroutine. No repeated tests per caller or cache wrapper: the hook does not branch on wrapper types.
- DatabaseQueue storage rollback releases its snapshot after acceptance, including nested rollback/deadlock retry; broker-accepted jobs are not released by unrelated rollback. Preserve existing deferred ownership coverage.
- SQS later-finalizer/queueing-listener failures finish unaccepted producer state without falsifying confirmed chunks; preserve cancellation. Reuse/extend affected existing tests.
- Horizon combined preparation encodes once and preserves classification/pushed timing/customization. Run the existing direct/retry/delayed tests; do not duplicate them under outbox names.

### Contract and routing tests

- `Queueable`/`Batchable` shared fluent state, pending dispatch/batch APIs, jobs that use only `Batchable`, jobs added to an existing batch, explicit per-job overrides, and the absence of chain-specific propagation/API state;
- precedence across explicit true/false, marker, and connection default; `beforeCommit`/`afterCommit` remain independent direct-publish controls; wrapper jobs preserve marker/explicit outbox state; and `dispatchSync`/`dispatchAfterResponse` retain their existing local/deferred semantics rather than pretending to provide pre-deferral durability;
- a custom durable transport without the raw-batch SPI stages normally and relays through the sequential raw fallback; failover selected by explicit state, marker, or its own connection default degrades to its existing after-commit attempt without schema access, child defaults are suppressed so fallbacks still run, nested suppression restores correctly, and a custom queue that bypasses the base lifecycle remains direct and is documented as not outbox-capable; preflight failure releases only locks acquired by the current dispatch;
- `pushRaw()` remains direct even on a connection whose default enables outbox;
- local/null/fake/database fast paths never query the outbox; exact local transaction selection is honored; expanded defaults stay fixed and queue-name forwarding runs once, including `A → B`, `B → C` and a changed forward before publication;
- staged single dispatch returns the same job UUID indexed on its row; direct driver return values remain unchanged.

### Transaction tests

- no transaction, commit, rollback, nested savepoint commit/rollback, and rollback lock release;
- exactly one active connection, nested records on it, multiple active owners with/without explicit selection, and unconfigured-source rejection; a `::write` owner covered by its configured base stages on the exact alias session and is drained/countable once through the base source;
- a read-role owner is rejected before insertion with no staged row and existing dispatch-lock cleanup; cover active ownership and explicit no-transaction selection within the owner tests, not a separate suite;
- same database queue connection inserts directly and rolls back atomically; a differently named/session connection stages and relays;
- bulk is all-or-nothing across insert chunks within each owner group both with no outer transaction and when an outer caller catches the exception after nested-savepoint rollback; separate owners and direct/outbox partitions are independently atomic and preserve only internal order; batch counters and outbox rows commit/roll back together;
- outbox preflight, payload-finalizer, option, and storage failures follow the existing lifecycle: every unaccepted single/bulk member raises `JobQueueingFailed`, dispatch-owned locks remain exact-owner safe, cancellation remains cancellation, and an accepted job is never reported as failed because a later `JobQueued` listener throws;
- rollback snapshots register on the selected owner and release only that dispatch's locks even after acceptance removes its live record; successful publication preserves the normal queued-job lock lifetime.

### Payload and API integration tests

- queued jobs, closures, listeners, mailables, notifications, broadcasts, encrypted jobs, serialized models, custom payload hooks, OpenTelemetry propagation, unique jobs, debounced jobs, delayed jobs, batches, nested batched chains, and ordinary chains; wrapper producers propagate marker/explicit selection and database without changing their public APIs, while absent source state leaves the wrapper's typed fields uninitialized;
- payload creation/finalization occurs once; malformed final envelopes or missing/invalid UUIDs fail uniformly before storage on every database engine; an outbox bulk finalizer failure leaves no earlier producer state open; opaque payload bytes remain unchanged in SQL and UUID/command bytes remain stable over relay retries apart from documented delivery-time transport metadata;
- SQS FIFO group/deduplication callback is evaluated once at staging; standard delays longer than 900 seconds wait until the broker window, FIFO delays wait until due, relay latency/backoff never rewrites immutable `deliver_at`, and publication after that instant uses zero additional delay;
- `Bus::bulk()` uses bounded multi-row inserts and preserves its existing lack of unique/debounce/preparation side effects; Beanstalkd and a custom durable driver inheriting base `Queue::bulk()` each stage one set-based insert per owner group rather than one insert per job, while their direct partitions retain the base loop;
- fake assertions work without schema setup or outbox service resolution; an unselected job does not serialize the uninitialized outbox state.

### Persistence and concurrency tests

- schema/migration command/config validation, byte-preserving payloads, opaque nullable text options, public UUID lookup and the hot composite index; staged time fields equal the frozen framework clock's epoch values, with one post-2038 fixture rather than a timezone/type matrix; normalize past dates and never truncate a future fractional due time early;
- indexed eligibility, pre-claim order/latency, attempt increment, lease expiry, token-guarded stale settlement, capped-bucket jitter bounds and last-error truncation;
- two/many concurrent relays do not claim the same live row; a killed claimant is recoverable after `retry_after`;
- instrumented tiny database/queue pools prove claim connection release before transport blocking, transport release before settlement, settlement release before another target or failure sleep, and bounded forward progress under concurrency; phase boundaries return no pool-backed objects and the long-lived coordinator/concurrency task retains none;
- PostgreSQL CTE/returning, MySQL/MariaDB skip-locked claim and competing fallback claims/full-row reload, consuming existing connection capabilities rather than retesting their engine-version detection; custom non-PDO paths do not assume PDO methods;
- query-count and bounded-fetch assertions cover one-statement PostgreSQL, two-statement MySQL/MariaDB and three-statement fallback claims, excluding ordinary transaction control. Verify limits in the SQL path, not just a truncated returned array. Do not assert a specific optimizer plan or pretend a mock proves live Vitess behavior; inspect representative query plans during approved benchmarks.

Place engine-specific cases under `tests/Integration/Queue/Database/{Postgres|MySql|MariaDb|Sqlite}` so the existing database workflow discovers them. Live Redis cases stay under `tests/Integration/Queue/Redis` and use `InteractsWithRedis`; do not pretend SQLite or a mock validates PostgreSQL/MySQL locking or Redis behavior.

### Transport tests

- Redis immediate/delayed Lua batching, exact confirmation and ambiguous failure after earlier confirmed destinations;
- database queue multi-row insertion across logical queues and remaining-delay conversion;
- SQS 10-entry/1,048,576-byte chunking, standard/FIFO outbox delay behavior, group/deduplication, aggregate explicit-reject failure, SDK exception after confirmed earlier chunks, and partial accepted-ID results; overflow storage reuses one pointer inside SDK retries but uses independent job-UUID/lease-token pointers across outbox attempts, cleanup for a later explicitly rejected attempt cannot remove an earlier ambiguous attempt's payload, confirmed unpublished pointers are cleaned up, and ambiguous attempts are retained;
- recorded connections honor current driver configuration and native transport failures retain rows; invalid/overlapping result IDs retain the whole call, sibling groups continue independently, and stale settlement leaves newer owners untouched; SPI-to-non-SPI replacement before `deliver_at` defers without publication, failure telemetry, attempt inflation or error mutation;
- shared generic raw fallback accepted-prefix/deferred/ambiguous-failure results; Beanstalk uses it with one pooled lease per connection batch and switches tubes correctly;
- only confirmed accepted IDs are deleted; every failed/unattempted row is released with the aggregate failure, while future fallback rows follow the non-failure deferral path; acceptance followed by delete failure produces a duplicate with the same job UUID and no lost row;
- confirm settlement adds no broker probe beyond the normal publish call/result.

### Worker, Horizon, and telemetry tests

- round-robin sources, positive fractional empty/outage sleeps, backlog drain, once/stop/max-jobs/memory/restart/signal/cancellation behavior, maintenance-mode independence and exception reporting;
- list bounds/display names/source selection; monitor counts distinguish live leases from future tokenless rows and alert on eligible age; retry/forget use writer reads and atomic live-lease guards, bounded `all`, confirmation/source pinning and cross-source UUID disambiguation; forget releases exact scalar unique ownership, exposes its narrow cleanup override and reports malformed metadata. Retain bounded debounce expiry without adding cleanup machinery;
- Horizon outbox paths preserve type/tags/silenced, refresh only `pushedAt` and emit Pending/Pushed correctly on partial results; prerequisite coverage owns shared direct/retry/delay regressions;
- OpenTelemetry producer state terminates during staging; relay batch instruments do not leak state or duplicate normal enqueue metrics;
- delivery-listener and exception-reporter failures release their child-coroutine resources and cannot alter an already-settled row, emit the opposite delivery event, or cause a republish.

### Performance gates

- Pin direct dispatch before/after query count, outbox service-resolution count, serialized fields, and payload transformations in automated tests: all remain unchanged when outbox is unselected. Use a repeatable local benchmark to measure the minimal cached job/config selection checks and investigate any material CPU or allocation regression; do not place unstable wall-time thresholds in CI.
- Obtain the owner's confirmation that the machine is idle before any benchmark. Benchmark single and 1k/10k staging, lease claims, Redis Lua batches, database inserts and SQS request construction; compare query/network counts and allocation as well as throughput.
- Verify one insert per normal bulk chunk, one short claim transaction, publication using existing destination/protocol batches, and at most one accepted delete, deferred release and failed-row release per non-empty binding-safe settlement subset. Record peak borrowed source/transport connections and prove no lease spans unrelated work or sleep.
- Profile PostgreSQL/MySQL plans with representative pending/live-leased/retrying backlogs and concurrency. Compare the indexed availability/lease update against the prior separate-reservation predicate, including PostgreSQL index-write/HOT costs. Confirm the chosen design with evidence; resolve a substantive contrary result before changing the plan. Do not add indexes or tune engine settings speculatively.

Run focused files immediately as they are added, then affected package suites, cross-package integration suites, analysis, and the repository checkpoint:

```bash
cd /home/binaryfire/workspace/contrib/hypervel/components-queue-outbox
composer test:parallel -- tests/Queue --filter Outbox
composer test:parallel -- tests/Bus --filter Outbox
composer test:parallel -- tests/Horizon --filter Outbox
composer fix

cd /home/binaryfire/workspace/contrib/hypervel/hypervel
composer cs-fix
composer analyse
composer test

```

Run each new or changed test file immediately. The filters are only a coherent-slice inner loop. `composer fix` includes `composer test:testbench`, satisfying the Testbench migration-change check without a duplicate run, plus formatting, analysis, parallel tests and dogfood suites. When verifying the Testbench change independently, run `composer test:testbench` directly.

If implementation uses a components worktree, copy `.env` from the main components checkout and run `composer install` in the worktree; never symlink `vendor` or Composer state.

## Implementation order and acceptance

1. Complete and verify the independently useful framework prerequisites in dependency order, then add marker/shared fluent state and selection tests.
2. Add config, migration command/schema, immutable value objects, `DatabaseOutboxRepository`, and transaction/multi-database tests.
3. Integrate single/bulk staging, existing fast paths, SQS delay selection, forwarding and the prerequisite snapshot lifecycle.
4. Add the raw-batch SPI and first-party driver implementations, including pooled and Horizon paths.
5. Add claim/publication/settlement resource scopes, relay/daemon and operator commands, guarded events, and OpenTelemetry listeners.
6. Complete canonical docs and the exact-owner compatibility note, approved benchmarks, focused suites, static analysis and `composer fix`.

Before completion, re-read every changed file and confirm:

- no mutable cross-coroutine state, pool lease retained beyond its owning phase or established queued-job terminal lifecycle, container swap, hidden environment branch, or network call under a SQL lock;
- source claim, each target publication, settlement, and arbitrary listener/reporter execution end their borrowing coroutine before unrelated work, another target, or throttling sleep begins;
- every crash boundary retains a retryable row or has a confirmed target acceptance, with duplicates preferred over loss;
- ordinary queue, batch, chain, Horizon, and OpenTelemetry behavior remains coherent;
- disabled/local/database-fast-path dispatch performs no outbox I/O;
- Foundation and application-skeleton queue defaults and outbox migrations agree;
- `queue-outbox.md` is the single detailed outbox reference and shorter queue/producer mentions link to it without duplicating guarantees;
- all configured owners are drainable, exact transaction identity is preserved, queue-name forwarding occurs once, current connection configuration is honored, and all SQL settlement is lease-token guarded;
- public names read like first-party Laravel queue APIs while internals remain Hypervel-native;
- no dead status/history/dead-letter/CDC/inbox abstraction, stale comment, duplicate README line, or superseded documentation remains.
