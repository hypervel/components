<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\MariaDbParser;
use Hypervel\SchemaParser\MySqlParser;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\ReferentialAction;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MariaDbParserTest extends TestCase
{
    public function testMariaDbExecutableCommentsAreReadOnlyAsMariaDbSql(): void
    {
        $sql = "/*M!999999\\- enable the sandbox mode */\nCREATE TABLE example (id int /*M!100301 INVISIBLE */)";

        $this->assertTrue((new MariaDbParser)->parse($sql)->table('example')->column('id')->invisible);
        $this->assertFalse((new MySqlParser)->parse($sql)->table('example')->column('id')->invisible);
    }

    public function testNativeTemporalDefinitionsRemainExplicitlyIncomplete(): void
    {
        $sql = file_get_contents(__DIR__ . '/Fixtures/MariaDb/options.sql')
            . file_get_contents(__DIR__ . '/Fixtures/MariaDb/periods.sql')
            . 'CREATE TABLE implicit_versioning (id int) WITH SYSTEM VERSIONING;';
        $schema = (new MariaDbParser)->parse($sql);

        $this->assertFalse($schema->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertTrue($schema->table('places')->isComplete());
        $this->assertSame('important', $schema->table('places')->comment);
        $this->assertSame('spatial', $schema->table('places')->indexes[2]->type);
        $this->assertSame(['id', 'starts_at', 'ends_at'], array_column($schema->table('bookings')->columns, 'name'));
        $this->assertCount(1, $schema->table('bookings')->issues);
        $versioned = $schema->table('versioned');
        $this->assertSame(['id', 'row_start', 'row_end'], array_column($versioned->columns, 'name'));
        $this->assertNull($versioned->column('row_start')->generation);
        $this->assertTrue($versioned->column('row_start')->invisible);
        $this->assertCount(1, $versioned->issues);
        $this->assertCount(1, $schema->table('implicit_versioning')->issues);

        $rooms = $schema->table('rooms');
        $this->assertCount(1, $rooms->issues);
        $this->assertSame('NULL', $rooms->column('notes')->default);
        $this->assertTrue($rooms->indexes[0]->unique);
        $this->assertSame('id', $rooms->indexes[0]->columns[0]->name);
        $this->assertNull($rooms->indexes[0]->columns[1]->name);
        $this->assertSame('`stay` WITHOUT OVERLAPS', $rooms->indexes[0]->columns[1]->expression);
    }

    public function testSystemVersionedAndPersistentColumnsKeepTheirFacts(): void
    {
        $schema = (new MariaDbParser)->parse(<<<'SQL'
            CREATE TABLE example (
                id int,
                doubled int AS (id * 2) PERSISTENT,
                tripled int GENERATED ALWAYS AS (id * 3) VIRTUAL,
                starts timestamp(6) AS ROW START,
                ends timestamp(6) AS ROW END,
                notes text WITHOUT SYSTEM VERSIONING NOT NULL,
                PERIOD FOR SYSTEM_TIME (starts, ends)
            ) WITH SYSTEM VERSIONING
            SQL);
        $table = $schema->table('example');

        $this->assertSame(['id', 'doubled', 'tripled', 'starts', 'ends', 'notes'], array_column($table->columns, 'name'));
        $this->assertSame(GenerationType::Stored, $table->column('doubled')->generation->type);
        $this->assertSame('(id * 2)', $table->column('doubled')->generation->expression);
        $this->assertSame(GenerationType::Virtual, $table->column('tripled')->generation->type);
        $this->assertNull($table->column('starts')->generation);
        $this->assertFalse($table->column('notes')->nullable);
        $this->assertCount(1, $table->issues);
    }

    public function testVectorIndexesDoNotChangeColumnsNamedVector(): void
    {
        $schema = (new MariaDbParser)->parse(<<<'SQL'
            CREATE TABLE embeddings (
                embedding vector(3) NOT NULL,
                VECTOR KEY by_embedding (embedding) `m`='4' `distance`='cosine'
            );
            CREATE TABLE vector_names (vector VECTOR(3) NOT NULL, vector index by_vector (vector));
            CREATE VECTOR INDEX added ON vector_names (vector) M=8 DISTANCE=euclidean;
            SQL);

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['embedding'], array_column($schema->table('embeddings')->columns, 'name'));
        $index = $schema->table('embeddings')->indexes[0];
        $this->assertSame('vector', $index->type);
        $this->assertSame('embedding', $index->columns[0]->name);
        $this->assertFalse($index->unique);
        $table = $schema->table('vector_names');
        $this->assertSame('vector', $table->column('vector')->type->name);
        $this->assertSame(['by_vector', 'added'], array_column($table->indexes, 'name'));
        $this->assertSame(['vector', 'vector'], array_column($table->indexes, 'type'));
    }

    public function testPhysicalAttributesDoNotHideLaterColumnOptions(): void
    {
        $schema = (new MariaDbParser)->parse(<<<'SQL'
            CREATE TABLE example (
                location point REF_SYSTEM_ID=4326 NOT NULL,
                notes blob COMPRESSED=zlib DEFAULT NULL,
                body text COMPRESSED COMMENT 'kept'
            );
            SQL);
        $table = $schema->table('example');

        $this->assertTrue($schema->isComplete());
        $this->assertFalse($table->column('location')->nullable);
        $this->assertSame('NULL', $table->column('notes')->default);
        $this->assertSame('kept', $table->column('body')->comment);
    }

    public function testForeignKeysWithoutConstraintNamesAreNamedByTheirIndex(): void
    {
        $schema = (new MariaDbParser)->parse(<<<'SQL'
            CREATE TABLE parents (id int PRIMARY KEY);
            CREATE TABLE children (
                parent_id int REFERENCES parents (id) ON DELETE CASCADE NOT NULL,
                FOREIGN KEY by_parent (parent_id) REFERENCES parents (id),
                CONSTRAINT named FOREIGN KEY by_index (parent_id) REFERENCES parents (id),
                CONSTRAINT FOREIGN KEY by_unnamed_constraint (parent_id) REFERENCES parents (id),
                FOREIGN KEY (parent_id) REFERENCES parents (id)
            );
            SQL);
        $table = $schema->table('children');

        $this->assertTrue($schema->isComplete());
        $this->assertSame([null, 'by_parent', 'named', 'by_unnamed_constraint', null], array_column($table->foreignKeys, 'name'));
        $this->assertSame(['parent_id'], $table->foreignKeys[0]->columns);
        $this->assertSame(ReferentialAction::Cascade, $table->foreignKeys[0]->onDelete);
        $this->assertFalse($table->column('parent_id')->nullable);
    }

    #[DataProvider('malformedDefinitionProvider')]
    public function testMalformedDefinitionsFail(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message . ' at SQL line 1');

        (new MariaDbParser)->parse($sql);
    }

    /**
     * Provide malformed MariaDB declarations with their errors.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function malformedDefinitionProvider(): iterable
    {
        yield 'vector option' => ['CREATE TABLE broken (embedding vector(3), VECTOR KEY by_embedding (embedding) DISTANCE=)', 'Expected a value for option DISTANCE'];
        yield 'assigned column option' => ['CREATE TABLE broken (data blob COMPRESSED=)', 'Expected a value for option COMPRESSED'];
        yield 'row boundary' => ['CREATE TABLE broken (starts timestamp(6) AS ROW BEGIN)', 'Expected ROW START or ROW END'];
        yield 'period name' => ['CREATE TABLE broken (a date, b date, PERIOD FOR (a, b))', 'Expected a period name'];
    }
}
