<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

trait ChoosesConnection
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return 'pgsql';
    }
}
