<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use RuntimeException;

class InvalidCastException extends RuntimeException
{
    /**
     * The name of the affected Eloquent model.
     */
    public string $model;

    /**
     * The name of the column.
     */
    public string $column;

    /**
     * The name of the cast type.
     */
    public string $castType;

    /**
     * Create a new exception instance.
     */
    public function __construct(object $model, string $column, string $castType)
    {
        $class = get_class($model);

        parent::__construct("Call to undefined cast [{$castType}] on column [{$column}] in model [{$class}].");

        $this->model = $class;
        $this->column = $column;
        $this->castType = $castType;
    }
}
