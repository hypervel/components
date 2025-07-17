<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Hyperf\Collection\Collection;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CacheDatabaseStoreTest extends TestCase
{
    public function testNullIsReturnedWhenItemNotFound()
    {
        $store = $this->getStore();
        $connection = m::mock(ConnectionInterface::class);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection());

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertNull($store->get('foo'));
    }

    public function testNullIsReturnedAndItemDeletedWhenItemIsExpired()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $currentTime = Carbon::now()->getTimestamp();
        Carbon::setTestNow(Carbon::createFromTimestamp($currentTime));
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->times(2)->with('table')->andReturn($table);
        
        // First call for retrieval
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([(object) [
            'key' => 'prefixfoo',
            'value' => serialize('bar'),
            'expiration' => $currentTime - 10,
        ]]));
        
        // Second call for deletion of expired items
        $table->shouldReceive('whereIn')
            ->once()
            ->with('key', ['prefixfoo', 'prefixilluminate:cache:flexible:created:foo'])
            ->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '<=', $currentTime)->andReturn($table);
        $table->shouldReceive('delete')->once();

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertNull($store->get('foo'));
    }

    public function testItemsCanBeRetrievedFromDatabase()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([(object) [
            'key' => 'prefixfoo',
            'value' => serialize('bar'),
            'expiration' => 999999999999999,
        ]]));

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testManyReturnsMultipleItems()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('whereIn')
            ->once()
            ->with('key', ['prefixfoo', 'prefixbar', 'prefixbaz'])
            ->andReturn($table);
        
        $table->shouldReceive('get')->once()->andReturn(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => 999999999999999,
            ],
            (object) [
                'key' => 'prefixbaz',
                'value' => serialize('qux'),
                'expiration' => 999999999999999,
            ],
        ]));

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $results = $store->many(['foo', 'bar', 'baz']);
        
        $this->assertEquals([
            'foo' => 'bar',
            'bar' => null,
            'baz' => 'qux',
        ], $results);
    }

    public function testExpiredItemsAreRemovedOnRetrieval()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $currentTime = Carbon::now()->getTimestamp();
        Carbon::setTestNow(Carbon::createFromTimestamp($currentTime));

        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->times(2)->with('table')->andReturn($table);
        
        // First call for retrieval
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => $currentTime - 10,
            ],
        ]));
        
        // Second call for deletion
        $table->shouldReceive('whereIn')
            ->once()
            ->with('key', ['prefixfoo', 'prefixilluminate:cache:flexible:created:foo'])
            ->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '<=', $currentTime)->andReturn($table);
        $table->shouldReceive('delete')->once();

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertNull($store->get('foo'));
    }

    public function testItemsCanBeStored()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('upsert')->once()->with([
            [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => m::type('int'),
            ],
        ], 'key')->andReturn(1);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $result = $store->put('foo', 'bar', 10);
        $this->assertTrue($result);
    }

    public function testManyItemsCanBeStoredAtOnce()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        
        $table->shouldReceive('upsert')->once()->with([
            [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => m::type('int'),
            ],
            [
                'key' => 'prefixbaz',
                'value' => serialize('qux'),
                'expiration' => m::type('int'),
            ],
        ], 'key')->andReturn(2);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $result = $store->putMany(['foo' => 'bar', 'baz' => 'qux'], 10);
        $this->assertTrue($result);
    }

    public function testAddOnlyAddsIfKeyDoesntExist()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        // Check if exists (returns null)
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection());
        
        // Insert
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('insert')->once()->with([
            'key' => 'prefixfoo',
            'value' => serialize('bar'),
            'expiration' => m::type('int'),
        ])->andReturn(true);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertTrue($store->add('foo', 'bar', 10));
    }

    public function testAddReturnsFalseIfKeyExists()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('whereIn')->once()->with('key', ['prefixfoo'])->andReturn($table);
        $table->shouldReceive('get')->once()->andReturn(new Collection([
            (object) [
                'key' => 'prefixfoo',
                'value' => serialize('bar'),
                'expiration' => 999999999999999,
            ],
        ]));

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertFalse($store->add('foo', 'new-bar', 10));
    }

    public function testIncrementReturnsCorrectValues()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
            return $callback($connection);
        });
        
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('lockForUpdate')->once()->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'key' => 'prefixfoo',
            'value' => serialize(2),
            'expiration' => 999999999999999,
        ]);
        
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('update')->once()->with(['value' => serialize(3)])->andReturn(1);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertEquals(3, $store->increment('foo'));
    }

    public function testIncrementReturnsFalseIfItemNotNumeric()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
            return $callback($connection);
        });
        
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('lockForUpdate')->once()->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'key' => 'prefixfoo',
            'value' => serialize('not-a-number'),
            'expiration' => 999999999999999,
        ]);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertFalse($store->increment('foo'));
    }

    public function testDecrementReturnsCorrectValues()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
            return $callback($connection);
        });
        
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('lockForUpdate')->once()->andReturn($table);
        $table->shouldReceive('first')->once()->andReturn((object) [
            'key' => 'prefixfoo',
            'value' => serialize(10),
            'expiration' => 999999999999999,
        ]);
        
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('update')->once()->with(['value' => serialize(7)])->andReturn(1);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertEquals(7, $store->decrement('foo', 3));
    }

    public function testForeverCallsStoreItemWithReallyLongTime()
    {
        $store = $this->getMockBuilder(DatabaseStore::class)
            ->onlyMethods(['put'])
            ->setConstructorArgs($this->getMocks())
            ->getMock();

        $store->expects($this->once())
            ->method('put')
            ->with($this->equalTo('foo'), $this->equalTo('bar'), $this->equalTo(315360000))
            ->willReturn(true);

        $result = $store->forever('foo', 'bar');
        $this->assertTrue($result);
    }

    public function testItemsCanBeForgotten()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('key', '=', 'prefixfoo')->andReturn($table);
        $table->shouldReceive('delete')->once();

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertTrue($store->forget('foo'));
    }

    public function testItemsCanBeFlushed()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('delete')->once()->andReturn(1);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $result = $store->flush();
        $this->assertTrue($result);
    }

    public function testPruneExpiredRemovesExpiredEntries()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $table = m::mock(Builder::class);
        $currentTime = Carbon::now()->getTimestamp();
        
        $resolver->shouldReceive('connection')->with('default')->andReturn($connection);
        $connection->shouldReceive('table')->once()->with('table')->andReturn($table);
        $table->shouldReceive('where')->once()->with('expiration', '<=', $currentTime)->andReturn($table);
        $table->shouldReceive('delete')->once()->andReturn(5);

        $store = new DatabaseStore($resolver, 'default', 'table', 'prefix');
        $this->assertEquals(5, $store->pruneExpired());
    }

    public function testGetPrefixReturnsConfiguredPrefix()
    {
        $store = $this->getStore();
        $this->assertSame('prefix', $store->getPrefix());
    }

    protected function getStore(): DatabaseStore
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        
        $resolver->shouldReceive('connection')->andReturn($connection);
        
        return new DatabaseStore($resolver, 'default', 'table', 'prefix');
    }

    protected function getMocks(): array
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        
        return [$resolver, 'default', 'table', 'prefix'];
    }
}