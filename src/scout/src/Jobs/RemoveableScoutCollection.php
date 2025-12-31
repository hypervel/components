<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Searchable;


/**
 * Collection wrapper that uses Scout keys for queue serialization.
 *
 * When models are queued for removal, we need to preserve their Scout keys
 * rather than their database IDs, as the models may already be deleted.
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

        return in_array(Searchable::class, class_uses_recursive($this->first()))
            ? $this->map->getScoutKey()->all()
            : parent::getQueueableIds();
    }
}
