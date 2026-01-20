<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

class ModelPruningFinished
{
    /**
     * Create a new event instance.
     *
     * @param array<class-string> $models The class names of the models that were pruned.
     */
    public function __construct(
        public array $models,
    ) {
    }
}
