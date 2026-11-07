<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Parsing;

use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\Parsing\Lexer;
use Hypervel\SchemaParser\Parsing\PostgresLexer;
use Hypervel\Tests\SchemaParser\Fixtures\LexesStatements;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PostgresLexerTest extends TestCase
{
    use LexesStatements;

    private const string ROUTINES = <<<'SQL'
        CREATE FUNCTION add_one(value integer) RETURNS integer LANGUAGE sql
            BEGIN ATOMIC SELECT CASE WHEN value > 0 THEN value + 1 ELSE 0 END; END;
        CREATE OR REPLACE PROCEDURE log_value() LANGUAGE sql
            BEGIN ATOMIC INSERT INTO log VALUES (1); INSERT INTO log VALUES (2); END;
        CREATE RULE copy_rule AS ON INSERT TO t DO ALSO (INSERT INTO a VALUES (1); INSERT INTO b VALUES (2));
        BEGIN;
        CREATE TABLE following (id integer);
        SQL;

    public function testCopyDataDoesNotHideFollowingStatements(): void
    {
        $statements = array_column(self::statements(new PostgresLexer($this->copyDump())), 1);

        $this->assertCount(2, array_filter($statements, static fn (string $statement): bool => str_starts_with($statement, 'COPY ')));
        $this->assertContains('CREATE INDEX parser_copy_index ON public.parser_copy_probe USING btree (migration)', $statements);
        $this->assertFalse(array_any($statements, static fn (string $statement): bool => str_contains($statement, "add_o'brien_flag")));
    }

    public function testCommentsAndMetaCommandsKeepOriginalOffsets(): void
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

        $this->assertSame([
            [strpos($sql, 'CREATE TABLE first_table'), 'CREATE TABLE first_table (id integer)'],
            [strpos($sql, 'CREATE TABLE parser$tag$probe'), 'CREATE TABLE parser$tag$probe (id integer)'],
            [strpos($sql, 'CREATE FUNCTION'), 'CREATE FUNCTION example() RETURNS void AS $é$BEGIN PERFORM 1; END$é$ LANGUAGE plpgsql'],
        ], self::statements(new PostgresLexer($sql)));
    }

    #[DataProvider('newlineProvider')]
    public function testCarriageReturnsAndLineFeedsEndCommentsAndContinueStrings(string $newline): void
    {
        $tokens = (new PostgresLexer("-- header{$newline}SELECT 'one' -- note{$newline}'two';"))->nextStatement();

        $this->assertSame(strlen("-- header{$newline}"), $tokens->start);
        $tokens->next();
        $this->assertSame('onetwo', $tokens->value());
        $tokens->next();
        $this->assertTrue($tokens->atEnd());
    }

    /**
     * Provide the newlines PostgreSQL recognizes.
     *
     * @return iterable<string, array{string}>
     */
    public static function newlineProvider(): iterable
    {
        yield 'line feed' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'carriage return line feed' => ["\r\n"];
    }

    public function testLeadingByteOrderMarkIsSkipped(): void
    {
        $tokens = (new PostgresLexer("\xEF\xBB\xBFCREATE TABLE t (id integer);"))->nextStatement();

        $this->assertSame(3, $tokens->start);
        $this->assertSame('CREATE', $tokens->keyword());
    }

    #[DataProvider('copyStatements')]
    public function testCopyFromStdinDataEndsAtAnExactTerminatorLine(string $copy, string $newline): void
    {
        $sql = $copy . ';' . $newline
            . '\.not-the-end' . $newline
            . "quote' ; -- \$tag\$ /*" . $newline
            . '\.' . $newline
            . 'CREATE TABLE following (id integer);';

        $this->assertSame([
            [0, $copy],
            [strpos($sql, 'CREATE TABLE'), 'CREATE TABLE following (id integer)'],
        ], self::statements(new PostgresLexer($sql)));
    }

    /**
     * Provide COPY headers that psql reads data for, with native line endings.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function copyStatements(): iterable
    {
        yield 'pg_dump form' => ['COPY public.example (value) FROM stdin', "\n"];
        yield 'carriage return line feed' => ['COPY example FROM stdin', "\r\n"];
        yield 'qualified binary copy' => ['COPY BINARY public.example FROM STDIN WITH (FORMAT binary)', "\n"];
        yield 'stdout, which the server also accepts' => ['COPY example FROM STDOUT', "\n"];
    }

    public function testCopyTerminatorMayEndTheInput(): void
    {
        $this->assertSame([[0, 'COPY example FROM stdin']], self::statements(new PostgresLexer("COPY example FROM stdin;\nrow\n\\.")));
    }

    #[DataProvider('statementsWithoutCopyData')]
    public function testOtherCopyStatementsHaveNoData(string $copy): void
    {
        $sql = $copy . ";\nCREATE TABLE following (id integer);";

        $this->assertSame([[0, $copy], [strlen($copy) + 2, 'CREATE TABLE following (id integer)']], self::statements(new PostgresLexer($sql)));
    }

    /**
     * Provide COPY statements that psql sends without reading data.
     *
     * @return iterable<string, array{string}>
     */
    public static function statementsWithoutCopyData(): iterable
    {
        yield 'to stdout' => ['COPY example TO stdout'];
        yield 'from a file' => ["COPY example FROM '/tmp/rows'"];
        yield 'stdin inside a query' => ['COPY (SELECT value FROM stdin) TO stdout'];
    }

    #[DataProvider('unterminatedCopyData')]
    public function testUnterminatedCopyDataReportsTheCopyLine(string $sql): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unterminated COPY data at SQL line 2');

        self::statements(new PostgresLexer("-- header\n" . $sql));
    }

    /**
     * Provide truncated COPY headers and data.
     *
     * @return list<array{string}>
     */
    public static function unterminatedCopyData(): array
    {
        return [["COPY example FROM stdin;\nunclosed'"], ['COPY example FROM stdin']];
    }

    public function testRoutineBodiesAndParenthesesKeepTheirSemicolons(): void
    {
        $statements = array_column(self::statements(new PostgresLexer(self::ROUTINES)), 1);

        $this->assertCount(5, $statements);
        $this->assertStringEndsWith('ELSE 0 END; END', $statements[0]);
        $this->assertStringEndsWith('VALUES (2); END', $statements[1]);
        $this->assertStringEndsWith('VALUES (2))', $statements[2]);
        $this->assertSame('BEGIN', $statements[3]);
        $this->assertSame('CREATE TABLE following (id integer)', $statements[4]);
    }

    public function testSkippingAStatementFindsTheSameBoundaries(): void
    {
        $sql = $this->copyDump() . "\n" . self::ROUTINES;

        $this->assertSame(self::boundaries(new PostgresLexer($sql), false), self::boundaries(new PostgresLexer($sql), true));
    }

    /**
     * @param array{string, string} $expected
     */
    #[DataProvider('groupProvider')]
    public function testSkippedGroupsKeepTheirSourceAndContinueAfterIt(string $sql, array $expected): void
    {
        $this->assertSame($expected, self::group(new PostgresLexer($sql)));
    }

    /**
     * Provide groups whose remainder is skipped, as a string at the end of a line stops the run of tokens read so far.
     *
     * @return iterable<string, array{string, array{string, string}}>
     */
    public static function groupProvider(): iterable
    {
        yield 'parenthesis in a string' => ["x ((b), 'a'\n, ')') y", ["((b), 'a'\n, ')')", 'Y']];
        yield 'parenthesis after a comment' => ["x (b, 'a'\n  -- )\n) y", ["(b, 'a'\n  -- )\n)", 'Y']];
        yield 'dollar-quoted and escaped parentheses' => ["x (b, 'a'\n, \$\$)\$\$, E'\\')') y", ["(b, 'a'\n, \$\$)\$\$, E'\\')')", 'Y']];
        yield 'semicolon in a group' => ["x (b, 'a'\n; c) y", ["(b, 'a'\n; c)", 'Y']];
    }

    public function testUnterminatedGroupReportsItsOpeningLine(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unterminated parenthesized expression at SQL line 2');

        self::group(new PostgresLexer("x\n(b, 'a'\n, c\n"));
    }

    #[DataProvider('unterminatedRuns')]
    public function testUnterminatedRunsReportTheirSourceLine(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("{$message} at SQL line 2");

        self::statements(new PostgresLexer("-- header\n" . $sql));
    }

    /**
     * Provide quoted and commented runs missing their terminator.
     *
     * @return list<array{string, string}>
     */
    public static function unterminatedRuns(): array
    {
        return [
            ["'unfinished", 'Unterminated string literal'],
            ["E'escaped quote\\'", 'Unterminated string literal'],
            ['"unfinished', 'Unterminated quoted identifier'],
            ['$tag$unfinished', 'Unterminated dollar-quoted string'],
            ['/* outer /* inner */', 'Unterminated comment'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('tokenProvider')]
    public function testTokensKeepQuotedNestedAndCommentedText(string $sql, array $expected): void
    {
        $this->assertSame($expected, self::tokens(new PostgresLexer($sql)));
    }

    /**
     * Provide token boundaries that quoting, comments and operators decide.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tokenProvider(): iterable
    {
        yield 'comma inside a string literal' => ["label text DEFAULT 'a,b', other", ['label', 'text', 'DEFAULT', "'a,b'", ',', 'other']];
        yield 'comma inside a quoted identifier' => ['"a,b" text', ['"a,b"', 'text']];
        yield 'comment marker inside a string literal' => ["a text DEFAULT '-- not a comment', b", ['a', 'text', 'DEFAULT', "'-- not a comment'", ',', 'b']];
        yield 'doubled quote' => ["'it''s, fine'", ["'it''s, fine'"]];
        yield 'word ending in e before a string' => ["name'path\\'", ['name', "'path\\'"]];
        yield 'escape string' => ["E'a\\',b'", ["E'a\\',b'"]];
        yield 'dollar-bearing identifier' => ['parser$tag$probe integer', ['parser$tag$probe', 'integer']];
        yield 'Unicode dollar tag' => ['$é$one, two$é$', ['$é$one, two$é$']];
        yield 'positional parameter' => ['$1 + $23', ['$1', '+', '$23']];
        yield 'nested comment' => ['first /* outer /* , */ comment */ following', ['first', 'following']];
        yield 'operator before a comment' => ["a <-- comment\nb", ['a', '<', 'b']];
        yield 'semicolon inside parentheses' => ['(a; b)', ['(', 'a', ';', 'b', ')']];
        yield 'continued string' => ["'one'\n  -- comment\n  'two' three", ["'one'\n  -- comment\n  'two'", 'three']];
        yield 'string continued after a vertical tab' => ["'one' \x0B\n'two' three", ["'one' \x0B\n'two'", 'three']];
        yield 'high byte after an operator' => ["a+\x85b", ['a', '+', "\x85b"]];
        yield 'strings on one line' => ["'one' 'two'", ["'one'", "'two'"]];
        yield 'Unicode escape clause' => ["U&'d!0061t' UESCAPE '!' following", ["U&'d!0061t' UESCAPE '!'", 'following']];
        yield 'numbers' => ['1.5e3 .5 0x1F 1_000', ['1.5e3', '.5', '0x1F', '1_000']];
        yield 'string without whitespace past a native window' => ["x '" . str_repeat('a', 10000) . "' y", ['x', "'" . str_repeat('a', 10000) . "'", 'y']];
        yield 'tokens separated by commas past a native window' => ['x ' . implode(',', array_fill(0, 3000, 'ab')) . ' y', ['x', ...explode(' ', implode(' , ', array_fill(0, 3000, 'ab'))), 'y']];
        yield 'tokens without a window cut' => [implode('.', array_fill(0, 9000, 'ab')) . ' y', [...explode(' ', implode(' . ', array_fill(0, 9000, 'ab'))), 'y']];
    }

    #[DataProvider('valueProvider')]
    public function testValuesAreDecodedAsPostgresReadsThem(string $sql, string $expected, bool $standardConformingStrings = true): void
    {
        $lexer = new PostgresLexer($sql);
        $lexer->standardConformingStrings = $standardConformingStrings;
        $tokens = $lexer->nextStatement();

        $this->assertSame($expected, $tokens->value());
    }

    /**
     * Provide identifiers and literals with their decoded values.
     *
     * @return iterable<string, array{0: string, 1: string, 2?: bool}>
     */
    public static function valueProvider(): iterable
    {
        yield 'unquoted identifier is folded' => ['Users', 'users'];
        yield 'quoted identifier keeps case' => ['"Users"', 'Users'];
        yield 'quoted identifier keeps punctuation' => ['"a,b"', 'a,b'];
        yield 'doubled identifier quote' => ['"a""b"', 'a"b'];
        yield 'Unicode identifier' => ['U&"d\0061t"', 'dat'];
        yield 'doubled string quote' => ["'it''s'", "it's"];
        yield 'standard string keeps backslashes' => ["'a\\nb'", 'a\nb'];
        yield 'escape string' => ["E'line\\nbreak\\t\\101\\x41\\u0041\\U0001F600\\q'", "line\nbreak\tAAA😀q"];
        yield 'escape surrogate pair' => ["E'\\uD83D\\uDE00'", '😀'];
        yield 'continued escape string' => ["E'a\\n'\n'\\t'", "a\n\t"];
        yield 'continued string' => ["'one'\n'two'", 'onetwo'];
        yield 'Unicode string' => ["U&'d\\0061t\\+000061 \\\\'", 'data \\'];
        yield 'Unicode surrogate pair' => ["U&'\\D83D\\DE00'", '😀'];
        yield 'Unicode escape clause' => ["U&'d!0061t!!' UESCAPE '!'", 'dat!'];
        yield 'dollar string' => ["\$tag\$it's \\n\$tag\$", "it's \\n"];
        yield 'bit string' => ["B'101'", '101'];
        yield 'nonstandard string' => ["'a\\nb\\''", "a\nb'", false];
        yield 'national string follows the setting' => ["N'a\\nb'", "a\nb", false];
        yield 'national standard string' => ["N'a\\nb'", 'a\nb'];
    }

    #[DataProvider('invalidUnicode')]
    public function testInvalidUnicodeEscapesFail(string $sql, string $message): void
    {
        $tokens = (new PostgresLexer($sql))->nextStatement();

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        $tokens->value();
    }

    /**
     * Provide Unicode escapes PostgreSQL rejects.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUnicode(): iterable
    {
        yield 'lone high surrogate' => ["U&'\\D83D'", 'Invalid Unicode surrogate pair'];
        yield 'unpaired escape surrogate' => ["E'\\uD83Dx'", 'Invalid Unicode surrogate pair'];
        yield 'short escape' => ["U&'\\00'", 'Invalid Unicode escape'];
        yield 'null character' => ["U&'\\0000'", 'Invalid Unicode escape value'];
        yield 'beyond Unicode' => ["E'\\U00110000'", 'Invalid Unicode escape value'];
    }

    #[DataProvider('runProvider')]
    public function testRunsReadTokensAsTheScannerDoes(string $sql, bool $standardConformingStrings = true): void
    {
        $lexer = new PostgresLexer($sql);
        $scanner = new ScanningPostgresLexer($sql);
        $lexer->standardConformingStrings = $scanner->standardConformingStrings = $standardConformingStrings;

        $this->assertSame(self::lexed($scanner), self::lexed($lexer));
    }

    /**
     * Provide the lexical cases with their tokens in runs, after a statement's first token, and runs across window cuts.
     *
     * @return iterable<string, array{0: string, 1?: bool}>
     */
    public static function runProvider(): iterable
    {
        foreach (self::tokenProvider() as $name => [$sql]) {
            yield "token: {$name}" => ["x {$sql}"];
        }

        foreach (self::valueProvider() as $name => $case) {
            yield "value: {$name}" => ["x {$case[0]}", $case[2] ?? true];
        }

        $strings = "x 'a\\b' E'c\\'d' N'e\\f' U&'\\0041' \$\$g\$\$ \$t\$h\$t\$ \"i\"\"j\" 'k''l' 1.5e3 a::text b->>'c' d||e @> (1, 2);\nx 'm';";

        yield 'standard strings' => [$strings];
        yield 'nonstandard strings' => [$strings, false];
        yield 'routines' => [self::ROUTINES];
        yield 'COPY data' => [file_get_contents(__DIR__ . '/../Fixtures/Postgres/copy-data.sql')];
        yield 'real dump' => [file_get_contents(__DIR__ . '/../Fixtures/Postgres/schema-dump.sql')];

        $columns = '';

        for ($column = 0; $column < 1500; ++$column) {
            $columns .= "  \"col {$column}\" varchar(9) DEFAULT 'a ''b'' \\ {$column}'::text CHECK (\"col {$column}\" <> \$\$-1.5e3\$\$), -- note {$column}\n  /* c /* d */ e */ f int,\n";
        }

        $table = "CREATE TABLE t (\n{$columns}  id integer);\nSELECT 1;";

        yield 'window cuts' => [$table];
        yield 'window cuts in nonstandard strings' => [$table, false];
    }

    /**
     * Get the committed dump whose migration data is COPY records.
     */
    private function copyDump(): string
    {
        $sql = file_get_contents(__DIR__ . '/../Fixtures/Postgres/copy-data.sql');
        $this->assertNotFalse($sql);

        return $sql;
    }
}

class ScanningPostgresLexer extends PostgresLexer
{
    /**
     * Read one scanned token at a time, as the reference for runs.
     */
    public function read(array &$types, array &$starts, array &$ends, array &$texts): void
    {
        Lexer::read($types, $starts, $ends, $texts);
    }
}
