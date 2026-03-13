<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie;

use Hypervel\Context\RequestContext;
use Hypervel\Cookie\Cookie;
use Hypervel\Cookie\CookieJar;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
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
    public function testHas()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');
        RequestContext::set($request);

        $manager = new CookieJar();

        $this->assertTrue($manager->has('foo'));
    }

    public function testGet()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');
        RequestContext::set($request);

        $manager = new CookieJar();

        $this->assertEquals('bar', $manager->get('foo'));
    }

    public function testMake()
    {
        $manager = new CookieJar();

        $this->assertInstanceOf(Cookie::class, $manager->make('foo', 'bar'));
    }

    public function testQueue()
    {
        $manager = $this->getMockBuilder(CookieJar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueuedCookiesRaw', 'setQueuedCookies'])
            ->getMock();

        $manager->expects($this->once())
            ->method('getQueuedCookiesRaw')
            ->willReturn([]);
        $manager->expects($this->once())
            ->method('setQueuedCookies')
            ->with([
                'foo' => [
                    '/' => $cookie = new Cookie('foo', 'bar'),
                ],
            ]);

        $manager->queue($cookie);
    }

    public function testQueuedReturnsNullWhenCookieDoesNotExist()
    {
        $manager = new CookieJar();

        $this->assertNull($manager->queued('missing'));
    }

    public function testHasQueuedReturnsFalseWhenCookieDoesNotExist()
    {
        $manager = new CookieJar();

        $this->assertFalse($manager->hasQueued('missing'));
    }

    public function testUnqueue()
    {
        $manager = $this->getMockBuilder(CookieJar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueuedCookiesRaw', 'setQueuedCookies'])
            ->getMock();

        $manager->expects($this->once())
            ->method('getQueuedCookiesRaw')
            ->willReturn([
                'foo' => [
                    '/' => new Cookie('foo', 'bar'),
                ],
            ]);
        $manager->expects($this->once())
            ->method('setQueuedCookies')
            ->with([]);

        $manager->unqueue('foo');
    }

    public function testUnqueueWithPath()
    {
        $manager = $this->getMockBuilder(CookieJar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueuedCookiesRaw', 'setQueuedCookies'])
            ->getMock();

        $manager->expects($this->once())
            ->method('getQueuedCookiesRaw')
            ->willReturn([
                'foo' => [
                    '/bar' => new Cookie('foo', 'bar'),
                ],
            ]);
        $manager->expects($this->once())
            ->method('setQueuedCookies')
            ->with([]);

        $manager->unqueue('foo', '/bar');
    }

    // =========================================================================
    // Enum Support Tests
    // =========================================================================

    public function testHasAcceptsStringBackedEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('session_id', null)->andReturn('abc123');
        RequestContext::set($request);

        $manager = new CookieJar();

        $this->assertTrue($manager->has(CookieJarTestNameEnum::Session));
    }

    public function testHasAcceptsUnitEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('theme', null)->andReturn('dark');
        RequestContext::set($request);

        $manager = new CookieJar();

        $this->assertTrue($manager->has(CookieJarTestNameUnitEnum::theme));
    }

    public function testGetAcceptsStringBackedEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('session_id', null)->andReturn('abc123');
        RequestContext::set($request);

        $manager = new CookieJar();

        $this->assertSame('abc123', $manager->get(CookieJarTestNameEnum::Session));
    }

    public function testGetAcceptsUnitEnum()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('cookie')->with('theme', null)->andReturn('dark');
        RequestContext::set($request);

        $manager = new CookieJar();

        $this->assertSame('dark', $manager->get(CookieJarTestNameUnitEnum::theme));
    }

    public function testMakeAcceptsStringBackedEnum()
    {
        $manager = new CookieJar();
        $cookie = $manager->make(CookieJarTestNameEnum::Session, 'abc123');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('session_id', $cookie->getName());
        $this->assertSame('abc123', $cookie->getValue());
    }

    public function testMakeAcceptsUnitEnum()
    {
        $manager = new CookieJar();
        $cookie = $manager->make(CookieJarTestNameUnitEnum::theme, 'dark');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('theme', $cookie->getName());
        $this->assertSame('dark', $cookie->getValue());
    }

    public function testForeverAcceptsStringBackedEnum()
    {
        $manager = new CookieJar();
        $cookie = $manager->forever(CookieJarTestNameEnum::Remember, 'token123');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('remember_token', $cookie->getName());
        $this->assertSame('token123', $cookie->getValue());
    }

    public function testForeverUsesLaravelDuration()
    {
        $manager = new CookieJar();
        $cookie = $manager->forever('remember_token', 'token123');

        $this->assertEqualsWithDelta(576000 * 60, $cookie->getExpiresTime() - time(), 5);
    }

    public function testForeverAcceptsUnitEnum()
    {
        $manager = new CookieJar();
        $cookie = $manager->forever(CookieJarTestNameUnitEnum::locale, 'en');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('locale', $cookie->getName());
        $this->assertSame('en', $cookie->getValue());
    }

    public function testForgetAcceptsStringBackedEnum()
    {
        $manager = new CookieJar();
        $cookie = $manager->forget(CookieJarTestNameEnum::Session);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('session_id', $cookie->getName());
        $this->assertSame('', $cookie->getValue());
    }

    public function testForgetAcceptsUnitEnum()
    {
        $manager = new CookieJar();
        $cookie = $manager->forget(CookieJarTestNameUnitEnum::theme);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('theme', $cookie->getName());
        $this->assertSame('', $cookie->getValue());
    }

    public function testMakeWithIntBackedEnumThrowsTypeError()
    {
        $this->expectException(TypeError::class);

        $manager = new CookieJar();
        $cookie = $manager->make(CookieJarTestNameIntEnum::First, 'value');
        $cookie->getName(); // TypeError thrown here
    }
}
