<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Foundation\Support\Providers\EventServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'event:generate')]
class EventGenerateCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'event:generate';

    /**
     * The console command description.
     */
    protected string $description = 'Generate the missing events and listeners based on registration';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     */
    protected bool $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $providers = $this->hypervel->getProviders(EventServiceProvider::class);

        foreach ($providers as $provider) {
            foreach ($provider->listens() as $event => $listeners) {
                $this->makeEventAndListeners($event, $listeners);
            }
        }

        $this->components->info('Events and listeners generated successfully.');
    }

    /**
     * Make the event and listeners for the given event.
     */
    protected function makeEventAndListeners(string $event, array $listeners): void
    {
        if (! str_contains($event, '\\')) {
            return;
        }

        $this->callSilent('make:event', ['name' => $event]);

        $this->makeListeners($event, $listeners);
    }

    /**
     * Make the listeners for the given event.
     */
    protected function makeListeners(string $event, array $listeners): void
    {
        foreach ($listeners as $listener) {
            $listener = preg_replace('/@.+$/', '', $listener);

            $this->callSilent('make:listener', array_filter(
                ['name' => $listener, '--event' => $event]
            ));
        }
    }
}
