<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Stringable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reload')]
class ReloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'reload {--e|except= : The commands to skip}';

    /**
     * The console command description.
     */
    protected string $description = 'Reload running services';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->components->info('Reloading services.');

        $exceptions = (new Stringable($this->option('except') ?? ''))->explode(',')
            ->map(fn ($except) => trim($except))
            ->filter()
            ->unique()
            ->flip();

        $tasks = Collection::wrap($this->getReloadTasks())
            ->reject(fn ($command, $key) => $exceptions->hasAny([$command, $key]))
            ->toArray();

        foreach ($tasks as $description => $command) {
            $this->components->task($description, fn () => $this->callSilently($command) === 0);
        }

        $this->newLine();
    }

    /**
     * Get the commands that should be reloaded.
     */
    public function getReloadTasks(): array
    {
        return [
            'queue' => 'queue:restart',
            'schedule' => 'schedule:interrupt',
            'server' => 'server:reload',
            ...ServiceProvider::$reloadCommands,
        ];
    }
}
