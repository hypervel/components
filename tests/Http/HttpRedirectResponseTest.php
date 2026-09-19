<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use BadMethodCallException;
use Hypervel\Contracts\Support\MessageProvider;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Session\Store;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Cookie;

class HttpRedirectResponseTest extends TestCase
{
    public function testHeaderOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $this->assertNull($response->headers->get('foo'));
        $response->header('foo', 'bar');
        $this->assertSame('bar', $response->headers->get('foo'));
        $response->header('foo', 'baz', false);
        $this->assertSame('bar', $response->headers->get('foo'));
        $response->header('foo', 'baz');
        $this->assertSame('baz', $response->headers->get('foo'));
    }

    public function testWithOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $response->setSession($session);
        $session->expects('flash')->with('0', 'name');
        $session->expects('flash')->with('1', 'age');
        $response->with(['name', 'age']);
    }

    public function testWithAssociativeArrayOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $session = m::mock(Store::class);
        $session->expects('flash')->with('name', 'Taylor');
        $response->setSession($session);
        $response->with(['name' => 'Taylor']);
    }

    public function testWithCookieOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $this->assertCount(0, $response->headers->getCookies());
        $this->assertSame($response, $response->withCookie(new Cookie('foo', 'bar')));
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('foo', $cookies[0]->getName());
        $this->assertSame('bar', $cookies[0]->getValue());
    }

    public function testFragmentIdentifierOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');

        $response->withFragment('foo');
        $this->assertSame('foo', parse_url($response->getTargetUrl(), PHP_URL_FRAGMENT));

        $response->withFragment('#bar');
        $this->assertSame('bar', parse_url($response->getTargetUrl(), PHP_URL_FRAGMENT));

        $response->withoutFragment();
        $this->assertNull(parse_url($response->getTargetUrl(), PHP_URL_FRAGMENT));
    }

    public function testInputOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $session->expects('flashInput')->with(['name' => 'Taylor', 'age' => 26]);
        $response->setSession($session);
        $response->withInput();
    }

    public function testWithCookies(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $response->withCookies([
            new Cookie('name', 'milwad'),
        ]);

        $this->assertSame('name', $response->headers->getCookies()[0]->getName());
        $this->assertSame('milwad', $response->headers->getCookies()[0]->getValue());
    }

    public function testOnlyInputOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $session->expects('flashInput')->with(['name' => 'Taylor']);
        $response->setSession($session);
        $response->onlyInput('name');
    }

    public function testExceptInputOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $session->expects('flashInput')->with(['name' => 'Taylor']);
        $response->setSession($session);
        $response->exceptInput('age');
    }

    public function testFlashingErrorsOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $session->expects('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new ViewErrorBag);
        $session->expects('flash')->with('errors', m::type(ViewErrorBag::class));
        $response->setSession($session);
        $provider = m::mock(MessageProvider::class);
        $provider->expects('getMessageBag')->andReturn(new MessageBag);
        $response->withErrors($provider);
    }

    public function testCanEnforceSameOriginWhenSameOrigin(): void
    {
        $response = new RedirectResponse('https://example.com/foo/bar');
        $response->setRequest(Request::create('https://example.com/baz/buzz'));
        $response->enforceSameOrigin('fallback');

        $this->assertSame('https://example.com/foo/bar', $response->getTargetUrl());
    }

    public function testCanEnforceSameOriginWhenSameOriginAndCustomPort(): void
    {
        $response = new RedirectResponse('https://example.com:1/foo/bar');
        $response->setRequest(Request::create('https://example.com:1/baz/buzz'));
        $response->enforceSameOrigin('fallback');

        $this->assertSame('https://example.com:1/foo/bar', $response->getTargetUrl());
    }

    public function testCanEnforceSameOriginWhenNotSameScheme(): void
    {
        $response = new RedirectResponse('https://example.com/foo/bar');
        $response->setRequest(Request::create('http://example.com/baz/buzz'));
        $response->enforceSameOrigin('fallback');

        $this->assertSame('fallback', $response->getTargetUrl());
    }

    public function testCanEnforceSameOriginWhenNotSameHostname(): void
    {
        $response = new RedirectResponse('https://example.com/foo/bar');
        $response->setRequest(Request::create('https://example2.com/baz/buzz'));
        $response->enforceSameOrigin('fallback');

        $this->assertSame('fallback', $response->getTargetUrl());
    }

    public function testCanEnforceSameOriginWhenNotSamePort(): void
    {
        $response = new RedirectResponse('https://example.com:1/foo/bar');
        $response->setRequest(Request::create('https://example.com:2/baz/buzz'));
        $response->enforceSameOrigin('fallback');

        $this->assertSame('fallback', $response->getTargetUrl());
    }

    public function testCanEnforceSameOriginWhenNotSameSchemeAndSchemeValidationIsDisabled(): void
    {
        $response = new RedirectResponse('https://example.com/foo/bar');
        $response->setRequest(Request::create('http://example.com/baz/buzz'));
        $response->enforceSameOrigin('fallback', validateScheme: false);

        $this->assertSame('https://example.com/foo/bar', $response->getTargetUrl());
    }

    public function testCanEnforceSameOriginWhenNotSamePortAndPortValidationIsDisabled(): void
    {
        $response = new RedirectResponse('https://example.com:1/foo/bar');
        $response->setRequest(Request::create('https://example.com:2/baz/buzz'));
        $response->enforceSameOrigin('fallback', validatePort: false);

        $this->assertSame('https://example.com:1/foo/bar', $response->getTargetUrl());
    }

    public function testSettersGettersOnRequest(): void
    {
        $response = new RedirectResponse('foo.bar');
        $this->assertNull($response->getRequest());
        $this->assertNull($response->getSession());

        $request = Request::create('/', 'GET');
        $session = m::mock(Store::class);
        $response->setRequest($request);
        $response->setSession($session);
        $this->assertSame($request, $response->getRequest());
        $this->assertSame($session, $response->getSession());
    }

    public function testRedirectWithErrorsArrayConvertsToMessageBag(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $session->expects('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new ViewErrorBag);
        $session->expects('flash')->with('errors', m::type(ViewErrorBag::class));
        $response->setSession($session);
        $provider = ['foo' => 'bar'];
        $response->withErrors($provider);
    }

    public function testMagicCall(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $session = m::mock(Store::class);
        $session->expects('flash')->with('foo', 'bar');
        $response->setSession($session);
        $response->withFoo('bar');
    }

    public function testMagicCallException(): void
    {
        $this->expectExceptionObject(new BadMethodCallException('Call to undefined method Hypervel\Http\RedirectResponse::doesNotExist()'));

        $response = new RedirectResponse('foo.bar');
        $response->doesNotExist('bar');
    }
}
