<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hyperf\Collection\Collection;
use Hyperf\Context\ApplicationContext;
use Hypervel\Bus\Contracts\Dispatcher;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Throwable;

class ChainedBatch implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The collection of batched jobs.
     */
    public Collection $jobs;

    /**
     * The name of the batch.
     */
    public string $name;

    /**
     * The batch options.
     */
    public array $options;

    /**
     * Create a new chained batch instance.
     */
    public function __construct(PendingBatch $batch)
    {
        $this->jobs = static::prepareNestedBatches($batch->jobs);

        $this->name = $batch->name;
        $this->options = $batch->options;
    }

    /**
     * Prepare any nested batches within the given collection of jobs.
     */
    public static function prepareNestedBatches(Collection $jobs): Collection
    {
        return $jobs->map(fn ($job) => match (true) {
            is_array($job) => static::prepareNestedBatches(collect($job))->all(),
            $job instanceof Collection => static::prepareNestedBatches($job),
            $job instanceof PendingBatch => new ChainedBatch($job),
            default => $job,
        });
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        $this->attachRemainderOfChainToEndOfBatch(
            $this->toPendingBatch()
        )->dispatch();
    }

    /**
     * Convert the chained batch instance into a pending batch.
     */
    public function toPendingBatch(): PendingBatch
    {
        $batch = ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->batch($this->jobs);

        $batch->name = $this->name;
        $batch->options = $this->options;

        if ($this->queue) {
            $batch->onQueue($this->queue);
        }

        if ($this->connection) {
            $batch->onConnection($this->connection);
        }

        foreach ($this->chainCatchCallbacks ?? [] as $callback) {
            $batch->catch(function (Batch $batch, ?Throwable $exception) use ($callback) {
                if (! $batch->allowsFailures()) {
                    $callback($exception);
                }
            });
        }

        return $batch;
    }

    /**
     * Move the remainder of the chain to a "finally" batch callback.
     */
    protected function attachRemainderOfChainToEndOfBatch(PendingBatch $batch): PendingBatch
    {
        if (! empty($this->chained)) {
            $next = unserialize(array_shift($this->chained));

            $next->chained = $this->chained;

            $next->onConnection($next->connection ?: $this->chainConnection);
            $next->onQueue($next->queue ?: $this->chainQueue);

            $next->chainConnection = $this->chainConnection;
            $next->chainQueue = $this->chainQueue;
            $next->chainCatchCallbacks = $this->chainCatchCallbacks;

            $batch->finally(function (Batch $batch) use ($next) {
                if (! $batch->cancelled()) {
                    ApplicationContext::getContainer()
                        ->get(Dispatcher::class)
                        ->dispatch($next);
                }
            });

            $this->chained = [];
        }

        return $batch;
    }
}
