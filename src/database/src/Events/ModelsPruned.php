<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

class ModelsPruned
{
    /**
     * Create a new event instance.
     *
     * @param string $model the class name of the model that was pruned
     * @param int $count the number of pruned records
     */
    public function __construct(
        public string $model,
        public int $count,
    ) {
    }
}
