<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Scout\Exceptions\ScoutException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Import model records into the search index.
 */
#[AsCommand(name: 'scout:import')]
class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:import
        {model : Class name of model to bulk import}
        {--fresh : Flush the index before importing}
        {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `scout.chunk.searchable`)}';

    /**
     * The console command description.
     */
    protected string $description = 'Import the given model into the search index';

    /**
     * Execute the console command.
     *
     * @throws ScoutException
     */
    public function handle(Dispatcher $events): void
    {
        defined('SCOUT_COMMAND') || define('SCOUT_COMMAND', true);

        $class = $this->resolveModelClass((string) $this->argument('model'));
        $chunk = $this->option('chunk');
        $fresh = $this->option('fresh');

        try {
            $events->listen(ModelsImported::class, function (ModelsImported $event) use ($class): void {
                $lastModel = $event->models->last();
                $key = $lastModel?->getScoutKey();

                if ($key !== null) {
                    $this->line("<comment>Imported [{$class}] models up to ID:</comment> {$key}");
                }
            });

            if ($fresh) {
                $class::removeAllFromSearch();
            }

            $class::makeAllSearchable($chunk !== null ? (int) $chunk : null);
        } finally {
            $class::waitForSearchableJobs();
            $events->forget(ModelsImported::class);
        }

        $this->info("All [{$class}] records have been imported.");
    }

    /**
     * Resolve the fully-qualified model class name.
     *
     * @throws ScoutException
     */
    protected function resolveModelClass(string $class): string
    {
        if (class_exists($class)) {
            return $class;
        }

        // Try the conventional App\Models namespace
        $namespacedClass = "App\\Models\\{$class}";

        if (class_exists($namespacedClass)) {
            return $namespacedClass;
        }

        throw new ScoutException("Model [{$class}] not found.");
    }
}
