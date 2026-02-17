<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

/**
 * Collection wrapper that uses Scout keys for queue serialization.
 *
 * When models are queued for removal, we need to preserve their Scout keys
 * rather than their database IDs, as the models may already be deleted.
 *
 * @template TKey of array-key
 * @template TModel of Model&SearchableInterface
 * @extends Collection<TKey, TModel>
 */
class RemoveableScoutCollection extends Collection
{
    /**
     * Get the Scout identifiers for all of the entities.
     *
     * @return array<mixed>
     */
    public function getQueueableIds(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        /** @var Model&SearchableInterface $first */
        $first = $this->first();

        if (in_array(Searchable::class, class_uses_recursive($first))) {
            return $this->map(fn (SearchableInterface $model) => $model->getScoutKey())->all();
        }

        // Fallback to model primary keys (equivalent to Laravel's modelKeys())
        return $this->modelKeys();
    }
}
