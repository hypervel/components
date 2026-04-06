<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie;

use ArgumentCountError;
use Hypervel\Context\RequestContext;
use Hypervel\Cookie\CookieJar;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Symfony\Component\HttpFoundation\Cookie;
use TypeError;

enum CookieJarTestNameEnum: string
{
    case Session = 'session_id';
    case Remember = 'remember_token';
}

enum CookieJarTestNameUnitEnum
{
    case theme;
    case locale;
}

enum CookieJarTestNameIntEnum: int
{
    case First = 1;
}

/**
 * @internal
 * @coversNothing
 */
class CookieJarTest extends TestCase
{
    // =========================================================================
    // Laravel CookieTest (adapted for Context-based storage)
    // =========================================================================

    public function testCookiesAreCreatedWithProperOptions()
    {
        $cookie = new CookieJar;
        $cookie->setDefaultPathAndDomain('foo', 'bar');
        $c = $cookie->make('color', 'blue', 10, '/path', '/domain', true, false, false, 'lax');
        $this->assertSame('blue', $c->getValue());
        $this->assertFalse($c->isHttpOnly());
        $this->assertTrue($c->isSecure());
        $this->assertSame('/domain', $c->getDomain());
        $this->assertSame('/path', $c->getPath());
        $this->assertSame('lax', $c->getSameSite());

        $c2 = $cookie->forever('color', 'blue', '/path', '/domain', true, false, false, 'strict');
        $this->assertSame('blue', $c2->getValue());
        $this->assertFalse($c2->isHttpOnly());
        $this->assertTrue($c2->isSecure());
        $this->assertSame('/domain', $c2->getDomain());
        $this->assertSame('/path', $c2->getPath());
        $this->assertSame('strict', $c2->getSameSite());

        $c3 = $cookie->forget('color');
        $this->assertNull($c3->getValue());
        $this->assertTrue($c3->getExpiresTime() < time());
    }

    public function testCookiesAreCreatedWithProperOptionsUsingDefaultPathAndDomain()
    {
        $cookie = new CookieJar;
        $cookie->setDefaultPathAndDomain('/path', '/domain', true, 'lax');
        $c = $cookie->make('color', 'blue');
        $this->assertSame('blue', $c->getValue());
        $this->assertTrue($c->isSecure());
        $this->assertSame('/domain', $c->getDomain());
        $this->assertSame('/path', $c->getPath());
        $this->assertSame('lax', $c->getSameSite());
        $this->assertTrue($c->isHttpOnly());
    }

    public function testCookiesCanSetSecureOptionUsingDefaultPathAndDomain()
    {
        $cookie = new CookieJar;
        $cookie->setDefaultPathAndDomain('/path', '/domain', true, 'lax');
        $c = $cookie->make('color', 'blue', 10, null, null, false);
        $this->assertSame('blue', $c->getValue());
        $this->assertFalse($c->isSecure());
        $this->assertSame('/domain', $c->getDomain());
        $this->assertSame('/path', $c->getPath());
        $this->assertSame('lax', $c->getSameSite());
    }

    public function testQueuedCookiesWithoutName()
    {
        $this->expectException(InvalidArgumentException::class);

        $cookie = new CookieJar;
        $cookie->queue($cookie->make('', 'bar'));
    }

    public function testQueuedCookiesWithInvalidParameter()
    {
        $this->expectException(ArgumentCountError::class);

        $cookie = new CookieJar;
        $cookie->queue('invalidCookie');
    }

    public function testQueuedCookiesWithHandlingEmptyValues()
    {
        $cookie = new CookieJar;
        $cookie->queue($cookie->make('foo', ''));
        $this->assertTrue($cookie->hasQueued('foo'));
        $this->assertEquals('', $cookie->queued('foo')->getValue());
    }

    public function testQueuedCookiesWithRepeatedValue()
    {
        $cookie = new CookieJar;
        $cookie->queue($cookie->make('foo', 'newBar'));
        $this->assertTrue($cookie->hasQueued('foo'));
        $this->assertEquals('newBar', $cookie->queued('foo')->getValue());

        $this->expectException(ArgumentCountError::class);
        $cookie->queue('invalidCookie');
    }

    public function testQueuedCookies()
    {
        $cookie = new CookieJar;
        $this->assertEmpty($cookie->getQueuedCookies());
        $this->assertFalse($cookie->hasQueued('foo'));
        $cookie->queue($cookie->make('foo', 'bar'));
        $this->assertTrue($cookie->hasQueued('foo'));
        $this->assertInstanceOf(Cookie::class, $cookie->queued('foo'));
        $cookie->queue('qu', 'ux');
        $this->assertTrue($cookie->hasQueued('qu'));
        $this->assertInstanceOf(Cookie::class, $cookie->queued('qu'));
    }

    public function testQueuedWithPath()
    {
        $cookieJar = new CookieJar;
        $cookieOne = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieTwo = $cookieJar->make('foo', 'rab', 0, '/');
        $cookieJar->queue($cookieOne);
        $cookieJar->queue($cookieTwo);
        $this->assertEquals($cookieOne, $cookieJar->queued('foo', null, '/path'));
        $this->assertEquals($cookieTwo, $cookieJar->queued('foo', null, '/'));
    }

    public function testQueuedWithoutPath()
    {
        $cookieJar = new CookieJar;
        $cookieOne = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieTwo = $cookieJar->make('foo', 'rab', 0, '/');
        $cookieJar->queue($cookieOne);
        $cookieJar->queue($cookieTwo);
        $this->assertEquals($cookieTwo, $cookieJar->queued('foo'));
    }

    public function testHasQueued()
    {
        $cookieJar = new CookieJar;
        // test empty queue
        $this->assertFalse($cookieJar->hasQueued('foo'));

        $cookie = $cookieJar->make('foo', 'bar');
        $cookieJar->queue($cookie);
        $this->assertTrue($cookieJar->hasQueued('foo'));
        $this->assertFalse($cookieJar->hasQueued('nonexistent'));
    }

    public function testHasQueuedWithPath()
    {
        $cookieJar = new CookieJar;
        $cookieOne = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieTwo = $cookieJar->make('foo', 'rab', 0, '/');
        $cookieJar->queue($cookieOne);
        $cookieJar->queue($cookieTwo);
        $this->assertTrue($cookieJar->hasQueued('foo', '/path'));
        $this->assertTrue($cookieJar->hasQueued('foo', '/'));
        $this->assertFalse($cookieJar->hasQueued('foo', '/wrongPath'));
    }

    public function testExpire()
    {
        $cookieJar = new CookieJar;
        $this->assertCount(0, $cookieJar->getQueuedCookies());

        $cookieJar->expire('foobar', '/path', '/domain');

        $cookie = $cookieJar->queued('foobar');
        $this->assertSame('foobar', $cookie->getName());
        $this->assertNull($cookie->getValue());
        $this->assertSame('/path', $cookie->getPath());
        $this->assertSame('/domain', $cookie->getDomain());
        $this->assertTrue($cookie->getExpiresTime() < time());
        $this->assertCount(1, $cookieJar->getQueuedCookies());
    }

    public function testUnqueue()
    {
        $cookie = new CookieJar;

        $cookie->unqueue('nonexistent');
        $this->assertEmpty($cookie->getQueuedCookies());

        $cookie->queue($cookie->make('foo', 'bar'));
        $cookie->unqueue('foo');
        $this->assertEmpty($cookie->getQueuedCookies());
    }

    public function testUnqueueMultipleCookies()
    {
        $cookie = new CookieJar;
        $cookie->queue($cookie->make('foo', 'bar'));
        $cookie->queue($cookie->make('baz', 'qux'));
        $cookie->unqueue('foo');
        $this->assertTrue($cookie->hasQueued('baz'));
        $this->assertFalse($cookie->hasQueued('foo'));
    }

    public function testUnqueueWithPath()
    {
        $cookieJar = new CookieJar;
        $cookieOne = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieTwo = $cookieJar->make('foo', 'rab', 0, '/');
        $cookieJar->queue($cookieOne);
        $cookieJar->queue($cookieTwo);
        $cookieJar->unqueue('foo', '/path');
        $this->assertFalse($cookieJar->hasQueued('foo', '/path'));
        $this->assertTrue($cookieJar->hasQueued('foo', '/'));
    }

    public function testUnqueueOnlyCookieForName()
    {
        $cookieJar = new CookieJar;
        $cookie = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieJar->queue($cookie);
        $cookieJar->unqueue('foo', '/path');
        $this->assertEmpty($cookieJar->getQueuedCookies());
    }

    public function testCookieJarIsMacroable()
    {
        $cookie = new CookieJar;
        $cookie->macro('foo', function () {
            return 'bar';
        });
        $this->assertSame('bar', $cookie->foo());
    }

    public function testQueueCookie()
    {
        $cookieJar = new CookieJar;
        $cookie = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieJar->queue($cookie);
        $this->assertEquals($cookie, $cookieJar->queued('foo', null, '/path'));
    }

    public function testQueueWithCreatingNewCookie()
    {
        $cookieJar = new CookieJar;
        $cookieJar->queue('foo', 'bar', 0, '/path');
        $this->assertEquals(
            new Cookie('foo', 'bar', 0, '/path'),
            $cookieJar->queued('foo', null, '/path')
        );
    }

    public function testGetQueuedCookies()
    {
        $cookieJar = new CookieJar;
        $cookieOne = $cookieJar->make('foo', 'bar', 0, '/path');
        $cookieTwo = $cookieJar->make('foo', 'rab', 0, '/');
        $cookieThree = $cookieJar->make('oof', 'bar', 0, '/path');
        $cookieJar->queue($cookieOne);
        $cookieJar->queue($cookieTwo);
        $cookieJar->queue($cookieThree);
        $this->assertEquals(
            [$cookieOne, $cookieTwo, $cookieThree],
            $cookieJar->getQueuedCookies()
        );
    }

    public function testFlushQueuedCookies()
    {
        $cookieJar = new CookieJar;
        $cookieJar->queue($cookieJar->make('foo', 'bar', 0, '/path'));
        $cookieJar->queue($cookieJar->make('foo', 'rab', 0, '/'));
        $this->assertCount(2, $cookieJar->getQueuedCookies());

        $cookieJar->flushQueuedCookies();
        $this->assertEmpty($cookieJar->getQueuedCookies());
    }

    // =========================================================================
    // Hypervel-specific: has() / get() from request context
    // =========================================================================

    public function testHas()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');
        RequestContext::set($request);

        $manager = new CookieJar;

        $this->assertTrue($manager->has('foo'));
    }

    public function testGet()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');
        RequestContext::set($request);

        $manager = new CookieJar;

        $this->assertEquals('bar', $manager->get('foo'));
    }

    // =========================================================================
    // Hypervel-specific: forever duration
    // =========================================================================

    public function testForeverUsesLaravelDuration()
    {
        $manager = new CookieJar;
        $cookie = $manager->forever('remember_token', 'token123');

        $this->assertEqualsWithDelta(576000 * 60, $cookie->getExpiresTime() - time(), 5);
    }

    // =========================================================================
    // Hypervel-specific: Enum Support
    // =========================================================================

    public function testHasAcceptsStringBackedEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('session_id', null)->andReturn('abc123');
        RequestContext::set($request);

        $manager = new CookieJar;

        $this->assertTrue($manager->has(CookieJarTestNameEnum::Session));
    }

    public function testHasAcceptsUnitEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('theme', null)->andReturn('dark');
        RequestContext::set($request);

        $manager = new CookieJar;

        $this->assertTrue($manager->has(CookieJarTestNameUnitEnum::theme));
    }

    public function testGetAcceptsStringBackedEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('session_id', null)->andReturn('abc123');
        RequestContext::set($request);

        $manager = new CookieJar;

        $this->assertSame('abc123', $manager->get(CookieJarTestNameEnum::Session));
    }

    public function testGetAcceptsUnitEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('theme', null)->andReturn('dark');
        RequestContext::set($request);

        $manager = new CookieJar;

        $this->assertSame('dark', $manager->get(CookieJarTestNameUnitEnum::theme));
    }

    public function testMakeAcceptsStringBackedEnum()
    {
        $manager = new CookieJar;
        $cookie = $manager->make(CookieJarTestNameEnum::Session, 'abc123');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('session_id', $cookie->getName());
        $this->assertSame('abc123', $cookie->getValue());
    }

    public function testMakeAcceptsUnitEnum()
    {
        $manager = new CookieJar;
        $cookie = $manager->make(CookieJarTestNameUnitEnum::theme, 'dark');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('theme', $cookie->getName());
        $this->assertSame('dark', $cookie->getValue());
    }

    public function testForeverAcceptsStringBackedEnum()
    {
        $manager = new CookieJar;
        $cookie = $manager->forever(CookieJarTestNameEnum::Remember, 'token123');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('remember_token', $cookie->getName());
        $this->assertSame('token123', $cookie->getValue());
    }

    public function testForeverAcceptsUnitEnum()
    {
        $manager = new CookieJar;
        $cookie = $manager->forever(CookieJarTestNameUnitEnum::locale, 'en');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('locale', $cookie->getName());
        $this->assertSame('en', $cookie->getValue());
    }

    public function testForgetAcceptsStringBackedEnum()
    {
        $manager = new CookieJar;
        $cookie = $manager->forget(CookieJarTestNameEnum::Session);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('session_id', $cookie->getName());
        $this->assertNull($cookie->getValue());
    }

    public function testForgetAcceptsUnitEnum()
    {
        $manager = new CookieJar;
        $cookie = $manager->forget(CookieJarTestNameUnitEnum::theme);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('theme', $cookie->getName());
        $this->assertNull($cookie->getValue());
    }

    public function testMakeWithIntBackedEnumThrowsTypeError()
    {
        $this->expectException(TypeError::class);

        $manager = new CookieJar;
        $cookie = $manager->make(CookieJarTestNameIntEnum::First, 'value');
        $cookie->getName(); // TypeError thrown here
    }
}
