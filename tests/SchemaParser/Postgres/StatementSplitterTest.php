<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Postgres;

use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\Postgres\StatementSplitter;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StatementSplitterTest extends TestCase
{
    public function testNativeCopyRecordsDoNotHideFollowingSchemaStatements(): void
    {
        $sql = file_get_contents(__DIR__ . '/../Fixtures/Postgres/copy-data.sql');
        $statements = iterator_to_array((new StatementSplitter)->split($sql));
        $queries = array_column($statements, 'sql');

        $this->assertCount(2, array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'COPY ')));
        $this->assertContains('CREATE INDEX parser_copy_index ON public.parser_copy_probe USING btree (migration)', $queries);
        $this->assertFalse(array_any($queries, static fn (string $query): bool => str_contains($query, "add_o'brien_flag")));
    }

    public function testCommentsAndMetaCommandsPreserveOriginalStatementOffsets(): void
    {
        $sql = <<<'SQL'
            -- header with SQL punctuation;
            \restrict example
            CREATE TABLE first_table (id integer);
            /* a nested /* comment; */ remains a comment */
            CREATE TABLE parser$tag$probe (id integer);
            CREATE FUNCTION example() RETURNS void AS $é$BEGIN PERFORM 1; END$é$ LANGUAGE plpgsql;
            \unrestrict example
            SQL;
        $statements = iterator_to_array((new StatementSplitter)->split($sql));

        $this->assertSame([
            ['sql' => 'CREATE TABLE first_table (id integer)', 'offset' => strpos($sql, 'CREATE TABLE first_table')],
            ['sql' => 'CREATE TABLE parser$tag$probe (id integer)', 'offset' => strpos($sql, 'CREATE TABLE parser$tag$probe')],
            ['sql' => 'CREATE FUNCTION example() RETURNS void AS $é$BEGIN PERFORM 1; END$é$ LANGUAGE plpgsql', 'offset' => strpos($sql, 'CREATE FUNCTION')],
        ], $statements);
    }

    #[DataProvider('lineEndings')]
    public function testCopyTerminatorIsAnExactLine(string $newline): void
    {
        $sql = 'COPY example (value) FROM stdin;' . $newline
            . '\.not-the-end' . $newline
            . "quote' ; -- \$tag\$ /*" . $newline
            . '\.' . $newline
            . 'CREATE TABLE following (id integer);';
        $statements = iterator_to_array((new StatementSplitter)->split($sql));

        $this->assertSame(['COPY example (value) FROM stdin', 'CREATE TABLE following (id integer)'], array_column($statements, 'sql'));
        $this->assertSame(strpos($sql, 'CREATE TABLE'), $statements[1]['offset']);
    }

    /**
     * Provide native line-ending variants.
     *
     * @return list<array{string}>
     */
    public static function lineEndings(): array
    {
        return [["\n"], ["\r\n"]];
    }

    public function testCopyTerminatorMayEndTheFile(): void
    {
        $statements = iterator_to_array((new StatementSplitter)->split("COPY example FROM stdin;\nrow\n\\."));

        $this->assertSame([['sql' => 'COPY example FROM stdin', 'offset' => 0]], $statements);
    }

    #[DataProvider('incompleteCopyStatements')]
    public function testMissingCopyTerminatorReportsTheCopyStatementLine(string $sql): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unterminated COPY data at SQL line 2');

        iterator_to_array((new StatementSplitter)->split("-- header\n" . $sql));
    }

    /**
     * Provide truncated COPY headers and data.
     *
     * @return list<array{string}>
     */
    public static function incompleteCopyStatements(): array
    {
        return [["COPY example FROM stdin;\nunclosed'"], ['COPY example FROM stdin']];
    }
}
