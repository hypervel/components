<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration\Postgres;

use Hypervel\Tests\Database\Integration\IntegrationTestCase;

/**
 * Base test case for PostgreSQL-specific integration tests.
 *
 * @internal
 */
abstract class PostgresIntegrationTestCase extends IntegrationTestCase
{
    protected function getDatabaseDriver(): string
    {
        return 'pgsql';
    }
}
