<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\Model;
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
        {--order=asc : The order in which ranges should be queued (`asc` or `desc`)}
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
    public function handle(Repository $config): int
    {
        $class = $this->resolveModelClass((string) $this->argument('model'));

        /** @var Model&SearchableInterface $model */
        $model = new $class;

        $chunk = max(1, (int) ($this->option('chunk') ?? $config->integer('scout.chunk.searchable', 500)));
        $queueName = $this->option('queue') ?? $model->syncWithSearchUsingQueue();
        $connection = $model->syncWithSearchUsing();
        $order = (string) $this->option('order');

        if (! in_array($order, ['asc', 'desc'], true)) {
            $this->error('The order option must be either "asc" or "desc".');

            return Command::FAILURE;
        }

        return in_array($model->getScoutKeyType(), ['int', 'integer'], true)
            ? $this->dispatchIntegerRange($class, $model, $chunk, $order, $queueName, $connection)
            : $this->dispatchStringRange($class, $model, $chunk, $order, $queueName, $connection);
    }

    /**
     * Dispatch range jobs for an integer-keyed model using min/max arithmetic.
     */
    protected function dispatchIntegerRange(string $class, Model $model, int $chunk, string $order, ?string $queueName, ?string $connection): int
    {
        /** @var Model&SearchableInterface $model */
        $query = $class::makeAllSearchableQuery();
        $keyName = $model->getScoutKeyName();
        $qualified = $query->qualifyColumn($keyName);

        $min = $this->option('min') ?? $query->min($qualified);
        $max = $this->option('max') ?? $query->max($qualified);

        if ($min === null || $max === null) {
            $this->info("No records found for [{$class}].");

            return Command::SUCCESS;
        }

        $min = $this->parseIntegerBound($min);
        $max = $this->parseIntegerBound($max);

        if ($min === false || $max === false) {
            $this->error("The minimum and maximum keys for [{$class}] must be valid integers.");

            return Command::FAILURE;
        }

        if ($min > $max) {
            $this->error("Invalid range for [{$class}]: --min ({$min}) is greater than --max ({$max}).");

            return Command::FAILURE;
        }

        // An overflowed distance exceeds every integer offset, so the selected endpoint stays representable.
        $offset = $chunk - 1;

        if ($order === 'asc') {
            for ($start = $min; $start <= $max; $start = $end + 1) {
                $end = $max - $start < $offset ? $max : $start + $offset;

                MakeRangeSearchable::dispatch($class, $start, $end)
                    ->onQueue($queueName)
                    ->onConnection($connection);

                $this->line("<comment>Queued [{$class}] models up to ID:</comment> {$end}");

                if ($end === $max) {
                    break;
                }
            }
        } else {
            for ($end = $max; $end >= $min; $end = $start - 1) {
                $start = $end - $min < $offset ? $min : $end - $offset;

                MakeRangeSearchable::dispatch($class, $start, $end)
                    ->onQueue($queueName)
                    ->onConnection($connection);

                $this->line("<comment>Queued [{$class}] models down to ID:</comment> {$start}");

                if ($start === $min) {
                    break;
                }
            }
        }

        $this->info("All [{$class}] records have been queued for importing.");

        return Command::SUCCESS;
    }

    /**
     * Dispatch range jobs for a string-keyed model using key-cursor chunking.
     *
     * Walks the table by primary key (selecting only the key column), and
     * dispatches one MakeRangeSearchable per chunk with the first/last keys
     * in that chunk. Workers re-query their range via whereBetween.
     */
    protected function dispatchStringRange(string $class, Model $model, int $chunk, string $order, ?string $queueName, ?string $connection): int
    {
        /** @var Model&SearchableInterface $model */
        $query = $class::makeAllSearchableQuery();
        $keyName = $model->getScoutKeyName();
        $qualified = $query->qualifyColumn($keyName);
        $min = $this->option('min');
        $max = $this->option('max');

        $jobsDispatched = 0;

        $query = $query
            ->select("{$qualified} as {$keyName}")
            ->when($min !== null, fn ($q) => $q->where($qualified, '>=', $min))
            ->when($max !== null, fn ($q) => $q->where($qualified, '<=', $max));

        $dispatch = function ($keys) use ($class, $keyName, $order, $queueName, $connection, &$jobsDispatched): void {
            $start = (string) ($order === 'asc' ? $keys->first()->{$keyName} : $keys->last()->{$keyName});
            $end = (string) ($order === 'asc' ? $keys->last()->{$keyName} : $keys->first()->{$keyName});

            MakeRangeSearchable::dispatch($class, $start, $end)
                ->onQueue($queueName)
                ->onConnection($connection);

            $this->line($order === 'asc'
                ? "<comment>Queued [{$class}] models up to key:</comment> {$end}"
                : "<comment>Queued [{$class}] models down to key:</comment> {$start}");
            ++$jobsDispatched;
        };

        if ($order === 'asc') {
            $query->chunkById($chunk, $dispatch, $qualified, $keyName);
        } else {
            $query->chunkByIdDesc($chunk, $dispatch, $qualified, $keyName);
        }

        if ($jobsDispatched === 0) {
            $this->info("No records found for [{$class}].");

            return Command::SUCCESS;
        }

        $this->info("All [{$class}] records have been queued for importing.");

        return Command::SUCCESS;
    }

    /**
     * Parse an integer range boundary.
     */
    protected function parseIntegerBound(mixed $value): int|false
    {
        if (is_string($value) && preg_match('/^[+-]?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return false;
        }

        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_INT);
    }
}
