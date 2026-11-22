<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Migrations\Migration;

abstract class SentinelMigration extends Migration
{
    public static int $executions = 0;

    /**
     * Record migration construction.
     */
    public function __construct()
    {
        ++self::$executions;
    }

    /**
     * Record migration getter execution.
     */
    public function getConnection(): ?string
    {
        ++self::$executions;

        return app(BillingConfiguration::class)->connection();
    }
}
