<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Parsing;

use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\Parsing\MySqlLexer;
use Hypervel\SchemaParser\Parsing\TokenType;
use Hypervel\Tests\SchemaParser\Fixtures\LexesStatements;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MySqlLexerTest extends TestCase
{
    use LexesStatements;

    private const string ROUTINES = <<<'SQL'
        /*!50003 SET @saved_sql_mode = @@sql_mode */ ;
        DELIMITER ;;
        /*!50003 CREATE*/ /*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER audit BEFORE INSERT ON t FOR EACH ROW BEGIN
          SET NEW.note = 'a;;b'; -- a comment with ;;
          SET NEW.created = NOW();
        END */;;
        DELIMITER ;
        DELIMITER $$
        CREATE PROCEDURE p() BEGIN SELECT 1; SELECT '$$'; END$$
        delimiter //
        CREATE FUNCTION f() RETURNS int RETURN 1 //
        DELIMITER ;
        CREATE TABLE following (id int);
        SQL;

    public function testExecutableCommentsAreReadAsSqlAtTheirSourceOffsets(): void
    {
        $sql = <<<'SQL'
            /*!40101 SET NAMES utf8mb4 */;
            /*!50001 CREATE ALGORITHM=UNDEFINED */
            /*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
            /*!50001 VIEW `v` AS select 1 /* note */ AS `a` */;
            SQL;

        $this->assertSame([
            [strpos($sql, 'SET'), 'SET NAMES utf8mb4'],
            [strpos($sql, 'CREATE'), substr($sql, strpos($sql, 'CREATE'), strpos($sql, 'AS `a`') + 6 - strpos($sql, 'CREATE'))],
        ], self::statements(new MySqlLexer($sql)));
        $this->assertSame(
            ['CREATE', 'ALGORITHM', '=', 'UNDEFINED', 'DEFINER', '=', '`root`', '@', '`localhost`', 'SQL', 'SECURITY', 'DEFINER', 'VIEW', '`v`', 'AS', 'select', '1', 'AS', '`a`'],
            self::tokens(new MySqlLexer(substr($sql, strpos($sql, '/*!50001')))),
        );
    }

    public function testMariaDbExecutableCommentsAreReadOnlyForMariaDb(): void
    {
        $sql = "/*M!100100 SET check_constraint_checks=0 */;\n/*M!999999\\- enable the sandbox mode */\nCREATE TABLE t (id int);";

        $this->assertSame(['CREATE TABLE t (id int)'], array_column(self::statements(new MySqlLexer($sql)), 1));
        $this->assertSame(['SET check_constraint_checks=0', 'CREATE TABLE t (id int)'], array_column(self::statements(new MySqlLexer($sql, mariaDb: true)), 1));
    }

    public function testUnterminatedExecutableCommentReportsItsStart(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unterminated executable comment at SQL line 2');

        self::statements(new MySqlLexer("SELECT 1;\n/*!50001 CREATE VIEW v AS select 1"));
    }

    public function testDelimitersMakeRoutinesSingleStatements(): void
    {
        $statements = array_column(self::statements(new MySqlLexer(self::ROUTINES)), 1);

        $this->assertCount(5, $statements);
        $this->assertSame('SET @saved_sql_mode = @@sql_mode', $statements[0]);
        $this->assertStringStartsWith('CREATE', $statements[1]);
        $this->assertStringEndsWith("SET NEW.created = NOW();\nEND", $statements[1]);
        $this->assertSame("CREATE PROCEDURE p() BEGIN SELECT 1; SELECT '$$'; END", $statements[2]);
        $this->assertSame('CREATE FUNCTION f() RETURNS int RETURN 1', $statements[3]);
        $this->assertSame('CREATE TABLE following (id int)', $statements[4]);
    }

    public function testDelimiterCommandsAcceptWindowsLineEndings(): void
    {
        $sql = "DELIMITER //\r\nCREATE PROCEDURE p() BEGIN SELECT 1; END //\r\nDELIMITER ;\r\nSELECT 2;";

        $this->assertSame(
            ['CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 2'],
            array_column(self::statements(new MySqlLexer($sql)), 1),
        );
    }

    public function testSkippingAStatementFindsTheSameBoundaries(): void
    {
        $sql = file_get_contents(__DIR__ . '/../Fixtures/MySql/glitch_main.sql');
        $this->assertNotFalse($sql);
        $sql .= "\n" . self::ROUTINES;

        $this->assertSame(self::boundaries(new MySqlLexer($sql), false), self::boundaries(new MySqlLexer($sql), true));
    }

    public function testSkippedGroupsKeepTheirSourceAndContinueAfterIt(): void
    {
        $this->assertSame(["(a, (b) /* ) */, ')')", 'Y'], self::group(new MySqlLexer("x (a, (b) /* ) */, ')') y")));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('tokenProvider')]
    public function testTokensFollowMySqlLexicalRules(string $sql, array $expected): void
    {
        $this->assertSame($expected, self::tokens(new MySqlLexer($sql)));
    }

    /**
     * Provide token boundaries for comments, names and literals.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tokenProvider(): iterable
    {
        yield 'hash comment' => ["a # comment\nb", ['a', 'b']];
        yield 'dash comment before whitespace' => ["a -- comment\nb", ['a', 'b']];
        yield 'dash comment at the end' => ['a --', ['a']];
        yield 'dashes without whitespace' => ['a --b', ['a', '-', '-', 'b']];
        yield 'digit-leading names' => ['1abc 12_x $x', ['1abc', '12_x', '$x']];
        yield 'numbers stay numbers' => ['1e5 1.5 .5 1.5e3abc', ['1e5', '1.5', '.5', '1.5e3', 'abc']];
        yield 'qualified digit-leading names' => ['db.1table t.1e5', ['db', '.', '1table', 't', '.', '1e5']];
        yield 'signs are operators' => ['-1 +2', ['-', '1', '+', '2']];
        yield 'prefixed literals' => ["x'1F' b'01' N'n' _utf8mb4'u' _binary 'b'", ["x'1F'", "b'01'", "N'n'", "_utf8mb4'u'", '_binary', "'b'"]];
        yield 'quoted names' => ['`a``b` "c"', ['`a``b`', '"c"']];
    }

    public function testSqlModesDecideQuotesAndEscapes(): void
    {
        $tokens = (new MySqlLexer("'a\\'b' \"c\""))->nextStatement();

        $this->assertSame(TokenType::EscapedString, $tokens->type());
        $this->assertSame("a'b", $tokens->value());
        $tokens->next();
        $this->assertSame(TokenType::EscapedString, $tokens->type());
        $this->assertSame('c', $tokens->value());

        $lexer = new MySqlLexer("'a\\' \"c\"");
        $lexer->noBackslashEscapes = true;
        $lexer->ansiQuotes = true;
        $tokens = $lexer->nextStatement();

        $this->assertSame(TokenType::String, $tokens->type());
        $this->assertSame('a\\', $tokens->value());
        $tokens->next();
        $this->assertSame(TokenType::QuotedIdentifier, $tokens->type());
        $this->assertSame('c', $tokens->value());
    }

    #[DataProvider('valueProvider')]
    public function testValuesAreDecodedAsMySqlReadsThem(string $sql, string $expected): void
    {
        $tokens = (new MySqlLexer($sql))->nextStatement();

        $this->assertSame($expected, $tokens->value());
    }

    /**
     * Provide identifiers and literals with their decoded values.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function valueProvider(): iterable
    {
        yield 'backslash escapes' => ["'\\0\\b\\n\\r\\t\\Z\\\\\\'\\q'", "\0\x08\n\r\t\x1a\\'q"];
        yield 'pattern escapes keep their backslash' => ["'\\%\\_'", '\%\_'];
        yield 'doubled quote' => ["'it''s'", "it's"];
        yield 'double-quoted string' => ['"say ""hi"""', 'say "hi"'];
        yield 'national string' => ["N'a\\tb'", "a\tb"];
        yield 'charset introducer' => ["_utf8mb4'a\\tb'", "a\tb"];
        yield 'doubled backtick' => ['`a``b`', 'a`b'];
        yield 'unquoted name keeps case' => ['Users', 'Users'];
    }
}
