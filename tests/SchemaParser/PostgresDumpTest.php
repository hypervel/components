<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\Identity;
use Hypervel\SchemaParser\PostgresParser;
use Hypervel\SchemaParser\Schema;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixtures/Postgres/all-types-schema.sql is the pg_dump output of all-types-source.sql,
 * a table declaring every PostgreSQL data type, dumped from PostgreSQL 18 as the
 * framework's schema:dump command runs it.
 */
class PostgresDumpTest extends TestCase
{
    public function testAllTypesDumpReadsEveryTableAndColumn(): void
    {
        $schema = self::allTypes();

        // all_types, partitioned, related and reporting.summaries: the view and the trigger function are not tables.
        $this->assertCount(4, $schema->tables);
        $this->assertCount(102, $schema->table('all_types')->columns);
        $this->assertNull($schema->table('active_types'));
        $this->assertNotNull($schema->view('active_types', 'public'));
        $this->assertTrue($schema->isComplete());
    }

    #[DataProvider('columnProvider')]
    public function testAllTypesDumpReadsRepresentativeColumns(string $name, ?string $typeSchema, string $type, bool $nullable): void
    {
        $column = self::allTypes()->table('all_types')->column($name);

        $this->assertSame([$typeSchema, $type], [$column->type->schema, $column->type->name]);
        $this->assertSame($nullable, $column->nullable);
    }

    /**
     * Provide representative native column declarations with their type identity and nullability.
     *
     * @return iterable<string, array{string, ?string, string, bool}>
     */
    public static function columnProvider(): iterable
    {
        yield 'bigserial primary key' => ['id', 'pg_catalog', 'int8', false];
        yield 'smallserial' => ['small_id', 'pg_catalog', 'int2', false];
        yield 'identity' => ['identity_col', 'pg_catalog', 'int4', false];
        yield 'decimal collapses to numeric' => ['c_decimal', 'pg_catalog', 'numeric', true];
        yield 'int2 alias' => ['c_int2', 'pg_catalog', 'int2', true];
        yield 'float4 alias' => ['c_float4', 'pg_catalog', 'float4', true];
        yield 'double precision' => ['c_double', 'pg_catalog', 'float8', true];
        yield 'money' => ['c_money', null, 'money', true];
        yield 'character varying' => ['c_varchar', 'pg_catalog', 'varchar', true];
        yield 'text not null' => ['c_text', null, 'text', false];
        yield 'bytea' => ['c_bytea', null, 'bytea', true];
        yield 'timestamp without time zone' => ['c_timestamp', 'pg_catalog', 'timestamp', true];
        yield 'timestamptz' => ['c_timestamptz', 'pg_catalog', 'timestamptz', true];
        yield 'interval' => ['c_interval', 'pg_catalog', 'interval', true];
        yield 'boolean not null' => ['c_boolean', 'pg_catalog', 'bool', false];
        yield 'enum type' => ['c_mood', 'public', 'mood', true];
        yield 'domain type' => ['c_positive', 'public', 'positive_int', true];
        yield 'inet' => ['c_inet', null, 'inet', true];
        yield 'bit' => ['c_bit', 'pg_catalog', 'bit', true];
        yield 'bit varying' => ['c_varbit', 'pg_catalog', 'varbit', true];
        yield 'uuid' => ['c_uuid', null, 'uuid', true];
        yield 'jsonb' => ['c_jsonb', null, 'jsonb', true];
        yield 'daterange' => ['c_daterange', null, 'daterange', true];
        yield 'extension type' => ['c_hstore', 'public', 'hstore', true];
        yield 'generated column' => ['c_generated', 'pg_catalog', 'int4', true];
    }

    public function testAllTypesDumpReadsArraysEnumsAndDomains(): void
    {
        $schema = self::allTypes();
        $table = $schema->table('all_types');
        $integers = $table->column('c_int_array')->type;
        $mood = $table->column('c_mood')->type;

        $this->assertSame(['pg_catalog', 'int4', 1], [$integers->schema, $integers->name, $integers->dimensions]);
        $this->assertSame(['255'], $table->column('c_varchar_array')->type->modifiers);
        $this->assertSame(1, $table->column('c_mood_array')->type->dimensions);
        $this->assertSame(['sad', 'ok', 'happy'], $schema->enum($mood->name, $mood->schema)->values);
        $this->assertSame('pg_catalog', $table->column('c_positive')->type->base->schema);
        $this->assertSame('int4', $table->column('c_positive')->type->base->name);
    }

    public function testAllTypesDumpReadsKeysIdentityAndGeneratedColumns(): void
    {
        $schema = self::allTypes();
        $table = $schema->table('all_types');

        $this->assertSame(['id'], array_column($table->primaryKey()->columns, 'name'));
        $this->assertTrue($table->column('id')->autoIncrement);
        $this->assertSame(['id'], array_column($schema->table('related')->primaryKey()->columns, 'name'));
        $this->assertSame(['id'], array_column($schema->table('summaries', 'reporting')->primaryKey()->columns, 'name'));
        $this->assertSame(Identity::Always, $table->column('identity_always')->identity);
        $this->assertSame(Identity::ByDefault, $table->column('identity_col')->identity);
        $this->assertSame(GenerationType::Stored, $table->column('c_generated')->generation->type);
    }

    #[DataProvider('dumpProvider')]
    public function testNativeDumpsReadEveryTable(string $file): void
    {
        $sql = file_get_contents(__DIR__ . '/Fixtures/Postgres/' . $file);
        $schema = (new PostgresParser)->parse($sql);

        $this->assertTrue($schema->isComplete());

        // An independent count of the CREATE TABLE statements, so a parser that silently drops tables cannot pass.
        $this->assertCount(preg_match_all('/^CREATE\s+(?:UNLOGGED\s+|FOREIGN\s+)?TABLE\s/im', $sql), $schema->tables);

        foreach ($schema->tables as $table) {
            $this->assertNotSame([], $table->columns, "Table {$table->name} has no columns.");

            foreach ($table->columns as $column) {
                $this->assertNotSame('', $column->type->name, "Column {$table->name}.{$column->name} has no resolved type.");
            }
        }
    }

    /**
     * Provide native dumps with different server and data formats.
     *
     * @return iterable<string, array{string}>
     */
    public static function dumpProvider(): iterable
    {
        foreach (['all-types-schema.sql', 'copy-data.sql', 'schema-dump.sql'] as $file) {
            yield $file => [$file];
        }
    }

    public function testNativeCleanDumpKeepsDefaultsForeignTablesAndViews(): void
    {
        $schema = (new PostgresParser)->parse(file_get_contents(__DIR__ . '/Fixtures/Postgres/schema-dump.sql'));
        $defaults = $schema->table('defaults');

        $this->assertTrue($schema->isComplete());
        $this->assertFalse($defaults->column('nested')->nullable);
        $this->assertSame(<<<'SQL'
            CASE
                WHEN (random() > (0.5)::double precision) THEN
                CASE
                    WHEN (random() > (0.2)::double precision) THEN NULL::integer
                    ELSE 2
                END
                ELSE 1
            END
            SQL, $defaults->column('nested')->default);
        $this->assertStringEndsWith('END', $schema->domain('cased')->default);
        $this->assertFalse($schema->domain('cased')->nullable);
        $this->assertSame('storage', $defaults->column('bucket')->type->schema);
        $this->assertSame('buckettype', $defaults->column('bucket')->type->name);
        $this->assertSame("'STANDARD'::storage.buckettype", $defaults->column('bucket')->default);
        $this->assertFalse($defaults->column('bucket')->nullable);
        $this->assertSame('compression', $defaults->column('algorithm')->type->name);
        $this->assertNotNull($defaults->column('exclude'));

        $foreign = $schema->table('foreign_records');
        $this->assertSame('remote records', $foreign->comment);
        $this->assertFalse($foreign->column('id')->nullable);
        $this->assertSame('pg_catalog."C"', $foreign->column('name')->collation);
        $this->assertSame(['id', 'label', 'extra'], array_column($schema->table('child_remote')->columns, 'name'));
        $this->assertSame("'b'::text", $schema->table('child_remote')->column('label')->default);
        $this->assertSame('measurements', $schema->table('measurements_remote')->partitionOf->name);
        $this->assertSame("FOR VALUES FROM ('2020-01-01') TO ('2021-01-01')", $schema->table('measurements_remote')->partitionBounds);
        $this->assertTrue($schema->view('order_totals')->materialized);
        $this->assertStringNotContainsString('WITH NO DATA', $schema->view('order_totals')->definition);
        $this->assertFalse($schema->view('order_view')->materialized);
    }

    /**
     * Parse the all-types dump fixture.
     */
    private static function allTypes(): Schema
    {
        return (new PostgresParser)->parse(file_get_contents(__DIR__ . '/Fixtures/Postgres/all-types-schema.sql'));
    }
}
