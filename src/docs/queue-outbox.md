# Transactional Queue Outbox

- [Introduction](#introduction)
- [Setup](#setup)
- [Dispatching Jobs](#dispatching-jobs)
    - [Selecting the Outbox](#selecting-the-outbox)
    - [Bulk Dispatch and Batches](#bulk-dispatch-and-batches)
    - [Queued Listeners, Mail, Notifications, and Broadcasts](#queued-producers)
- [Database Transactions](#database-transactions)
- [Queue Connections and Delays](#queue-connections-and-delays)
    - [Delayed Jobs](#delayed-jobs)
    - [Changing Queue Configuration](#changing-queue-configuration)
- [Running the Relay](#running-the-relay)
    - [Concurrency and Shutdown](#concurrency-and-shutdown)
    - [Failures and Recovery](#failures-and-recovery)
- [Inspecting and Managing Messages](#managing-messages)
    - [Monitoring the Outbox](#monitoring-the-outbox)
    - [Retrying Messages](#retrying-messages)
    - [Deleting Messages](#deleting-messages)
- [Delivery Guarantees](#delivery-guarantees)
- [Custom Queue Drivers](#custom-queue-drivers)

<a name="introduction"></a>
## Introduction

When you dispatch a job [after a database transaction commits](/docs/{{version}}/queues#jobs-and-database-transactions), queue workers will not process it before the transaction completes. However, your application could stop after committing the transaction but before sending the job to the queue. In that case, your database changes would be saved, but the job would be lost.

Hypervel's transactional outbox allows you to store a job as part of the same transaction as your database changes. A separate process, called the relay, sends the stored job to your queue after the transaction commits. If the transaction rolls back, the job is discarded as well. If the queue is unavailable, the job remains in the outbox until delivery succeeds.

For example, you may call `viaOutbox` when dispatching a job inside a transaction:

```php
use App\Jobs\SendOrderReceipt;
use App\Models\Order;
use Hypervel\Support\Facades\DB;

DB::transaction(function () use ($attributes) {
    $order = Order::create($attributes);

    SendOrderReceipt::dispatch($order)->viaOutbox();
});
```

The outbox works with your existing jobs, queue connections, and workers. It does not require Horizon. Jobs that do not use the outbox are dispatched normally without accessing the outbox table.

<a name="setup"></a>
## Setup

You may configure the outbox in your application's `config/queue.php` configuration file:

```php
'outbox' => [
    'databases' => [
        env('QUEUE_OUTBOX_DATABASE', env('DB_CONNECTION', 'sqlite')),
    ],
    'table' => env('QUEUE_OUTBOX_TABLE', 'job_outbox'),
    'retry_after' => (int) env('QUEUE_OUTBOX_RETRY_AFTER', 300),
],
```

The `databases` option contains the names of the database connections the relay should read from. Each database needs an outbox table. New Hypervel applications include a migration for this table. If your application does not have this migration, you may create it using the `make:queue-outbox-table` Artisan command:

```shell
php artisan make:queue-outbox-table
php artisan migrate
```

The generated migration uses the table name from your outbox configuration. You may also use the `queue:outbox-table` alias to generate it. If you use several outbox databases, run the migration on each connection.

Finally, run the relay alongside your normal queue workers:

```shell
php artisan queue:relay
php artisan queue:work redis
```

The relay sends jobs to the queue; your queue workers still process them. If you use Horizon, it may continue managing your Redis workers as usual.

<a name="dispatching-jobs"></a>
## Dispatching Jobs

<a name="selecting-the-outbox"></a>
### Selecting the Outbox

To dispatch a job through the outbox, you may chain the `viaOutbox` method onto your dispatch operation:

```php
SendOrderReceipt::dispatch($order)->viaOutbox();
```

If a job should always use the outbox, you may implement the `ShouldQueueViaOutbox` interface on its class. This interface extends `ShouldQueue`, so you do not need to implement both:

```php
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Foundation\Queue\Queueable;

class SendOrderReceipt implements ShouldQueueViaOutbox
{
    use Queueable;

    // ...
}
```

Alternatively, you may set the `outbox` option to `true` on a queue connection in your `config/queue.php` file. This enables the outbox for jobs dispatched to that connection. When this option is omitted, it defaults to `false`.

You may override either default for a specific dispatch:

```php
SendOrderReceipt::dispatch($order)->withoutOutbox();
```

Calling `viaOutbox` or `withoutOutbox` overrides both the job's interface and the connection's configuration. Otherwise, a job uses the outbox if it implements `ShouldQueueViaOutbox` or its queue connection enables the `outbox` option.

The `afterCommit` and `beforeCommit` methods do not disable the outbox. When a job uses the outbox, it is stored within the database transaction and cannot be delivered until that transaction commits.

The `Queueable` and `Batchable` traits provide the outbox methods and properties. If your class uses either trait, use `ShouldQueueViaOutbox` for its default behavior rather than redeclaring the trait's properties with default values, which PHP does not allow. Classes without these properties may declare a public `viaOutbox` property with a default of `true` or `false`. Unlike `viaConnection` or `viaQueues`, `viaOutbox` configures the object when called; it is not a method that you implement to return a setting.

If you push a job using a queue method such as `Queue::push` and the job is stored in the outbox, the method returns the job's UUID instead of a queue-assigned ID. You may use this UUID with the [outbox commands](#managing-messages). Dispatches that bypass the outbox retain their usual return values.

<a name="bulk-dispatch-and-batches"></a>
### Bulk Dispatch and Batches

To store several jobs in the outbox, you may use the `bulk` method on the `Bus` facade:

```php
use Hypervel\Support\Facades\Bus;

Bus::bulk([
    (new SendOrderReceipt($firstOrder))->viaOutbox(),
    (new SendOrderReceipt($secondOrder))->viaOutbox(),
]);
```

Hypervel groups bulk jobs by queue connection and queue name. Within each group, jobs using the same outbox database are inserted together. Large inserts are split to respect the database's parameter limit, but all inserts for that database group succeed or roll back together. Jobs sent directly to a queue or stored in different database groups do not share this guarantee, and may be delivered in a different order.

You may also select the outbox for a batch:

```php
$batch = Bus::batch($jobs)
    ->onConnection('redis')
    ->viaOutbox()
    ->dispatch();

$batch->add($moreJobs);
```

The batch's outbox settings also apply to jobs added later, unless a job has explicitly configured its own settings. With database batch storage, outbox jobs are stored in your batch database in the same transaction as the batch counters. Include this database in `queue.outbox.databases`. If a job selects a different outbox database, Hypervel throws an exception before updating the batch. Jobs only need the `Batchable` trait to receive these settings; `Queueable` is not required.

Chains retain their normal behavior. Selecting the outbox for the first job does not automatically select it for later jobs. Batch outbox defaults likewise apply only to the first job of each chain. Each later job is dispatched after its predecessor succeeds and uses its own selection or its queue connection's default.

<a name="queued-producers"></a>
### Queued Listeners, Mail, Notifications, and Broadcasts

You may also use the outbox with queued listeners, mailables, notifications, and broadcast events. Implement `ShouldQueueViaOutbox` on the class, or call `viaOutbox` if it uses the `Queueable` trait. For example, you may queue a mailable through the outbox:

```php
use App\Mail\OrderReceipt;
use Hypervel\Support\Facades\Mail;

Mail::to($customer)->queue(
    (new OrderReceipt($order))->viaOutbox()
);
```

For queued listeners, implement `ShouldQueueViaOutbox` instead of calling `viaOutbox` in the constructor. Hypervel reads the listener's settings without constructing it. A listener that does not use the outbox properties from `Queueable` or `Batchable` may also define a public property default, as described [above](#selecting-the-outbox).

Dispatch events inside your database transaction so their queued listeners and broadcasts can be stored with your database changes.

> [!WARNING]
> An event implementing `ShouldDispatchAfterCommit` is not dispatched until the transaction commits. Its listener and broadcast jobs therefore cannot be stored as part of that transaction. To use the outbox for these jobs, implement `ShouldQueueViaOutbox` on the listener or broadcast event instead of deferring the entire event.

<a name="database-transactions"></a>
## Database Transactions

For queues that store jobs in the outbox, Hypervel uses the connection with the active transaction. Without an active transaction, it uses the first database in your outbox configuration. Whichever database stores the job must be listed in `queue.outbox.databases`. You may select a database using the `onOutboxDatabase` method:

```php
SendOrderReceipt::dispatch($order)
    ->onOutboxDatabase('billing');
```

Calling `onOutboxDatabase` also enables the outbox for the job. If transactions are open on several connections, you must select one explicitly. Otherwise, Hypervel throws an exception listing the active connections.

If any database transactions are open, the selected connection must have an active transaction. Only changes made in that transaction are committed or rolled back with the job. Nested transactions on the same connection are supported; rolling back a savepoint also removes its outbox jobs and releases the unique and debounce locks acquired by those dispatches.

Use the connection that owns your transaction. For example, `mysql` and `mysql::write` can use separate database sessions with independent transactions. You may select `mysql::write` with `onOutboxDatabase`, but list only `mysql` in `queue.outbox.databases` so the relay reads the table once. Read connections such as `mysql::read` cannot store outbox jobs; use the base or write connection instead.

Calling `withoutOutbox` clears an explicit database choice. Calling `viaOutbox` afterwards lets Hypervel select the database using the rules above.

> [!WARNING]
> The outbox cannot combine changes to different databases into one transaction. It also cannot include an HTTP request, payment, email, or filesystem write in your database transaction. Perform these operations in the queued job, taking care to [handle duplicate delivery](#delivery-guarantees).

<a name="queue-connections-and-delays"></a>
## Queue Connections and Delays

You may switch queue drivers without removing `viaOutbox` calls from your jobs. Hypervel handles each driver as follows:

| Queue connection | Behavior when the outbox is selected |
| --- | --- |
| Redis, SQS, Beanstalkd | Store the job in the outbox, then relay it to the queue. |
| Database, using the active transaction's connection | Insert directly into the jobs table as part of that transaction. |
| Database, with no active transaction | Insert directly into the durable jobs table. |
| Database, on another connection during a business transaction | Store the job on the business transaction's outbox, then relay it to the database queue. |
| Sync, background, deferred | Wait for the current transaction to commit, or the transaction selected with `onOutboxDatabase`, then use the driver's normal execution behavior. Without a transaction, behave normally. Jobs are not stored for recovery after a process stops. |
| Failover | Wait for the applicable transactions to commit, then attempt normal failover delivery. The attempt is not stored for recovery after a process stops. |
| Null | Discard the job normally without creating an outbox row. |
| Queue or Bus fake | Record the job only in the fake, without accessing an outbox table. |

The `dispatchSync` and after-response dispatch methods retain their existing behavior; they do not store jobs in the outbox. Calling `pushRaw` also bypasses the outbox, even when the connection's configuration enables it.

<a name="delayed-jobs"></a>
### Delayed Jobs

You may use the usual `delay` method when dispatching through the outbox:

```php
SendOrderReceipt::dispatch($order)->viaOutbox()->delay(now()->plus(minutes: 10));
```

Retrying delivery does not restart the job's delay. Redis and database queues can receive delayed jobs immediately and hold them until they are due. With standard SQS queues, the relay waits until the job is within SQS's per-message delay limit. With SQS FIFO queues, it waits until the job is due before sending it, since FIFO queues do not support per-message delays.

Any delay configured on an SQS FIFO queue still applies after the relay sends the job. For standard SQS queues, omitting a job delay preserves the queue's default delay. An explicitly requested job delay overrides that default, including when the job is already due.

<a name="changing-queue-configuration"></a>
### Changing Queue Configuration

The outbox stores the job's queue connection and queue name when you dispatch it. If you did not specify a queue name, it stores the connection's default. Changing that default later does not move pending jobs to another queue. Queue-name forwarding, however, uses the rules in effect when the relay sends the job.

The relay uses the connection's current configuration. Allow pending jobs to leave the outbox before switching to a driver that does not persist jobs, changing between standard and FIFO SQS queues, or making other changes that would invalidate the stored queue options.

<a name="running-the-relay"></a>
## Running the Relay

The `queue:relay` Artisan command sends committed outbox jobs to their queues. Like `queue:work`, it runs as a long-lived process and should be managed by a process supervisor. You may configure its batch size and concurrency when starting it:

```shell
php artisan queue:relay --batch=100 --concurrency=4
```

By default, the relay reads from each configured outbox database in turn. To run a relay for a single database, pass its connection name to the command:

```shell
php artisan queue:relay billing
```

The relay supports the following options:

| Option | Default | Purpose |
| --- | --- | --- |
| `--batch` | `100` | Maximum messages to take from the outbox at once, from 1 to 1,000. |
| `--concurrency` | `1` | Maximum batches being delivered concurrently. |
| `--sleep` | `1` | Seconds to wait after finding no work or after a delivery failure. Must be positive; fractions are supported. |
| `--max-jobs` | `0` | Stop after taking this many messages from the outbox; zero means unlimited. |
| `--max-time` | `0` | Stop taking new work after this many seconds; zero means unlimited. |
| `--memory` | `128` | Memory limit in megabytes, or a percentage of PHP's finite `memory_limit` (for example, `60%`). |
| `--stop-when-empty` | Off | Stop when no selected database has messages ready for delivery, after finishing work already started. |
| `--once` | Off | Process at most one batch from each selected database, without sleeping. |

The `--stop-when-empty` option does not wait for delayed messages or scheduled retries. A database that cannot be read, or is waiting to be retried, counts as empty for that pass. The `--max-jobs` option counts messages taken for delivery, including failed attempts. When using `--once`, batches are delivered one at a time regardless of the concurrency setting.

<a name="concurrency-and-shutdown"></a>
### Concurrency and Shutdown

You may run several relay processes against the same outbox. Within each process, the `--concurrency` option controls how many batches may be delivered at once using coroutines. The relay reserves messages in a short database operation, then releases its database connections and locks before contacting the queue. If another relay takes over an expired reservation, the original relay can no longer delete those records. SQLite supports this behavior, but adding relays does not allow it to perform concurrent writes.

Your database and queue connection pools should have enough connections for the configured concurrency. Allow an additional connection if restart checks or event listeners use the same pool. Each relay process has its own pools; increasing concurrency does not increase their size.

The `queue.outbox.retry_after` option controls how many seconds a batch remains reserved for one relay. Set it longer than the longest expected delivery attempt for an entire batch, including all queue connections, network timeouts, and SDK retries. Reservations are not extended while delivery is running. If a reservation expires, another relay may deliver the same messages.

You may gracefully restart relays using the `queue:restart` command. Relays read this signal from the same configured cache store as ordinary queue workers. On restart or shutdown, a relay stops taking new work and finishes the batches it has already started.

The `SIGTERM`, `SIGINT`, and `SIGQUIT` signals request shutdown. `SIGUSR2` pauses new work, and `SIGCONT` resumes it. Configure your process supervisor to allow enough time for active deliveries and their database updates to finish before forcibly stopping the relay.

Maintenance mode does not pause the relay. It only moves already committed jobs to their queues; your normal workers control whether those jobs execute.

<a name="failures-and-recovery"></a>
### Failures and Recovery

Once a queue confirms that it has accepted a job, the relay deletes the outbox record. If delivery fails or its outcome is unknown, the record remains available for another attempt. The delay between attempts increases up to ten minutes, with some random variation to avoid retrying all failed deliveries at once. Retries continue until delivery succeeds or you delete the message; there is no maximum attempt limit.

If the relay cannot read a database, it skips that database for the `--sleep` interval and continues reading the others. After a batch fails delivery, it occupies its concurrency slot for the `--sleep` interval before another batch can take its place. This limits repeated attempts against an unavailable queue even when many new messages are waiting.

Delivery errors are reported through your application's exception handler with the database, queue connection, row IDs, job UUIDs, and job names. If a delivery event listener fails, its error is reported without sending accepted jobs again or delaying successful deliveries. If the exception handler itself fails, the error is allowed to stop the command.

When using SQS payload overflow, each relay attempt stores its payload separately so that cleanup from one attempt does not remove another attempt's data. If SQS does not return a clear result, the stored payload may remain after the attempt, just as with ordinary dispatch. Account for these payloads in your storage retention policy; the outbox does not change your existing overflow cleanup settings.

<a name="managing-messages"></a>
## Inspecting and Managing Messages

You may inspect pending outbox messages using the `queue:outbox` Artisan command. By default, it lists messages from all configured outbox databases. You may pass a database name to inspect only that connection:

```shell
php artisan queue:outbox --limit=100
php artisan queue:outbox billing --json
```

The listing includes each job's UUID, destination, attempt count, next delivery time, and last delivery error. Table output displays UTC dates and abbreviated errors. The `--json` option returns Unix timestamps and complete stored errors instead. Neither format displays job payloads or lock-owner tokens.

<a name="monitoring-the-outbox"></a>
### Monitoring the Outbox

To monitor the number of pending messages and how long they have been waiting for delivery, you may use the `queue:outbox-monitor` command:

```shell
php artisan queue:outbox-monitor --max=10000 --age=60
```

The command reports messages ready for delivery, delayed messages, messages reserved by a relay, and messages waiting to retry. The optional `--max` and `--age` options set alert thresholds for the total pending count and oldest ready message's age in seconds. When either threshold is reached, Hypervel dispatches a `Hypervel\Queue\Events\OutboxBusy` event to registered listeners. Time spent waiting for an intentional delay is not included in the ready message's age. You may use `--json` to read these statistics from an external monitoring tool.

Horizon displays Redis jobs after they reach Redis, not while they are waiting in the outbox. The [OpenTelemetry integration](/docs/{{version}}/opentelemetry#queues) provides delivery counts, batch durations, and waiting times without polling the outbox or creating another producer span.

<a name="retrying-messages"></a>
### Retrying Messages

If you would like to retry messages before their next scheduled attempt, you may pass one or more UUIDs to the `queue:outbox-retry` command. This clears the last error but preserves the attempt count:

```shell
php artisan queue:outbox-retry JOB_UUID
php artisan queue:outbox-retry all --database=billing
```

The `all` argument retries all previously attempted messages. You may limit it to one database using `--database`. If you are retrying a UUID that exists on more than one database, this option is required.

Only messages that have already been attempted can be retried. Messages currently reserved by a relay cannot be retried manually.

<a name="deleting-messages"></a>
### Deleting Messages

To remove a message that should no longer be delivered, pass its UUID to the `queue:outbox-forget` command:

```shell
php artisan queue:outbox-forget JOB_UUID --database=billing
```

This command asks for confirmation in every environment. You may pass `--force` to skip the prompt when running it unattended. Messages currently reserved by a relay cannot be deleted, even with `--force`, since an active delivery cannot be recalled.

After deleting the record, Hypervel attempts to release the unique-job lock using its recorded cache store and lock owner. If the lock cannot be released, the command reports the failure, but the message remains deleted. For custom cache stores or context-dependent cache namespaces, you may extend `Hypervel\Queue\Console\ForgetOutboxCommand` and override its protected `releaseUniqueLock` method. The command reads only the stored lock metadata; it does not deserialize the job or restore arbitrary application context.

<a name="delivery-guarantees"></a>
## Delivery Guarantees

The outbox provides at-least-once delivery, which means a job may reach the queue more than once. For example, the relay could stop after the queue accepts a job but before its outbox record is deleted. When delivery resumes, the same job is sent again with the same UUID.

Your jobs should therefore be safe to process more than once. For example, you may use a unique database constraint to prevent duplicate records, or pass a stable operation identifier to a payment provider that supports idempotent requests. The outbox does not detect duplicate processing or undo external actions.

Jobs are not guaranteed to reach the queue in dispatch order. Concurrent relays, delays, and retries can change that order. SQS FIFO preserves arrival order within a message group, not the order in which your transactions stored jobs in the outbox.

Successful rows are deleted rather than archived. The outbox records pending delivery, not a business audit trail or a list of failed job executions. Once a worker receives a job, ordinary queue retry and failed-job behavior applies.

<a name="custom-queue-drivers"></a>
## Custom Queue Drivers

If you maintain a custom queue driver, its dispatch methods must use the base `Queue` class's enqueue methods to support the outbox. Methods that bypass this behavior continue sending jobs directly. You may customize outbox storage using the protected `resolveOutboxQueue`, `preparePayloadForOutbox`, `prepareOutboxOptions`, and `availableAtForOutbox` methods. These methods determine the queue name, stored payload, queue-specific options, and earliest delivery time while the original job is available.

By default, the relay waits until a message is due and sends its stored payload through your driver's `pushRaw` method. It does not dispatch through the `Bus` facade or serialize the job again. If your driver uses a connection pool, the relay borrows one connection for the whole delivery batch.

If your driver supports more efficient bulk delivery, you may implement `Hypervel\Contracts\Queue\PublishesOutboxMessages`. Its `publishOutboxMessages` method receives an array of immutable `Hypervel\Queue\Outbox\OutboxMessage` objects and returns a `Hypervel\Queue\Outbox\OutboxBatchResult`. The result contains the IDs of accepted messages, the IDs of messages deferred until their delivery time, and an optional failure.

These IDs refer to outbox rows, not identifiers assigned by the queue. Array keys are ignored. Each ID must belong to an input message and appear only once across the accepted and deferred lists. A result without a failure must account for every message. Only messages with an intended delivery time may be deferred.

If only part of a batch succeeds, include the confirmed messages in the result alongside the error. Do not report a message as accepted if its outcome is unknown. Allow coroutine cancellation to propagate, and do not dispatch ordinary enqueue events again; those events were already dispatched when the job entered the outbox.
