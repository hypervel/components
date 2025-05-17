<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Cache\RedisStore;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class CacheRedisTaggedCacheTest extends TestCase
{
    private RedisStore $redis;

    /** @var MockInterface|RedisProxy */
    private RedisProxy $redisProxy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRedis();
    }

    public function testTagEntriesCanBeStoredForever()
    {
        $key = sha1('tag:people:entries|tag:author:entries') . ':name';
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:people:entries', -1, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:author:entries', -1, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('set')->once()->with("prefix:{$key}", serialize('Sally'))->andReturn('OK');

        $this->redis->tags(['people', 'author'])->forever('name', 'Sally');

        $key = sha1('tag:people:entries|tag:author:entries') . ':age';
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:people:entries', -1, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:author:entries', -1, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('set')->once()->with("prefix:{$key}", 30)->andReturn('OK');

        $this->redis->tags(['people', 'author'])->forever('age', 30);

        $this->redisProxy
            ->shouldReceive('zScan')
            ->once()
            ->with('prefix:tag:people:entries', null, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['tag:people:entries:name' => 0, 'tag:people:entries:age' => 0];
            });
        $this->redisProxy
            ->shouldReceive('zScan')
            ->once()
            ->with('prefix:tag:people:entries', 0, '*', 1000)
            ->andReturnNull();
        $this->redisProxy
            ->shouldReceive('zScan')
            ->once()
            ->with('prefix:tag:author:entries', null, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['tag:author:entries:name' => 0, 'tag:author:entries:age' => 0];
            });
        $this->redisProxy
            ->shouldReceive('zScan')
            ->once()
            ->with('prefix:tag:author:entries', 0, '*', 1000)
            ->andReturnNull();

        $this->redisProxy->shouldReceive('del')->once()->with(
            'prefix:tag:people:entries:name',
            'prefix:tag:people:entries:age',
            'prefix:tag:author:entries:name',
            'prefix:tag:author:entries:age'
        )->andReturn('OK');

        $this->redisProxy->shouldReceive('del')->once()->with('prefix:tag:people:entries')->andReturn('OK');
        $this->redisProxy->shouldReceive('del')->once()->with('prefix:tag:author:entries')->andReturn('OK');

        $this->redis->tags(['people', 'author'])->flush();
    }

    public function testTagEntriesCanBeIncremented()
    {
        $key = sha1('tag:votes:entries') . ':person-1';
        $this->redisProxy->shouldReceive('zadd')->times(4)->with('prefix:tag:votes:entries', 'NX', -1, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('incrby')->once()->with("prefix:{$key}", 1)->andReturn(1);
        $this->redisProxy->shouldReceive('incrby')->once()->with("prefix:{$key}", 1)->andReturn(2);
        $this->redisProxy->shouldReceive('decrby')->once()->with("prefix:{$key}", 1)->andReturn(1);
        $this->redisProxy->shouldReceive('decrby')->once()->with("prefix:{$key}", 1)->andReturn(0);

        $this->assertSame(1, $this->redis->tags(['votes'])->increment('person-1'));
        $this->assertSame(2, $this->redis->tags(['votes'])->increment('person-1'));

        $this->assertSame(1, $this->redis->tags(['votes'])->decrement('person-1'));
        $this->assertSame(0, $this->redis->tags(['votes'])->decrement('person-1'));
    }

    public function testStaleEntriesCanBeFlushed()
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $pipe = m::mock(RedisProxy::class);
        $pipe->shouldReceive('zremrangebyscore')->once()->with('prefix:tag:people:entries', 0, now()->timestamp)->andReturn('OK');
        $this->redisProxy->shouldReceive('pipeline')->once()->withArgs(function ($callback) use ($pipe) {
            $callback($pipe);

            return true;
        });

        $this->redis->tags(['people'])->flushStale();
    }

    public function testPut()
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $key = sha1('tag:people:entries|tag:author:entries') . ':name';
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:people:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:author:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('setex')->once()->with("prefix:{$key}", 5, serialize('Sally'))->andReturn('OK');

        $this->redis->tags(['people', 'author'])->put('name', 'Sally', 5);

        $key = sha1('tag:people:entries|tag:author:entries') . ':age';
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:people:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:author:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('setex')->once()->with("prefix:{$key}", 5, 30)->andReturn('OK');

        $this->redis->tags(['people', 'author'])->put('age', 30, 5);
    }

    public function testPutWithArray()
    {
        Carbon::setTestNow('2000-01-01 00:00:00');

        $key = sha1('tag:people:entries|tag:author:entries') . ':name';
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:people:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:author:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('setex')->once()->with("prefix:{$key}", 5, serialize('Sally'))->andReturn('OK');

        $key = sha1('tag:people:entries|tag:author:entries') . ':age';
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:people:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('zadd')->once()->with('prefix:tag:author:entries', now()->timestamp + 5, $key)->andReturn('OK');
        $this->redisProxy->shouldReceive('setex')->once()->with("prefix:{$key}", 5, 30)->andReturn('OK');

        $this->redis->tags(['people', 'author'])->put([
            'name' => 'Sally',
            'age' => 30,
        ], 5);
    }

    private function mockRedis()
    {
        $this->redis = new RedisStore(m::mock(RedisFactory::class), 'prefix');
        $this->redisProxy = m::mock(RedisProxy::class);

        $this->redis->getRedis()->shouldReceive('get')->with('default')->andReturn($this->redisProxy);
    }
}
