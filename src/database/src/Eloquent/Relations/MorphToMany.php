<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\Pivot = \Hypervel\Database\Eloquent\Relations\MorphPivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends \Hypervel\Database\Eloquent\Relations\BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
class MorphToMany extends BelongsToMany
{
    /**
     * The type of the polymorphic relation.
     */
    protected string $morphType;

    /**
     * The class name of the morph type constraint.
     *
     * @var class-string<TRelatedModel>
     */
    protected string $morphClass;

    /**
     * Indicates if we are connecting the inverse of the relation.
     *
     * This primarily affects the morphClass constraint.
     */
    protected bool $inverse;

    /**
     * Create a new morph to many relationship instance.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
        bool $inverse = false,
    ) {
        $this->inverse = $inverse;
        $this->morphType = $name.'_type';
        $this->morphClass = $inverse ? $query->getModel()->getMorphClass() : $parent->getMorphClass();

        parent::__construct(
            $query, $parent, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey, $relatedKey, $relationName
        );
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function addWhereConstraints(): static
    {
        parent::addWhereConstraints();

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);

        return $this;
    }

    /** @inheritDoc */
    public function addEagerConstraints(array $models)
    {
        parent::addEagerConstraints($models);

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);
    }

    /**
     * Create a new pivot attachment record.
     */
    protected function baseAttachRecord(int $id, bool $timed): array
    {
        return Arr::add(
            parent::baseAttachRecord($id, $timed), $this->morphType, $this->morphClass
        );
    }

    /** @inheritDoc */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->where(
            $this->qualifyPivotColumn($this->morphType), $this->morphClass
        );
    }

    /**
     * Get the pivot models that are currently attached, filtered by related model keys.
     *
     * @return \Hypervel\Support\Collection<int, TPivotModel>
     */
    protected function getCurrentlyAttachedPivotsForIds(mixed $ids = null): Collection
    {
        return parent::getCurrentlyAttachedPivotsForIds($ids)->map(function ($record) {
            return $record instanceof MorphPivot
                ? $record->setMorphType($this->morphType)
                    ->setMorphClass($this->morphClass)
                : $record;
        });
    }

    /**
     * Create a new query builder for the pivot table.
     */
    public function newPivotQuery(): QueryBuilder
    {
        return parent::newPivotQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Create a new pivot model instance.
     *
     * @return TPivotModel
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $using = $this->using;

        $attributes = array_merge([$this->morphType => $this->morphClass], $attributes);

        $pivot = $using
            ? $using::fromRawAttributes($this->parent, $attributes, $this->table, $exists)
            : MorphPivot::fromAttributes($this->parent, $attributes, $this->table, $exists);

        $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
            ->setRelatedModel($this->related)
            ->setMorphType($this->morphType)
            ->setMorphClass($this->morphClass);

        return $pivot;
    }

    /**
     * Get the pivot columns for the relation.
     *
     * "pivot_" is prefixed at each column for easy removal later.
     */
    protected function aliasedPivotColumns(): array
    {
        return (new Collection([
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            $this->morphType,
            ...$this->pivotColumns,
        ]))
            ->map(fn ($column) => $this->qualifyPivotColumn($column).' as pivot_'.$column)
            ->unique()
            ->all();
    }

    /**
     * Get the foreign key "type" name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the fully qualified morph type for the relation.
     */
    public function getQualifiedMorphTypeName(): string
    {
        return $this->qualifyPivotColumn($this->morphType);
    }

    /**
     * Get the class name of the parent model.
     *
     * @return class-string<TRelatedModel>
     */
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    /**
     * Get the indicator for a reverse relationship.
     */
    public function getInverse(): bool
    {
        return $this->inverse;
    }
}
