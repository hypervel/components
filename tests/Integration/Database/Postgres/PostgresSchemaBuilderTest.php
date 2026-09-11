<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_pgsql')]
class PostgresSchemaBuilderTest extends PostgresTestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.connections.pgsql.search_path', 'public,private');
    }

    /**
     * Configure pgsql_dont_drop_all connection with dont_drop for all schemas.
     */
    protected function usePgsqlDontDropAll(Application $app): void
    {
        $config = $app->make('config');
        $baseConfig = $config->array('database.connections.pgsql');

        $config->set('database.connections.pgsql_dont_drop_all', array_merge($baseConfig, [
            'search_path' => 'public,private',
            'dont_drop' => ['spatial_ref_sys', 'table'],
        ]));
    }

    /**
     * Configure pgsql_dont_drop_one connection with dont_drop for one schema only.
     */
    protected function usePgsqlDontDropOne(Application $app): void
    {
        $config = $app->make('config');
        $baseConfig = $config->array('database.connections.pgsql');

        $config->set('database.connections.pgsql_dont_drop_one', array_merge($baseConfig, [
            'search_path' => 'public,private',
            'dont_drop' => ['spatial_ref_sys', 'private.table'],
        ]));
    }

    /**
     * Configure PostgreSQL's conventional user-first search path.
     */
    protected function usePgsqlUserSearchPath(Application $app): void
    {
        $config = $app->make('config');
        $baseConfig = $config->array('database.connections.pgsql');

        $config->set('database.connections.pgsql_user_search_path', array_merge($baseConfig, [
            'search_path' => '"$user", public',
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

    #[RequiresDatabase('pgsql', '>=13')]
    #[TestWith(['storedAs'])]
    #[TestWith(['virtualAs'])]
    public function testRemovingStoredExpressionsPreservesRowsAndAllowsOrdinaryWrites(string $modifier): void
    {
        Schema::create('generated_records', function (Blueprint $table): void {
            $table->integer('source');
            $table->integer('value')->storedAs('source * 2');
            $table->integer('label')->storedAs('source * 3');
        });
        DB::table('generated_records')->insert(['source' => 2]);

        Schema::table('generated_records', function (Blueprint $table) use ($modifier): void {
            $table->integer('value')->{$modifier}(null)->change();
            $table->text('label')->{$modifier}(null)->default('new')->change();
        });

        $this->assertSame(['source' => 2, 'value' => 4, 'label' => '6'], (array) DB::table('generated_records')->first());
        $columns = collect(Schema::getColumns('generated_records'))->keyBy('name');
        $this->assertNull($columns['value']['generation']);
        $this->assertNull($columns['value']['default']);
        $this->assertNull($columns['label']['generation']);
        $this->assertSame('text', $columns['label']['type_name']);

        DB::table('generated_records')->insert(['source' => 3, 'value' => 11]);
        $this->assertSame(['source' => 3, 'value' => 11, 'label' => 'new'], (array) DB::table('generated_records')->where('source', 3)->first());
        DB::table('generated_records')->where('source', 2)->update(['value' => 12, 'label' => 'changed']);
        $this->assertSame(['source' => 2, 'value' => 12, 'label' => 'changed'], (array) DB::table('generated_records')->where('source', 2)->first());
    }

    #[RequiresDatabase('pgsql', '>=13')]
    public function testRemovingAnAbsentExpressionAllowsANewDefault(): void
    {
        Schema::create('ordinary_records', function (Blueprint $table): void {
            $table->integer('value')->default(1);
        });
        DB::table('ordinary_records')->insert(['value' => 2]);

        Schema::table('ordinary_records', function (Blueprint $table): void {
            $table->integer('value')->storedAs(null)->default(7)->change();
        });

        DB::statement('insert into ordinary_records default values');
        $this->assertSame([2, 7], DB::table('ordinary_records')->orderBy('value')->pluck('value')->all());
    }

    #[RequiresDatabase('pgsql', '>=17')]
    #[TestWith(['storedAs', 'stored', false])]
    #[TestWith(['virtualAs', 'virtual', false])]
    #[TestWith(['storedAs', 'stored', true])]
    #[TestWith(['virtualAs', 'virtual', true])]
    public function testChangingGeneratedExpressionsRecalculatesValues(string $modifier, string $generation, bool $nullable): void
    {
        if ($generation === 'virtual' && version_compare($this->getConnection()->getServerVersion(), '18', '<')) {
            $this->markTestSkipped('Virtual generated columns require PostgreSQL 18.');
        }

        if (! $nullable && version_compare($this->getConnection()->getServerVersion(), '18', '>=')) {
            // @TODO Enable after the PostgreSQL double constraint-cleanup fix ships and is verified:
            // https://www.postgresql.org/message-id/CACJufxHZsgn3zM5g-x7YmtFGzNDnRwR07S%2BGYfiUs%2BtZ45MDDw@mail.gmail.com
            $this->markTestSkipped('PostgreSQL cannot combine expression and type changes with an existing NOT NULL constraint.');
        }

        Schema::create('generated_records', function (Blueprint $table) use ($modifier, $nullable): void {
            $table->integer('source');
            $table->integer('value')->nullable($nullable)->{$modifier}('source * 2');
        });
        DB::table('generated_records')->insert(['source' => 2]);

        Schema::table('generated_records', function (Blueprint $table) use ($modifier, $nullable): void {
            $table->integer('value')->nullable($nullable)->{$modifier}('source * 10')->change();
        });
        $this->assertSame(20, DB::table('generated_records')->value('value'));

        Schema::table('generated_records', function (Blueprint $table) use ($modifier, $nullable): void {
            $table->bigInteger('value')->nullable($nullable)->{$modifier}(DB::raw('source * 20'))->change();
        });
        DB::table('generated_records')->insert(['source' => 3]);

        $this->assertSame([40, 60], DB::table('generated_records')->orderBy('source')->pluck('value')->all());
        $column = collect(Schema::getColumns('generated_records'))->firstWhere('name', 'value');
        $this->assertSame('int8', $column['type_name']);
        $this->assertSame($generation, $column['generation']['type']);
        $this->assertNull($column['default']);
    }

    #[RequiresDatabase('pgsql', '>=17')]
    public function testChangingGeneratedExpressionsPreservesCheckConstraints(): void
    {
        // @TODO Enable after the PostgreSQL double constraint-cleanup fix ships and is verified:
        // https://www.postgresql.org/message-id/CACJufxHZsgn3zM5g-x7YmtFGzNDnRwR07S%2BGYfiUs%2BtZ45MDDw@mail.gmail.com
        $this->markTestSkipped('PostgreSQL cannot combine expression and type changes with an existing CHECK constraint.');

        DB::statement('create table generated_records (source integer, value integer generated always as (source * 2) stored check (value > 0))');
        DB::table('generated_records')->insert(['source' => 2]);

        Schema::table('generated_records', function (Blueprint $table): void {
            $table->bigInteger('value')->nullable()->storedAs('source * 10')->change();
        });

        $this->assertSame(20, DB::table('generated_records')->value('value'));
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('violates check constraint');
        DB::table('generated_records')->insert(['source' => -1]);
    }

    #[RequiresDatabase('pgsql', '>=17')]
    #[TestWith([false])]
    #[TestWith([true])]
    public function testInvalidGeneratedExpressionChangesRetainNativeErrors(bool $generated): void
    {
        Schema::create('generated_records', function (Blueprint $table) use ($generated): void {
            $table->integer('source');
            $column = $table->integer('value')->nullable();

            if ($generated) {
                $column->storedAs('source * 2');
            }
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($generated ? 'is a generated column' : 'is not a generated column');

        Schema::table('generated_records', function (Blueprint $table) use ($generated): void {
            $column = $table->integer('value')->storedAs('source * 10')->change();

            if ($generated) {
                $column->default(5);
            }
        });
    }

    public function testWithoutForeignKeyConstraintsNestsUntilTheOuterScopeRestoresImmediateChecks(): void
    {
        Schema::create('constraint_parents', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('constraint_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->foreign('parent_id')
                ->references('id')
                ->on('constraint_parents')
                ->deferrable()
                ->initiallyImmediate();
        });

        $connection = DB::connection();
        $outer = $connection->getSchemaBuilder();
        $inner = $connection->getSchemaBuilder();

        $connection->transaction(function () use ($connection, $inner, $outer): void {
            $outer->withoutForeignKeyConstraints(function () use ($connection, $inner): void {
                $connection->table('constraint_children')->insert(['id' => 1, 'parent_id' => 1]);

                $inner->withoutForeignKeyConstraints(function () use ($connection): void {
                    $connection->table('constraint_children')->insert(['id' => 2, 'parent_id' => 2]);
                });

                $connection->table('constraint_parents')->insert([
                    ['id' => 1],
                    ['id' => 2],
                ]);
            });
        });

        $this->assertSame(2, $connection->table('constraint_children')->count());
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

    #[DefineEnvironment('usePgsqlUserSearchPath')]
    public function testCurrentSchemaNameUsesTheFirstExistingSearchPathEntry(): void
    {
        $connection = DB::connection('pgsql_user_search_path');
        $currentUser = $connection->scalar('select current_user', [], false);

        $this->assertIsString($currentUser);
        $this->assertFalse((bool) $connection->scalar(
            'select exists (select 1 from pg_namespace where nspname = ?)',
            [$currentUser],
            false
        ));

        $schema = $connection->getSchemaBuilder();
        $currentSchema = $schema->getCurrentSchemaName();
        $defaultSchema = collect($schema->getSchemas())->firstWhere('default', true);

        $this->assertSame([$currentUser, 'public'], $schema->getCurrentSchemaListing());
        $this->assertSame('public', $currentSchema);
        $this->assertIsArray($defaultSchema);
        $this->assertSame($defaultSchema['name'], $currentSchema);
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

    public function testLateSchemaFailureRollsBackTheCompleteBlueprint(): void
    {
        try {
            Schema::create('atomic_records', function (Blueprint $table): void {
                $table->id();
                $table->rawIndex('(', 'invalid_index');
            });
            $this->fail('Expected the invalid index to fail.');
        } catch (QueryException) {
        }

        $this->assertFalse(Schema::hasTable('atomic_records'));
    }

    public function testOnlineIndexInsideATransactionRetainsTheNativeFailure(): void
    {
        Schema::create('transaction_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        try {
            DB::transaction(function (): void {
                Schema::table('transaction_records', function (Blueprint $table): void {
                    $table->index('name')->online();
                });
            });
            $this->fail('Expected PostgreSQL to reject the online index inside a transaction.');
        } catch (QueryException) {
        }

        $this->assertFalse(Schema::hasIndex('transaction_records', ['name']));
    }
}
