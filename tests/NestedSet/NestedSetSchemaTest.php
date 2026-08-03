<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\NestedSet\NestedSet;
use Hypervel\NestedSet\NestedSetServiceProvider;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

class NestedSetSchemaTest extends TestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            NestedSetServiceProvider::class,
        ];
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('nested_set_schema');

        parent::tearDown();
    }

    public function testNestedSetMacroCreatesExpectedColumnsAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->nestedSet(['tenant_id']);
        });

        $this->assertNestedSetColumns(['tenant_id', NestedSet::LFT, NestedSet::RGT, NestedSet::PARENT_ID, NestedSet::DEPTH]);
        $this->assertNestedSetIndexes(['tenant_id']);
    }

    public function testIntegerNestedSetMacroCreatesExpectedColumnsAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->increments('id');
            $table->integerNestedSet();
        });

        $this->assertNestedSetColumns([NestedSet::LFT, NestedSet::RGT, NestedSet::PARENT_ID, NestedSet::DEPTH]);
        $this->assertNestedSetIndexes();
    }

    public function testUuidNestedSetMacroCreatesCompatibleSqliteParentColumnAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidNestedSet();
        });

        $this->assertSame('varchar', Schema::getColumnType('nested_set_schema', NestedSet::PARENT_ID));
        $this->assertNestedSetIndexes();
    }

    public function testUlidNestedSetMacroCreatesCompatibleSqliteParentColumnAndIndexes(): void
    {
        Schema::create('nested_set_schema', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulidNestedSet();
        });

        $this->assertSame('varchar', Schema::getColumnType('nested_set_schema', NestedSet::PARENT_ID));
        $this->assertNestedSetIndexes();
    }

    public function testDropNestedSetMacroDropsTheColumnsAndIndexesItCreates(): void
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
     * Assert that the table contains the expected nested set columns.
     */
    protected function assertNestedSetColumns(array $columns): void
    {
        $this->assertEqualsCanonicalizing($columns, array_values(array_intersect(
            Schema::getColumnListing('nested_set_schema'),
            $columns,
        )));
    }

    /**
     * Assert that the table contains the expected nested set indexes.
     */
    protected function assertNestedSetIndexes(array $scopes = []): void
    {
        $indexColumns = array_map(
            fn (array $index): array => $index['columns'],
            Schema::getIndexes('nested_set_schema'),
        );

        $this->assertContains([...$scopes, NestedSet::RGT], $indexColumns);
        $this->assertContains([...$scopes, NestedSet::LFT], $indexColumns);
        $this->assertContains([...$scopes, NestedSet::PARENT_ID, NestedSet::LFT], $indexColumns);
    }
}
