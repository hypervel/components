<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\SchemaParser\ParseException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LexTest extends TestCase
{
    public function testSimpleWordTokens(): void
    {
        $this->assertTokens('hello world', ['hello', 'world']);
    }

    public function testWhitespace(): void
    {
        $this->assertTokens('foo bar', ['foo', 'bar']);
        $this->assertTokens(' foo bar ', ['foo', 'bar']);
        $this->assertTokens("\n\nfoo\tbar\n", ['foo', 'bar']);
        $this->assertTokens("hello \nworld-- foo\nyeah", ['hello', 'world', 'yeah']);
    }

    public function testComments(): void
    {
        $this->assertTokens('foo -- bar', ['foo']);
        // MySQL requires whitespace or a control character after --.
        $this->assertTokens('foo --bar', ['foo', '-', '-', 'bar']);
        $this->assertTokens("foo -- bar\n", ['foo']);
        $this->assertTokens("foo -- bar \n", ['foo']);
        $this->assertTokens("foo -- bar \n ", ['foo']);
        $this->assertTokens("foo --\tbar\n", ['foo']);
        $this->assertTokens('foo/* hello */ bar', ['foo', 'bar']);
        $this->assertTokens('foo/*hello*/ bar', ['foo', 'bar']);
        $this->assertTokens("foo/* hello \n world */ bar", ['foo', 'bar']);
        $this->assertTokens("foo/*hello \n world*/ bar", ['foo', 'bar']);
    }

    #[DataProvider('unterminatedTokens')]
    public function testUnterminatedTokensFail(string $sql): void
    {
        $this->expectException(ParseException::class);

        (new SqlParser)->lex($sql);
    }

    /**
     * Provide malformed inputs that upstream silently truncated.
     *
     * @return list<array{string}>
     */
    public static function unterminatedTokens(): array
    {
        return [
            ['foo/* hello'],
            ['foo/* hello *'],
            ["foo/* hello \n world"],
            ["hello `foo bar\n baz"],
        ];
    }

    public function testBacktickFields(): void
    {
        $this->assertTokens('hello `world` foo', ['hello', '`world`', 'foo']);
        $this->assertTokens('hello  `world`  foo', ['hello', '`world`', 'foo']);
        $this->assertTokens("hello `world \n test`  foo", ['hello', "`world \n test`", 'foo']);
        $this->assertTokens('hello `a``b` foo', ['hello', '`a``b`', 'foo']);
    }

    public function testNumericLiterals(): void
    {
        $this->assertTokens('1 12 12.3 12.34', ['1', '12', '12.3', '12.34']);
        $this->assertTokens('12. 1. .3 .34', ['12.', '1.', '.3', '.34']);
        $this->assertTokens('1--2', ['1', '-', '-', '2']);
    }

    public function testDigitLeadingIdentifiersPreserveNumericBoundaries(): void
    {
        $this->assertTokens('2fa_codes 0x01 1e5 1.2foo 1e5foo 12e_codes $identifier', [
            '2fa_codes', '0x01', '1e5', '1.2', 'foo', '1e5', 'foo', '12e_codes', '$identifier',
        ]);
        $this->assertTokens('+2fa.id t.2fa `t`.1e5foo 9-2fa', [
            '+', '2fa', '.', 'id', 't', '.', '2fa', '`t`', '.', '1e5foo', '9', '-', '2fa',
        ]);
    }

    public function testStrings(): void
    {
        $this->assertTokens("foo 'bar' baz", ['foo', "'bar'", 'baz']);
        $this->assertTokens("foo 'bar \\' baz' qux", ['foo', "'bar \\' baz'", 'qux']);
        $this->assertTokens('foo "bar" baz', ['foo', '"bar"', 'baz']);
        $this->assertTokens('foo "bar \" baz" qux', ['foo', '"bar \" baz"', 'qux']);
        $this->assertTokens("'hel''lo'", ["'hel''lo'"]);
        $this->assertTokens('"hel""lo"', ['"hel""lo"']);
        $this->assertTokens("\"hel''lo\"", ["\"hel''lo\""]);
        $this->assertTokens('\'hel""lo\'', ['\'hel""lo\'']);
    }

    public function testPrefixedBinaryLiteralsKeepTheirValue(): void
    {
        $this->assertTokens("b'101' X'0a1b'", ["b'101'", "X'0a1b'"]);
    }

    public function testExecutableCommentsRespectQuotedTerminators(): void
    {
        $this->assertTokens("SELECT /*!50001 'a;*/b' */;", ['SELECT', "'a;*/b'", ';']);
        $this->assertTokens('SELECT /*M!100000 `a*/b` */;', ['SELECT', '`a*/b`', ';']);
    }

    public function testExecutableCommentsRespectInnerComments(): void
    {
        $this->assertTokens("SELECT /*!50001 1 /* it's */ + 1 */;", ['SELECT', '1', '+', '1', ';']);
        $this->assertTokens("SELECT /*!50001 1 -- it's */\n+ 1 */;", ['SELECT', '1', '+', '1', ';']);
        $this->assertTokens("SELECT /*!50001 1 # it's */\n+ 1 */;", ['SELECT', '1', '+', '1', ';']);
    }

    public function testExecutableCommentSourcePositionsReferToTheInput(): void
    {
        $sql = "/*M!999999\\- enable the sandbox mode */\nSELECT /*!50001 'value' */;";
        $parser = new SqlParser;

        $this->assertSame(['SELECT', "'value'", ';'], $parser->lex($sql));
        $this->assertSame([strpos($sql, "'value'"), strlen("'value'")], $parser->source_map[1]);
    }

    public function testIncompleteKeywordDoesNotReadPastTheInput(): void
    {
        $this->assertTokens('foo NOT', ['foo', 'NOT']);
    }

    /**
     * Assert the tokens produced from SQL text.
     *
     * @param list<string> $tokens
     */
    private function assertTokens(string $sql, array $tokens): void
    {
        $this->assertSame($tokens, (new SqlParser)->lex($sql));
    }
}
