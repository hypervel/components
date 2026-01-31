<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasOneOrMany;
use Hypervel\Database\Eloquent\Relations\MorphOneOrMany;
use Hypervel\Support\Collection;

class Relationship
{
    /**
     * The related factory instance.
     */
    protected Factory $factory;

    /**
     * The relationship name.
     */
    protected string $relationship;

    /**
     * Create a new child relationship instance.
     */
    public function __construct(Factory $factory, string $relationship)
    {
        $this->factory = $factory;
        $this->relationship = $relationship;
    }

    /**
     * Create the child relationship for the given parent model.
     */
    public function createFor(Model $parent): void
    {
        $relationship = $parent->{$this->relationship}();

        if ($relationship instanceof MorphOneOrMany) {
            $this->factory->state([
                $relationship->getMorphType() => $relationship->getMorphClass(),
                $relationship->getForeignKeyName() => $relationship->getParentKey(),
            ])->prependState($relationship->getQuery()->pendingAttributes)->create([], $parent);
        } elseif ($relationship instanceof HasOneOrMany) {
            $this->factory->state([
                $relationship->getForeignKeyName() => $relationship->getParentKey(),
            ])->prependState($relationship->getQuery()->pendingAttributes)->create([], $parent);
        } elseif ($relationship instanceof BelongsToMany) {
            $relationship->attach(
                $this->factory->prependState($relationship->getQuery()->pendingAttributes)->create([], $parent)
            );
        }
    }

    /**
     * Specify the model instances to always use when creating relationships.
     *
     * @return $this
     */
    public function recycle(Collection $recycle): static
    {
        $this->factory = $this->factory->recycle($recycle);

        return $this;
    }
}
