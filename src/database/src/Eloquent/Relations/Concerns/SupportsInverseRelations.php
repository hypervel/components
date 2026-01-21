<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Concerns;

use Hyperf\Collection\Arr;
use Hypervel\Database\Eloquent\Model;
use Hyperf\Stringable\Str;
use Hypervel\Database\Eloquent\RelationNotFoundException;

trait SupportsInverseRelations
{
    /**
     * The name of the inverse relationship.
     */
    protected ?string $inverseRelationship = null;

    /**
     * Instruct Eloquent to link the related models back to the parent after the relationship query has run.
     *
     * Alias of "chaperone".
     *
     * @return $this
     */
    public function inverse(?string $relation = null): static
    {
        return $this->chaperone($relation);
    }

    /**
     * Instruct Eloquent to link the related models back to the parent after the relationship query has run.
     *
     * @return $this
     */
    public function chaperone(?string $relation = null): static
    {
        $relation ??= $this->guessInverseRelation();

        if (! $relation || ! $this->getModel()->isRelation($relation)) {
            throw RelationNotFoundException::make($this->getModel(), $relation ?: 'null');
        }

        if ($this->inverseRelationship === null && $relation) {
            $this->query->afterQuery(function ($result) {
                return $this->inverseRelationship
                    ? $this->applyInverseRelationToCollection($result, $this->getParent())
                    : $result;
            });
        }

        $this->inverseRelationship = $relation;

        return $this;
    }

    /**
     * Guess the name of the inverse relationship.
     */
    protected function guessInverseRelation(): ?string
    {
        return Arr::first(
            $this->getPossibleInverseRelations(),
            fn ($relation) => $relation && $this->getModel()->isRelation($relation)
        );
    }

    /**
     * Get the possible inverse relations for the parent model.
     *
     * @return array<non-empty-string>
     */
    protected function getPossibleInverseRelations(): array
    {
        return array_filter(array_unique([
            Str::camel(Str::beforeLast($this->getForeignKeyName(), $this->getParent()->getKeyName())),
            Str::camel(Str::beforeLast($this->getParent()->getForeignKey(), $this->getParent()->getKeyName())),
            Str::camel(class_basename($this->getParent())),
            'owner',
            get_class($this->getParent()) === get_class($this->getModel()) ? 'parent' : null,
        ]));
    }

    /**
     * Set the inverse relation on all models in a collection.
     *
     * @template TCollection of \Hypervel\Database\Eloquent\Collection
     * @param TCollection $models
     * @return TCollection
     */
    protected function applyInverseRelationToCollection(mixed $models, ?Model $parent = null): mixed
    {
        $parent ??= $this->getParent();

        foreach ($models as $model) {
            $model instanceof Model && $this->applyInverseRelationToModel($model, $parent);
        }

        return $models;
    }

    /**
     * Set the inverse relation on a model.
     */
    protected function applyInverseRelationToModel(Model $model, ?Model $parent = null): Model
    {
        if ($inverse = $this->getInverseRelationship()) {
            $parent ??= $this->getParent();

            $model->setRelation($inverse, $parent);
        }

        return $model;
    }

    /**
     * Get the name of the inverse relationship.
     */
    public function getInverseRelationship(): ?string
    {
        return $this->inverseRelationship;
    }

    /**
     * Remove the chaperone / inverse relationship for this query.
     *
     * Alias of "withoutChaperone".
     *
     * @return $this
     */
    public function withoutInverse(): static
    {
        return $this->withoutChaperone();
    }

    /**
     * Remove the chaperone / inverse relationship for this query.
     *
     * @return $this
     */
    public function withoutChaperone(): static
    {
        $this->inverseRelationship = null;

        return $this;
    }
}
