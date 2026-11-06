<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\SchemaParser\ParseException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InvalidTest extends TestCase
{
    // REMOVED: Permissive token recovery can turn a truncated dump into a false schema snapshot.

    #[DataProvider('brokenSyntax')]
    public function testBrokenSyntaxException(string $sql): void
    {
        $this->expectException(ParseException::class);

        (new SqlParser)->lex($sql);
    }

    /**
     * Provide the upstream malformed identifier and string cases.
     *
     * @return list<array{string}>
     */
    public static function brokenSyntax(): array
    {
        return [
            ['CREATE TABLE `users ( id int(10) )'],
            ["CREATE TABLE `users` ' ( `id` int(10) )"],
        ];
    }

    #[DataProvider('unclosedWrappers')]
    public function testUnclosedWrapperReportsItsStartingLine(string $sql, int $line): void
    {
        try {
            (new SqlParser)->lex($sql);
        } catch (ParseException $exception) {
            $this->assertSame($line, $exception->sqlLine);
            $this->assertStringContainsString('Unterminated executable comment', $exception->getMessage());

            return;
        }

        $this->fail('An unclosed executable comment must fail parsing.');
    }

    /**
     * Provide wrappers whose closing marker is absent or inside a line comment.
     *
     * @return list<array{string, int}>
     */
    public static function unclosedWrappers(): array
    {
        return [
            ["\nSELECT /*!50001 1\n", 2],
            ["\n\nSELECT /*M!100000 1 -- */\n", 3],
        ];
    }

    #[DataProvider('malformedDefinitions')]
    public function testMalformedDefinitionsFailWithParseErrors(string $sql): void
    {
        $this->expectException(ParseException::class);

        (new SqlParser)->parse($sql);
    }

    /**
     * Provide truncated and malformed supported declarations.
     *
     * @return list<array{string}>
     */
    public static function malformedDefinitions(): array
    {
        return [
            ['USE;'],
            ['CREATE TABLE'],
            ['CREATE TABLE copied LIKE'],
            ['CREATE TABLE broken (id int'],
            ['CREATE TABLE broken (id)'],
            ['CREATE TABLE broken (id int DEFAULT)'],
            ['CREATE TABLE broken (id int DEFAULT -)'],
            ['CREATE TABLE broken (id int,, name int)'],
            ['CREATE TABLE broken (id int, INDEX lookup (id name))'],
            ['CREATE TABLE broken (id int, CONSTRAINT)'],
            ['CREATE TABLE broken (id int CONSTRAINT)'],
            ['CREATE TABLE broken (id int, CHECK)'],
            ['CREATE TABLE broken (name char(10) CHARACTER SET)'],
            ['CREATE TABLE broken (name char(10) COLLATE)'],
            ['CREATE TABLE broken (id int COLUMN_FORMAT)'],
            ['CREATE TABLE broken (id int STORAGE)'],
            ['CREATE TABLE broken (location point SRID)'],
            ['CREATE TABLE broken (location point REF_SYSTEM_ID=)'],
            ['CREATE TABLE broken (data blob COMPRESSED=)'],
            ['CREATE TABLE broken (id int, KEY by_id (id) COMMENT)'],
            ['CREATE TABLE broken (id int, KEY by_id (id) KEY_BLOCK_SIZE)'],
            ['CREATE TABLE broken (id int, KEY by_id (id) WITH PARSER)'],
            ['CREATE TABLE broken (id int, FOREIGN KEY (id) REFERENCES parent (id) ON DELETE)'],
            ['CREATE TABLE broken (id int, FOREIGN KEY (id) REFERENCES parent (id) ON UPDATE UNKNOWN)'],
            ['CREATE TABLE broken (embedding vector(3), VECTOR KEY by_embedding (embedding) DISTANCE=)'],
            ['CREATE TABLE broken (id int) ENGINE='],
            ['CREATE TABLE broken (id int) PHYSICAL_OPTION='],
        ];
    }

    public function testDiagnosticLinesAdvanceWithinTablesAndResetForTheNextParse(): void
    {
        $parser = new SqlParser;
        $tables = $parser->parse(<<<'SQL'
            CREATE TABLE bookings (
                starts_at date, ends_at date,
                PERIOD FOR validity (starts_at, ends_at)
            );
            -- keep original source positions
            ALTER TABLE bookings ADD COLUMN extra int;
            SQL);

        $this->assertSame([3, 6], array_column(array_values($tables)[0]['issues'], 'line'));
        $next = $parser->parse("\nALTER TABLE another ADD COLUMN extra int;");
        $this->assertSame([2], array_column(array_values($next)[0]['issues'], 'line'));

        try {
            $parser->parse("\n\nCREATE TABLE broken (id int DEFAULT);");
        } catch (ParseException $exception) {
            $this->assertSame(3, $exception->sqlLine);
            return;
        }

        $this->fail('The malformed default must report its original source line.');
    }
}
