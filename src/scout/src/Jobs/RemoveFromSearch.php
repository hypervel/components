<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Traits\ConfiguresJobOptions;

/**
 * Queue job that removes models from the search index.
 */
class RemoveFromSearch implements ShouldQueue
{
    use ConfiguresJobOptions;
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

        $this->configureJob();
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

    /**
     * Restore a queueable collection instance.
     */
    protected function restoreCollection(ModelIdentifier $value): RemoveableScoutCollection
    {
        $class = $value->getClass();

        if ($class === null || $value->id === []) {
            return new RemoveableScoutCollection;
        }

        /** @var array<int, int|string> $ids */
        $ids = $value->id;

        return new RemoveableScoutCollection(array_map(
            function (int|string $id) use ($class, $value): Model {
                /** @var Model&SearchableInterface $model */
                $model = (new $class)->setConnection($value->connection);

                return $model->setKeyType(is_string($id) ? 'string' : 'int')
                    ->forceFill([$model->getScoutKeyName() => $id]);
            },
            $ids
        ));
    }
}
