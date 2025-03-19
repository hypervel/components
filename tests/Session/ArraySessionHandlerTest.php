<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Carbon\Carbon;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Tests\TestCase;
use SessionHandlerInterface;

/**
 * @internal
 * @coversNothing
 */
class ArraySessionHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow(null);
    }

    public function testIsSessionHandlerInterface()
    {
        $this->assertInstanceOf(
            SessionHandlerInterface::class,
            new ArraySessionHandler(10)
        );
    }

    public function testInitializeSession()
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->open('', ''));
    }

    public function testCloseSession()
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->close());
    }

    public function testReadDataFromAlmostExpiredSession()
    {
        $handler = new ArraySessionHandler(10);

        $handler->write('foo', 'bar');

        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        $this->assertSame('bar', $handler->read('foo'));
    }

    public function testReadDataFromExpiredSession()
    {
        $handler = new ArraySessionHandler(10);

        $handler->write('foo', 'bar');

        Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());

        $this->assertSame('', $handler->read('foo'));
    }

    public function testReadDataFromNonExistingSession()
    {
        $handler = new ArraySessionHandler(10);

        $this->assertSame('', $handler->read('foo'));
    }

    public function testWriteSessionData()
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->write('foo', 'bar'));
        $this->assertSame('bar', $handler->read('foo'));

        $this->assertTrue($handler->write('foo', 'baz'));
        $this->assertSame('baz', $handler->read('foo'));
    }

    public function testDestroySession()
    {
        $handler = new ArraySessionHandler(10);

        $handler->write('foo', 'bar');

        $this->assertTrue($handler->destroy('foo'));
        $this->assertSame('', $handler->read('foo'));
    }

    public function testCleanOldSession()
    {
        $handler = new ArraySessionHandler(10);

        $this->assertSame(0, $handler->gc(300));

        $handler->write('foo', 'bar');
        $this->assertSame(0, $handler->gc(300));
        $this->assertSame('bar', $handler->read('foo'));

        Carbon::setTestNow(Carbon::now()->addSecond());

        $handler->write('baz', 'qux');

        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        $this->assertSame(1, $handler->gc(300));
        $this->assertSame('', $handler->read('foo'));
        $this->assertSame('qux', $handler->read('baz'));
    }
}
