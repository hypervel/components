<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

class BillingConfiguration
{
    public static int $instances = 0;

    public int $calls = 0;

    /**
     * Create a billing configuration.
     */
    public function __construct(public mixed $name = 'pgsql')
    {
        ++self::$instances;
    }

    /**
     * Get the configured migration connection.
     */
    public function connection(): mixed
    {
        ++$this->calls;

        return $this->name;
    }
}
