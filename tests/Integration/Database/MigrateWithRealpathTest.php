<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

class MigrateWithRealpathTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Set up the migration test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $options = [
            '--path' => realpath(__DIR__ . '/Fixtures/'),
            '--realpath' => true,
        ];

        $this->artisan('migrate', $options);

        $this->beforeApplicationDestroyed(function () use ($options): void {
            $this->artisan('migrate:rollback', $options);
        });
    }

    public function testRealpathMigrationHasProperlyExecuted(): void
    {
        $this->assertTrue(Schema::hasTable('members'));
    }

    public function testMigrationsHasTheMigratedTable(): void
    {
        $this->assertDatabaseHas('migrations', [
            'id' => 1,
            'migration' => '2014_10_12_000000_create_members_table',
            'batch' => 1,
        ]);
    }
}
