<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Console\Command;
use Hypervel\Scout\Console\Traits\ResolvesScoutModelClass;
use Hypervel\Scout\Exceptions\ScoutException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Flush all model records from the search index.
 */
#[AsCommand(name: 'scout:flush')]
class FlushCommand extends Command
{
    use ResolvesScoutModelClass;

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
        $class = $this->resolveModelClass((string) $this->argument('model'));

        $class::removeAllFromSearch();

        $this->info("All [{$class}] records have been flushed.");
    }
}
