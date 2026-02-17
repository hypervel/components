<?php

declare(strict_types=1);

namespace Hypervel\Database;

use RuntimeException;

class LazyLoadingViolationException extends RuntimeException
{
    /**
     * The name of the affected Eloquent model.
     */
    public string $model;

    /**
     * The name of the relation.
     */
    public string $relation;

    /**
     * Create a new exception instance.
     */
    public function __construct(object $model, string $relation)
    {
        $class = get_class($model);

        parent::__construct("Attempted to lazy load [{$relation}] on model [{$class}] but lazy loading is disabled.");

        $this->model = $class;
        $this->relation = $relation;
    }
}
