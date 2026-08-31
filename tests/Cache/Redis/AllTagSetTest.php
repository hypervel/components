<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Hypervel\Cache\Redis\AllTagSet;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

/**
 * Tests for AllTagSet class.
 *
 * Note: Operation-specific tests (addEntry, entries, flushStaleEntries) have been
 * moved to dedicated test classes in tests/Cache/Redis/Operations/AllTag/.
 *
 * This file tests the TagSet-specific API methods: reset, flush, tagId, tagKey, flushTag, resetTag.
 */
class AllTagSetTest extends RedisCacheTestCase
{
    public function testResetAttemptsEveryTag(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts']);

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:posts:entries')
            ->andReturn(1);

        $this->assertTrue($tagSet->reset());
    }

    public function testFlushAttemptsEveryTag(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts']);

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:posts:entries')
            ->andReturn(1);

        $this->assertTrue($tagSet->flush());
    }

    public function testResetAndFlushAttemptEveryTagAndTreatMissingTagsAsSuccess(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts']);

        $connection->shouldReceive('del')
            ->twice()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(0);
        $connection->shouldReceive('del')
            ->twice()
            ->with('prefix:_all:tag:posts:entries')
            ->andReturn(1);

        $this->assertTrue($tagSet->reset());
        $this->assertTrue($tagSet->flush());
    }

    public function testResetAndFlushUseTheirPerTagExtensionPoints(): void
    {
        $tagSet = new class($this->createStore($this->mockConnection()), ['users', 'posts']) extends AllTagSet {
            public array $flushed = [];

            public array $reset = [];

            public function resetTag(string $name): string
            {
                $this->reset[] = $name;

                return $name;
            }

            public function flushTag(string $name): string
            {
                $this->flushed[] = $name;

                return $name;
            }
        };

        $this->assertTrue($tagSet->reset());
        $this->assertTrue($tagSet->flush());
        $this->assertSame(['users', 'posts'], $tagSet->reset);
        $this->assertSame(['users', 'posts'], $tagSet->flushed);
    }

    public function testResetAttemptsLaterTagsAndRethrowsTheFirstException(): void
    {
        $firstException = new RuntimeException('users failed');
        $connection = $this->mockConnection();
        $tagSet = new AllTagSet($this->createStore($connection), ['users', 'posts', 'comments']);

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andThrow($firstException);
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:posts:entries')
            ->andThrow(new RuntimeException('posts failed'));
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:comments:entries')
            ->andReturn(1);

        try {
            $tagSet->reset();
            $this->fail('Expected the first tag reset exception to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstException, $exception);
        }
    }

    public function testResetStopsImmediatelyOnCancellation(): void
    {
        $cancellation = new CanceledException;
        $connection = $this->mockConnection();
        $tagSet = new AllTagSet($this->createStore($connection), ['users', 'posts']);

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andThrow($cancellation);
        $connection->shouldNotReceive('del')->with('prefix:_all:tag:posts:entries');

        try {
            $tagSet->reset();
            $this->fail('Expected the tag reset cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testFlushTagCallsResetTag(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        // resetTag calls store->forget which uses del
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $result = $tagSet->flushTag('users');

        // Returns the tag identifier
        $this->assertSame('_all:tag:users:entries', $result);
    }

    public function testPerTagOperationsReturnTheIdentifierWhenTheTagDoesNotExist(): void
    {
        $connection = $this->mockConnection();
        $tagSet = new AllTagSet($this->createStore($connection), ['users']);

        $connection->shouldReceive('del')
            ->twice()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(0);

        $this->assertSame('_all:tag:users:entries', $tagSet->resetTag('users'));
        $this->assertSame('_all:tag:users:entries', $tagSet->flushTag('users'));
    }

    public function testResetTagDeletesTagAndReturnsId(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $result = $tagSet->resetTag('users');

        $this->assertSame('_all:tag:users:entries', $result);
    }

    public function testTagIdReturnsCorrectFormat(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        $this->assertSame('_all:tag:users:entries', $tagSet->tagId('users'));
        $this->assertSame('_all:tag:posts:entries', $tagSet->tagId('posts'));
    }

    public function testTagKeyReturnsCorrectFormat(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users']);

        // In AllTagSet, tagKey and tagId return the same value
        $this->assertSame('_all:tag:users:entries', $tagSet->tagKey('users'));
    }

    public function testTagIdsReturnsArrayOfTagIdentifiers(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts', 'comments']);

        $tagIds = $tagSet->tagIds();

        $this->assertSame([
            '_all:tag:users:entries',
            '_all:tag:posts:entries',
            '_all:tag:comments:entries',
        ], $tagIds);
    }

    public function testGetNamesReturnsOriginalTagNames(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $tagSet = new AllTagSet($store, ['users', 'posts']);

        $this->assertSame(['users', 'posts'], $tagSet->getNames());
    }
}
