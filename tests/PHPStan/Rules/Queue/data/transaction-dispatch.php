<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;

use function dispatch;

class NotifyOwner implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public int $productId)
    {
    }
}

class NotifyOwnerAfterCommit implements ShouldQueue
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(public int $productId)
    {
    }
}

class PlainDispatchable
{
    use Dispatchable;
}

DB::transaction(static function (): void {
    NotifyOwner::dispatch(1);
});

DB::transaction(static function (): void {
    dispatch(new NotifyOwner(1));
});

DB::transaction(static function (): void {
    NotifyOwner::dispatch(1)->onQueue('default');
});

DB::transaction(static fn (): mixed => NotifyOwner::dispatch(1));

// Not flagged: the dispatch is deferred explicitly.
DB::transaction(static function (): void {
    NotifyOwner::dispatch(1)->afterCommit();
});

DB::transaction(static function (): void {
    NotifyOwner::dispatch(1)->onQueue('default')->afterCommit();
});

// Not flagged: the job opts in for every dispatch.
DB::transaction(static function (): void {
    NotifyOwnerAfterCommit::dispatch(1);
});

// Not flagged: synchronous dispatch runs inline, inside the transaction.
DB::transaction(static function (): void {
    NotifyOwner::dispatchSync(1);
});

// Hypervel: unlike upstream, Bus::dispatch() is flagged: it queues the job the same way.
DB::transaction(static function (): void {
    Bus::dispatch(new NotifyOwner(1));
});

// Not flagged: a non queued dispatchable runs synchronously.
DB::transaction(static function (): void {
    PlainDispatchable::dispatch();
});

// Not flagged: outside any transaction.
NotifyOwner::dispatch(1);

class NotifyOwnerUsingAfterCommitContract implements ShouldQueueAfterCommit
{
    use Dispatchable;
}

class QueueableNotifyOwner implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
}

class QueueableNotifyOwnerAfterCommit extends QueueableNotifyOwner
{
    public ?bool $afterCommit = true;
}

DB::transaction(static function (): void {
    NotifyOwnerUsingAfterCommitContract::dispatch(1);
    dispatch(new NotifyOwnerUsingAfterCommitContract());
});

// Hypervel: unlike upstream, a final beforeCommit() is not flagged: it is a supported way to
// dispatch inside the transaction.
DB::transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->afterCommit()->beforeCommit();
    QueueableNotifyOwner::dispatch()->beforeCommit()->afterCommit();
    QueueableNotifyOwnerAfterCommit::dispatch()->beforeCommit();
    QueueableNotifyOwnerAfterCommit::dispatch();
});

// Hypervel: the connection and driver decide whether the queue waits for the commit.
#[Connection('transaction-database')]
class DatabaseQueuedJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
}

DB::connection('mysql')->transaction(static function (): void {
    DatabaseQueuedJob::dispatch();
});

DB::connection('pgsql::write')->transaction(static function (): void {
    DatabaseQueuedJob::dispatch();
});

// Not flagged: the job is inserted inside the transaction on the queue's own connection.
DB::connection('pgsql')->transaction(static function (): void {
    DatabaseQueuedJob::dispatch();
});

// Not flagged: role aliases of an SQLite connection may share one in-memory database.
DB::connection('sqlite::write')->transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->onConnection('transaction-sqlite');
});

// Not flagged: the connection waits for the commit, by its configuration or its driver's default.
DB::transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->onConnection('redis');
    QueueableNotifyOwner::dispatch()->onConnection('transaction-redis-default');
});

// Not flagged: synchronous, failover and environment-dependent connections.
DB::transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->onConnection('sync');
    QueueableNotifyOwner::dispatch()->onConnection('failover');
});

DB::connection('mysql')->transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->onConnection('database');
});

// The outbox stages the job inside the transaction. A dispatch that opts out is flagged.
class OutboxJob implements ShouldQueueViaOutbox
{
    use Dispatchable;
    use Queueable;
}

DB::transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->viaOutbox();
    QueueableNotifyOwner::dispatch()->onOutboxDatabase('pgsql');
    QueueableNotifyOwner::dispatch()->onConnection('transaction-outbox');
    OutboxJob::dispatch();
    QueueableNotifyOwner::dispatch()->onConnection('transaction-outbox')->withoutOutbox();
});

// afterResponse() runs the job synchronously once the response is sent; afterResponse(false) queues it.
DB::transaction(static function (): void {
    QueueableNotifyOwner::dispatch()->afterResponse();
    QueueableNotifyOwner::dispatch()->afterResponse(false);
});

// Not flagged: a callback run after the commit, and a job the call does not name.
DB::transaction(static function (): void {
    DB::afterCommit(static fn (): mixed => NotifyOwner::dispatch(1));
    $job = new NotifyOwner(1);
    dispatch($job);
});

// The helper queues a closure as a job of its own.
DB::transaction(static function (): void {
    dispatch(static function (): void {
    });
});

// A nested transaction is checked from its own call, where the database queue may share the outer one.
DB::connection('mysql')->transaction(static function (): void {
    DB::connection('mysql')->transaction(static function (): void {
        DatabaseQueuedJob::dispatch();
        NotifyOwner::dispatch(1);
    });
});

DB::afterCommit(static function (): void {
    DB::connection('mysql')->transaction(static function (): void {
        DatabaseQueuedJob::dispatch();
    });
});

// A chained connection replaces the attribute, and a public property declared below the
// attribute's class replaces it too.
#[Connection('sync')]
class SyncAttributeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
}

class RedisPropertyJob extends SyncAttributeJob
{
    public ?string $connection = 'transaction-redis';
}

DB::transaction(static function (): void {
    SyncAttributeJob::dispatch();
    SyncAttributeJob::dispatch()->onConnection('transaction-redis');
    RedisPropertyJob::dispatch();
});

// A constructor that may change the settings leaves them unknown, including through a fluent chain
// or a parent constructor calling the job's own override. Queueable's other setters do not.
class ConstructorConnectionJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct()
    {
        $this->onConnection('sync');
    }
}

class ConstructorHelperJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct()
    {
        $this->configure();
    }

    private function configure(): void
    {
    }
}

class ConstructorAfterCommitJob extends QueueableNotifyOwner
{
    public function __construct()
    {
        $this->afterCommit = true;
    }
}

class ParentConstructorJob extends ConstructorAfterCommitJob
{
    public function __construct()
    {
        parent::__construct();
    }
}

class ConstructorQueueJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('high');
    }
}

class FluentConstructorJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct()
    {
        $this->onQueue('high')->onConnection('sync');
    }
}

class OverriddenSetterJob extends ConstructorQueueJob
{
    public function __construct()
    {
        parent::__construct();
    }

    public function onQueue(mixed $queue): static
    {
        $this->connection = 'sync';

        return $this;
    }
}

DB::transaction(static function (): void {
    ConstructorConnectionJob::dispatch();
    ConstructorHelperJob::dispatch();
    ParentConstructorJob::dispatch();
    ConstructorQueueJob::dispatch();
    FluentConstructorJob::dispatch();
    OverriddenSetterJob::dispatch();
});

// Not flagged: a booted route may choose the connection.
class RoutedJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
}

DB::transaction(static function (): void {
    RoutedJob::dispatch();
});

// Not flagged: the enclosing transaction stays known after a statement that invalidates an expression.
class TrackedOrder
{
    public string $status = 'new';

    public function save(): void
    {
    }
}

$order = new TrackedOrder();

DB::connection('pgsql')->transaction(static function () use ($order): void {
    $order->status = 'paid';
    $order->save();

    DB::connection('mysql')->transaction(static function (): void {
        DatabaseQueuedJob::dispatch();
    });
});

// Hypervel: conditional dispatches are flagged too, unless a literal condition never dispatches.
DB::transaction(static function () use ($order): void {
    NotifyOwner::dispatchIf(true, 1);
    NotifyOwner::dispatchUnless(false, 1);
    NotifyOwner::dispatchIf(boolean: $order->status === 'paid', productId: 1);
    NotifyOwner::dispatchIf(false, 1);
    NotifyOwner::dispatchUnless(true, 1);
    NotifyOwner::dispatchIf(true, 1)->afterCommit();
});
