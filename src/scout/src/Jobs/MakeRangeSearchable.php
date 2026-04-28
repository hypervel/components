<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Scout\Scout;

/**
 * Queue job that re-queries an ID range and dispatches a MakeSearchable for it.
 */
class MakeRangeSearchable implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param string $class the model class to be made searchable
     * @param int|string $start the first key in the range to be made searchable
     * @param int|string $end the last key in the range to be made searchable
     */
    public function __construct(
        public string $class,
        public int|string $start,
        public int|string $end,
    ) {
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        $class = $this->class;
        $model = new $class;

        $query = $class::makeAllSearchableQuery();
        $qualified = $query->qualifyColumn($model->getScoutKeyName());

        $models = $query
            ->whereBetween($qualified, [$this->start, $this->end])
            ->get()
            ->filter
            ->shouldBeSearchable();

        if ($models->isEmpty()) {
            return;
        }

        $jobClass = Scout::$makeSearchableJob;
        $jobClass::dispatch($models)
            ->onQueue($this->queue ?? $model->syncWithSearchUsingQueue())
            ->onConnection($this->connection ?? $model->syncWithSearchUsing());
    }
}
