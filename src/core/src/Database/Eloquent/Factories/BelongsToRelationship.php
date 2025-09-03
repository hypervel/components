<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Closure;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hyperf\Database\Model\Relations\MorphTo;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;

class BelongsToRelationship
{
    /**
     * The cached, resolved parent instance ID.
     */
    protected int|string|null $resolved = null;

    /**
     * Create a new "belongs to" relationship definition.
     * @param Factory|Model $factory the related factory instance or model
     * @param string $relationship the relationship name
     */
    public function __construct(
        protected Factory|Model $factory,
        protected string $relationship
    ) {
    }

    /**
     * Get the parent model attributes and resolvers for the given child model.
     *
     * @return array<string, Closure|string>
     */
    public function attributesFor(Model $model): array
    {
        /** @var BelongsTo|MorphTo $relationship */
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
     */
    public function recycle(Collection $recycle): self
    {
        if ($this->factory instanceof Factory) {
            $this->factory = $this->factory->recycle($recycle);
        }

        return $this;
    }
}
