<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Database\Model\Relations\MorphTo as BaseMorphTo;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\WithoutAddConstraints;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TChildModel of \Hypervel\Database\Eloquent\Model
 *
 * @implements RelationContract<TRelatedModel, TChildModel, null|TRelatedModel>
 *
 * @method \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> get(array|string $columns = ['*'])
 * @method TRelatedModel make(array $attributes = [])
 * @method TRelatedModel create(array $attributes = [])
 * @method TRelatedModel firstOrNew(array $attributes = [], array $values = [])
 * @method TRelatedModel firstOrCreate(array $attributes = [], array $values = [])
 * @method TRelatedModel updateOrCreate(array $attributes, array $values = [])
 * @method null|TRelatedModel first(array|string $columns = ['*'])
 * @method TRelatedModel firstOrFail(array|string $columns = ['*'])
 * @method TRelatedModel findOrFail(mixed $id, array|string $columns = ['*'])
 * @method null|TRelatedModel find(mixed $id, array|string $columns = ['*'])
 * @method \Hypervel\Database\Eloquent\Builder<TRelatedModel> getQuery()
 * @method string getMorphType()
 * @method TRelatedModel createModelByType(string $type)
 * @method null|TRelatedModel getResults()
 * @method \Hypervel\Database\Eloquent\Collection<int, TChildModel> getEager()
 * @method TChildModel associate(\Hyperf\Database\Model\Model $model)
 * @method TChildModel dissociate()
 */
class MorphTo extends BaseMorphTo implements RelationContract
{
    use WithoutAddConstraints;

    /**
     * @param string $type
     * @return TRelatedModel
     */
    public function createModelByType($type)
    {
        $class = Model::getActualClassNameForMorph($type);

        return new $class();
    }
}
