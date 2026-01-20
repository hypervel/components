<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;

class BelongsToManyRelationship
{
    /**
     * Create a new attached relationship definition.
     * @param $factory the related factory instance or model, or an array of models
     * @param array|callable $pivot the pivot attributes / attribute resolver
     * @param $relationship The relationship name
     */
    public function __construct(
        protected array|EloquentCollection|Factory|Model $factory,
        protected mixed $pivot,
        protected string $relationship
    ) {
    }

    /**
     * Create the attached relationship for the given model.
     */
    public function createFor(Model $model): void
    {
        Collection::wrap($this->factory instanceof Factory ? $this->factory->create([], $model) : $this->factory)
            ->each(function ($attachable) use ($model) {
                $model->{$this->relationship}()->attach(
                    $attachable,
                    is_callable($this->pivot) ? call_user_func($this->pivot, $model) : $this->pivot
                );
            });
    }

    /**
     * Specify the model instances to always use when creating relationships.
     */
    public function recycle(Collection $recycle): self
    {
        if ($this->factory instanceof Factory) {
            $this->factory = $this->factory->recycle($recycle);
        }

        return $this;
    }
}
