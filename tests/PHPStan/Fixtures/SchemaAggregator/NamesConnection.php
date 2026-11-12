<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

trait NamesConnection
{
    /**
     * Get the name of the connection the migration runs on.
     */
    public function connectionName(): ?string
    {
        return 'pgsql';
    }
}
