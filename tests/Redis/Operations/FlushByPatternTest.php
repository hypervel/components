<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Operations;

use Hypervel\Redis\Operations\FlushByPattern;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Redis\Stub\FakeRedisClient;
use Hypervel\Tests\Redis\Stubs\RedisConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for FlushByPattern - pattern-based key deletion with OPT_PREFIX handling.
 *
 * @internal
 * @coversNothing
 */
class FlushByPatternTest extends TestCase
{
    /**
     * Create a RedisConnection wrapping a FakeRedisClient for testing.
     *
     * Returns both the connection (as a partial mock) and the client so tests
     * can set up unlink expectations while using FakeRedisClient for scan behavior.
     *
     * @return array{m\MockInterface&RedisConnectionStub, FakeRedisClient}
     */
    private function createConnectionWithClient(FakeRedisClient $client): array
    {
        // Create a real stub, then wrap it in a partial mock to allow shouldReceive
        $stub = new RedisConnectionStub();
        $stub->setActiveConnection($client);

        // Create a mock that delegates unknown methods to the stub
        $connection = m::mock($stub)->makePartial();

        return [$connection, $client];
    }

    public function testFlushDeletesMatchingKeys(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1', 'cache:test:key2', 'cache:test:key3'], 'iterator' => 0],
            ],
        );

        [$connection] = $this->createConnectionWithClient($client);
        $connection->shouldReceive('unlink')
            ->once()
            ->with('cache:test:key1', 'cache:test:key2', 'cache:test:key3')
            ->andReturn(3);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(3, $deletedCount);
    }

    public function testFlushReturnsZeroWhenNoKeysMatch(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        [$connection] = $this->createConnectionWithClient($client);
        // unlink should NOT be called when no keys found
        $connection->shouldNotReceive('unlink');

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:nonexistent:*');

        $this->assertSame(0, $deletedCount);
    }

    public function testFlushHandlesOptPrefixCorrectly(): void
    {
        // Client has OPT_PREFIX set - SafeScan should handle this
        $client = new FakeRedisClient(
            scanResults: [
                // Redis returns keys WITH the OPT_PREFIX
                ['keys' => ['myapp:cache:test:key1', 'myapp:cache:test:key2'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
        );

        [$connection] = $this->createConnectionWithClient($client);
        // Keys passed to unlink should have OPT_PREFIX stripped
        // (phpredis will auto-add it back)
        $connection->shouldReceive('unlink')
            ->once()
            ->with('cache:test:key1', 'cache:test:key2')
            ->andReturn(2);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(2, $deletedCount);
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

        $client = new FakeRedisClient(
            scanResults: [
                // Return all keys in one scan result to simplify test
                ['keys' => array_merge($batch1Keys, $batch2Keys, $batch3Keys), 'iterator' => 0],
            ],
        );

        [$connection] = $this->createConnectionWithClient($client);

        // Should be called 3 times (1000 + 1000 + 500)
        $connection->shouldReceive('unlink')
            ->times(3)
            ->andReturn(1000, 1000, 500);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(2500, $deletedCount);
    }

    public function testFlushHandlesMultipleScanIterations(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1', 'cache:test:key2'], 'iterator' => 42],  // More to scan
                ['keys' => ['cache:test:key3'], 'iterator' => 0],  // Done
            ],
        );

        [$connection] = $this->createConnectionWithClient($client);
        // All keys should be collected and deleted together (under buffer size)
        $connection->shouldReceive('unlink')
            ->once()
            ->with('cache:test:key1', 'cache:test:key2', 'cache:test:key3')
            ->andReturn(3);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(3, $deletedCount);
    }

    public function testFlushHandlesUnlinkReturningNonInteger(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:test:key1'], 'iterator' => 0],
            ],
        );

        [$connection] = $this->createConnectionWithClient($client);
        // unlink might return false on error
        $connection->shouldReceive('unlink')
            ->once()
            ->andReturn(false);

        $flushByPattern = new FlushByPattern($connection);

        $deletedCount = $flushByPattern->execute('cache:test:*');

        $this->assertSame(0, $deletedCount);
    }

    public function testFlushPassesPatternToSafeScan(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        [$connection] = $this->createConnectionWithClient($client);

        $flushByPattern = new FlushByPattern($connection);

        $flushByPattern->execute('cache:users:*');

        // Verify the pattern was passed to scan
        $this->assertSame(1, $client->getScanCallCount());
        $this->assertSame('cache:users:*', $client->getScanCalls()[0]['pattern']);
    }
}
