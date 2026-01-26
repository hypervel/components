<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

/**
 * @mixin Builder
 */
class HigherOrderBuilderProxy
{
    /**
     * Create a new proxy instance.
     *
     * @param Builder<*> $builder
     */
    public function __construct(
        protected Builder $builder,
        protected string $method,
    ) {
    }

    /**
     * Proxy a scope call onto the query builder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->builder->{$this->method}(function ($value) use ($method, $parameters) {
            return $value->{$method}(...$parameters);
        });
    }
}
