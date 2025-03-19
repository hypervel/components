<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Hyperf\Stringable\Str;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Container\ContainerInterface;
use Swoole\Table;

/**
 * @internal
 * @coversNothing
 */
class CacheSwooleStoreTest extends TestCase
{
    public function testCanRetrieveItemsFromStore()
    {
        Carbon::setTestNow(now());

        $table = $this->createSwooleTable();

        $table->set('foo', ['value' => serialize('bar'), 'expiration' => time() + 100]);

        $store = $this->createStore($table);

        $this->assertEquals('bar', $store->get('foo'));
        $this->assertEquals($this->getCurrentTimestamp(), $table->get('foo')['last_used_at']);
        $this->assertEquals(1, $table->get('foo')['used_count']);

        Carbon::setTestNow(now()->addMinutes(1));

        $store->get('foo');
        $this->assertEquals($this->getCurrentTimestamp(), $table->get('foo')['last_used_at']);
        $this->assertEquals(2, $table->get('foo')['used_count']);
    }

    public function testMissingItemsReturnNull()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $this->assertNull($store->get('foo'));
    }

    public function testExpiredItemsReturnNull()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $table->set('foo', ['value' => serialize('bar'), 'expiration' => time() - 100]);

        $this->assertNull($store->get('foo'));
    }

    public function testGetMethodCanResolvePendingInterval()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->interval('foo', fn () => 'bar', 1);

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testManyMethodCanReturnManyValues()
    {
        $table = $this->createSwooleTable();

        $table->set('foo', ['value' => serialize('bar'), 'expiration' => time() + 100]);
        $table->set('bar', ['value' => serialize('baz'), 'expiration' => time() + 100]);

        $store = $this->createStore($table);

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $store->many(['foo', 'bar']));
    }

    public function testPutStoresValueInTable()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->put('foo', 'bar', 5);

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testPutManyStoresValueInTable()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->putMany(['foo' => 'bar', 'bar' => 'baz'], 5);

        $this->assertEquals('bar', $store->get('foo'));
        $this->assertEquals('baz', $store->get('bar'));
    }

    public function testAdd()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $this->assertTrue($store->add('foo', 'bar', 5));
        $this->assertEquals('bar', $store->get('foo'));
        $this->assertFalse($store->add('foo', 'baz', 5));
    }

    public function testIncrementAndDecrementOperations()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->increment('counter');
        $this->assertEquals(1, $store->get('counter'));

        $store->increment('counter', 2);
        $this->assertEquals(3, $store->get('counter'));

        $store->decrement('counter', 2);
        $this->assertEquals(1, $store->get('counter'));
    }

    public function testForeverStoresValueInTable()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->forever('foo', 'bar');

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testIntervalsCanBeRefreshed()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->interval('foo', fn () => Str::random(10), 1);

        $this->assertTrue(is_string($first = $store->get('foo')));

        Carbon::setTestNow(now()->addMinutes(1));

        $store->refreshIntervalCaches();

        $this->assertTrue(is_string($second = $store->get('foo')));
        $this->assertNotEquals($first, $second);

        Carbon::setTestNow();
    }

    public function testCanForgetCacheItems()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->put('foo', 'bar', 5);
        $this->assertTrue($store->forget('foo'));

        $this->assertNull($store->get('foo'));

        $store->put('foo', 'bar', 5);
        $this->assertTrue($store->flush());

        $this->assertNull($store->get('foo'));
    }

    public function testIntervalsAreNotFlushed()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        $store->interval('foo', fn () => 'bar', 1);
        $this->assertTrue($store->flush());

        $this->assertEquals('bar', $store->get('foo'));
    }

    public function testExpiredAtWithMicrosecond()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        Carbon::setTestNow('2000-01-01 00:00:00.500000');
        $store->put('foo', 'bar', 1);

        Carbon::setTestNow('2000-01-01 00:00:01.499999');
        $this->assertSame('bar', $store->get('foo'));

        Carbon::setTestNow('2000-01-01 00:00:01.500000');
        $this->assertNull($store->get('foo'));
    }

    public function testCanRemoveExpiredRecordFromTable()
    {
        $table = $this->createSwooleTable();

        $table->set('foo', ['value' => serialize('bar'), 'expiration' => time() - 100]);

        $store = $this->createStore($table);

        $this->assertNull($store->get('foo'));
        $this->assertFalse($table->get('foo'));
    }

    public function testEvictRecordsWhenMemoryLimitIsReached()
    {
        $table = $this->createSwooleTable();

        $store = $this->createStore($table);

        for ($i = 0; $i < 256; ++$i) {
            $store->put(sha1("key:{$i}"), $i, 100);
        }

        $this->assertNull($store->get(sha1('key:0')));
        $this->assertSame(255, $store->get(sha1('key:255')));
        $this->assertLessThanOrEqual(128, $table->count());
    }

    public function testFlushStaleRecords()
    {
        Carbon::setTestNow(now());

        $table = $this->createSwooleTable();

        $table->set('foo', ['value' => serialize('foo'), 'expiration' => time() - 100]);

        $store = $this->createStore($table);

        $store->put('bar', 'bar', 100);

        $this->assertFalse($table->get('foo'));
    }

    private function createStore(Table $table)
    {
        return new SwooleStore($table, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    private function createSwooleTable()
    {
        return (new SwooleTableManager(m::mock(ContainerInterface::class)))->createTable(128, 10240, 0.2);
    }

    private function getCurrentTimestamp()
    {
        return Carbon::now()->getPreciseTimestamp(6) / 1000000;
    }
}
