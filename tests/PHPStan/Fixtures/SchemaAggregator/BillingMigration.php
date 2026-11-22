<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Migrations\Migration;

abstract class BillingMigration extends Migration
{
    /**
     * Get the configured billing connection.
     */
    public function getConnection(): ?string
    {
        return config('billing.connection');
    }

    /**
     * Determine whether billing is enabled.
     */
    public function shouldRun(): bool
    {
        return config()->boolean('billing.enabled', true);
    }
}
