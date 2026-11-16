<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Session\NullSessionHandler;
use Hypervel\Session\Store;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\HttpFoundation\HeaderBag;

class RoutingRedirectorTest extends RoutingTestCase
{
    use VerifiesDoubles;

    protected HeaderBag $headers;

    protected Request $request;

    protected UrlGenerator $url;

    protected Store $session;

    protected Redirector $redirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->headers = new HeaderBag;

        $this->request = Request::create('/', 'GET');
        $this->request->setRouteResolver(fn (): bool => true);
        $this->request->headers = $this->headers;

        $this->url = Double::for(UrlGenerator::class);
        $this->url->allows('getRequest')->returns($this->request);
        $this->url->allows('to')->with('bar', [], null)->returns('http://foo.com/bar');
        $this->url->allows('to')->with('bar', [], true)->returns('https://foo.com/bar');
        $this->url->allows('to')->with('login', [], null)->returns('http://foo.com/login');
        $this->url->allows('to')->with('http://foo.com/bar', [], null)->returns('http://foo.com/bar');
        $this->url->allows('to')->with('/', [], null)->returns('http://foo.com/');
        $this->url->allows('to')->with('http://foo.com/bar?signature=secret', [], null)->returns('http://foo.com/bar?signature=secret');

        $this->session = new Store('test', new NullSessionHandler);

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
        $this->url->expects('full')->returns('http://foo.com/bar');

        $response = $this->redirect->guest('login');

        $this->assertSame('http://foo.com/login', $response->getTargetUrl());
        $this->assertSame('http://foo.com/bar', $this->session->get('url.intended'));
    }

    public function testGuestPutPreviousUrlInSession(): void
    {
        $this->request->setMethod('POST');
        $this->url->expects('previous')->returns('http://foo.com/bar');

        $response = $this->redirect->guest('login');

        $this->assertSame('http://foo.com/login', $response->getTargetUrl());
        $this->assertSame('http://foo.com/bar', $this->session->get('url.intended'));
    }

    public function testIntendedRedirectToIntendedUrlInSession(): void
    {
        $this->session->put('url.intended', 'http://foo.com/bar');

        $response = $this->redirect->intended();

        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
        $this->assertNull($this->session->get('url.intended'));
    }

    public function testIntendedWithoutIntendedUrlInSession(): void
    {
        // without fallback url
        $response = $this->redirect->intended();
        $this->assertSame('http://foo.com/', $response->getTargetUrl());

        // with a fallback url
        $response = $this->redirect->intended('bar');
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testRefreshRedirectToCurrentUrl(): void
    {
        $this->url->allows('getRequest')->returns(Request::create('/bar'));
        $response = $this->redirect->refresh();
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testBackRedirectToHttpReferer(): void
    {
        $this->url->expects('previous')->returns('http://foo.com/bar');
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
        $this->url->expects('action')->with('bar@index', [])->returns('http://foo.com/bar');
        $response = $this->redirect->action('bar@index');
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testRoute(): void
    {
        $this->url->expects('route')->with('home', [])->returns('http://foo.com/bar');

        $response = $this->redirect->route('home');
        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testRouteAcceptsUrlRoutableParameters(): void
    {
        $parameter = Double::for(UrlRoutable::class);

        $this->url->expects('route')->with('home', $parameter)->returns('http://foo.com/bar');

        $response = $this->redirect->route('home', $parameter);

        $this->assertSame('http://foo.com/bar', $response->getTargetUrl());
    }

    public function testSignedRoute(): void
    {
        $this->url->expects('signedRoute')->with('home', [], null)->returns('http://foo.com/bar?signature=secret');

        $response = $this->redirect->signedRoute('home');
        $this->assertSame('http://foo.com/bar?signature=secret', $response->getTargetUrl());
    }

    public function testTemporarySignedRoute(): void
    {
        $this->url->expects('temporarySignedRoute')->with('home', 10, [])->returns('http://foo.com/bar?signature=secret');

        $response = $this->redirect->temporarySignedRoute('home', 10);
        $this->assertSame('http://foo.com/bar?signature=secret', $response->getTargetUrl());
    }

    public function testItSetsAndGetsValidIntendedUrl(): void
    {
        $result = $this->redirect->setIntendedUrl('http://foo.com/bar');
        $this->assertInstanceOf(Redirector::class, $result);

        $this->assertSame('http://foo.com/bar', $this->redirect->getIntendedUrl());
    }
}
