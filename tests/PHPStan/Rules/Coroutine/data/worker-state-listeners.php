<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Queue\InteractsWithQueue;

class RequestListener
{
    public static bool $seen = false;

    /**
     * Handle the event.
     */
    public function handle(): void
    {
        static::$seen = true;
    }
}

class JobListener
{
    public static bool $recorded = false;

    /**
     * Record the job.
     */
    public function record(): void
    {
        static::$recorded = true;
    }
}

class QueuedListener implements ShouldQueue
{
    public static bool $recorded = false;

    /**
     * Record the request on the queue.
     */
    public function record(): void
    {
        static::$recorded = true;
    }
}

class ProviderObserver
{
    public static bool $created = false;

    /**
     * Handle the model's creation.
     */
    public function created(): void
    {
        static::$created = true;
    }
}

#[ObservedBy(DirectObserver::class)]
#[ObservedBy([RepeatedObserver::class])]
class Post extends Model
{
}

class DirectObserver
{
    public static bool $audited = false;

    /**
     * Handle the model's creation.
     */
    public function created(): void
    {
        $this->audit();
    }

    /**
     * Audit the change.
     */
    public function audit(): void
    {
        static::$audited = true;
    }

    /**
     * Audit nothing; no event calls it.
     */
    public function unused(): void
    {
        static::$audited = true;
    }
}

class RepeatedObserver
{
    public static bool $updated = false;

    /**
     * Handle the model's update.
     */
    public function updated(): void
    {
        static::$updated = true;
    }
}

#[ObservedBy(AuditObserver::class)]
abstract class AuditedModel extends Model
{
}

class Invoice extends AuditedModel
{
    protected array $observables = ['archived'];
}

class BaseObserver
{
    public static bool $deleted = false;

    /**
     * Handle the model's deletion.
     */
    public function deleted(): void
    {
        static::$deleted = true;
    }
}

class AuditObserver extends BaseObserver
{
    public static bool $archived = false;

    /**
     * Handle the model's custom archived event.
     */
    public function archived(): void
    {
        static::$archived = true;
    }
}

#[ObservedBy(CustomEventsObserver::class)]
class CustomEventsModel extends Model
{
    /**
     * Get the observable event names.
     */
    public function getObservableEvents(): array
    {
        return ['created'];
    }
}

class CustomEventsObserver
{
    public static bool $created = false;

    /**
     * Handle the model's creation.
     */
    public function created(): void
    {
        static::$created = true;
    }
}

class WildcardJobListener
{
    public static bool $seen = false;

    /**
     * Handle any job event.
     */
    public function handle(): void
    {
        static::$seen = true;
    }
}

class CatchAllObserver
{
    public static bool $observed = false;

    /**
     * Observe any event.
     */
    public function observe(): void
    {
        static::$observed = true;
    }
}

class QueuedObserver implements ShouldQueue
{
    public static bool $observed = false;

    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * Observe the request, which runs synchronously even though the class is queueable.
     */
    public function handle(): void
    {
        static::$observed = true;
        $this->config->set('app.observed', true);
    }
}

class ClonedEvent
{
    public function __construct(public Request $request)
    {
    }
}

class ClonedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public ?Request $request = null;

    public ?Request $latest = null;

    /** @var array<string, bool> */
    public array $seen = [];

    public int $tracked = 0;

    public int $failures = 0;

    /**
     * Decide on the container's instance whether to queue the event.
     */
    public function shouldQueue(ClonedEvent $event): bool
    {
        $this->remember($event);

        return true;
    }

    /**
     * Handle the event on the job's clone of the listener.
     */
    public function handle(ClonedEvent $event): void
    {
        $this->request = $event->request;
        $this->seen[$event->request->ip()] = true;
        $this->remember($event);
        $this->track();
    }

    /**
     * Handle the failure on the container's instance.
     */
    public function failed(): void
    {
        ++$this->failures;
    }

    /**
     * Remember the event's request.
     */
    private function remember(ClonedEvent $event): void
    {
        $this->latest = $event->request;
    }

    /**
     * Track the event.
     */
    private function track(): void
    {
        ++$this->tracked;
    }
}
