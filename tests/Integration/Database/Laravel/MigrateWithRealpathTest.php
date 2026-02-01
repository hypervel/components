<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class MigrateWithRealpathTest extends DatabaseTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app['config']->get('database.default') !== 'testing') {
            $this->artisan('db:wipe', ['--drop-views' => true]);
        }

        $options = [
            '--path' => realpath(__DIR__ . '/stubs/'),
            '--realpath' => true,
        ];

        $this->artisan('migrate', $options);

        $this->beforeApplicationDestroyed(function () use ($options) {
            $this->artisan('migrate:rollback', $options);
        });
    }

    public function testRealpathMigrationHasProperlyExecuted()
    {
        $this->assertTrue(Schema::hasTable('members'));
    }

    public function testMigrationsHasTheMigratedTable()
    {
        $this->assertDatabaseHas('migrations', [
            'id' => 1,
            'migration' => '2014_10_12_000000_create_members_table',
            'batch' => 1,
        ]);
    }
}
