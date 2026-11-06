<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Postgres;

use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\Identity;
use Hypervel\SchemaParser\PostgresParser;
use Hypervel\SchemaParser\Schema;
use Hypervel\Tests\SchemaParser\Fixtures\Postgres\AssertsSchema;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function file_get_contents;
use function sprintf;

/**
 * Exercises the parser against real `pg_dump --schema-only` output.
 *
 * Fixtures/Postgres/all-types-schema.sql was produced by loading
 * Fixtures/Postgres/all-types-source.sql - a table declaring every PostgreSQL data
 * type - into PostgreSQL 18 and dumping it exactly as Laravel's `schema:dump`
 * does. Keeping the generated file rather than a handwritten one means the
 * fixture cannot drift from what pg_dump actually emits.
 */
class AllTypesSchemaTest extends TestCase
{
    use AssertsSchema;

    /** Every column of the all_types table, and none of the views or functions. */
    private const int ALL_TYPES_COLUMNS = 102;

    /**
     * Parse the upstream native dump fixture.
     */
    private static function database(): Schema
    {
        $sql = file_get_contents(__DIR__ . '/../Fixtures/Postgres/all-types-schema.sql');
        self::assertNotFalse($sql);

        return (new PostgresParser)->parse($sql);
    }

    #[Test]
    public function itFindsEveryTableAndNoViews(): void
    {
        $database = self::database();

        // all_types, partitioned, related, reporting.summaries - the view
        // active_types and the trigger function are not tables.
        $this->assertCount(4, $database->tables);
        $this->assertNull($database->table('active_types'));
        $this->assertNotNull($database->view('active_types', 'public'));
        $this->assertTrue($database->isComplete());
    }

    #[Test]
    public function itReadsEveryColumnOfTheAllTypesTable(): void
    {
        $this->assertCount(self::ALL_TYPES_COLUMNS, self::assertTable(self::database(), 'all_types')->columns);
    }

    #[Test]
    public function itResolvesEveryColumnType(): void
    {
        $database = self::database();

        foreach ($database->tables as $table) {
            foreach ($table->columns as $column) {
                $this->assertNotSame(
                    '',
                    $column->type->name,
                    sprintf('Column %s.%s has no resolved type.', $table->name, $column->name),
                );
            }
        }
    }

    #[Test]
    #[DataProvider('columnProvider')]
    public function itReadsRepresentativeColumns(string $name, string $type, bool $nullable): void
    {
        $column = self::assertColumn(self::database(), 'all_types', $name);
        $parts = explode('.', $type);
        $this->assertSame(array_pop($parts), $column->type->name);
        $this->assertSame($parts[0] ?? null, $column->type->schema);
        $this->assertSame($nullable, $column->nullable);
    }

    /**
     * Provide representative native column declarations.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function columnProvider(): iterable
    {
        // name => [column, canonical type, nullable]
        yield 'bigserial primary key' => ['id', 'pg_catalog.int8', false];
        yield 'smallserial' => ['small_id', 'pg_catalog.int2', false];
        yield 'identity' => ['identity_col', 'pg_catalog.int4', false];
        yield 'decimal collapses to numeric' => ['c_decimal', 'pg_catalog.numeric', true];
        yield 'int2 alias' => ['c_int2', 'pg_catalog.int2', true];
        yield 'float4 alias' => ['c_float4', 'pg_catalog.float4', true];
        yield 'double precision' => ['c_double', 'pg_catalog.float8', true];
        yield 'money' => ['c_money', 'money', true];
        yield 'character varying' => ['c_varchar', 'pg_catalog.varchar', true];
        yield 'text not null' => ['c_text', 'text', false];
        yield 'bytea' => ['c_bytea', 'bytea', true];
        yield 'timestamp without time zone' => ['c_timestamp', 'pg_catalog.timestamp', true];
        yield 'timestamptz' => ['c_timestamptz', 'pg_catalog.timestamptz', true];
        yield 'interval' => ['c_interval', 'pg_catalog.interval', true];
        yield 'boolean not null' => ['c_boolean', 'pg_catalog.bool', false];
        yield 'enum type' => ['c_mood', 'public.mood', true];
        yield 'domain type' => ['c_positive', 'public.positive_int', true];
        yield 'inet' => ['c_inet', 'inet', true];
        yield 'bit' => ['c_bit', 'pg_catalog.bit', true];
        yield 'bit varying' => ['c_varbit', 'pg_catalog.varbit', true];
        yield 'uuid' => ['c_uuid', 'uuid', true];
        yield 'jsonb' => ['c_jsonb', 'jsonb', true];
        yield 'daterange' => ['c_daterange', 'daterange', true];
        yield 'extension type' => ['c_hstore', 'public.hstore', true];
        yield 'generated column' => ['c_generated', 'pg_catalog.int4', true];
    }

    #[Test]
    public function itReadsArrayColumns(): void
    {
        $database = self::database();

        $intArray = self::assertColumnType($database, 'all_types', 'c_int_array');

        $this->assertSame(1, $intArray->dimensions);
        $this->assertSame('pg_catalog', $intArray->schema);
        $this->assertSame('int4', $intArray->name);
        $this->assertSame(['255'], self::assertColumnType($database, 'all_types', 'c_varchar_array')->modifiers);
        $this->assertSame(1, self::assertColumnType($database, 'all_types', 'c_mood_array')->dimensions);
    }

    #[Test]
    public function itReadsTheEnumDeclaredByTheDump(): void
    {
        $database = self::database();

        $type = self::assertColumnType($database, 'all_types', 'c_mood');

        $this->assertSame(['sad', 'ok', 'happy'], $database->enum($type->name, $type->schema)?->values);
    }

    #[Test]
    public function itResolvesTheDomainDeclaredByTheDump(): void
    {
        $database = self::database();

        $type = self::assertColumnType($database, 'all_types', 'c_positive');

        $this->assertSame('pg_catalog', $type->base->schema);
        $this->assertSame('int4', $type->base->name);
    }

    #[Test]
    public function itReadsPrimaryKeysAndSerialDefaults(): void
    {
        $database = self::database();

        $this->assertSame(['id'], array_column(self::assertTable($database, 'all_types')->primaryKey()->columns, 'name'));
        $this->assertTrue(self::assertColumn($database, 'all_types', 'id')->autoIncrement);
        $this->assertSame(['id'], array_column(self::assertTable($database, 'related')->primaryKey()->columns, 'name'));
        $this->assertSame(['id'], array_column(self::assertTable($database, 'summaries', 'reporting')->primaryKey()->columns, 'name'));
        $this->assertSame(Identity::Always, self::assertColumn($database, 'all_types', 'identity_always')->identity);
        $this->assertSame(Identity::ByDefault, self::assertColumn($database, 'all_types', 'identity_col')->identity);
    }

    #[Test]
    public function itReadsGeneratedColumns(): void
    {
        $this->assertSame(GenerationType::Stored, self::assertColumn(self::database(), 'all_types', 'c_generated')->generation->type);
    }
}
