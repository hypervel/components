<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Queueable;
use Hypervel\Scout\Contracts\SearchableInterface;

/**
 * Queue job that removes models from the search index.
 */
class RemoveFromSearch implements ShouldQueue
{
    use Queueable;

    /**
     * The models to be removed from the search index.
     */
    public RemoveableScoutCollection $models;

    /**
     * Create a new job instance.
     *
     * @param Collection<int, Model&SearchableInterface> $models
     */
    public function __construct(Collection $models)
    {
        $this->models = RemoveableScoutCollection::make($models);
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        if ($this->models->isNotEmpty()) {
            /** @var Model&SearchableInterface $firstModel */
            $firstModel = $this->models->first();
            $firstModel->searchableUsing()->delete($this->models);
        }
    }
}
