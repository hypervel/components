<?php

declare(strict_types=1);

namespace Hypervel\Database\Contracts\Eloquent;

use Closure;
use Hypervel\Database\Eloquent\Builder;

interface SupportsPartialRelations
{
    /**
     * Indicate that the relation is a single result of a larger one-to-many relationship.
     *
     * @return $this
     */
    public function ofMany(string|null $column = 'id', string|Closure|null $aggregate = 'MAX', ?string $relation = null);

    /**
     * Determine whether the relationship is a one-of-many relationship.
     */
    public function isOneOfMany(): bool;

    /**
     * Get the one of many inner join subselect query builder instance.
     */
    public function getOneOfManySubQuery(): Builder|null;
}
