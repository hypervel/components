<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Postgres;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Tests dont_drop config with table excluded on ONE schema only.
 *
 * Separated from PostgresSchemaBuilderTest because Swoole requires config
 * to be set in defineEnvironment() before connections are created.
 *
 * @internal
 * @coversNothing
 */
#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_pgsql')]
class PostgresSchemaBuilderDontDropOneTest extends PostgresTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Purge any existing pool before setting config
        DB::purge('pgsql');

        $app['config']->set('database.connections.pgsql.search_path', 'public,private');
        $app['config']->set('database.connections.pgsql.dont_drop', ['spatial_ref_sys', 'private.table']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        DB::statement('create schema if not exists private');
    }

    protected function destroyDatabaseMigrations(): void
    {
        DB::statement('drop table if exists public.table');
        DB::statement('drop table if exists private.table');
        DB::statement('drop schema private');

        parent::destroyDatabaseMigrations();
    }

    protected function tearDown(): void
    {
        // Reset dont_drop config and purge pool to prevent leaking to other tests
        $this->app['config']->set('database.connections.pgsql.dont_drop', null);
        DB::purge('pgsql');

        parent::tearDown();
    }

    public function testDropAllTablesUsesDontDropConfigOnOneSchema(): void
    {
        Schema::create('public.table', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('private.table', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::dropAllTables();

        $this->artisan('migrate:install');

        $this->assertFalse(Schema::hasTable('public.table'));
        $this->assertTrue(Schema::hasTable('private.table'));
    }
}
