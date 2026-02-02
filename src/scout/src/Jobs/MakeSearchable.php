<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Queueable;
use Hypervel\Scout\Contracts\SearchableInterface;

/**
 * Queue job that makes models searchable by updating them in the search index.
 */
class MakeSearchable implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param Collection<int, Model&SearchableInterface> $models
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

        /** @var Model&SearchableInterface $firstModel */
        $firstModel = $this->models->first();

        $models = $firstModel->makeSearchableUsing($this->models);

        if ($models->isEmpty()) {
            return;
        }

        /** @var Model&SearchableInterface $searchableModel */
        $searchableModel = $models->first();

        $searchableModel->searchableUsing()->update($models);
    }
}
