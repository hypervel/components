<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Foundation\Support\Providers\EventServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'event:cache')]
class EventCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'event:cache';

    /**
     * The console command description.
     */
    protected string $description = "Discover and cache the application's events and listeners";

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->callSilent('event:clear');

        file_put_contents(
            $this->hypervel->getCachedEventsPath(),
            '<?php return ' . var_export($this->getEvents(), true) . ';'
        );

        $this->components->info('Events cached successfully.');
    }

    /**
     * Get all of the events and listeners configured for the application.
     */
    protected function getEvents(): array
    {
        $events = [];

        foreach ($this->hypervel->getProviders(EventServiceProvider::class) as $provider) {
            $providerEvents = array_merge_recursive(
                $provider->shouldDiscoverEvents() ? $provider->discoverEvents() : [],
                $provider->listens()
            );

            $events[get_class($provider)] = $providerEvents;
        }

        return $events;
    }
}
