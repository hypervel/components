<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Migration;

use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

#[WithConfig('database.default', 'sqlite')]
#[WithConfig('database.connections.sqlite.database', ':memory:')]
class StatusCommandTest extends TestCase
{
    /**
     * Create the migration repository inside the test coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        $this->app->make('migrator')->getRepository()->createRepository();
    }

    public function testMigrationsThatShouldNotRunAreReportedAsSkipped(): void
    {
        $this->app->make('migrator')->run([__DIR__ . '/../../Database/Fixtures/Migrator']);

        $this->artisan('migrate:status', ['--path' => __DIR__ . '/../../Database/Fixtures/Migrator', '--realpath' => true])
            ->expectsOutputToContain('Skipped')
            ->doesntExpectOutputToContain('Pending')
            ->assertSuccessful();
    }

    public function testSkippedMigrationsAreNotConsideredPending(): void
    {
        $this->app->make('migrator')->run([__DIR__ . '/../../Database/Fixtures/Migrator']);

        $this->artisan('migrate:status', ['--path' => __DIR__ . '/../../Database/Fixtures/Migrator', '--realpath' => true, '--pending' => 100])
            ->expectsOutputToContain('No pending migrations')
            ->assertSuccessful();
    }

    public function testShouldRunIsNotEvaluatedAfterThePendingMigrationsItMayDependOn(): void
    {
        $this->artisan('migrate:status', ['--path' => __DIR__ . '/Fixtures/status', '--realpath' => true, '--pending' => 100])
            ->doesntExpectOutputToContain('2024_01_01_000000_create_airlines_table')
            ->expectsOutputToContain('2024_01_02_000000_create_flights_table')
            ->expectsOutputToContain('2024_01_03_000000_add_name_to_flights_table')
            ->assertExitCode(100);

        $this->app->make('migrator')->run([__DIR__ . '/Fixtures/status']);

        $this->assertFalse(Schema::hasTable('airlines'));
        $this->assertTrue(Schema::hasColumn('flights', 'name'));
    }
}
