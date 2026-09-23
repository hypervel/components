<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOneOrMany<TRelatedModel, TDeclaringModel, EloquentCollection<int, TRelatedModel>>
 */
class HasMany extends HasOneOrMany
{
    /**
     * Convert the relationship to a "has one" relationship.
     *
     * @return HasOne<TRelatedModel, TDeclaringModel>
     */
    public function one(): HasOne
    {
        return HasOne::noConstraints(fn () => tap(
            new HasOne(
                $this->getQuery(),
                $this->parent,
                $this->foreignKey,
                $this->localKey
            ),
            function ($hasOne) {
                if ($inverse = $this->getInverseRelationship()) {
                    $hasOne->inverse($inverse);
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
}
