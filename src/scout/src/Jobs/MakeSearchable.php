<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Traits\ConfiguresJobOptions;

/**
 * Queue job that makes models searchable by updating them in the search index.
 */
class MakeSearchable implements ShouldQueue
{
    use ConfiguresJobOptions;
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param Collection<int, Model> $models
     */
    public function __construct(
        public Collection $models
    ) {
        $this->configureJob();
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
