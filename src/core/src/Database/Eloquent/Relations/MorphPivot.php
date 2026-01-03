<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Database\Model\Relations\MorphPivot as BaseMorphPivot;
use Hyperf\DbConnection\Traits\HasContainer;
use Hypervel\Database\Eloquent\Concerns\HasCallbacks;
use Hypervel\Database\Eloquent\Concerns\HasObservers;

/**
 * MorphPivot class with event dispatcher support.
 *
 * Uses HasContainer to get the event dispatcher from the container,
 * enabling model events (creating, created, deleting, deleted, etc.) to fire.
 */
class MorphPivot extends BaseMorphPivot
{
    use HasCallbacks;
    use HasObservers;
    use HasContainer;

    /**
     * Delete the pivot model record from the database.
     *
     * Overrides parent to fire deleting/deleted events even for composite key pivots,
     * while maintaining the morph type constraint.
     */
    public function delete(): mixed
    {
        // If pivot has a primary key, use parent's delete which fires events
        if (isset($this->attributes[$this->getKeyName()])) {
            return parent::delete();
        }

        // For composite key pivots, manually fire events around the raw delete
        if ($this->fireModelEvent('deleting') === false) {
            return 0;
        }

        $query = $this->getDeleteQuery();

        // Add morph type constraint (from Hyperf's MorphPivot::delete())
        $query->where($this->morphType, $this->morphClass);

        $result = $query->delete();

        $this->exists = false;

        $this->fireModelEvent('deleted');

        return $result;
    }
}
