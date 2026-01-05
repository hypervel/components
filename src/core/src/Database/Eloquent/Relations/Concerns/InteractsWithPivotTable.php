<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Concerns;

use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Relations\Pivot;

/**
 * Overrides Hyperf's InteractsWithPivotTable to support pivot model events.
 *
 * When a custom pivot class is specified via `->using()`, operations like
 * attach/detach/update use model methods (save/delete) instead of raw queries,
 * enabling model events (creating, created, deleting, deleted, etc.) to fire.
 *
 * Without `->using()`, the parent's performant bulk query behavior is preserved.
 */
trait InteractsWithPivotTable
{
    /**
     * Attach a model to the parent.
     *
     * @param mixed $id
     * @param bool $touch
     */
    public function attach($id, array $attributes = [], $touch = true)
    {
        if ($this->using) {
            $this->attachUsingCustomClass($id, $attributes);
        } else {
            parent::attach($id, $attributes, $touch);

            return;
        }

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * Attach a model to the parent using a custom class.
     *
     * @param mixed $ids
     */
    protected function attachUsingCustomClass($ids, array $attributes)
    {
        $records = $this->formatAttachRecords(
            $this->parseIds($ids),
            $attributes
        );

        foreach ($records as $record) {
            $this->newPivot($record, false)->save();
        }
    }

    /**
     * Detach models from the relationship.
     *
     * @param mixed $ids
     * @param bool $touch
     */
    public function detach($ids = null, $touch = true)
    {
        if ($this->using) {
            $results = $this->detachUsingCustomClass($ids);
        } else {
            return parent::detach($ids, $touch);
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * Detach models from the relationship using a custom class.
     *
     * @param mixed $ids
     * @return int
     */
    protected function detachUsingCustomClass($ids)
    {
        $results = 0;

        $pivots = $this->getCurrentlyAttachedPivots($ids);

        foreach ($pivots as $pivot) {
            $results += $pivot->delete();
        }

        return $results;
    }

    /**
     * Update an existing pivot record on the table.
     *
     * @param mixed $id
     * @param bool $touch
     */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        if ($this->using) {
            return $this->updateExistingPivotUsingCustomClass($id, $attributes, $touch);
        }

        return parent::updateExistingPivot($id, $attributes, $touch);
    }

    /**
     * Update an existing pivot record on the table via a custom class.
     *
     * @param mixed $id
     * @return int
     */
    protected function updateExistingPivotUsingCustomClass($id, array $attributes, bool $touch)
    {
        $pivot = $this->getCurrentlyAttachedPivots($id)->first();

        $updated = $pivot ? $pivot->fill($attributes)->isDirty() : false;

        if ($updated) {
            $pivot->save();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return (int) $updated;
    }

    /**
     * Get the pivot models that are currently attached.
     *
     * @param mixed $ids
     */
    protected function getCurrentlyAttachedPivots($ids = null): Collection
    {
        $query = $this->newPivotQuery();

        if ($ids !== null) {
            $query->whereIn($this->relatedPivotKey, $this->parseIds($ids));
        }

        return $query->get()->map(function ($record) {
            /** @var class-string<Pivot> $class */
            $class = $this->using ?: Pivot::class;

            return $class::fromRawAttributes($this->parent, (array) $record, $this->getTable(), true)
                ->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);
        });
    }

    /**
     * Create a new pivot model instance.
     *
     * Overrides parent to include pivotValues in the attributes.
     *
     * @param bool $exists
     */
    public function newPivot(array $attributes = [], $exists = false)
    {
        $attributes = array_merge(
            array_column($this->pivotValues, 'value', 'column'),
            $attributes
        );

        /** @var Pivot $pivot */
        $pivot = $this->related->newPivot(
            $this->parent,
            $attributes,
            $this->table,
            $exists,
            $this->using
        );

        return $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);
    }
}
