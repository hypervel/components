<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Parsing;

use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\Parsing\SQLiteLexer;
use Hypervel\Tests\SchemaParser\Fixtures\LexesStatements;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SQLiteLexerTest extends TestCase
{
    use LexesStatements;

    private const string SCHEMA = <<<'SQL'
        CREATE TABLE "log" (id integer primary key, note text default 'a;b');
        CREATE TRIGGER audit AFTER INSERT ON log BEGIN
          INSERT INTO log (note) VALUES ('x;y');
          UPDATE log SET note = CASE WHEN 1 THEN 'end' END;
          SELECT "END";
        END;
        CREATE TEMP TRIGGER temporary_audit AFTER DELETE ON log BEGIN DELETE FROM log; END;
        create temporary trigger lower_audit after update on log begin select 1; end;
        CREATE VIEW recent AS SELECT * FROM log; SELECT 2;
        SQL;

    public function testTriggerBodiesKeepTheirSemicolons(): void
    {
        $statements = array_column(self::statements(new SQLiteLexer(self::SCHEMA)), 1);

        $this->assertSame([
            "CREATE TABLE \"log\" (id integer primary key, note text default 'a;b')",
            "CREATE TRIGGER audit AFTER INSERT ON log BEGIN\n  INSERT INTO log (note) VALUES ('x;y');\n  UPDATE log SET note = CASE WHEN 1 THEN 'end' END;\n  SELECT \"END\";\nEND",
            'CREATE TEMP TRIGGER temporary_audit AFTER DELETE ON log BEGIN DELETE FROM log; END',
            'create temporary trigger lower_audit after update on log begin select 1; end',
            'CREATE VIEW recent AS SELECT * FROM log',
            'SELECT 2',
        ], $statements);
    }

    public function testSkippingAStatementFindsTheSameBoundaries(): void
    {
        $this->assertSame(self::boundaries(new SQLiteLexer(self::SCHEMA), false), self::boundaries(new SQLiteLexer(self::SCHEMA), true));
    }

    public function testSkippedGroupsKeepTheirSourceAndContinueAfterIt(): void
    {
        $this->assertSame(['(a, [)], (b))', 'Y'], self::group(new SQLiteLexer('x (a, [)], (b)) y')));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('tokenProvider')]
    public function testTokensFollowSQLiteLexicalRules(string $sql, array $expected): void
    {
        $this->assertSame($expected, self::tokens(new SQLiteLexer($sql)));
    }

    /**
     * Provide token boundaries for names, literals and comments.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tokenProvider(): iterable
    {
        yield 'quoted names' => ['"a""b" `c` [d e]', ['"a""b"', '`c`', '[d e]']];
        yield 'blobs' => ["x'0A' X'ff' xy", ["x'0A'", "X'ff'", 'xy']];
        yield 'numbers' => ['0x1F 1_000 1.5e3 .5 1.', ['0x1F', '1_000', '1.5e3', '.5', '1.']];
        yield 'names with dollars and high bytes' => ["a\$b \u{e9}t\u{e9}", ['a$b', "\u{e9}t\u{e9}"]];
        yield 'comments' => ["a -- comment\nb /* comment */ c", ['a', 'b', 'c']];
        yield 'byte order marks before tokens' => ["\xEF\xBB\xBFa \xEF\xBB\xBFb\xEF\xBB\xBF", ['a', "b\xEF\xBB\xBF"]];
    }

    #[DataProvider('valueProvider')]
    public function testValuesAreDecodedAsSQLiteReadsThem(string $sql, string $expected): void
    {
        $tokens = (new SQLiteLexer($sql))->nextStatement();

        $this->assertSame($expected, $tokens->value());
    }

    /**
     * Provide identifiers and literals with their decoded values.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function valueProvider(): iterable
    {
        yield 'string' => ["'it''s \\n'", "it's \\n"];
        yield 'double-quoted name' => ['"a""b"', 'a"b'];
        yield 'backtick name' => ['`a``b`', 'a`b'];
        yield 'bracketed name' => ['[a""b]', 'a""b'];
    }

    #[DataProvider('unterminatedProvider')]
    public function testUnterminatedRunsReportTheirStartLine(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("{$message} at SQL line 2");

        self::statements(new SQLiteLexer("SELECT 1;\n{$sql}"));
    }

    /**
     * Provide unterminated runs with their messages.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unterminatedProvider(): iterable
    {
        yield 'string' => ["'a\n", 'Unterminated string literal'];
        yield 'blob' => ["x'0A\n", 'Unterminated blob literal'];
        yield 'quoted name' => ["\"a\n", 'Unterminated quoted identifier'];
        yield 'bracketed name' => ["[a\n", 'Unterminated quoted identifier'];
        yield 'comment' => ["/* a\n", 'Unterminated comment'];
    }
}
