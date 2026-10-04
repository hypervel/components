<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('pdo_pgsql')]
#[RequiresOperatingSystem('Linux|Darwin')]
class TablePartitioningTest extends PostgresTestCase
{
    private const string FIRST_TENANT = '01800000-0000-7000-8000-00000000000a';

    private const string SECOND_TENANT = '01800000-0000-7000-8000-00000000000b';

    private const string FIRST_ATTEMPT = '01900000-0000-7000-8000-000000000001';

    private const string SECOND_ATTEMPT = '01910000-0000-7000-8000-000000000001';

    /**
     * Configure a connection that only lists the archive schema.
     */
    protected function usePgsqlArchiveSearchPath(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.connections.pgsql_archive', array_merge($config->array('database.connections.pgsql'), [
            'search_path' => 'archive',
        ]));
    }

    /**
     * Configure a connection that lists the public and archive schemas and keeps the partitioned table.
     */
    protected function usePgsqlDontDropPartitionedTable(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.connections.pgsql_dont_drop_partitioned', array_merge($config->array('database.connections.pgsql'), [
            'search_path' => 'public,archive',
            'dont_drop' => ['spatial_ref_sys', 'public.attempts'],
        ]));
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        Schema::create('attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('status');
            $table->unique(['tenant_id', 'id']);
            $table->index('status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->partitionByRange('id');
        });

        Schema::createRangePartition('attempts', 'attempts_1', ['01900000-0000-7000-8000-000000000000'], ['01910000-0000-7000-8000-000000000000']);
        Schema::createRangePartition('attempts', 'attempts_2', ['01910000-0000-7000-8000-000000000000'], ['01920000-0000-7000-8000-000000000000']);

        DB::table('tenants')->insert([
            ['id' => self::FIRST_TENANT, 'name' => 'first'],
            ['id' => self::SECOND_TENANT, 'name' => 'second'],
        ]);

        DB::table('attempts')->insert([
            ['id' => self::FIRST_ATTEMPT, 'tenant_id' => self::FIRST_TENANT, 'status' => 'pending'],
            ['id' => self::SECOND_ATTEMPT, 'tenant_id' => self::SECOND_TENANT, 'status' => 'pending'],
        ]);
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('tenants');

        DB::statement('drop schema if exists archive cascade');
        DB::statement('drop schema if exists cold cascade');
    }

    public function testPartitionedTablesCanBeInspected(): void
    {
        $tables = array_column(array_filter(
            Schema::getTables(),
            fn (array $table): bool => in_array($table['name'], ['attempts', 'attempts_1', 'attempts_2', 'tenants'], true)
        ), 'partition_of', 'name');

        $this->assertSame(['attempts' => null, 'attempts_1' => 'public.attempts', 'attempts_2' => 'public.attempts', 'tenants' => null], $tables);

        $this->assertSame([
            [
                'name' => 'attempts_1',
                'schema' => 'public',
                'schema_qualified_name' => 'public.attempts_1',
                'bounds' => "FOR VALUES FROM ('01900000-0000-7000-8000-000000000000') TO ('01910000-0000-7000-8000-000000000000')",
            ],
            [
                'name' => 'attempts_2',
                'schema' => 'public',
                'schema_qualified_name' => 'public.attempts_2',
                'bounds' => "FOR VALUES FROM ('01910000-0000-7000-8000-000000000000') TO ('01920000-0000-7000-8000-000000000000')",
            ],
        ], Schema::getPartitions('attempts'));

        $indexes = array_column(Schema::getIndexes('attempts'), 'columns', 'name');
        ksort($indexes);

        $this->assertSame([
            'attempts_pkey' => ['id'],
            'attempts_status_index' => ['status'],
            'attempts_tenant_id_id_unique' => ['tenant_id', 'id'],
        ], $indexes);

        $foreignKeys = Schema::getForeignKeys('attempts');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['tenant_id'], $foreignKeys[0]['columns']);
        $this->assertSame('tenants', $foreignKeys[0]['foreign_table']);
        $this->assertSame([], Schema::getPartitions('tenants'));
    }

    public function testDropAllTablesDropsPartitionedTables(): void
    {
        Schema::dropAllTables();

        $this->artisan('migrate:install');

        $this->assertFalse(Schema::hasTable('attempts'));
        $this->assertFalse(Schema::hasTable('attempts_1'));
        $this->assertFalse(Schema::hasTable('tenants'));
    }

    #[DefineEnvironment('usePgsqlArchiveSearchPath')]
    public function testDropAllTablesDropsAPartitionWhoseTableIsInAnUnlistedSchema(): void
    {
        DB::statement('create schema archive');
        Schema::createRangePartition('attempts', 'archive.attempts_3', ['01920000-0000-7000-8000-000000000000'], ['01930000-0000-7000-8000-000000000000']);
        DB::table('attempts')->insert(['id' => '01920000-0000-7000-8000-000000000001', 'tenant_id' => self::FIRST_TENANT, 'status' => 'pending']);

        Schema::connection('pgsql_archive')->dropAllTables();

        $this->assertSame(['attempts_1', 'attempts_2'], array_column(Schema::getPartitions('attempts'), 'name'));
        $this->assertSame([self::FIRST_ATTEMPT, self::SECOND_ATTEMPT], DB::table('attempts')->orderBy('id')->pluck('id')->all());
    }

    #[DefineEnvironment('usePgsqlDontDropPartitionedTable')]
    public function testDropAllTablesKeepsEveryPartitionOfAnExcludedTable(): void
    {
        // The leaf is listed, but its own partitioned parent lives in an unlisted schema.
        DB::statement('create schema cold');
        DB::statement('create schema archive');
        DB::statement("create table cold.attempts_3 partition of attempts for values from ('01920000-0000-7000-8000-000000000000') to ('01930000-0000-7000-8000-000000000000') partition by range (id)");
        Schema::createRangePartition('cold.attempts_3', 'archive.attempts_3a', ['01920000-0000-7000-8000-000000000000'], ['01930000-0000-7000-8000-000000000000']);
        DB::table('attempts')->insert(['id' => '01920000-0000-7000-8000-000000000001', 'tenant_id' => self::FIRST_TENANT, 'status' => 'pending']);

        Schema::connection('pgsql_dont_drop_partitioned')->dropAllTables();

        $this->artisan('migrate:install', ['--database' => 'pgsql_dont_drop_partitioned']);

        $this->assertFalse(Schema::hasTable('tenants'));
        $this->assertSame(['cold.attempts_3', 'public.attempts_1', 'public.attempts_2'], array_column(Schema::getPartitions('attempts'), 'schema_qualified_name'));
        $this->assertSame(['archive.attempts_3a'], array_column(Schema::getPartitions('cold.attempts_3'), 'schema_qualified_name'));
        $this->assertSame(3, DB::table('attempts')->count());
    }

    /**
     * @param Closure(): Builder $query
     */
    #[DataProvider('writesSelectingTheFirstTenant')]
    public function testLimitedAndJoinedWritesOnlyTouchTheSelectedRows(Closure $query): void
    {
        // Each partition's only row has the same ctid, so ctid alone cannot tell them apart.
        $this->assertSame(['(0,1)', '(0,1)'], array_column(DB::select('select ctid::text as ctid from attempts order by id'), 'ctid'));

        $this->assertSame(1, $query()->update(['status' => 'updated']));
        $this->assertSame(
            [self::FIRST_ATTEMPT => 'updated', self::SECOND_ATTEMPT => 'pending'],
            DB::table('attempts')->orderBy('id')->pluck('status', 'id')->all()
        );

        $this->assertSame(1, $query()->delete());
        $this->assertSame([self::SECOND_ATTEMPT], DB::table('attempts')->pluck('id')->all());
    }

    public static function writesSelectingTheFirstTenant(): array
    {
        return [
            'limit' => [static fn (): Builder => DB::table('attempts')->where('tenant_id', self::FIRST_TENANT)->limit(1)],
            'alias and limit' => [static fn (): Builder => DB::table('attempts as a')->where('a.tenant_id', self::FIRST_TENANT)->limit(1)],
            'join' => [static fn (): Builder => DB::table('attempts as a')->join('tenants as t', 't.id', '=', 'a.tenant_id')->where('t.name', 'first')],
        ];
    }
}
