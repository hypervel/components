<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Contracts;

interface UsesDatabaseConnection
{
    /**
     * Get the name of the database connection the driver currently uses.
     *
     * Values memoized while this connection has an open transaction are forgotten if it rolls back.
     */
    public function connectionName(): string;
}
