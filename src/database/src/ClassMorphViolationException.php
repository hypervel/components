<?php

declare(strict_types=1);

namespace Hypervel\Database;

use RuntimeException;

class ClassMorphViolationException extends RuntimeException
{
    /**
     * The name of the affected Eloquent model.
     */
    public string $model;

    /**
     * Create a new exception instance.
     */
    public function __construct(object $model)
    {
        $class = get_class($model);

        parent::__construct("No morph map defined for model [{$class}].");

        $this->model = $class;
    }
}
