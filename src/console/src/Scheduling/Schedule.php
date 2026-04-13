<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Support\Collection;
use Hypervel\Support\ProcessUtils;
use Hypervel\Support\Traits\Macroable;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Console\Scheduling\PendingEventAttributes
 */
class Schedule
{
    use Macroable {
        __call as macroCall;
    }

    public const SUNDAY = 0;

    public const MONDAY = 1;

    public const TUESDAY = 2;

    public const WEDNESDAY = 3;

    public const THURSDAY = 4;

    public const FRIDAY = 5;

    public const SATURDAY = 6;

    /**
     * All of the events on the schedule.
     *
     * @var array Event[]
     */
    protected array $events = [];

    /**
     * The event mutex implementation.
     */
    protected EventMutex $eventMutex;

    /**
     * The scheduling mutex implementation.
     */
    protected SchedulingMutex $schedulingMutex;

    /**
     * The job dispatcher implementation.
     */
    protected ?Dispatcher $dispatcher = null;

    /**
     * The cache of mutex results.
     *
     * @var array<string, bool>
     */
    protected array $mutexCache = [];

    /**
     * The attributes to pass to the event.
     */
    protected ?PendingEventAttributes $attributes = null;

    /**
     * The schedule group attributes stack.
     *
     * @var array<int, PendingEventAttributes>
     */
    protected array $groupStack = [];

    /**
     * Create a new schedule instance.
     *
     * @param null|DateTimeZone|string $timezone the timezone the date should be evaluated on
     *
     * @throws RuntimeException
     */
    public function __construct(
        protected DateTimeZone|string|null $timezone = null
    ) {
        if (! class_exists(Container::class)) {
            throw new RuntimeException(
                'A container implementation is required to use the scheduler. Please install the hypervel/container package.'
            );
        }

        $container = Container::getInstance();

        $this->eventMutex = $container->bound(EventMutex::class)
            ? $container->make(EventMutex::class)
            : $container->make(CacheEventMutex::class);

        $this->schedulingMutex = $container->bound(SchedulingMutex::class)
            ? $container->make(SchedulingMutex::class)
            : $container->make(CacheSchedulingMutex::class);
    }

    /**
     * Add a new callback event to the schedule.
     */
    public function call(callable|string|array $callback, array $parameters = []): CallbackEvent
    {
        $this->events[] = $event = new CallbackEvent(
            $this->eventMutex,
            $callback,
            $parameters,
            $this->timezone
        );

        $this->mergePendingAttributes($event);

        return $event;
    }

    /**
     * Add a new Artisan command event to the schedule.
     */
    public function command(SymfonyCommand|string $command, array $parameters = []): Event
    {
        if ($command instanceof SymfonyCommand) {
            $command = get_class($command);
        }

        if (class_exists($command)) {
            $command = Container::getInstance()->make($command);

            return $this->exec(
                $command->getName(),
                $parameters,
                false,
            )->description($command->getDescription());
        }

        return $this->exec($command, $parameters, false);
    }

    /**
     * Add a new job callback event to the schedule.
     */
    public function job(
        object|string $job,
        UnitEnum|string|null $queue = null,
        UnitEnum|string|null $connection = null
    ): CallbackEvent {
        $jobName = $job;

        $queue = is_null($queue) ? null : enum_value($queue);
        $connection = is_null($connection) ? null : enum_value($connection);

        if (! is_string($job)) {
            $jobName = method_exists($job, 'displayName')
                ? $job->displayName()
                : $job::class;
        }

        $this->events[] = $event = new CallbackEvent(
            $this->eventMutex,
            function () use ($job, $queue, $connection) {
                $job = is_string($job) ? Container::getInstance()->make($job) : $job;

                if ($job instanceof ShouldQueue) {
                    $this->dispatchToQueue($job, $queue ?? $job->queue, $connection ?? $job->connection); /* @phpstan-ignore-line */
                } else {
                    $this->dispatchNow($job);
                }
            },
            [],
            $this->timezone
        );

        $event->name($jobName);

        $this->mergePendingAttributes($event);

        return $event;
    }

    /**
     * Dispatch the given job to the queue.
     *
     * @throws RuntimeException
     */
    protected function dispatchToQueue(object $job, ?string $queue, ?string $connection): void
    {
        if ($job instanceof Closure) {
            if (! class_exists(CallQueuedClosure::class)) {
                throw new RuntimeException(
                    'To enable support for closure jobs, please install the illuminate/queue package.'
                );
            }

            $job = CallQueuedClosure::create($job);
        }

        // Clone the job to prevent mutation of the original instance. Hypervel's
        // container caches unbound concretes (auto-singletons) for Swoole performance,
        // so Container::make() may return the same object across multiple schedule
        // callbacks. Without cloning, onConnection()/onQueue() would mutate a shared
        // instance, causing state bleed between scheduled events and corrupting
        // QueueFake assertions (which store object references, not snapshots).
        $job = clone $job;

        if ($job instanceof ShouldBeUnique) {
            $this->dispatchUniqueJobToQueue($job, $queue, $connection);
            return;
        }

        $this->getDispatcher()->dispatch(
            $job->onConnection($connection)->onQueue($queue)
        );
    }

    /**
     * Dispatch the given unique job to the queue.
     *
     * @throws RuntimeException
     */
    protected function dispatchUniqueJobToQueue(object $job, ?string $queue, ?string $connection): void
    {
        if (! Container::getInstance()->has(Cache::class)) {
            throw new RuntimeException('Cache driver not available. Scheduling unique jobs not supported.');
        }

        $cache = Container::getInstance()->make(Cache::class);
        if (! (new UniqueLock($cache))->acquire($job)) {
            return;
        }

        $this->getDispatcher()->dispatch(
            $job->onConnection($connection)->onQueue($queue)
        );
    }

    /**
     * Dispatch the given job right now.
     */
    protected function dispatchNow(object $job): void
    {
        $this->getDispatcher()->dispatchNow($job);
    }

    /**
     * Add a new command event to the schedule.
     */
    public function exec(string $command, array $parameters = [], bool $isSystem = true): Event
    {
        if (count($parameters)) {
            $command .= ' ' . $this->compileParameters($parameters);
        }

        $this->events[] = $event = (new Event($this->eventMutex, $command, $this->timezone, $isSystem));

        $this->mergePendingAttributes($event);

        return $event;
    }

    /**
     * Create new schedule group.
     *
     * @throws RuntimeException
     */
    public function group(Closure $events): void
    {
        if ($this->attributes === null) {
            throw new RuntimeException('Invoke an attribute method such as Schedule::daily() before defining a schedule group.');
        }

        $this->groupStack[] = $this->attributes;
        $this->attributes = null;

        $events($this);

        array_pop($this->groupStack);
    }

    /**
     * Merge the current group attributes with the given event.
     */
    protected function mergePendingAttributes(Event $event): void
    {
        if (! empty($this->groupStack)) {
            $group = end($this->groupStack);

            $group->mergeAttributes($event);
        }

        if (isset($this->attributes)) {
            $this->attributes->mergeAttributes($event);

            $this->attributes = null;
        }
    }

    /**
     * Compile parameters for a command.
     */
    protected function compileParameters(array $parameters): string
    {
        return (new Collection($parameters))->map(function ($value, $key) {
            if (is_array($value)) {
                return $this->compileArrayInput($key, $value);
            }

            if (! is_numeric($value) && ! preg_match('/^(-.$|--.*)/i', $value)) {
                $value = ProcessUtils::escapeArgument($value);
            }

            return is_numeric($key) ? $value : "{$key}={$value}";
        })->implode(' ');
    }

    /**
     * Compile array input for a command.
     */
    public function compileArrayInput(int|string $key, array $value): string
    {
        $value = (new Collection($value))->map(function ($value) {
            return ProcessUtils::escapeArgument($value);
        });

        if (is_string($key) && str_starts_with($key, '--')) {
            $value = $value->map(function ($value) use ($key) {
                return "{$key}={$value}";
            });
        } elseif (is_string($key) && str_starts_with($key, '-')) {
            $value = $value->map(function ($value) use ($key) {
                return "{$key} {$value}";
            });
        }

        return $value->implode(' ');
    }

    /**
     * Determine if the server is allowed to run this event.
     */
    public function serverShouldRun(Event $event, DateTimeInterface $time): bool
    {
        return $this->mutexCache[$event->mutexName()] ??= $this->schedulingMutex->create($event, $time);
    }

    /**
     * Get all of the events on the schedule that are due.
     */
    public function dueEvents(Application $app): Collection
    {
        return (new Collection($this->events))->filter->isDue($app);
    }

    /**
     * Get all of the events on the schedule.
     *
     * @return array Event[]
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Specify the cache store that should be used to store mutexes.
     */
    public function useCache(UnitEnum|string|null $store): static
    {
        if (is_null($store)) {
            return $this;
        }

        $store = enum_value($store);

        if ($this->eventMutex instanceof CacheAware) {
            $this->eventMutex->useStore($store);
        }

        if ($this->schedulingMutex instanceof CacheAware) {
            $this->schedulingMutex->useStore($store);
        }

        return $this;
    }

    /**
     * Get the job dispatcher, if available.
     *
     * @throws RuntimeException
     */
    protected function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher === null) {
            try {
                $this->dispatcher = Container::getInstance()->make(Dispatcher::class);
            } catch (BindingResolutionException $e) {
                throw new RuntimeException(
                    'Unable to resolve the dispatcher from the service container. Please bind it or install the hypervel/bus package.',
                    $e->getCode(),
                    $e
                );
            }
        }

        return $this->dispatcher;
    }

    /**
     * Dynamically handle calls into the schedule instance.
     */
    public function __call(string $method, array $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (method_exists(PendingEventAttributes::class, $method)) {
            $this->attributes ??= $this->groupStack ? clone end($this->groupStack) : new PendingEventAttributes($this);

            return $this->attributes->{$method}(...$parameters);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $method
        ));
    }
}
