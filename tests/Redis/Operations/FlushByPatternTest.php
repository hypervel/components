<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Operations;

use Closure;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\Operations\FlushByPattern;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use Redis;
use RedisException;

/**
 * Tests for FlushByPattern - pattern-based key deletion with OPT_PREFIX handling.
 */
class FlushByPatternTest extends TestCase
{
    /**
     * Create a raw connection around the fake client.
     */
    private function createConnection(FlushingRedisClient $client): PhpRedisConnectionStub
    {
        return (new PhpRedisConnectionStub)->setActiveConnection($client);
    }

    public function testFlushRejectsTransformedConnectionsBeforeDeleting(): void
    {
        $client = new FlushingRedisClient;
        $connection = $this->createConnection($client)->shouldTransform();

        $this->expectException(InvalidRedisConnectionException::class);
        $this->expectExceptionMessageIsOrContains('SafeScan requires a raw Redis connection.');

        try {
            (new FlushByPattern($connection))->execute('cache:test:*');
        } finally {
            $this->assertSame([], $client->unlinked);
        }
    }

    public function testFlushDeletesMatchingKeys(): void
    {
        $client = new FlushingRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1', 'cache:test:key2', 'cache:test:key3'], 'iterator' => 0],
            ],
        );

        $connection = $this->createConnection($client);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(3, $deletedCount);
        $this->assertSame([['cache:test:key1', 'cache:test:key2', 'cache:test:key3']], $client->unlinked);
        $this->assertSame(1, $client->errorClears);
        $this->assertSame(0, $client->errorReads);
    }

    public function testFlushReturnsZeroWhenNoKeysMatch(): void
    {
        $client = new FlushingRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $connection = $this->createConnection($client);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:nonexistent:*');

        $this->assertSame(0, $deletedCount);
        $this->assertSame([], $client->unlinked);
    }

    public function testFlushHandlesOptPrefixCorrectly(): void
    {
        // Client has OPT_PREFIX set - SafeScan should handle this
        $client = new FlushingRedisClient(
            scanResults: [
                // Redis returns keys WITH the OPT_PREFIX
                ['keys' => ['myapp:cache:test:key1', 'myapp:cache:test:key2'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
        );

        $connection = $this->createConnection($client);
        // Keys passed to unlink should have OPT_PREFIX stripped
        // (phpredis will auto-add it back)

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(2, $deletedCount);
        $this->assertSame([['cache:test:key1', 'cache:test:key2']], $client->unlinked);
    }

    public function testFlushDeletesInBatches(): void
    {
        // Generate 2500 keys to test batching (BUFFER_SIZE is 1000)
        $batch1Keys = [];
        $batch2Keys = [];
        $batch3Keys = [];

        for ($i = 0; $i < 1000; ++$i) {
            $batch1Keys[] = "cache:test:key{$i}";
        }
        for ($i = 1000; $i < 2000; ++$i) {
            $batch2Keys[] = "cache:test:key{$i}";
        }
        for ($i = 2000; $i < 2500; ++$i) {
            $batch3Keys[] = "cache:test:key{$i}";
        }

        $client = new FlushingRedisClient(
            scanResults: [
                // Return all keys in one scan result to simplify test
                ['keys' => array_merge($batch1Keys, $batch2Keys, $batch3Keys), 'iterator' => 0],
            ],
        );

        $connection = $this->createConnection($client);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(2500, $deletedCount);
        $this->assertSame([$batch1Keys, $batch2Keys, $batch3Keys], $client->unlinked);
    }

    public function testFlushHandlesMultipleScanIterations(): void
    {
        $client = new FlushingRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1', 'cache:test:key2'], 'iterator' => 42],  // More to scan
                ['keys' => ['cache:test:key3'], 'iterator' => 0],  // Done
            ],
        );

        $connection = $this->createConnection($client);
        // All keys should be collected and deleted together (under buffer size)

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(3, $deletedCount);
        $this->assertSame([['cache:test:key1', 'cache:test:key2', 'cache:test:key3']], $client->unlinked);
    }

    public function testFlushThrowsTheNativeErrorWhenUnlinkFails(): void
    {
        $client = new FlushingRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1'], 'iterator' => 0],
            ],
        );
        $client->setFakeLastError('stale error');

        $connection = $this->createConnection($client);
        $client->unlinkCallback = function () use ($client): false {
            $this->assertSame(1, $client->errorClears);

            $client->setFakeLastError('UNLINK is not permitted');

            return false;
        };

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('UNLINK is not permitted');

        try {
            (new FlushByPattern($connection))->execute('cache:test:*');
        } finally {
            $this->assertSame([['cache:test:key1']], $client->unlinked);
            $this->assertSame(1, $client->errorClears);
            $this->assertSame(1, $client->errorReads);
        }
    }

    public function testFlushThrowsAStableErrorWhenUnlinkFailsWithoutANativeError(): void
    {
        $client = new FlushingRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1'], 'iterator' => 0],
            ],
        );
        $client->setFakeLastError('stale error');

        $connection = $this->createConnection($client);
        $client->unlinkCallback = function () use ($client): false {
            $this->assertSame(1, $client->errorClears);

            return false;
        };

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageIs('Redis UNLINK failed while deleting keys by pattern.');

        try {
            (new FlushByPattern($connection))->execute('cache:test:*');
        } finally {
            $this->assertSame([['cache:test:key1']], $client->unlinked);
            $this->assertSame(1, $client->errorClears);
            $this->assertSame(1, $client->errorReads);
        }
    }

    public function testFlushPassesPatternToSafeScan(): void
    {
        $client = new FlushingRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $connection = $this->createConnection($client);

        $flushByPattern = new FlushByPattern($connection);

        $flushByPattern->execute('cache:users:*');

        // Verify the pattern was passed to scan
        $this->assertSame(1, $client->getScanCallCount());
        $this->assertSame('cache:users:*', $client->getScanCalls()[0]['pattern']);
    }
}

class FlushingRedisClient extends FakeRedisClient
{
    /** @var list<list<string>> */
    public array $unlinked = [];

    public int $errorClears = 0;

    public int $errorReads = 0;

    public ?Closure $unlinkCallback = null;

    /**
     * Record each deletion batch.
     */
    public function unlink(array|string $key, string ...$other_keys): Redis|int|false
    {
        $keys = is_array($key) ? $key : [$key, ...$other_keys];
        $this->unlinked[] = $keys;

        return $this->unlinkCallback !== null ? ($this->unlinkCallback)() : count($keys);
    }

    /**
     * Count diagnostic resets before deletion.
     */
    public function clearLastError(): bool
    {
        ++$this->errorClears;

        return parent::clearLastError();
    }

    /**
     * Count diagnostic reads after deletion failures.
     */
    public function getLastError(): ?string
    {
        ++$this->errorReads;

        return parent::getLastError();
    }
}
