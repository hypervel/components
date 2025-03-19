<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie;

use Hyperf\Context\RequestContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Cookie\Cookie;
use Hypervel\Cookie\CookieManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swow\Psr7\Message\ServerRequestPlusInterface;

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
}
