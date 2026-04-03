<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'optimize')]
class OptimizeCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'optimize';

    /**
     * The console command description.
     */
    protected string $description = 'Cache framework bootstrap, configuration, and metadata to increase performance';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->components->info('Caching framework bootstrap, configuration, and metadata.');

        $exceptions = Collection::wrap(explode(',', $this->option('except') ?? ''))
            ->map(fn ($except) => trim($except))
            ->filter()
            ->unique()
            ->flip();

        $tasks = Collection::wrap($this->getOptimizeTasks())
            ->reject(fn ($command, $key) => $exceptions->hasAny([$command, $key]))
            ->toArray();

        foreach ($tasks as $description => $command) {
            $this->components->task($description, fn () => $this->callSilently($command) === 0);
        }

        $this->newLine();
    }

    /**
     * Get the commands that should be run to optimize the framework.
     */
    protected function getOptimizeTasks(): array
    {
        return [
            'config' => 'config:cache',
            'events' => 'event:cache',
            'routes' => 'route:cache',
            'views' => 'view:cache',
            ...ServiceProvider::$optimizeCommands,
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['except', 'e', InputOption::VALUE_OPTIONAL, 'Do not run the commands matching the key or name'],
        ];
    }
}
