<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tmp;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Tests\Support\DatabaseIntegrationTestCase;

/**
 * Base test case for Tmp integration tests with custom migrations.
 *
 * @internal
 */
abstract class TmpIntegrationTestCase extends DatabaseIntegrationTestCase
{
    use RefreshDatabase;

    /**
     * Force fresh migration since Tmp tests have custom migrations
     * that differ from the default application migrations.
     */
    protected bool $migrateRefresh = true;

    protected function getDatabaseDriver(): string
    {
        return 'pgsql';
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }
}
