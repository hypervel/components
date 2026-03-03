<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use Hypervel\Context\RequestContext;
use Hypervel\ExceptionHandler\Handler\WhoopsExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;

/**
 * @internal
 * @coversNothing
 */
class WhoopsExceptionHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        RequestContext::destroy();
    }

    public function testPlainTextWhoops()
    {
        $request = Request::create('/');
        $request->headers->remove('Accept');
        RequestContext::set($request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('text/plain', $response->headers->get('Content-Type'));
    }

    public function testHtmlWhoops()
    {
        $request = Request::create('/');
        $request->headers->set('Accept', 'text/html,application/json,application/xml');
        RequestContext::set($request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('text/html', $response->headers->get('Content-Type'));
    }

    public function testJsonWhoops()
    {
        $request = Request::create('/');
        $request->headers->set('Accept', 'application/json,application/xml');
        RequestContext::set($request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        $arr = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('trace', $arr['error']);
    }

    public function testXmlWhoops()
    {
        $request = Request::create('/');
        $request->headers->set('Accept', 'application/xml');
        RequestContext::set($request);
        $handler = new WhoopsExceptionHandler();
        $response = $handler->handle(new RuntimeException(), new Response());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->headers->get('Content-Type'));
    }
}
