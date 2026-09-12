<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Session\ArraySessionHandler;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;
use SessionHandlerInterface;

class ArraySessionHandlerTest extends TestCase
{
    public function testIsSessionHandlerInterface(): void
    {
        $this->assertInstanceOf(
            SessionHandlerInterface::class,
            new ArraySessionHandler(10)
        );
    }

    public function testInitializeSession(): void
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->open('', ''));
    }

    public function testCloseSession(): void
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->close());
    }

    public function testReadDataFromSession(): void
    {
        $handler = new ArraySessionHandler(10);

        $handler->write('foo', 'bar');

        $this->assertSame('bar', $handler->read('foo'));
    }

    public function testReadDataFromAlmostExpiredSession(): void
    {
        $handler = new ArraySessionHandler(10);

        CarbonImmutable::setTestNow($now = Date::now());
        $handler->write('foo', 'bar');

        CarbonImmutable::setTestNow($now->addMinutes(10));

        $this->assertSame('bar', $handler->read('foo'));
    }

    public function testReadDataFromExpiredSession(): void
    {
        $handler = new ArraySessionHandler(10);

        CarbonImmutable::setTestNow($now = Date::now());
        $handler->write('foo', 'bar');

        CarbonImmutable::setTestNow($now->addMinutes(10)->addSecond());

        $this->assertSame('', $handler->read('foo'));
    }

    public function testReadDataFromNonExistingSession(): void
    {
        $handler = new ArraySessionHandler(10);

        $this->assertSame('', $handler->read('foo'));
    }

    public function testWriteSessionData(): void
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->write('foo', 'bar'));
        $this->assertSame('bar', $handler->read('foo'));

        $this->assertTrue($handler->write('foo', 'baz'));
        $this->assertSame('baz', $handler->read('foo'));
    }

    public function testDestroySession(): void
    {
        $handler = new ArraySessionHandler(10);

        $this->assertTrue($handler->destroy('foo'));

        $handler->write('foo', 'bar');

        $this->assertTrue($handler->destroy('foo'));
        $this->assertSame('', $handler->read('foo'));
    }

    public function testCleanOldSession(): void
    {
        $handler = new ArraySessionHandler(10);

        $this->assertSame(0, $handler->gc(300));

        CarbonImmutable::setTestNow($now = Date::now());
        $handler->write('foo', 'bar');
        $this->assertSame(0, $handler->gc(300));
        $this->assertSame('bar', $handler->read('foo'));

        CarbonImmutable::setTestNow($now = $now->addSecond());

        $handler->write('baz', 'qux');

        CarbonImmutable::setTestNow($now->addMinutes(5));

        $this->assertSame(1, $handler->gc(300));
        $this->assertSame('', $handler->read('foo'));
        $this->assertSame('qux', $handler->read('baz'));
    }
}
