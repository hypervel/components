<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Http;

use Hypervel\Http\Request;
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\Cookie;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\Tests\TestCase;

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

    public function testReturnsNullWhenBearerHeaderIsMissing(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Basic token',
        ]);

        $this->assertNull($parser->parseToken($request));
    }

    public function testReturnsNullWhenBearerIsNotTheAuthScheme(): void
    {
        $parser = new Parser([new AuthHeaders]);

        $request = Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Basic not-a-Bearer token',
        ]);

        $this->assertNull($parser->parseToken($request));
    }

    public function testParsesQueryInput(): void
    {
        $parser = new Parser([new InputSource]);

        $request = Request::create('/?token=query-token');

        $this->assertSame('query-token', $parser->parseToken($request));
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
}
