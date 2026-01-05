<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\DbConnection\Model\Relations\MorphPivot as BaseMorphPivot;
use Hypervel\Database\Eloquent\Concerns\HasAttributes;
use Hypervel\Database\Eloquent\Concerns\HasCallbacks;
use Hypervel\Database\Eloquent\Concerns\HasGlobalScopes;
use Hypervel\Database\Eloquent\Concerns\HasObservers;
use Psr\EventDispatcher\StoppableEventInterface;

class MorphPivot extends BaseMorphPivot
{
    use HasAttributes;
    use HasCallbacks;
    use HasGlobalScopes;
    use HasObservers;

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
        if ($event = $this->fireModelEvent('deleting')) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                return 0;
            }
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
