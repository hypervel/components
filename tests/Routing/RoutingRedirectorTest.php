<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Session\Store;
use Mockery as m;
use Symfony\Component\HttpFoundation\HeaderBag;

class RoutingRedirectorTest extends RoutingTestCase
{
    protected HeaderBag $headers;

    protected Request $request;

    protected UrlGenerator $url;

    protected Store $session;

    protected Redirector $redirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->headers = m::mock(HeaderBag::class);

        $this->request = m::mock(Request::class);
        $this->request->shouldReceive('isMethod')->andReturn(true)->byDefault();
        $this->request->shouldReceive('method')->andReturn('GET')->byDefault();
        $this->request->shouldReceive('route')->andReturn(true)->byDefault();
        $this->request->shouldReceive('ajax')->andReturn(false)->byDefault();
        $this->request->shouldReceive('expectsJson')->andReturn(false)->byDefault();
        $this->request->headers = $this->headers;

        $this->url = m::mock(UrlGenerator::class);
        $this->url->shouldReceive('getRequest')->andReturn($this->request);
        $this->url->shouldReceive('to')->with('bar', [], null)->andReturn('http://foo.com/bar');
        $this->url->shouldReceive('to')->with('bar', [], true)->andReturn('https://foo.com/bar');
        $this->url->shouldReceive('to')->with('login', [], null)->andReturn('http://foo.com/login');
        $this->url->shouldReceive('to')->with('http://foo.com/bar', [], null)->andReturn('http://foo.com/bar');
        $this->url->shouldReceive('to')->with('/', [], null)->andReturn('http://foo.com/');
        $this->url->shouldReceive('to')->with('http://foo.com/bar?signature=secret', [], null)->andReturn('http://foo.com/bar?signature=secret');

        $this->session = m::mock(Store::class);

        $this->redirect = new Redirector($this->url);
        $this->redirect->setSession($this->session);
    }

    public function testBasicRedirectTo(): void
    {
        $response = $this->redirect->to('bar');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals($this->session, $response->getSession());
    }

    public function testComplexRedirectTo(): void
    {
        $response = $this->redirect->to('bar', 303, ['X-RateLimit-Limit' => 60, 'X-RateLimit-Remaining' => 59], true);

        $this->assertSame('https://foo.com/bar', $response->getTargetUrl());
        $this->assertEquals(303, $response->getStatusCode());
        $this->assertEquals(60, $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals(59, $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testGuestPutCurrentUrlInSession(): void
    {
        $this->url->expects('full')->andReturn('http://foo.com/bar');
        $this->session->expects('put')->with('url.intended', 'http://foo.com/bar');

        $response = $this->redirect->guest('login');

        $this->assertSame('http://foo.com/login', $response->getTargetUrl());
    }

    public function testGuestPutPreviousUrlInSession(): void
    {
        $this->request->expects('isMethod')->with('GET')->andReturn(false);
        $this->session->expects('put')->with('url.intended', 'http://foo.com/bar');
        $this->url->expects('previous')->andReturn('http://foo.com/bar');

        $response = $this->redirect->guest('login');

        $this->assertSame('http://foo.com/login', $response->getTargetUrl());
    }

    public function testIntendedRedirectToIntendedUrlInSession(): void
    {
        $this->session->expects('pull')->with('url.intended', '/')->andReturn('http://foo.com/bar');

        $response = $this->redirect->intended();

        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testIntendedWithoutIntendedUrlInSession(): void
    {
        // without fallback url
        $this->session->expects('pull')->with('url.intended', '/')->andReturn('/');
        $response = $this->redirect->intended();
        $this->assertSame('http://foo.com/', $response->getTargetUrl());

        // with a fallback url
        $this->session->expects('pull')->with('url.intended', 'bar')->andReturn('bar');
        $response = $this->redirect->intended('bar');
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testRefreshRedirectToCurrentUrl(): void
    {
        $this->request->expects('path')->andReturn('http://foo.com/bar');
        $response = $this->redirect->refresh();
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testBackRedirectToHttpReferer(): void
    {
        $this->url->expects('previous')->andReturn('http://foo.com/bar');
        $response = $this->redirect->back();
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testAwayDoesntValidateTheUrl(): void
    {
        $response = $this->redirect->away('bar');
        $this->assertSame('bar', $response->getTargetUrl());
    }

    public function testSecureRedirectToHttpsUrl(): void
    {
        $response = $this->redirect->secure('bar');
        $this->assertSame('https://foo.com/bar', $response->getTargetUrl());
    }

    public function testAction(): void
    {
        $this->url->expects('action')->with('bar@index', [])->andReturn('http://foo.com/bar');
        $response = $this->redirect->action('bar@index');
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testRoute(): void
    {
        $this->url->expects('route')->with('home', [])->andReturn('http://foo.com/bar');

        $response = $this->redirect->route('home');
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testRouteAcceptsUrlRoutableParameters(): void
    {
        $parameter = m::mock(UrlRoutable::class);

        $this->url->expects('route')->with('home', $parameter)->andReturn('http://foo.com/bar');

        $response = $this->redirect->route('home', $parameter);

        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testSignedRoute(): void
    {
        $this->url->expects('signedRoute')->with('home', [], null)->andReturn('http://foo.com/bar?signature=secret');

        $response = $this->redirect->signedRoute('home');
        $this->assertSame('http://foo.com/bar?signature=secret', $response->getTargetUrl());
    }

    public function testTemporarySignedRoute(): void
    {
        $this->url->expects('temporarySignedRoute')->with('home', 10, [])->andReturn('http://foo.com/bar?signature=secret');

        $response = $this->redirect->temporarySignedRoute('home', 10);
        $this->assertSame('http://foo.com/bar?signature=secret', $response->getTargetUrl());
    }

    public function testItSetsAndGetsValidIntendedUrl(): void
    {
        $this->session->expects('put')->with('url.intended', 'http://foo.com/bar');
        $this->session->expects('get')->andReturn('http://foo.com/bar');

        $result = $this->redirect->setIntendedUrl('http://foo.com/bar');
        $this->assertInstanceOf(Redirector::class, $result);

        $this->assertSame('http://foo.com/bar', $this->redirect->getIntendedUrl());
    }
}
