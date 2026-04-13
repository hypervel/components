<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Collection;
use ReflectionFunction;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'event:list')]
class EventListCommand extends Command
{
    protected ?string $signature = 'event:list
                            {--event= : Filter the events by name}
                            {--listener= : Filter the events by listener name}
                            {--json : Output the events and listeners as JSON}';

    protected string $description = "List the application's events and listeners";

    /**
     * The events dispatcher resolver callback.
     */
    protected static ?Closure $eventsResolver = null;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $events = $this->getEvents()->sortKeys();

        if ($events->isEmpty()) {
            if ($this->option('json')) {
                $this->output->writeln('[]');
            } else {
                $this->components->info("Your application doesn't have any events matching the given criteria.");
            }

            return;
        }

        if ($this->option('json')) {
            $this->displayJson($events);
        } else {
            $this->displayForCli($events);
        }
    }

    /**
     * Display events and their listeners in JSON.
     */
    protected function displayJson(Collection $events): void
    {
        $data = $events->map(function ($listeners, $event) {
            return [
                'event' => strip_tags($this->appendEventInterfaces($event)),
                'listeners' => (new Collection($listeners))->map(fn ($listener) => strip_tags($listener))->values()->all(),
            ];
        })->values();

        $this->output->writeln($data->toJson());
    }

    /**
     * Display the events and their listeners for the CLI.
     */
    protected function displayForCli(Collection $events): void
    {
        $this->newLine();

        $events->each(function ($listeners, $event) {
            $this->components->twoColumnDetail($this->appendEventInterfaces($event));
            $this->components->bulletList($listeners);
        });

        $this->newLine();
    }

    /**
     * Get all of the events and listeners configured for the application.
     */
    protected function getEvents(): Collection
    {
        $events = new Collection($this->getListenersOnDispatcher());

        if ($this->filteringByEvent()) {
            $events = $this->filterEvents($events);
        }

        return $events;
    }

    /**
     * Get the event / listeners from the dispatcher object.
     */
    protected function getListenersOnDispatcher(): array
    {
        $events = [];
        $listenerFilter = $this->option('listener');

        foreach ($this->getRawListeners() as $event => $rawListeners) {
            foreach ($rawListeners as $rawListener) {
                if (is_string($rawListener)) {
                    $formatted = $this->appendListenerInterfaces($rawListener);
                } elseif ($rawListener instanceof Closure) {
                    $formatted = $this->stringifyClosure($rawListener);
                } elseif (is_array($rawListener) && count($rawListener) === 2) {
                    if (is_object($rawListener[0])) {
                        $rawListener[0] = get_class($rawListener[0]);
                    }

                    $formatted = $this->appendListenerInterfaces(implode('@', $rawListener));
                } else {
                    continue;
                }

                if ($listenerFilter && ! str_contains(strip_tags($formatted), $listenerFilter)) {
                    continue;
                }

                $events[$event][] = $formatted;
            }
        }

        return $events;
    }

    /**
     * Add the event implemented interfaces to the output.
     */
    protected function appendEventInterfaces(string $event): string
    {
        if (! class_exists($event)) {
            return $event;
        }

        $interfaces = class_implements($event);

        if (in_array(ShouldBroadcast::class, $interfaces)) {
            $event .= ' <fg=bright-blue>(ShouldBroadcast)</>';
        }

        return $event;
    }

    /**
     * Add the listener implemented interfaces to the output.
     */
    protected function appendListenerInterfaces(string $listener): string
    {
        $listener = explode('@', $listener);

        $interfaces = class_implements($listener[0]);

        $listener = implode('@', $listener);

        if (in_array(ShouldQueue::class, $interfaces)) {
            $listener .= ' <fg=bright-blue>(ShouldQueue)</>';
        }

        return $listener;
    }

    /**
     * Get a displayable string representation of a Closure listener.
     */
    protected function stringifyClosure(Closure $rawListener): string
    {
        $reflection = new ReflectionFunction($rawListener);

        $path = str_replace([base_path(), DIRECTORY_SEPARATOR], ['', '/'], $reflection->getFileName() ?: '');

        return 'Closure at: ' . $path . ':' . $reflection->getStartLine();
    }

    /**
     * Filter the given events using the provided event name filter.
     */
    protected function filterEvents(Collection $events): Collection
    {
        if (! $eventName = $this->option('event')) {
            return $events;
        }

        return $events->filter(
            fn ($listeners, $event) => str_contains($event, $eventName)
        );
    }

    /**
     * Determine whether the user is filtering by an event name.
     */
    protected function filteringByEvent(): bool
    {
        return ! empty($this->option('event'));
    }

    /**
     * Get the raw version of event listeners from the event dispatcher.
     *
     * @throws \Hypervel\Contracts\Container\BindingResolutionException
     */
    protected function getRawListeners(): array
    {
        return $this->getEventsDispatcher()->getRawListeners();
    }

    /**
     * Get the event dispatcher.
     *
     * @return \Hypervel\Events\Dispatcher
     *
     * @throws \Hypervel\Contracts\Container\BindingResolutionException
     */
    public function getEventsDispatcher()
    {
        return is_null(self::$eventsResolver)
            ? $this->getHypervel()->make('events')
            : call_user_func(self::$eventsResolver);
    }

    /**
     * Set a callback that should be used when resolving the events dispatcher.
     */
    public static function resolveEventsUsing(?Closure $resolver): void
    {
        static::$eventsResolver = $resolver;
    }
}
