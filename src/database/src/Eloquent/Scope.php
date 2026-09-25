<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

/**
 * @template TModel of Model
 */
interface Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param Builder<covariant TModel> $builder
     * @param TModel $model
     */
    public function apply(Builder $builder, Model $model): void;
}
