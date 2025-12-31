<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Console\Command;
use Hypervel\Scout\Exceptions\ScoutException;

/**
 * Flush all model records from the search index.
 */
class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:flush
        {model : Class name of the model to flush}';

    /**
     * The console command description.
     */
    protected string $description = "Flush all of the model's records from the index";

    /**
     * Execute the console command.
     *
     * @throws ScoutException
     */
    public function handle(): void
    {
        define('SCOUT_COMMAND', true);

        $class = $this->resolveModelClass((string) $this->argument('model'));

        $class::removeAllFromSearch();

        $this->info("All [{$class}] records have been flushed.");
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
