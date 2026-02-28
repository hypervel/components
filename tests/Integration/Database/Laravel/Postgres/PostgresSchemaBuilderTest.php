<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Postgres;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * @internal
 * @coversNothing
 */
#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_pgsql')]
class PostgresSchemaBuilderTest extends PostgresTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.pgsql.search_path', 'public,private');
    }

    /**
     * Configure pgsql_dont_drop_all connection with dont_drop for all schemas.
     */
    protected function usePgsqlDontDropAll(Application $app): void
    {
        $baseConfig = $app['config']->get('database.connections.pgsql');
        $app['config']->set('database.connections.pgsql_dont_drop_all', array_merge($baseConfig, [
            'search_path' => 'public,private',
            'dont_drop' => ['spatial_ref_sys', 'table'],
        ]));
    }

    /**
     * Configure pgsql_dont_drop_one connection with dont_drop for one schema only.
     */
    protected function usePgsqlDontDropOne(Application $app): void
    {
        $baseConfig = $app['config']->get('database.connections.pgsql');
        $app['config']->set('database.connections.pgsql_dont_drop_one', array_merge($baseConfig, [
            'search_path' => 'public,private',
            'dont_drop' => ['spatial_ref_sys', 'private.table'],
        ]));
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

        DB::statement('drop view if exists public.foo');
        DB::statement('drop view if exists private.foo');

        DB::statement('drop schema private');

        parent::destroyDatabaseMigrations();
    }

    public function testDropAllTablesOnAllSchemas()
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
        $this->assertFalse(Schema::hasTable('private.table'));
    }

    #[DefineEnvironment('usePgsqlDontDropAll')]
    public function testDropAllTablesUsesDontDropConfigOnAllSchemas(): void
    {
        $schema = Schema::connection('pgsql_dont_drop_all');

        $schema->create('public.table', function (Blueprint $table) {
            $table->increments('id');
        });
        $schema->create('private.table', function (Blueprint $table) {
            $table->increments('id');
        });

        $schema->dropAllTables();

        $this->artisan('migrate:install', ['--database' => 'pgsql_dont_drop_all']);

        $this->assertTrue($schema->hasTable('public.table'));
        $this->assertTrue($schema->hasTable('private.table'));
    }

    #[DefineEnvironment('usePgsqlDontDropOne')]
    public function testDropAllTablesUsesDontDropConfigOnOneSchema(): void
    {
        $schema = Schema::connection('pgsql_dont_drop_one');

        $schema->create('public.table', function (Blueprint $table) {
            $table->increments('id');
        });
        $schema->create('private.table', function (Blueprint $table) {
            $table->increments('id');
        });

        $schema->dropAllTables();

        $this->artisan('migrate:install', ['--database' => 'pgsql_dont_drop_one']);

        $this->assertFalse($schema->hasTable('public.table'));
        $this->assertTrue($schema->hasTable('private.table'));
    }

    public function testDropAllViewsOnAllSchemas()
    {
        DB::statement('create view public.foo (id) as select 1');
        DB::statement('create view private.foo (id) as select 1');

        $this->assertTrue(Schema::hasView('public.foo'));
        $this->assertTrue(Schema::hasView('private.foo'));

        Schema::dropAllViews();

        $this->assertFalse(Schema::hasView('public.foo'));
        $this->assertFalse(Schema::hasView('private.foo'));
    }

    public function testAddTableCommentOnNewTable()
    {
        Schema::create('public.posts', function (Blueprint $table) {
            $table->comment('This is a comment');
        });

        $this->assertEquals('This is a comment', DB::selectOne("select obj_description('public.posts'::regclass, 'pg_class')")->obj_description);
    }

    public function testAddTableCommentOnExistingTable()
    {
        Schema::create('public.posts', function (Blueprint $table) {
            $table->id();
            $table->comment('This is a comment');
        });

        Schema::table('public.posts', function (Blueprint $table) {
            $table->comment('This is a new comment');
        });

        $this->assertEquals('This is a new comment', DB::selectOne("select obj_description('public.posts'::regclass, 'pg_class')")->obj_description);
    }

    public function testGetTables()
    {
        Schema::create('public.table', function (Blueprint $table) {
            $table->string('name');
        });

        Schema::create('private.table', function (Blueprint $table) {
            $table->integer('votes');
        });

        $tables = Schema::getTables();

        $this->assertNotEmpty(array_filter($tables, function ($table) {
            return $table['name'] === 'table' && $table['schema'] === 'public';
        }));
        $this->assertNotEmpty(array_filter($tables, function ($table) {
            return $table['name'] === 'table' && $table['schema'] === 'private';
        }));
    }

    public function testGetViews()
    {
        DB::statement('create view public.foo (id) as select 1');
        DB::statement('create view private.foo (id) as select 1');

        $views = Schema::getViews();

        $this->assertNotEmpty(array_filter($views, function ($view) {
            return $view['name'] === 'foo' && $view['schema'] === 'public';
        }));
        $this->assertNotEmpty(array_filter($views, function ($view) {
            return $view['name'] === 'foo' && $view['schema'] === 'private';
        }));
    }

    #[RequiresDatabase('pgsql', '>=11.0')]
    public function testDropPartitionedTables()
    {
        DB::statement('create table groups (id bigserial, tenant_id bigint, name varchar, primary key (id, tenant_id)) partition by hash (tenant_id)');
        DB::statement('create table groups_1 partition of groups for values with (modulus 2, remainder 0)');
        DB::statement('create table groups_2 partition of groups for values with (modulus 2, remainder 1)');

        $tables = array_column(Schema::getTables(), 'name');

        $this->assertContains('groups', $tables);
        $this->assertContains('groups_1', $tables);
        $this->assertContains('groups_2', $tables);

        Schema::dropAllTables();

        $this->artisan('migrate:install');

        $tables = array_column(Schema::getTables(), 'name');

        $this->assertNotContains('groups', $tables);
        $this->assertNotContains('groups_1', $tables);
        $this->assertNotContains('groups_2', $tables);
    }

    public function testGetRawIndex()
    {
        Schema::create('public.table', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->rawIndex("DATE_TRUNC('year'::text,created_at)", 'table_raw_index');
        });

        $indexes = Schema::getIndexes('public.table');

        $this->assertSame([], collect($indexes)->firstWhere('name', 'table_raw_index')['columns']);
    }

    public function testCreateIndexesOnline()
    {
        Schema::create('public.table', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('title', 200);
            $table->text('body');

            $table->unique('title')->online();
            $table->index(['created_at'])->online();
            $table->fullText(['body'])->online();
            $table->rawIndex("DATE_TRUNC('year'::text,created_at)", 'table_raw_index')->online();
        });

        $indexes = Schema::getIndexes('public.table');
        $indexNames = collect($indexes)->pluck('name');

        $this->assertContains('public_table_title_unique', $indexNames);
        $this->assertContains('public_table_created_at_index', $indexNames);
        $this->assertContains('public_table_body_fulltext', $indexNames);
        $this->assertContains('table_raw_index', $indexNames);
    }
}
