<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Support\Collection;

class BelongsToRelationship
{
    /**
     * The related factory instance.
     */
    protected Factory|Model $factory;

    /**
     * The relationship name.
     */
    protected string $relationship;

    /**
     * The cached, resolved parent instance ID.
     */
    protected mixed $resolved;

    /**
     * Create a new "belongs to" relationship definition.
     */
    public function __construct(Factory|Model $factory, string $relationship)
    {
        $this->factory = $factory;
        $this->relationship = $relationship;
    }

    /**
     * Get the parent model attributes and resolvers for the given child model.
     */
    public function attributesFor(Model $model): array
    {
        $relationship = $model->{$this->relationship}();

        return $relationship instanceof MorphTo ? [
            $relationship->getMorphType() => $this->factory instanceof Factory ? $this->factory->newModel()->getMorphClass() : $this->factory->getMorphClass(),
            $relationship->getForeignKeyName() => $this->resolver($relationship->getOwnerKeyName()),
        ] : [
            $relationship->getForeignKeyName() => $this->resolver($relationship->getOwnerKeyName()),
        ];
    }

    /**
     * Get the deferred resolver for this relationship's parent ID.
     */
    protected function resolver(?string $key): Closure
    {
        return function () use ($key) {
            if (! $this->resolved) {
                $instance = $this->factory instanceof Factory
                    ? ($this->factory->getRandomRecycledModel($this->factory->modelName()) ?? $this->factory->create())
                    : $this->factory;

                return $this->resolved = $key ? $instance->{$key} : $instance->getKey();
            }

            return $this->resolved;
        };
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
