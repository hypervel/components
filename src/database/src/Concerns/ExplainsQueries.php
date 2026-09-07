<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Hypervel\Support\Collection;
use InvalidArgumentException;

trait ExplainsQueries
{
    /**
     * Explain the query.
     *
     * @throws InvalidArgumentException
     */
    public function explain(): Collection
    {
        if ($this->timeout !== null) {
            throw new InvalidArgumentException(
                'A query timeout cannot be applied to an EXPLAIN statement. Clear the timeout before calling explain().'
            );
        }

        $sql = $this->toSql();

        $bindings = $this->getBindings();

        $explanation = $this->getConnection()->select('EXPLAIN ' . $sql, $bindings);

        return new Collection($explanation);
    }
}
