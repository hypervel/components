<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Parsing;

use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\Parsing\Lexer;
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

    /**
     * @param list<string> $expected
     */
    #[DataProvider('executableCommentProvider')]
    public function testExecutableCommentsKeepQuotedTerminatorsAndInnerComments(string $sql, bool $mariaDb, array $expected): void
    {
        $this->assertSame($expected, self::tokens(new MySqlLexer($sql, $mariaDb)));
    }

    /**
     * Provide executable comments whose quoted text or inner comments contain a closing marker.
     *
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function executableCommentProvider(): iterable
    {
        yield 'quoted terminator' => ["SELECT /*!50001 'a;*/b' */;", false, ['SELECT', "'a;*/b'"]];
        yield 'quoted MariaDB terminator' => ['SELECT /*M!100000 `a*/b` */;', true, ['SELECT', '`a*/b`']];
        yield 'inner block comment' => ["SELECT /*!50001 1 /* it's */ + 1 */;", false, ['SELECT', '1', '+', '1']];
        yield 'inner dash comment' => ["SELECT /*!50001 1 -- it's */\n+ 1 */;", false, ['SELECT', '1', '+', '1']];
        yield 'inner hash comment' => ["SELECT /*!50001 1 # it's */\n+ 1 */;", false, ['SELECT', '1', '+', '1']];
    }

    #[DataProvider('unclosedExecutableCommentProvider')]
    public function testUnclosedExecutableCommentsReportTheirStart(string $sql, bool $mariaDb, int $line): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("Unterminated executable comment at SQL line {$line}");

        self::statements(new MySqlLexer($sql, $mariaDb));
    }

    /**
     * Provide executable comments whose closing marker is missing or inside a line comment.
     *
     * @return iterable<string, array{string, bool, int}>
     */
    public static function unclosedExecutableCommentProvider(): iterable
    {
        yield 'missing marker' => ["SELECT 1;\n/*!50001 CREATE VIEW v AS select 1", false, 2];
        yield 'marker in a line comment' => ["\n\nSELECT /*M!100000 1 -- */\n", true, 3];
    }

    #[DataProvider('unterminatedTokenProvider')]
    public function testUnterminatedTokensFail(string $sql): void
    {
        $this->expectException(ParseException::class);

        self::statements(new MySqlLexer($sql));
    }

    /**
     * Provide comments, names and strings that end with the input.
     *
     * @return iterable<string, array{string}>
     */
    public static function unterminatedTokenProvider(): iterable
    {
        yield 'comment' => ['foo/* hello'];
        yield 'comment ending with a star' => ['foo/* hello *'];
        yield 'comment across lines' => ["foo/* hello \n world"];
        yield 'quoted name' => ['CREATE TABLE `users ( id int(10) )'];
        yield 'string' => ["CREATE TABLE `users` ' ( `id` int(10) )"];
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
        yield 'dash comment before a tab' => ["a --\tcomment\nb", ['a', 'b']];
        yield 'dash comment after a word' => ["a\nb-- comment\nc", ['a', 'b', 'c']];
        yield 'dash comment at the end' => ['a --', ['a']];
        yield 'empty dash comment' => ["(--\n id int,\n name text)", ['(', 'id', 'int', ',', 'name', 'text', ')']];
        yield 'dashes without whitespace' => ['a --b 1--2', ['a', '-', '-', 'b', '1', '-', '-', '2']];
        yield 'block comments' => ["a/* one */ b/*two\n*/c", ['a', 'b', 'c']];
        yield 'digit-leading names' => ['2fa_codes 0x01 12e_codes $identifier', ['2fa_codes', '0x01', '12e_codes', '$identifier']];
        yield 'numbers stay numbers' => ['1e5 1.5 .5 12. 1.2foo 1e5foo 1.5e3abc', ['1e5', '1.5', '.5', '12.', '1.2', 'foo', '1e5', 'foo', '1.5e3', 'abc']];
        yield 'qualified digit-leading names' => ['+2fa.id t.2fa `t`.1e5foo 9-2fa', ['+', '2fa', '.', 'id', 't', '.', '2fa', '`t`', '.', '1e5foo', '9', '-', '2fa']];
        yield 'signs are operators' => ['-1 +2', ['-', '1', '+', '2']];
        yield 'prefixed literals' => ["x'1F' b'01' N'n' _utf8mb4'u' _binary 'b'", ["x'1F'", "b'01'", "N'n'", "_utf8mb4'u'", '_binary', "'b'"]];
        yield 'escaped and doubled quotes' => [<<<'SQL'
            'a \' b' "a \" b" 'a''b' "a""b" "a''b" 'a""b'
            SQL, ["'a \\' b'", '"a \" b"', "'a''b'", '"a""b"', "\"a''b\"", '\'a""b\'']];
        yield 'quoted names' => ["`a``b` `multi \n line` \"c\"", ['`a``b`', "`multi \n line`", '"c"']];
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

    #[DataProvider('runProvider')]
    public function testRunsReadTokensAsTheScannerDoes(string $sql, bool $mariaDb = false, bool $noBackslashEscapes = false, bool $ansiQuotes = false): void
    {
        $lexer = new MySqlLexer($sql, $mariaDb);
        $scanner = new ScanningMySqlLexer($sql, $mariaDb);
        $lexer->noBackslashEscapes = $scanner->noBackslashEscapes = $noBackslashEscapes;
        $lexer->ansiQuotes = $scanner->ansiQuotes = $ansiQuotes;

        $this->assertSame(self::lexed($scanner), self::lexed($lexer));
    }

    /**
     * Provide the lexical cases with their tokens in runs, after a statement's first token, and runs across window cuts.
     *
     * @return iterable<string, array{0: string, 1?: bool, 2?: bool, 3?: bool}>
     */
    public static function runProvider(): iterable
    {
        foreach (self::tokenProvider() as $name => [$sql]) {
            yield "token: {$name}" => ["x {$sql}"];
        }

        foreach (self::valueProvider() as $name => [$sql]) {
            yield "value: {$name}" => ["x {$sql}"];
        }

        foreach (self::executableCommentProvider() as $name => [$sql, $mariaDb]) {
            yield "executable comment: {$name}" => [$sql, $mariaDb];
        }

        $quotes = "x 'a\\b' \"c\"\"d\" 'e''f' `g`.h t.1e5 (1, 2);\nx 'i';";

        yield 'escaped quotes' => [$quotes];
        yield 'no backslash escapes' => [$quotes, false, true];
        yield 'ANSI quotes' => [$quotes, false, false, true];
        yield 'both modes' => [$quotes, false, true, true];
        yield 'routines' => [self::ROUTINES];
        yield 'real dump' => [file_get_contents(__DIR__ . '/../Fixtures/MySql/glitch_main.sql')];

        $columns = '';

        for ($column = 0; $column < 1500; ++$column) {
            $columns .= "  `col {$column}` varchar(9) DEFAULT 'a ''b'' {$column}' COMMENT \"c \\\" d\", # note {$column}\n  /*!80023 INVISIBLE */ -- e f\n";
        }

        yield 'window cuts' => ["CREATE TABLE `t` (\n{$columns}  id int);\nSELECT 1;"];
    }
}

class ScanningMySqlLexer extends MySqlLexer
{
    /**
     * Read one scanned token at a time, as the reference for runs.
     */
    public function read(array &$types, array &$starts, array &$ends, array &$texts): void
    {
        Lexer::read($types, $starts, $ends, $texts);
    }
}
