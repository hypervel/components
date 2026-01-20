<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hyperf\Database\Model\Relations\BelongsToMany;
use Hyperf\Database\Model\Relations\HasOneOrMany;
use Hyperf\Database\Model\Relations\MorphOneOrMany;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;

class Relationship
{
    /**
     * Create a new child relationship instance.
     * @param Factory $factory the related factory instance
     * @param string $relationship the relationship name
     */
    public function __construct(
        protected Factory $factory,
        protected string $relationship
    ) {
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
            ])->create([], $parent);
        } elseif ($relationship instanceof HasOneOrMany) {
            $this->factory->state([
                $relationship->getForeignKeyName() => $relationship->getParentKey(),
            ])->create([], $parent);
        } elseif ($relationship instanceof BelongsToMany) {
            $relationship->attach($this->factory->create([], $parent));
        }
    }

    /**
     * Specify the model instances to always use when creating relationships.
     */
    public function recycle(Collection $recycle): self
    {
        $this->factory = $this->factory->recycle($recycle);

        return $this;
    }
}
