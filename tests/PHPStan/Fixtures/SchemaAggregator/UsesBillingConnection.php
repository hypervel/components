<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

trait UsesBillingConnection
{
    /**
     * Get the configured billing connection.
     */
    public function getConnection(): ?string
    {
        return config('billing.connection');
    }
}
