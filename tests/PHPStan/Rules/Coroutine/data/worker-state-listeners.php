<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Collection;

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

    public string $status = 'idle';

    /** @var null|array<string, int> */
    public ?array $counts = null;

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
        $this->status[0] = 'b';
        $this->counts['handled'] = 1;
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

class CollectingListener implements ShouldQueue
{
    use InteractsWithQueue;

    /** @var Collection<string, mixed> */
    public Collection $values;

    /** @var array<string, Collection<string, int>> */
    public array $groups = [];

    /** @var array<string, int>|Collection<string, int> */
    public array|Collection $totals = [];

    /**
     * Handle the event on the job's clone, whose properties still hold the container instance's objects.
     */
    public function handle(ClonedEvent $event): void
    {
        $this->values['request'] = $event->request;
        $this->groups['imports']['count'] = 1;
        $this->totals['count'] = 1;
        $this->count();
    }

    /**
     * Count the event.
     */
    private function count(): void
    {
        $this->values['count'] = 1;
    }
}

class Registry
{
    public ?Request $request = null;

    /**
     * Remember the request.
     */
    public function remember(Request $request): void
    {
        $this->request = $request;
    }
}

class RegistryListener extends Registry implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event on the job's clone.
     */
    public function handle(ClonedEvent $event): void
    {
        $this->remember($event->request);
    }
}

abstract class BaseRegistry
{
    public ?Request $request = null;

    /**
     * Keep the request.
     */
    public function keep(Request $request): void
    {
        $this->request = $request;
    }
}

class BaseRegistryListener extends BaseRegistry implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event on the job's clone.
     */
    public function handle(ClonedEvent $event): void
    {
        $this->keep($event->request);
    }
}

class SharedRegistry extends BaseRegistry
{
}
