<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Postgres;

use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\Postgres\SqlScanner;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SqlScannerTest extends TestCase
{
    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('splitProvider')]
    public function itSplitsOnlyOnTopLevelCommas(string $sql, array $expected): void
    {
        $this->assertSame($expected, SqlScanner::splitTopLevel($sql));
    }

    /**
     * Provide declarations with nested groups and quoted commas.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function splitProvider(): iterable
    {
        yield 'plain' => ['a, b, c', ['a', 'b', 'c']];

        yield 'comma inside parentheses' => [
            'total numeric(12,2), label text',
            ['total numeric(12,2)', 'label text'],
        ];

        yield 'comma inside a string literal' => [
            "label text DEFAULT 'a,b', other text",
            ["label text DEFAULT 'a,b'", 'other text'],
        ];

        yield 'comma inside a quoted identifier' => [
            '"a,b" text, c text',
            ['"a,b" text', 'c text'],
        ];

        yield 'nested parentheses' => [
            'a integer GENERATED ALWAYS AS ((b * (c + 1))) STORED, d text',
            ['a integer GENERATED ALWAYS AS ((b * (c + 1))) STORED', 'd text'],
        ];

        yield 'doubled quote inside a literal' => [
            "a text DEFAULT 'it''s, fine', b text",
            ["a text DEFAULT 'it''s, fine'", 'b text'],
        ];

        yield 'nested array default' => [
            'numbers integer[] DEFAULT ARRAY[[1, NULL], [2, 3]], following integer',
            ['numbers integer[] DEFAULT ARRAY[[1, NULL], [2, 3]]', 'following integer'],
        ];

        yield 'type name ending in e' => [
            "label name DEFAULT name'path\\', following integer",
            ["label name DEFAULT name'path\\'", 'following integer'],
        ];

        yield 'escape literal' => [
            "label text DEFAULT E'a\\',b', following integer",
            ["label text DEFAULT E'a\\',b'", 'following integer'],
        ];

        yield 'dollar-bearing identifier' => [
            'parser$tag$probe integer, following integer',
            ['parser$tag$probe integer', 'following integer'],
        ];

        yield 'Unicode dollar tag' => [
            'label text DEFAULT $é$one, two$é$, following integer',
            ['label text DEFAULT $é$one, two$é$', 'following integer'],
        ];

        yield 'nested comments' => [
            'first integer /* outer /* , */ comment */, following integer',
            ['first integer /* outer /* , */ comment */', 'following integer'],
        ];
    }

    #[Test]
    #[DataProvider('identifierProvider')]
    public function itResolvesIdentifiersTheWayPostgresStoresThem(string $input, string $expected): void
    {
        $this->assertSame($expected, SqlScanner::identifier($input));
    }

    /**
     * Provide quoted and unquoted identifier forms.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function identifierProvider(): iterable
    {
        // PostgreSQL folds unquoted identifiers to lower case, and preserves the
        // case of quoted ones.
        yield 'bare is folded' => ['Users', 'users'];
        yield 'quoted keeps case' => ['"Users"', 'Users'];
        yield 'quoted keeps punctuation' => ['"a,b"', 'a,b'];
        yield 'doubled quote is unescaped' => ['"a""b"', 'a"b'];
    }

    #[Test]
    public function itReturnsTheContentsOfABalancedGroup(): void
    {
        $sql = 'CREATE TABLE t (a integer, b numeric(1,2))';

        $this->assertSame('a integer, b numeric(1,2)', SqlScanner::balanced($sql, 15));
    }

    #[Test]
    public function itReportsAnUnbalancedGroup(): void
    {
        $this->assertNull(SqlScanner::balanced('CREATE TABLE t (a integer', 15));
    }

    #[DataProvider('unterminatedRuns')]
    public function testUnterminatedRunsReportTheirSourceLine(string $sql): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('at SQL line 2');

        SqlScanner::splitTopLevel("-- header\n" . $sql);
    }

    /**
     * Provide quoted and commented runs missing their terminator.
     *
     * @return list<array{string}>
     */
    public static function unterminatedRuns(): array
    {
        return [["'unfinished"], ['"unfinished'], ['$tag$unfinished'], ['/* outer /* inner */']];
    }

    #[DataProvider('unbalancedGroups')]
    public function testMalformedGroupsCannotSplitIntoCompleteDeclarations(string $sql): void
    {
        $this->expectException(ParseException::class);

        SqlScanner::splitTopLevel($sql);
    }

    /**
     * Provide unmatched grouping delimiters in declaration fragments.
     *
     * @return list<array{string}>
     */
    public static function unbalancedGroups(): array
    {
        return [['values integer[] DEFAULT ARRAY[1, 2'], ['value integer)']];
    }
}
