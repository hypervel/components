<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Postgres;

use Hypervel\SchemaParser\PostgresParser;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function basename;
use function count;
use function file_get_contents;
use function preg_match_all;
use function sprintf;

/**
 * Exercise committed native dumps so parser coverage never depends on private files.
 */
class RealDumpTest extends TestCase
{
    #[Test]
    #[DataProvider('dumpProvider')]
    public function itReadsEveryTableOfARealDump(string $path): void
    {
        $sql = file_get_contents($path);
        self::assertNotFalse($sql);

        $database = (new PostgresParser)->parse($sql);
        self::assertTrue($database->isComplete());

        // An independent count of the CREATE TABLE statements, so a parser that
        // silently drops tables cannot pass.
        $expected = preg_match_all('/^CREATE\s+(?:UNLOGGED\s+|FOREIGN\s+)?TABLE\s/im', $sql);

        self::assertSame($expected, count($database->tables), 'Not every CREATE TABLE was read.');

        foreach ($database->tables as $table) {
            self::assertNotSame(
                [],
                $table->columns,
                sprintf('Table %s has no columns.', $table->name),
            );

            foreach ($table->columns as $column) {
                self::assertNotSame(
                    '',
                    $column->type->name,
                    sprintf('Column %s.%s has no resolved type.', $table->name, $column->name),
                );
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
            $path = __DIR__ . '/../Fixtures/Postgres/' . $file;
            yield basename($path) => [$path];
        }
    }

    public function testNativeCleanDumpKeepsDefaultsForeignTablesAndViews(): void
    {
        $schema = (new PostgresParser)->parse(file_get_contents(__DIR__ . '/../Fixtures/Postgres/schema-dump.sql'));
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
}
