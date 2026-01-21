<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Builder;

class MorphPivot extends Pivot
{
    /**
     * The type of the polymorphic relation.
     *
     * Explicitly define this so it's not included in saved attributes.
     */
    protected string $morphType;

    /**
     * The value of the polymorphic relation.
     *
     * Explicitly define this so it's not included in saved attributes.
     *
     * @var class-string
     */
    protected string $morphClass;

    /**
     * Set the keys for a save update query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<static>  $query
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query->where($this->morphType, $this->morphClass);

        return parent::setKeysForSaveQuery($query);
    }

    /**
     * Set the keys for a select query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<static>  $query
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query->where($this->morphType, $this->morphClass);

        return parent::setKeysForSelectQuery($query);
    }

    /**
     * Delete the pivot model record from the database.
     */
    public function delete(): int
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return (int) parent::delete();
        }

        if ($this->fireModelEvent('deleting') === false) {
            return 0;
        }

        $query = $this->getDeleteQuery();

        $query->where($this->morphType, $this->morphClass);

        return tap($query->delete(), function () {
            $this->exists = false;

            $this->fireModelEvent('deleted', false);
        });
    }

    /**
     * Get the morph type for the pivot.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Set the morph type for the pivot.
     *
     * @return $this
     */
    public function setMorphType(string $morphType): static
    {
        $this->morphType = $morphType;

        return $this;
    }

    /**
     * Set the morph class for the pivot.
     *
     * @param  class-string  $morphClass
     * @return $this
     */
    public function setMorphClass(string $morphClass): static
    {
        $this->morphClass = $morphClass;

        return $this;
    }

    /**
     * Get the queueable identity for the entity.
     */
    public function getQueueableId(): mixed
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return $this->getKey();
        }

        return sprintf(
            '%s:%s:%s:%s:%s:%s',
            $this->foreignKey, $this->getAttribute($this->foreignKey),
            $this->relatedKey, $this->getAttribute($this->relatedKey),
            $this->morphType, $this->morphClass
        );
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    public function newQueryForRestoration(array|int|string $ids): Builder
    {
        if (is_array($ids)) {
            return $this->newQueryForCollectionRestoration($ids);
        }

        if (! str_contains($ids, ':')) {
            return parent::newQueryForRestoration($ids);
        }

        $segments = explode(':', $ids);

        return $this->newQueryWithoutScopes()
            ->where($segments[0], $segments[1])
            ->where($segments[2], $segments[3])
            ->where($segments[4], $segments[5]);
    }

    /**
     * Get a new query to restore multiple models by their queueable IDs.
     *
     * @return \Hypervel\Database\Eloquent\Builder<static>
     */
    protected function newQueryForCollectionRestoration(array $ids): Builder
    {
        $ids = array_values($ids);

        if (! str_contains($ids[0], ':')) {
            return parent::newQueryForRestoration($ids);
        }

        $query = $this->newQueryWithoutScopes();

        foreach ($ids as $id) {
            $segments = explode(':', $id);

            $query->orWhere(function ($query) use ($segments) {
                return $query->where($segments[0], $segments[1])
                    ->where($segments[2], $segments[3])
                    ->where($segments[4], $segments[5]);
            });
        }

        return $query;
    }
}
