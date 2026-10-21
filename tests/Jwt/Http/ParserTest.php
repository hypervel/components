<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Http;

use Hypervel\Http\Request;
use Hypervel\Jwt\Contracts\TokenExtractor;
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class ParserTest extends TestCase
{
    public function testParsesBearerHeader(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer header-token',
        ]);

        $this->assertSame('header-token', $parser->parseToken($request));
    }

    public function testParsesBearerHeaderBeforeComma(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer header-token, Basic ignored',
        ]);

        $this->assertSame('header-token', $parser->parseToken($request));
    }

    public function testParsesBearerHeaderAfterComma(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Basic ignored, Bearer header-token',
        ]);

        $this->assertSame('header-token', $parser->parseToken($request));
    }

    public function testParsesRedirectAuthorizationHeader(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirect-token',
        ]);

        $this->assertSame('redirect-token', $parser->parseToken($request));
    }

    #[DataProvider('headerWithoutBearerTokenProvider')]
    public function testReturnsNullWhenTheHeaderHasNoBearerToken(string $header): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => $header,
        ]);

        $this->assertNull($parser->parseToken($request));
    }

    /**
     * Provide authorization headers that do not carry a bearer token.
     *
     * @return array<string, array{string}>
     */
    public static function headerWithoutBearerTokenProvider(): array
    {
        return [
            'basic scheme' => ['Basic OnBhc3N3b3Jk'],
            'bearer inside another credential' => ['Basic not-a-Bearer token'],
            'no scheme' => ['eyJhbGciOiJIUzI1NiIsInR5'],
            'empty bearer credential' => ['Bearer '],
            'tab after the scheme' => ["Bearer\tfoobar"],
        ];
    }

    #[DataProvider('bearerHeaderWhitespaceProvider')]
    public function testTrimsWhitespaceAroundTheBearerToken(string $header): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => $header,
        ]);

        $this->assertSame('foobar', $parser->parseToken($request));
    }

    /**
     * Provide bearer headers with extra whitespace around the token.
     *
     * @return array<string, array{string}>
     */
    public static function bearerHeaderWhitespaceProvider(): array
    {
        return [
            'space' => ['Bearer foobar '],
            'multiple spaces' => ['Bearer    foobar    '],
            'trailing tab' => ["Bearer foobar\t"],
            'trailing tabs' => ["Bearer foobar\t\t\t"],
            'trailing new line' => ["Bearer foobar\n"],
            'trailing new lines' => ["Bearer foobar\n\n\n"],
            'trailing carriage return' => ["Bearer foobar\r"],
            'trailing carriage returns' => ["Bearer foobar\r\r\r"],
            'trailing mixture of whitespace' => ["Bearer foobar\t \n \r \t \n"],
        ];
    }

    public function testKeepsTrailingHyphensInTheBearerToken(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer foobar--',
        ]);

        $this->assertSame('foobar--', $parser->parseToken($request));
    }

    public function testParsesQueryInput(): void
    {
        $parser = new Parser([new InputSource]);

        $request = Request::create('/?token=query-token');

        $this->assertSame('query-token', $parser->parseToken($request));
    }

    #[DataProvider('bodyInputRequestProvider')]
    public function testBodyInputTakesPrecedenceOverQueryInput(Request $request): void
    {
        $parser = new Parser([new InputSource]);

        $this->assertSame('body-token', $parser->parseToken($request));
    }

    /**
     * Provide requests with different tokens in the query and the body.
     *
     * @return array<string, array{Request}>
     */
    public static function bodyInputRequestProvider(): array
    {
        return [
            'form body' => [Request::create('/?token=query-token', 'POST', ['token' => 'body-token'])],
            'json body' => [Request::create(
                '/?token=query-token',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['token' => 'body-token']),
            )],
        ];
    }

    public function testParsesCookieWhenCookieExtractorIsConfigured(): void
    {
        $parser = new Parser([new Cookie]);

        $request = Request::create('/', 'GET', cookies: [
            'token' => 'cookie-token',
        ]);

        $this->assertSame('cookie-token', $parser->parseToken($request));
    }

    public function testIgnoresNonStringInputTokens(): void
    {
        $parser = new Parser([new InputSource]);

        $request = Request::create('/', 'GET', [
            'token' => ['not-a-token'],
        ]);

        $this->assertNull($parser->parseToken($request));
    }

    public function testParsesLiteralZeroToken(): void
    {
        $parser = new Parser([new InputSource]);

        $request = Request::create('/', 'GET', [
            'token' => '0',
        ]);

        $this->assertSame('0', $parser->parseToken($request));
    }

    public function testParserDoesNotRetainRequestBetweenCalls(): void
    {
        $parser = new Parser([new InputSource]);

        $firstRequest = Request::create('/?token=first-token');
        $secondRequest = Request::create('/');

        $this->assertSame('first-token', $parser->parseToken($firstRequest));
        $this->assertNull($parser->parseToken($secondRequest));
    }

    public function testUsesTheFirstExtractorInTheChainThatFindsAToken(): void
    {
        $request = Request::create('/');

        $missing = m::mock(TokenExtractor::class);
        $missing->shouldReceive('parseToken')->with($request)->once()->andReturnNull();

        $empty = m::mock(TokenExtractor::class);
        $empty->shouldReceive('parseToken')->with($request)->once()->andReturn('');

        $found = m::mock(TokenExtractor::class);
        $found->shouldReceive('parseToken')->with($request)->once()->andReturn('custom-token');

        $unused = m::mock(TokenExtractor::class);
        $unused->shouldNotReceive('parseToken');

        $parser = new Parser([$missing, $empty, $found, $unused]);

        $this->assertSame('custom-token', $parser->parseToken($request));
    }
}
