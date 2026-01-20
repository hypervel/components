<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Relations\MorphToMany as BaseMorphToMany;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;
use Hypervel\Database\Eloquent\Relations\Concerns\WithoutAddConstraints;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TParentModel of \Hypervel\Database\Eloquent\Model
 * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\MorphPivot = \Hypervel\Database\Eloquent\Relations\MorphPivot
 * @template TAccessor of string = 'pivot'
 *
 * @implements RelationContract<TRelatedModel, TParentModel, \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel>>
 *
 * @method \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> get(array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel make(array $attributes = [])
 * @method object{pivot: TPivotModel}&TRelatedModel create(array $attributes = [])
 * @method object{pivot: TPivotModel}&TRelatedModel firstOrNew(array $attributes = [], array $values = [])
 * @method object{pivot: TPivotModel}&TRelatedModel firstOrCreate(array $attributes = [], array $values = [])
 * @method object{pivot: TPivotModel}&TRelatedModel updateOrCreate(array $attributes, array $values = [])
 * @method null|(object{pivot: TPivotModel}&TRelatedModel) first(array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel firstOrFail(array|string $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> findMany(mixed $ids, array|string $columns = ['*'])
 * @method object{pivot: TPivotModel}&TRelatedModel findOrFail(mixed $id, array|string $columns = ['*'])
 * @method null|(object{pivot: TPivotModel}&TRelatedModel) find(mixed $id, array|string $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Builder<TRelatedModel> getQuery()
 * @method void attach(mixed $id, array $attributes = [], bool $touch = true)
 * @method int detach(mixed $ids = null, bool $touch = true)
 * @method void sync(array|\Hypervel\Support\Collection $ids, bool $detaching = true)
 * @method void syncWithoutDetaching(array|\Hypervel\Support\Collection $ids)
 * @method void toggle(mixed $ids, bool $touch = true)
 * @method string getMorphType()
 * @method string getMorphClass()
 * @method \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel> lazy(int $chunkSize = 1000)
 * @method \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel> lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel> lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null)
 * @method \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel> getResults()
 */
class MorphToMany extends BaseMorphToMany implements RelationContract
{
    use InteractsWithPivotTable;
    use WithoutAddConstraints;

    /**
     * Get the pivot models that are currently attached.
     *
     * Overrides trait to use MorphPivot and set morph type/class on the pivot models.
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
            /** @var class-string<MorphPivot> $class */
            $class = $this->using ?: MorphPivot::class;

            $pivot = $class::fromRawAttributes($this->parent, (array) $record, $this->getTable(), true)
                ->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);

            if ($pivot instanceof MorphPivot) {
                $pivot->setMorphType($this->morphType)
                    ->setMorphClass($this->morphClass);
            }

            return $pivot;
        });
    }

    /**
     * Create a new pivot model instance.
     *
     * Overrides parent to include pivotValues and set morph type/class.
     *
     * @param bool $exists
     * @return TPivotModel
     */
    public function newPivot(array $attributes = [], $exists = false)
    {
        $attributes = array_merge(
            array_column($this->pivotValues, 'value', 'column'),
            $attributes
        );

        $using = $this->using;

        $pivot = $using ? $using::fromRawAttributes($this->parent, $attributes, $this->table, $exists)
                        : MorphPivot::fromAttributes($this->parent, $attributes, $this->table, $exists);

        $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
            ->setMorphType($this->morphType)
            ->setMorphClass($this->morphClass);

        return $pivot;
    }
}
