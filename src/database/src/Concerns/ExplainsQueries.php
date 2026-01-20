<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Hypervel\Support\Collection;

trait ExplainsQueries
{
    /**
     * Explains the query.
     */
    public function explain(): Collection
    {
        $sql = $this->toSql();

        $bindings = $this->getBindings();

        $explanation = $this->getConnection()->select('EXPLAIN ' . $sql, $bindings);

        return new Collection($explanation);
    }
}
