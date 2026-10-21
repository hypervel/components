<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\NestedSet\NestedSet;
use Hypervel\NestedSet\NestedSetServiceProvider;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

class NestedSetSchemaTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            NestedSetServiceProvider::class,
        ];
    }

    /**
     * Use a table prefix so generated index names are checked against prefixed tables.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set(
            'database.connections.' . $config->string('database.default') . '.prefix',
            'prfx_',
        );
    }

    /**
     * Drop the schema fixture on the test coroutine's connection.
     */
    protected function tearDownInCoroutine(): void
    {
        Schema::dropIfExists('nested_set_schema');
    }

    public function testNestedSetMacroCreatesExpectedColumnsAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->nestedSet(['tenant_id']);
        });

        $this->assertNestedSetSchema(['tenant_id']);
    }

    public function testIntegerNestedSetMacroCreatesExpectedColumnsAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->increments('id');
            $table->integerNestedSet();
        });

        $this->assertNestedSetSchema();
    }

    public function testUuidNestedSetMacroCreatesExpectedColumnsAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidNestedSet();
        });

        $this->assertNestedSetSchema();
    }

    public function testUlidNestedSetMacroCreatesExpectedColumnsAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulidNestedSet();
        });

        $this->assertNestedSetSchema();
    }

    public function testDropsEveryNestedSetIndexItCreates(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->nestedSet(['tenant_id']);
        });

        Schema::table('nested_set_schema', function (Blueprint $table): void {
            $table->dropNestedSet(['tenant_id']);
        });

        $this->assertSame(['id', 'tenant_id'], Schema::getColumnListing('nested_set_schema'));
        $this->assertSame([], array_values(array_filter(
            Schema::getIndexes('nested_set_schema'),
            fn (array $index): bool => ! $index['primary'],
        )));
    }

    /**
     * Assert that the table has the nested set columns, column types, and exact indexes.
     */
    protected function assertNestedSetSchema(array $scopes = []): void
    {
        $columns = Schema::getColumns('nested_set_schema');
        $types = array_column($columns, 'type', 'name');
        $typeNames = array_column($columns, 'type_name', 'name');

        [$boundType, $depthType] = match (Schema::getConnection()->getDriverName()) {
            'pgsql' => ['int4', 'int2'],
            'sqlite' => ['integer', 'integer'],
            default => ['int', 'smallint'],
        };

        $this->assertSame($types['id'], $types[NestedSet::PARENT_ID]);
        $this->assertSame($boundType, $typeNames[NestedSet::LFT]);
        $this->assertSame($boundType, $typeNames[NestedSet::RGT]);
        $this->assertSame($depthType, $typeNames[NestedSet::DEPTH]);

        $this->assertEqualsCanonicalizing(
            [
                implode(',', [...$scopes, NestedSet::RGT]),
                implode(',', [...$scopes, NestedSet::LFT, NestedSet::RGT]),
                implode(',', [...$scopes, NestedSet::PARENT_ID, NestedSet::LFT]),
            ],
            array_values(array_map(
                fn (array $index): string => implode(',', $index['columns']),
                array_filter(
                    Schema::getIndexes('nested_set_schema'),
                    fn (array $index): bool => ! $index['primary'],
                ),
            )),
        );
    }
}
