<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

class ModelPruningStarting
{
    /**
     * Create a new event instance.
     *
     * @param array<class-string> $models The class names of the models that will be pruned.
     */
    public function __construct(
        public array $models,
    ) {
    }
}
