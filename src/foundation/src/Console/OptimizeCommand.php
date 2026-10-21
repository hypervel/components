<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Stringable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'optimize')]
class OptimizeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'optimize {--e|except= : Do not run the commands matching the key or name}';

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

        $exceptions = (new Stringable($this->option('except') ?? ''))->explode(',')
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
}
