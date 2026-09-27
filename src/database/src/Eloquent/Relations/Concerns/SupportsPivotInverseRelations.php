<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Concerns;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\RelationNotFoundException;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

trait SupportsPivotInverseRelations
{
    /**
     * The name of the declaring model's relationship on the pivot.
     */
    protected ?string $declaringInverseRelationship = null;

    /**
     * The name of the related model's relationship on the pivot.
     */
    protected ?string $relatedInverseRelationship = null;

    /**
     * Instruct Eloquent to link the declaring and related models back to the pivot after the relationship query has run.
     *
     * @return $this
     */
    public function chaperone(?string $declaring = null, ?string $related = null): static
    {
        if (! $this->using) {
            return $this;
        }

        $pivotModel = new $this->using;

        $this->declaringInverseRelationship = $this->resolvePivotInverseRelation(
            $pivotModel,
            $declaring,
            $this->foreignPivotKey,
            $this->parent
        );

        $this->relatedInverseRelationship = $this->resolvePivotInverseRelation(
            $pivotModel,
            $related,
            $this->relatedPivotKey,
            $this->related
        );

        return $this;
    }

    /**
     * Remove the chaperone relationships for this query.
     *
     * @return $this
     */
    public function withoutChaperone(): static
    {
        $this->declaringInverseRelationship = null;
        $this->relatedInverseRelationship = null;

        return $this;
    }

    /**
     * Resolve the inverse relation name on the pivot for a given model.
     *
     * If an explicit name is provided and invalid, an exception is thrown.
     * If guessing fails, null is returned.
     *
     * @throws RelationNotFoundException
     */
    protected function resolvePivotInverseRelation(Model $pivotModel, ?string $relation, string $foreignKey, Model $model): ?string
    {
        if ($relation !== null) {
            if (! $pivotModel->isRelation($relation)) {
                throw RelationNotFoundException::make($pivotModel, $relation);
            }

            return $relation;
        }

        return $this->guessPivotInverseRelation($pivotModel, $foreignKey, $model);
    }

    /**
     * Attempt to guess the inverse relation name on the pivot for a given model.
     */
    protected function guessPivotInverseRelation(Model $pivotModel, string $foreignKey, Model $model): ?string
    {
        $candidates = array_filter(array_unique([
            Str::camel(Str::beforeLast($foreignKey, $model->getKeyName())),
            // A shared model name cannot distinguish the two sides of the pivot.
            class_basename($this->parent) !== class_basename($this->related) ? Str::camel(class_basename($model)) : null,
        ]));

        return Arr::first(
            $candidates,
            fn (string $relation): bool => $pivotModel->isRelation($relation)
        );
    }

    /**
     * Apply chaperone relationships to a pivot model instance.
     */
    protected function applyChaperonesToPivot(Model $pivot, Model $declaring, Model $related): void
    {
        if ($this->declaringInverseRelationship) {
            $pivot->setRelation($this->declaringInverseRelationship, $declaring);
        }

        if ($this->relatedInverseRelationship) {
            $pivot->setRelation($this->relatedInverseRelationship, $related);
        }
    }
}
