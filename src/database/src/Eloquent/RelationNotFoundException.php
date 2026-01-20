<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use RuntimeException;

class RelationNotFoundException extends RuntimeException
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
    public static function make(object $model, string $relation, ?string $type = null): static
    {
        $class = get_class($model);

        $instance = new static(
            is_null($type)
                ? "Call to undefined relationship [{$relation}] on model [{$class}]."
                : "Call to undefined relationship [{$relation}] on model [{$class}] of type [{$type}].",
        );

        $instance->model = $class;
        $instance->relation = $relation;

        return $instance;
    }
}
