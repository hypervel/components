<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Broadcasting\Channel;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Mailable;
use Hypervel\Notifications\Notification;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\Tries;

enum QueueConnection: string
{
    case Redis = 'redis';
}

class SendInvoice implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 120;

    public $maxExceptions = 2;

    public $backoff = [1, 5, 10];

    public $failOnTimeout = true;

    public $countCrashesAsExceptions = true;

    public $deleteWhenMissingModels = true;
}

class SendReminder extends SendInvoice
{
    public ?string $queue = 'emails';

    public array|\DateInterval|\DateTimeInterface|int|null $delay = 30;

    // The parent's value applies without it.
    public $tries = 3;
}

class SendWelcome implements ShouldQueue
{
    public $connection = QueueConnection::Redis;

    public $uniqueFor = 60;

    public function handle(): void
    {
    }
}

class ImportFeed implements ShouldQueue, ShouldBeUnique
{
    public $uniqueFor = 3600;
}

#[DebounceFor(30, maxWait: 300)]
class RebuildIndex implements ShouldQueue
{
}

class RebuildSearchIndex extends RebuildIndex
{
    public $debounceFor = 10;
}

#[Tries(4)]
class SyncAccount implements ShouldQueue
{
    // The class's own attribute wins.
    public $tries = 2;
}

class ComputedJob implements ShouldQueue
{
    public $tries = 5;

    protected $timeout = 30;

    public $maxExceptions = null;

    public $failOnTimeout = false;

    public function tries(): int
    {
        return 5;
    }
}

class WrapperJob implements ShouldQueue
{
    public $tries = 1;

    public function __construct(object $wrapped)
    {
        $this->tries = $wrapped->tries ?? $this->tries;
    }
}

class WrapperChildJob extends WrapperJob
{
    // WrapperJob's constructor replaces the value this declares.
    public $tries = 5;
}

trait RetriesThreeTimes
{
    public $tries = 3;
}

class RetriedJob implements ShouldQueue
{
    use RetriesThreeTimes;
}

trait TimesOutAfterAMinute
{
    // @phpstan-ignore hypervel.legacyQueueProperty (fixture: one ignore beside a trait's declaration covers every class using it)
    public $timeout = 60;
}

class ImportOrders implements ShouldQueue
{
    use TimesOutAfterAMinute;
}

class ImportRefunds implements ShouldQueue
{
    use TimesOutAfterAMinute;
}

trait ResetsTries { public function resetTries(): void { $this->tries = null; } public function boot(): void { } }

class AliasedConstructorJob implements ShouldQueue
{
    // The constructor is boot(), which writes nothing; resetTries() on the same line is not run.
    use ResetsTries { boot as __construct; }

    public $tries = 2;
}

class InvoiceMail extends Mailable
{
    public $tries = 3;

    public $backoff = 5;

    public function backoff(): int
    {
        return 5;
    }
}

class InvoicePaid extends Notification implements ShouldQueue
{
    public $timeout = 60;
}

class InvoiceViewed extends Notification
{
    public $timeout = 60;
}

class OrderShipped implements ShouldBroadcast
{
    public $queue = 'broadcasts';

    public function broadcastOn(): array|Channel|string
    {
        return 'orders';
    }
}

class OrderPacked implements ShouldBroadcastNow
{
    public $queue = 'broadcasts';

    public function broadcastOn(): array|Channel|string
    {
        return 'orders';
    }
}

class NotQueued
{
    public $tries = 3;
}

class SendDigest extends SendInvoice
{
    // The constructor's onConnection() replaces this value.
    public ?string $connection = 'fixed';

    public $tries = 4;

    public function __construct()
    {
        $this->onConnection(null);
    }
}

trait RetriesPerClass
{
    // self::TRIES is each using class's constant, which an attribute on the trait cannot read.
    public $tries = self::TRIES;
}

class ImportContacts implements ShouldQueue
{
    use RetriesPerClass;

    public const int TRIES = 3;
}

class ImportDeals implements ShouldQueue
{
    use RetriesPerClass;

    public const int TRIES = 5;
}
