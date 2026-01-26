<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hypervel\Database\Eloquent\Relations\MorphOneOrMany<TRelatedModel, TDeclaringModel, \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>>
 */
class MorphMany extends MorphOneOrMany
{
    /**
     * Convert the relationship to a "morph one" relationship.
     *
     * @return \Hypervel\Database\Eloquent\Relations\MorphOne<TRelatedModel, TDeclaringModel>
     */
    public function one(): MorphOne
    {
        return MorphOne::noConstraints(fn () => tap(
            new MorphOne(
                $this->getQuery(),
                $this->getParent(),
                $this->morphType,
                $this->foreignKey,
                $this->localKey
            ),
            function ($morphOne) {
                if ($inverse = $this->getInverseRelationship()) {
                    $morphOne->inverse($inverse);
                }
            }
        ));
    }

    public function getResults()
    {
        return ! is_null($this->getParentKey())
            ? $this->query->get()
            : $this->related->newCollection();
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, EloquentCollection $results, string $relation): array
    {
        return $this->matchMany($models, $results, $relation);
    }

    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getMorphType()] = $this->morphClass;

        return parent::forceCreate($attributes);
    }
}
