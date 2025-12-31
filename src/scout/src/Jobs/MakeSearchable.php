<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Queueable;

/**
 * Queue job that makes models searchable by updating them in the search index.
 */
class MakeSearchable implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Collection $models
    ) {
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        if ($this->models->isEmpty()) {
            return;
        }

        $this->models->first()
            ->makeSearchableUsing($this->models)
            ->first()
            ->searchableUsing()
            ->update($this->models);
    }
}
