<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

#[WithConfig('database.default', 'testing')]
class LoadMigrationsFromUsingDatabaseMigrationsTest extends TestCase
{
    use DatabaseMigrations;

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    #[Test]
    public function itRegistersPackageMigrationsBeforeRefreshingTheDatabase(): void
    {
        $this->assertSame([], $this->cachedTestMigratorProcessors);
        $this->assertTrue(Schema::hasTable('testbench_users'));
    }
}
