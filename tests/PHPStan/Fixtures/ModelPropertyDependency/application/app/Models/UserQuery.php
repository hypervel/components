<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

use Hypervel\Database\Query\Builder;

class UserQuery extends Builder
{
    /**
     * Constrain the query to active rows.
     */
    public function onlyActive(): static
    {
        return $this->where('active', true);
    }
}
