<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;

class BelongsToManyRelationship
{
    /**
     * The related factory instance.
     */
    protected Factory|Collection|Model|array $factory;

    /**
     * The pivot attributes / attribute resolver.
     *
     * @var callable|array
     */
    protected mixed $pivot;

    /**
     * The relationship name.
     */
    protected string $relationship;

    /**
     * Create a new attached relationship definition.
     *
     * @param callable|array $pivot
     */
    public function __construct(Factory|Collection|Model|array $factory, callable|array $pivot, string $relationship)
    {
        $this->factory = $factory;
        $this->pivot = $pivot;
        $this->relationship = $relationship;
    }

    /**
     * Create the attached relationship for the given model.
     */
    public function createFor(Model $model): void
    {
        $factoryInstance = $this->factory instanceof Factory;

        if ($factoryInstance) {
            $relationship = $model->{$this->relationship}();
        }

        Collection::wrap($factoryInstance ? $this->factory->prependState($relationship->getQuery()->pendingAttributes)->create([], $model) : $this->factory)->each(function ($attachable) use ($model) {
            $model->{$this->relationship}()->attach(
                $attachable,
                is_callable($this->pivot) ? call_user_func($this->pivot, $model) : $this->pivot
            );
        });
    }

    /**
     * Specify the model instances to always use when creating relationships.
     *
     * @return $this
     */
    public function recycle(Collection $recycle): static
    {
        if ($this->factory instanceof Factory) {
            $this->factory = $this->factory->recycle($recycle);
        }

        return $this;
    }
}
