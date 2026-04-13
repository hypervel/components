<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'reload')]
class ReloadCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'reload';

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

        $exceptions = Collection::wrap(explode(',', $this->option('except') ?? ''))
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

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['except', 'e', InputOption::VALUE_OPTIONAL, 'The commands to skip'],
        ];
    }
}
