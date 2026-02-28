<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use Hypervel\Context\Context;
use Hypervel\ExceptionHandler\Handler\WhoopsExceptionHandler;
use Hypervel\HttpMessage\Base\Response;
use Hypervel\HttpMessage\Server\Request;
use Hypervel\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function json_decode;

/**
 * @internal
 * @coversNothing
 */
class WhoopsExceptionHandlerTest extends TestCase
{
    public function testPlainTextWhoops()
    {
        Context::set(ServerRequestInterface::class, new Request('GET', '/'));
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->getHeader('Content-Type')[0]);
    }

    public function testHtmlWhoops()
    {
        $request = new Request('GET', '/');
        $request = $request->withHeader('accept', ['text/html,application/json,application/xml']);
        Context::set(ServerRequestInterface::class, $request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('text/html', $response->getHeader('Content-Type')[0]);
    }

    public function testJsonWhoops()
    {
        $request = new Request('GET', '/');
        $request = $request->withHeader('accept', ['application/json,application/xml']);
        Context::set(ServerRequestInterface::class, $request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeader('Content-Type')[0]);
        $arr = json_decode($response->getBody()->__toString(), true);
        $this->assertArrayHasKey('trace', $arr['error']);
    }

    public function testXmlWhoops()
    {
        $request = new Request('GET', '/');
        $request = $request->withHeader('accept', ['application/xml']);
        Context::set(ServerRequestInterface::class, $request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeader('Content-Type')[0]);
    }
}
