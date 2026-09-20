<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\SessionStore;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use stdClass;

class CacheSessionStoreTest extends TestCase
{
    public function testItemsCanBeSetAndRetrieved(): void
    {
        $store = new SessionStore(self::getSession());
        $result = $store->put('foo', 'bar', 10);
        $this->assertTrue($result);
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testDottedKeysAreStoredLiterallyAndIndependently(): void
    {
        $store = new SessionStore(self::getSession());

        $store->put('form', 'first', 10);
        $store->put('form.value', 'second', 10);

        $this->assertSame('first', $store->get('form'));
        $this->assertSame('second', $store->get('form.value'));
        $this->assertSame(['form', 'form.value'], array_keys($store->all()));

        $this->assertTrue($store->forget('form'));
        $this->assertNull($store->get('form'));
        $this->assertSame('second', $store->get('form.value'));
    }

    public function testCacheTtl(): void
    {
        $store = new SessionStore(self::getSession());

        CarbonImmutable::setTestNow('2000-01-01 00:00:00.500'); // 500 milliseconds past
        $store->put('hello', 'world', 1);

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.499'); // progress 0.999 seconds
        $this->assertSame('world', $store->get('hello'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:01.500'); // progress 0.001 seconds. 1 second since putting into cache.
        $this->assertNull($store->get('hello'));
    }

    public function testMultipleItemsCanBeSetAndRetrieved(): void
    {
        $store = new SessionStore(self::getSession());
        $result = $store->put('foo', 'bar', 10);
        $resultMany = $store->putMany([
            'fizz' => 'buz',
            'quz' => 'baz',
        ], 10);
        $this->assertTrue($result);
        $this->assertTrue($resultMany);
        $this->assertEquals([
            'foo' => 'bar',
            'fizz' => 'buz',
            'quz' => 'baz',
            'norf' => null,
        ], $store->many(['foo', 'fizz', 'quz', 'norf']));
    }

    public function testItemsCanExpire(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new SessionStore(self::getSession());

        $store->put('foo', 'bar', 10);
        CarbonImmutable::setTestNow($now->addSeconds(10)->addSecond());
        $result = $store->get('foo');

        $this->assertNull($result);
    }

    public function testTouchExtendsTtl(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new SessionStore(self::getSession());
        $store->put('foo', 'bar', 10);

        // Move time forward and touch to extend TTL
        CarbonImmutable::setTestNow($now = $now->addSeconds(5));
        $this->assertTrue($store->touch('foo', 60));

        // Value should still exist past the original expiry
        CarbonImmutable::setTestNow($now = $now->addSeconds(10));
        $this->assertSame('bar', $store->get('foo'));

        // Value should expire after the new TTL
        CarbonImmutable::setTestNow($now->addSeconds(50));
        $this->assertNull($store->get('foo'));
    }

    public function testStoreItemForeverProperlyStoresInArray(): void
    {
        $mock = $this->getMockBuilder(SessionStore::class)
            ->setConstructorArgs([self::getSession()])
            ->onlyMethods(['put'])
            ->getMock();
        $mock->expects($this->once())
            ->method('put')->with('foo', 'bar', 0)
            ->willReturn(true);
        $result = $mock->forever('foo', 'bar');
        $this->assertTrue($result);
    }

    public function testValuesCanBeIncremented(): void
    {
        $store = new SessionStore(self::getSession());
        $store->put('foo', 1, 10);
        $result = $store->increment('foo');
        $this->assertEquals(2, $result);
        $this->assertEquals(2, $store->get('foo'));

        $result = $store->increment('foo', 2);
        $this->assertEquals(4, $result);
        $this->assertEquals(4, $store->get('foo'));
    }

    public function testDottedKeysCanBeIncrementedWithoutChangingTheirExpiration(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $store = new SessionStore(self::getSession());
        $store->put('counter.value', 1, 10);

        $expiresAt = $store->all()['counter.value']['expiresAt'];

        $this->assertSame(2, $store->increment('counter.value'));
        $this->assertSame(2, $store->get('counter.value'));
        $this->assertSame($expiresAt, $store->all()['counter.value']['expiresAt']);
        $this->assertSame(['counter.value'], array_keys($store->all()));
    }

    public function testValuesGetCastedByIncrementOrDecrement(): void
    {
        $store = new SessionStore(self::getSession());
        $store->put('foo', '1', 10);
        $result = $store->increment('foo');
        $this->assertEquals(2, $result);
        $this->assertEquals(2, $store->get('foo'));

        $store->put('bar', '1', 10);
        $result = $store->decrement('bar');
        $this->assertEquals(0, $result);
        $this->assertEquals(0, $store->get('bar'));
    }

    public function testIncrementNonNumericValues(): void
    {
        $store = new SessionStore(self::getSession());
        $store->put('foo', 'I am string', 10);
        $result = $store->increment('foo');
        $this->assertEquals(1, $result);
        $this->assertEquals(1, $store->get('foo'));
    }

    public function testNonExistingKeysCanBeIncremented(): void
    {
        $store = new SessionStore(self::getSession());
        $result = $store->increment('foo');
        $this->assertEquals(1, $result);
        $this->assertEquals(1, $store->get('foo'));

        // Will be there forever
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addCentury());
        $this->assertEquals(1, $store->get('foo'));
    }

    public function testExpiredKeysAreIncrementedLikeNonExistingKeys(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new SessionStore(self::getSession());

        $store->put('foo', 999, 10);
        CarbonImmutable::setTestNow($now->addSeconds(10)->addSecond());
        $result = $store->increment('foo');

        $this->assertEquals(1, $result);
    }

    public function testValuesCanBeDecremented(): void
    {
        $store = new SessionStore(self::getSession());
        $store->put('foo', 1, 10);
        $result = $store->decrement('foo');
        $this->assertEquals(0, $result);
        $this->assertEquals(0, $store->get('foo'));

        $result = $store->decrement('foo', 2);
        $this->assertEquals(-2, $result);
        $this->assertEquals(-2, $store->get('foo'));
    }

    public function testItemsCanBeRemoved(): void
    {
        $store = new SessionStore(self::getSession());
        $store->put('foo', 'bar', 10);
        $this->assertTrue($store->forget('foo'));
        $this->assertNull($store->get('foo'));
        $this->assertFalse($store->forget('foo'));
    }

    public function testItemsCanBeFlushed(): void
    {
        $store = new SessionStore(self::getSession());
        $store->put('foo', 'bar', 10);
        $store->put('baz', 'boom', 10);
        $result = $store->flush();
        $this->assertTrue($result);
        $this->assertNull($store->get('foo'));
        $this->assertNull($store->get('baz'));
    }

    public function testCacheKey(): void
    {
        $store = new SessionStore(self::getSession());
        $this->assertEmpty($store->getPrefix());
    }

    public function testItemKey(): void
    {
        $store = new SessionStore(self::getSession(), 'custom_prefix');
        $this->assertSame('custom_prefix.foo', $store->itemKey('foo'));
    }

    public function testValuesAreStoredByReference(): void
    {
        $store = new SessionStore(self::getSession());
        $object = new stdClass;
        $object->foo = true;

        $store->put('object', $object, 10);
        $object->bar = true;

        $retrievedObject = $store->get('object');

        $this->assertTrue($retrievedObject->foo);
        $this->assertTrue($retrievedObject->bar);
    }

    public function testCanGetAll(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new SessionStore(self::getSession());
        $store->put('foo', 'bar', 10);

        $this->assertEquals([
            'foo' => ['value' => 'bar', 'expiresAt' => $now->addSeconds(10)->getPreciseTimestamp(3) / 1000],
        ], $store->all());
    }

    /**
     * Create the session store.
     */
    protected static function getSession(): Store
    {
        return new Store(
            name: 'name',
            serialization: 'php',
            handler: new ArraySessionHandler(10),
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        );
    }
}
