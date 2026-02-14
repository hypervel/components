<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie;

use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\Context\RequestContext;
use Hypervel\Cookie\Cookie;
use Hypervel\Cookie\CookieManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swow\Psr7\Message\ServerRequestPlusInterface;
use TypeError;

enum CookieManagerTestNameEnum: string
{
    case Session = 'session_id';
    case Remember = 'remember_token';
}

enum CookieManagerTestNameUnitEnum
{
    case theme;
    case locale;
}

enum CookieManagerTestNameIntEnum: int
{
    case First = 1;
}

/**
 * @internal
 * @coversNothing
 */
class CookieManagerTest extends TestCase
{
    public function testHas()
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');

        RequestContext::set(m::mock(ServerRequestPlusInterface::class), null);

        $manager = new CookieManager($request);

        $this->assertTrue($manager->has('foo'));
    }

    public function testGet()
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');

        RequestContext::set(m::mock(ServerRequestPlusInterface::class), null);

        $manager = new CookieManager($request);

        $this->assertEquals('bar', $manager->get('foo'));
    }

    public function testMake()
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('foo', null)->andReturn('bar');

        $manager = new CookieManager($request);

        $this->assertInstanceOf(Cookie::class, $manager->make('foo', 'bar'));
    }

    public function testQueue()
    {
        $manager = $this->getMockBuilder(CookieManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueuedCookies', 'setQueueCookies'])
            ->getMock();

        $manager->expects($this->once())
            ->method('getQueuedCookies')
            ->willReturn([]);
        $manager->expects($this->once())
            ->method('setQueueCookies')
            ->with([
                'foo' => [
                    '/' => $cookie = new Cookie('foo', 'bar'),
                ],
            ]);

        $manager->queue($cookie);
    }

    public function tesetUnqueue()
    {
        $manager = $this->getMockBuilder(CookieManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueuedCookies', 'setQueueCookies'])
            ->getMock();

        $manager->expects($this->once())
            ->method('getQueuedCookies')
            ->willReturn([
                'foo' => [
                    '/' => new Cookie('foo', 'bar'),
                ],
            ]);
        $manager->expects($this->once())
            ->method('setQueueCookies')
            ->with([]);

        $manager->unqueue('foo');
    }

    public function tesetUnqueueWithPath()
    {
        $manager = $this->getMockBuilder(CookieManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueuedCookies', 'setQueueCookies'])
            ->getMock();

        $manager->expects($this->once())
            ->method('getQueuedCookies')
            ->willReturn([
                'foo' => [
                    '/bar' => new Cookie('foo', 'bar'),
                ],
            ]);
        $manager->expects($this->once())
            ->method('setQueueCookies')
            ->with([]);

        $manager->unqueue('foo', 'bar');
    }

    // =========================================================================
    // Enum Support Tests
    // =========================================================================

    public function testHasAcceptsStringBackedEnum(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('session_id', null)->andReturn('abc123');

        RequestContext::set(m::mock(ServerRequestPlusInterface::class), null);

        $manager = new CookieManager($request);

        $this->assertTrue($manager->has(CookieManagerTestNameEnum::Session));
    }

    public function testHasAcceptsUnitEnum(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('theme', null)->andReturn('dark');

        RequestContext::set(m::mock(ServerRequestPlusInterface::class), null);

        $manager = new CookieManager($request);

        $this->assertTrue($manager->has(CookieManagerTestNameUnitEnum::theme));
    }

    public function testGetAcceptsStringBackedEnum(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('session_id', null)->andReturn('abc123');

        RequestContext::set(m::mock(ServerRequestPlusInterface::class), null);

        $manager = new CookieManager($request);

        $this->assertSame('abc123', $manager->get(CookieManagerTestNameEnum::Session));
    }

    public function testGetAcceptsUnitEnum(): void
    {
        $request = m::mock(RequestInterface::class);
        $request->shouldReceive('cookie')->with('theme', null)->andReturn('dark');

        RequestContext::set(m::mock(ServerRequestPlusInterface::class), null);

        $manager = new CookieManager($request);

        $this->assertSame('dark', $manager->get(CookieManagerTestNameUnitEnum::theme));
    }

    public function testMakeAcceptsStringBackedEnum(): void
    {
        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->make(CookieManagerTestNameEnum::Session, 'abc123');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('session_id', $cookie->getName());
        $this->assertSame('abc123', $cookie->getValue());
    }

    public function testMakeAcceptsUnitEnum(): void
    {
        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->make(CookieManagerTestNameUnitEnum::theme, 'dark');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('theme', $cookie->getName());
        $this->assertSame('dark', $cookie->getValue());
    }

    public function testForeverAcceptsStringBackedEnum(): void
    {
        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->forever(CookieManagerTestNameEnum::Remember, 'token123');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('remember_token', $cookie->getName());
        $this->assertSame('token123', $cookie->getValue());
    }

    public function testForeverAcceptsUnitEnum(): void
    {
        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->forever(CookieManagerTestNameUnitEnum::locale, 'en');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('locale', $cookie->getName());
        $this->assertSame('en', $cookie->getValue());
    }

    public function testForgetAcceptsStringBackedEnum(): void
    {
        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->forget(CookieManagerTestNameEnum::Session);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('session_id', $cookie->getName());
        $this->assertSame('', $cookie->getValue());
    }

    public function testForgetAcceptsUnitEnum(): void
    {
        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->forget(CookieManagerTestNameUnitEnum::theme);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('theme', $cookie->getName());
        $this->assertSame('', $cookie->getValue());
    }

    public function testMakeWithIntBackedEnumThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);

        $request = m::mock(RequestInterface::class);

        $manager = new CookieManager($request);
        $cookie = $manager->make(CookieManagerTestNameIntEnum::First, 'value');
        $cookie->getName(); // TypeError thrown here
    }
}
