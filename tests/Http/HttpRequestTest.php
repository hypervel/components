<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;

class HttpRequestTest extends TestCase
{
    public function testWantsMarkdown()
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']);
        $this->assertTrue($request->wantsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown; charset=utf-8']);
        $this->assertTrue($request->wantsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertFalse($request->wantsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $this->assertFalse($request->wantsMarkdown());
    }

    public function testAcceptsMarkdown()
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']);
        $this->assertTrue($request->acceptsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html, text/markdown']);
        $this->assertFalse($request->wantsMarkdown());
        $this->assertTrue($request->acceptsMarkdown());

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertFalse($request->acceptsMarkdown());
    }
}
