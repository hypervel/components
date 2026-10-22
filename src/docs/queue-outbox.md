# Transactional Queue Outbox

- [Introduction](#introduction)
- [Setup](#setup)
- [Dispatching Jobs](#dispatching-jobs)
    - [Selecting the Outbox](#selecting-the-outbox)
    - [Bulk Dispatch and Batches](#bulk-dispatch-and-batches)
    - [Queued Listeners, Mail, Notifications and Broadcasts](#queued-producers)
- [Database Transactions](#database-transactions)
- [Queue Connections and Delays](#queue-connections-and-delays)
- [Running the Relay](#running-the-relay)
    - [Concurrency and Shutdown](#concurrency-and-shutdown)
    - [Failures and Recovery](#failures-and-recovery)
- [Inspecting and Managing Messages](#managing-messages)
- [Delivery Guarantees](#delivery-guarantees)
- [Custom Queue Drivers](#custom-queue-drivers)

<a name="introduction"></a>
## Introduction

Dispatching a job [after a database transaction commits](/docs/{{version}}/queues#jobs-and-database-transactions) prevents a worker from reading uncommitted data. However, if the application stops between committing the transaction and publishing the job, the database change can remain without its intended job.

The transactional outbox closes this gap. Hypervel stores the job in a database table as part of the same transaction, then a separate relay process publishes it to your queue. If the transaction rolls back, its outbox rows roll back too. If the relay stops or the queue is unavailable, committed rows remain available for recovery.

```php
use App\Jobs\SendOrderReceipt;
use App\Models\Order;
use Hypervel\Support\Facades\DB;

DB::transaction(function () use ($attributes) {
    $order = Order::create($attributes);

    SendOrderReceipt::dispatch($order)->viaOutbox();
});
```

The outbox is an opt-in feature of the queue package. It uses your existing jobs, queue connections and workers, and does not require Horizon. Jobs which do not select it continue through their normal dispatch path without outbox database access.

<a name="setup"></a>
## Setup

The outbox settings live in your application's `config/queue.php` file:

```php
'outbox' => [
    'databases' => [
        env('QUEUE_OUTBOX_DATABASE', env('DB_CONNECTION', 'sqlite')),
    ],
    'table' => env('QUEUE_OUTBOX_TABLE', 'job_outbox'),
    'retry_after' => (int) env('QUEUE_OUTBOX_RETRY_AFTER', 300),
],
```

The `databases` array lists the configured database connections whose outbox tables the relay will drain. Install the outbox table on each listed database. New applications include its migration; if your application does not, generate it using:

```shell
php artisan make:queue-outbox-table
php artisan migrate
```

The generator uses the configured table name. `queue:outbox-table` is an alias for the same command. When using several databases, apply the migration on each appropriate connection using your normal migration deployment process.

Finally, run the relay alongside your normal queue workers:

```shell
php artisan queue:relay
php artisan queue:work redis
```

The relay publishes jobs; it does not replace the workers that process them. Horizon may continue managing your Redis workers as usual.

<a name="dispatching-jobs"></a>
## Dispatching Jobs

<a name="selecting-the-outbox"></a>
### Selecting the Outbox

Call `viaOutbox` when dispatching a job:

```php
SendOrderReceipt::dispatch($order)->viaOutbox();
```

To select the outbox for a job class by default, implement `ShouldQueueViaOutbox` instead of `ShouldQueue`. The marker extends `ShouldQueue`, so you do not need both:

```php
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Foundation\Queue\Queueable;

class SendOrderReceipt implements ShouldQueueViaOutbox
{
    use Queueable;

    // ...
}
```

Alternatively, set `outbox` to `true` on a named queue connection in `config/queue.php`. Existing connection configurations which omit this option default to `false`.

You may override either default for a specific dispatch:

```php
SendOrderReceipt::dispatch($order)->withoutOutbox();
```

An explicit job choice takes precedence over the marker, followed by the queue connection's default. `afterCommit` and `beforeCommit` still control direct dispatch timing; neither disables the outbox. A selected outbox job is stored inside its owning transaction and cannot be relayed before that transaction commits.

The fluent methods are available through the usual `Queueable` trait. A directly queued object may also set a public `viaOutbox` property to `true` or `false`. `viaOutbox()` is a setter, not a getter hook like `viaConnection()` or `viaQueues()`.

A staged queue insertion returns the finalized job UUID instead of a broker identifier. The operator commands use this same UUID. Direct dispatch return values are unchanged.

<a name="bulk-dispatch-and-batches"></a>
### Bulk Dispatch and Batches

Use the existing bulk API to stage several jobs efficiently:

```php
use Hypervel\Support\Facades\Bus;

Bus::bulk([
    (new SendOrderReceipt($firstOrder))->viaOutbox(),
    (new SendOrderReceipt($secondOrder))->viaOutbox(),
]);
```

Within each queue bulk call, jobs sharing an outbox database are inserted together, using additional insert chunks only when the database's parameter limits require them. Each database group is atomic across those chunks. `Bus::bulk` keeps its existing grouping by queue connection and destination. A bulk containing direct jobs or several database owners is not one atomic unit, and publication order between its groups is not guaranteed.

You may also select the outbox for a batch:

```php
$batch = Bus::batch($jobs)
    ->onConnection('redis')
    ->viaOutbox()
    ->dispatch();

$batch->add($moreJobs);
```

Batch defaults apply to newly added jobs too, without replacing an explicit choice on an individual job. Batch counters and outbox rows participate in the batch repository's transaction; conflicting outbox ownership is rejected. Jobs using only `Batchable`, rather than `Queueable`, can receive batch defaults.

Chains retain their normal behavior. Selecting the outbox for the first job does not automatically select it for later jobs. Each later job is dispatched after its predecessor succeeds and uses its own selection or its queue connection's default.

<a name="queued-producers"></a>
### Queued Listeners, Mail, Notifications and Broadcasts

Queued listeners, mailables, notifications and broadcast events propagate their outbox choice to their existing wrapper jobs. Implement `ShouldQueueViaOutbox` on the producer, or use its `Queueable` methods when constructing it:

```php
use App\Mail\OrderReceipt;
use Hypervel\Support\Facades\Mail;

Mail::to($customer)->queue(
    (new OrderReceipt($order))->viaOutbox()
);
```

For queued listeners, use the marker or a public property default: the dispatcher reads these defaults without constructing the listener. Do not rely on a listener constructor calling `viaOutbox`. When a listener uses `Queueable`, prefer the marker instead of redeclaring that trait's properties.

Dispatch the event while the business transaction is open. An event implementing `ShouldDispatchAfterCommit` postpones the entire event, including listener and broadcast queueing, until after commit. Adding outbox selection cannot make that later staging atomic with the earlier transaction. Select the outbox on the queued listener or broadcast event instead of deferring the whole event when you need transactional delivery.

<a name="database-transactions"></a>
## Database Transactions

When one database connection has an active transaction, Hypervel stores the outbox row on that connection. Without an active transaction, it uses the first configured outbox database. You may select an owner explicitly:

```php
SendOrderReceipt::dispatch($order)
    ->onOutboxDatabase('billing');
```

`onOutboxDatabase` also selects the outbox. The database must be included in `queue.outbox.databases`. If transactions are open on several connections, an explicit owner is required. Hypervel reports the active connections rather than guessing which business write belongs to the job.

An explicitly selected owner must have an active transaction when other connections have active transactions. Only writes on that selected transaction are atomic with the job. Nested transactions on one connection remain one owner, and rolling back a savepoint also removes its outbox rows and releases their dispatch-owned locks.

Ownership follows the resolved database session. For example, a `mysql::write` transaction is distinct from a `mysql` transaction when they use separate sessions. Select the exact owner when necessary, but configure only `mysql` as the relay source: do not list both names for the same outbox table. Read-role connections cannot own SQL outbox storage; use their base or write connection.

Calling `withoutOutbox` clears an explicit database choice. Calling `viaOutbox` afterwards returns to normal owner selection.

> [!WARNING]
> The outbox cannot make writes to different databases atomic with one another. It also cannot include an HTTP request, payment, email or filesystem write in the database transaction. Perform those effects in the queued job with suitable idempotency handling.

<a name="queue-connections-and-delays"></a>
## Queue Connections and Delays

Selecting the outbox does not require environment-specific job code when you switch queue drivers:

| Queue connection | Behavior when the outbox is selected |
| --- | --- |
| Redis, SQS, Beanstalkd | Store the job in the outbox, then relay it to the queue. |
| Database, using the active owning connection | Insert directly into the jobs table inside that transaction; a second durable table adds no guarantee. |
| Database, with no active transaction | Insert directly into the durable jobs table. |
| Database, on another connection during a business transaction | Store the job on the business transaction's outbox, then relay it to the database queue. |
| Sync, background, deferred | Preserve local execution behavior after the selected transaction commits. These drivers do not gain crash durability. |
| Failover | Defer the complete failover attempt until the applicable transactions commit. Its normal child-driver attempts remain non-durable across a process crash. |
| Null | Discard the job normally without creating an outbox row. |
| Queue or Bus fake | Record the job only in the fake, without accessing an outbox table. |

`dispatchSync` and after-response dispatch retain their existing local or deferred behavior. They are not substitutes for durable queued dispatch. Direct `pushRaw` calls also bypass outbox staging, even on a connection whose default enables it.

Jobs keep their intended delivery time when the relay runs late or retries publication. Redis and database queues can receive delayed jobs immediately and hold them until due. Standard SQS jobs wait in the outbox until they fit its per-message delay window. SQS FIFO jobs wait until due before publication, without a per-message timer; the queue's own configured delay still applies. On standard SQS, omitting a job delay preserves the queue-level default, while an expired explicitly requested delay is sent as zero.

The outbox captures the selected queue connection and logical queue name, including its default, at dispatch. Queue-name forwarding is applied once when publication occurs, using the current forwarding rules. Changing a connection's default queue does not reroute existing rows.

The relay uses the recorded connection's current configuration. Drain pending rows before changing a connection to a non-durable driver, changing between incompatible SQS destination types, or otherwise invalidating its stored transport options.

<a name="running-the-relay"></a>
## Running the Relay

Run `queue:relay` under a process supervisor, just like `queue:work`. It is a dedicated CLI process which uses coroutines internally; it does not run as a job on the queue it is responsible for recovering.

```shell
php artisan queue:relay --batch=100 --concurrency=4
```

By default, it visits all configured outbox databases in turn. Pass a database name to dedicate a process to one source:

```shell
php artisan queue:relay billing
```

The relay supports the following options:

| Option | Default | Purpose |
| --- | --- | --- |
| `--batch` | `100` | Maximum rows per claim, from 1 to 1,000. |
| `--concurrency` | `1` | Maximum batches being delivered concurrently. |
| `--sleep` | `1` | Positive seconds to wait after an empty pass or delivery failure; fractions are supported. |
| `--max-jobs` | `0` | Stop after claiming this many rows; zero means unlimited. |
| `--max-time` | `0` | Stop admitting work after this many seconds; zero means unlimited. |
| `--memory` | `128` | Memory limit in megabytes. |
| `--stop-when-empty` | Off | Stop after a complete pass finds no eligible rows, then finish admitted work. |
| `--once` | Off | Process at most one batch from each selected database, without waiting between passes. |

`--stop-when-empty` does not wait for delayed or backed-off rows to become eligible. Sources which fail or are cooling down count as empty for that pass. `--max-jobs` counts claimed rows, not successfully delivered jobs. `--once` runs synchronously and does not use concurrent delivery.

<a name="concurrency-and-shutdown"></a>
### Concurrency and Shutdown

You may run several relay processes against the same outbox. Claims use short database operations and temporary ownership tokens, so one relay cannot settle rows reclaimed by another. Database connections and locks are released before broker publication. SQLite remains correct, but its serialized writes limit the benefit of adding relay processes.

Size database and transport pools for your relay concurrency, allowing an additional short-lived connection when restart-cache reads or application listeners use the same pool. A relay process has its own pools; increasing its concurrency does not increase the capacity of another process's pools.

The `queue.outbox.retry_after` setting is the claim lifetime. Set it above the longest expected lifetime of a claimed batch, including every destination group, network timeouts and SDK retries. A claim which outlives this value may be published again by another relay. There is no lease heartbeat.

The relay observes `queue:restart` through the same configured cache store as ordinary queue workers. On restart or graceful shutdown, it stops claiming and finishes admitted work. `SIGTERM`, `SIGINT` and `SIGQUIT` request shutdown; `SIGUSR2` pauses admission and `SIGCONT` resumes it. Configure your supervisor's stop allowance to let active calls and settlement finish.

Maintenance mode does not pause the relay. It only moves already committed jobs to their queues; your normal workers control whether those jobs execute.

<a name="failures-and-recovery"></a>
### Failures and Recovery

The relay removes rows after confirmed queue acceptance. Failed or uncertain publications remain retryable, with increasing, jittered backoff capped at ten minutes. There is no automatic maximum-attempt cutoff: a committed business action is not silently abandoned because its queue was unavailable overnight.

Failed sources cool down without preventing healthy sources from being visited. A failed delivery batch also waits for `--sleep` before releasing its concurrency slot, limiting repeated attempts against an unavailable destination while new rows remain in the backlog.

Exceptions include the source database, queue connection, row IDs, job UUIDs and display names. Delivery observer failures are reported without republishing accepted work or throttling healthy delivery. If exception reporting itself fails, the error reaches the command rather than being hidden.

With SQS payload overflow enabled, separate relay attempts use separate payload pointers, so cleanup from one attempt cannot delete another attempt's payload. An uncertain SQS response may leave an offloaded payload behind, as it can during ordinary dispatch. Account for those objects in your cache/storage retention policy; the outbox does not change the existing overflow cleanup settings.

<a name="managing-messages"></a>
## Inspecting and Managing Messages

List pending messages or select one database:

```shell
php artisan queue:outbox --limit=100
php artisan queue:outbox billing --json
```

The listing includes job UUIDs, destinations, attempts, availability and the last publication error. Tables show UTC dates and abbreviated errors; JSON retains epoch values and complete stored errors. Payload bodies and lock-owner tokens are not displayed.

Monitor backlog size and the age of eligible work:

```shell
php artisan queue:outbox-monitor --max=10000 --age=60
```

The command reports eligible, delayed, leased and retrying rows. `--max` and `--age` are optional alert thresholds; reaching either emits `Hypervel\Queue\Events\OutboxBusy` when a listener is registered. Eligible age excludes time a job intentionally waits for its delivery date. Use `--json` for external monitoring.

To retry attempted messages immediately without resetting their attempt history:

```shell
php artisan queue:outbox-retry JOB_UUID
php artisan queue:outbox-retry all --database=billing
```

Retry refuses live leases and never-attempted delayed jobs. When the same UUID exists on several sources, specify `--database` rather than relying on a guess.

To deliberately discard a message:

```shell
php artisan queue:outbox-forget JOB_UUID --database=billing
```

This command asks for confirmation in every environment. For unattended use, `--force` skips the prompt but never bypasses live-lease protection: an already running publication cannot be recalled.

After deleting the row, Hypervel releases its unique-job lock only when it can resolve the recorded cache store and exact owner. If cleanup fails, the command explicitly reports that the row was deleted but its lock could not be released. Custom or context-dependent cache integrations may override the command's protected `releaseUniqueLock` method to establish their namespace or resolve their repository. The command does not deserialize the queued job or hydrate arbitrary application context.

Horizon displays Redis jobs after they reach Redis, not while they remain in the SQL outbox. The [OpenTelemetry integration](/docs/{{version}}/opentelemetry#queues) observes relay delivery counts, batch duration and eligible-message age without polling the outbox or creating a second producer span.

<a name="delivery-guarantees"></a>
## Delivery Guarantees

Delivery is **at least once**, not exactly once. If the queue accepts a message and the relay stops before deleting its outbox row, recovery can publish the same payload again. Its job UUID stays the same, but the outbox does not deduplicate processing or external effects. Make handlers idempotent where repeating an action would be harmful, using database constraints or a stable operation identifier supported by the external service.

Publication order is best effort. Concurrent claims, delays and retries can change it. SQS FIFO orders messages by arrival within a message group, not by the order transactions originally staged them.

Successful rows are deleted rather than archived. The outbox records pending delivery, not a business audit trail or a list of failed job executions. Once a worker receives a job, ordinary queue retry and failed-job behavior applies.

<a name="custom-queue-drivers"></a>
## Custom Queue Drivers

Drivers which use the base queue's enqueue lifecycle can opt into staging without changing their public dispatch APIs. Overrides which bypass that lifecycle remain direct. The protected `resolveOutboxQueue`, `preparePayloadForOutbox`, `prepareOutboxOptions` and `availableAtForOutbox` hooks let a driver capture its logical destination, stored payload, transport options and initial eligibility while the original job is available.

By default, the relay waits until a message is due and publishes it through the driver's existing `pushRaw` method. It does not call Bus or serialize the job again. A pooled driver is borrowed once for the publication batch.

Drivers with a more efficient batch operation may implement `Hypervel\Contracts\Queue\PublishesOutboxMessages`. Its `publishOutboxMessages` method receives immutable `Hypervel\Queue\Outbox\OutboxMessage` values and returns an `OutboxBatchResult` containing confirmed accepted row IDs, deferred row IDs, and an optional failure. IDs refer to outbox rows, not broker identifiers; array keys are ignored. Accepted and deferred IDs must be unique, disjoint members of the input. A result without a failure must account for every input row. Only messages with an intended delivery time may be deferred.

Report confirmed partial acceptance before returning a publication error. Keep uncertain outcomes retryable, and propagate coroutine cancellation. Do not emit the ordinary enqueue lifecycle again: it completed when the job was accepted into the outbox.
