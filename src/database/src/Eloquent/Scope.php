<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

interface Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel> $builder
     * @param TModel $model
     */
    public function apply(Builder $builder, Model $model): void;
}
