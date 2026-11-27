<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
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
        $models = $this->withoutTrashedModels($this->models);

        if ($models->isEmpty()) {
            return;
        }

        /** @var Model&SearchableInterface $firstModel */
        $firstModel = $models->first();

        $models = $firstModel->makeSearchableUsing($models);

        if ($models->isEmpty()) {
            return;
        }

        /** @var Model&SearchableInterface $searchableModel */
        $searchableModel = $models->first();

        $searchableModel->searchableUsing()->runUpdate($models);
    }

    /**
     * Remove the models that were soft deleted after the job was dispatched.
     *
     * Queued models are restored without global scopes, so soft deleted models are restored as well.
     *
     * @param Collection<int, Model> $models
     * @return Collection<int, Model>
     */
    protected function withoutTrashedModels(Collection $models): Collection
    {
        if ($models->isEmpty()
            || config()->boolean('scout.soft_delete')
            || ! in_array(SoftDeletes::class, class_uses_recursive($models->first()), true)) {
            return $models;
        }

        return $models->reject->trashed()->values();
    }
}
