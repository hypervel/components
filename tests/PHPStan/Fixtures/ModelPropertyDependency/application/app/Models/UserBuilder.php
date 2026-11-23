<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class UserBuilder extends Builder
{
    /** @var UserQuery */
    protected QueryBuilder $query;

    protected array $passthru = ['exists'];

    /**
     * Get the underlying query builder instance.
     */
    public function getQuery(): UserQuery
    {
        return $this->query;
    }

    /**
     * Constrain the query to published users.
     */
    public function published(): static
    {
        return $this->whereNotNull('published_at');
    }
}
