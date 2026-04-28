<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Scout\Console\Traits\ResolvesScoutModelClass;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\MakeRangeSearchable;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Import the given model into the search index via chunked, queued jobs.
 */
#[AsCommand(name: 'scout:queue-import')]
class QueueImportCommand extends Command
{
    use ResolvesScoutModelClass;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:queue-import
        {model : Class name of model to bulk queue}
        {--min= : The minimum key value to start queuing from}
        {--max= : The maximum key value to queue up to}
        {--c|chunk= : The number of records to queue in a single job (Defaults to configuration value: `scout.chunk.searchable`)}
        {--queue= : The queue that should be used (Defaults to configuration value: `scout.queue.queue`)}';

    /**
     * The console command description.
     */
    protected string $description = 'Import the given model into the search index via chunked, queued jobs';

    /**
     * Execute the console command.
     *
     * @throws ScoutException
     */
    public function handle(Repository $config): void
    {
        $class = $this->resolveModelClass((string) $this->argument('model'));

        /** @var SearchableInterface $model */
        $model = new $class;

        $chunk = max(1, (int) ($this->option('chunk') ?? $config->integer('scout.chunk.searchable', 500)));
        $queueName = $this->option('queue') ?? $model->syncWithSearchUsingQueue();
        $connection = $model->syncWithSearchUsing();

        if ($model->getScoutKeyType() === 'int') {
            $this->dispatchIntegerRange($class, $model, $chunk, $queueName, $connection);
        } else {
            $this->dispatchStringRange($class, $model, $chunk, $queueName, $connection);
        }
    }

    /**
     * Dispatch range jobs for an integer-keyed model using min/max arithmetic.
     */
    protected function dispatchIntegerRange(string $class, SearchableInterface $model, int $chunk, ?string $queueName, ?string $connection): void
    {
        $query = $class::makeAllSearchableQuery();
        $keyName = $model->getScoutKeyName();
        $qualified = $query->qualifyColumn($keyName);

        $min = $this->option('min') ?? $query->min($qualified);
        $max = $this->option('max') ?? $query->max($qualified);

        if ($min === null || $max === null) {
            $this->info("No records found for [{$class}].");

            return;
        }

        if ((int) $min > (int) $max) {
            $this->error("Invalid range for [{$class}]: --min ({$min}) is greater than --max ({$max}).");

            return;
        }

        for ($start = (int) $min; $start <= (int) $max; $start += $chunk) {
            $end = min($start + $chunk - 1, (int) $max);

            MakeRangeSearchable::dispatch($class, $start, $end)
                ->onQueue($queueName)
                ->onConnection($connection);

            $this->line("<comment>Queued [{$class}] models up to ID:</comment> {$end}");
        }

        $this->info("All [{$class}] records have been queued for importing.");
    }

    /**
     * Dispatch range jobs for a string-keyed model using key-cursor chunking.
     *
     * Walks the table by primary key (selecting only the key column), and
     * dispatches one MakeRangeSearchable per chunk with the first/last keys
     * in that chunk. Workers re-query their range via whereBetween.
     */
    protected function dispatchStringRange(string $class, SearchableInterface $model, int $chunk, ?string $queueName, ?string $connection): void
    {
        $query = $class::makeAllSearchableQuery();
        $keyName = $model->getScoutKeyName();
        $qualified = $query->qualifyColumn($keyName);
        $min = $this->option('min');
        $max = $this->option('max');

        if ($min !== null && $max !== null && (string) $min > (string) $max) {
            $this->error("Invalid range for [{$class}]: --min ({$min}) is greater than --max ({$max}).");

            return;
        }

        $jobsDispatched = 0;

        $query
            ->select("{$qualified} as {$keyName}")
            ->when($min !== null, fn ($q) => $q->where($qualified, '>=', $min))
            ->when($max !== null, fn ($q) => $q->where($qualified, '<=', $max))
            ->chunkById($chunk, function ($keys) use ($class, $keyName, $queueName, $connection, &$jobsDispatched): void {
                $start = $keys->first()->{$keyName};
                $end = $keys->last()->{$keyName};

                MakeRangeSearchable::dispatch($class, $start, $end)
                    ->onQueue($queueName)
                    ->onConnection($connection);

                $this->line("<comment>Queued [{$class}] models up to key:</comment> {$end}");
                ++$jobsDispatched;
            }, $qualified, $keyName);

        if ($jobsDispatched === 0) {
            $this->info("No records found for [{$class}].");

            return;
        }

        $this->info("All [{$class}] records have been queued for importing.");
    }
}
