<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\DbConnection\Model\Relations\Pivot as BasePivot;
use Hypervel\Database\Eloquent\Concerns\HasAttributes;
use Hypervel\Database\Eloquent\Concerns\HasCallbacks;
use Hypervel\Database\Eloquent\Concerns\HasObservers;

class Pivot extends BasePivot
{
    use HasAttributes;
    use HasCallbacks;
    use HasObservers;

    /**
     * Delete the pivot model record from the database.
     *
     * Overrides parent to fire deleting/deleted events even for composite key pivots.
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

        $result = $this->getDeleteQuery()->delete();

        $this->exists = false;

        $this->fireModelEvent('deleted');

        return $result;
    }
}
