<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Operations;

use Hypervel\Redis\Operations\SafeScan;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Redis\Stub\FakeRedisClient;
use Hypervel\Tests\Redis\Stubs\RedisConnectionStub;
use Hypervel\Tests\TestCase;

/**
 * Tests for SafeScan - memory-efficient SCAN with OPT_PREFIX handling.
 *
 * @internal
 * @coversNothing
 */
class SafeScanTest extends TestCase
{
    /**
     * Create a RedisConnection wrapping a FakeRedisClient for testing.
     */
    private function createConnection(FakeRedisClient $client): RedisConnection
    {
        $connection = new RedisConnectionStub();
        $connection->setActiveConnection($client);

        return $connection;
    }

    public function testScanReturnsMatchingKeys(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:users:1', 'cache:users:2'], 'iterator' => 0],
            ],
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        $keys = iterator_to_array($safeScan->execute('cache:users:*'));

        $this->assertSame(['cache:users:1', 'cache:users:2'], $keys);

        // Verify scan was called with correct pattern
        $this->assertSame(1, $client->getScanCallCount());
        $this->assertSame('cache:users:*', $client->getScanCalls()[0]['pattern']);
    }

    public function testScanPrependsOptPrefixToPattern(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['myapp:cache:users:1'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
        );

        $safeScan = new SafeScan($this->createConnection($client), 'myapp:');
        $keys = iterator_to_array($safeScan->execute('cache:users:*'));

        // Returned keys should have prefix stripped
        $this->assertSame(['cache:users:1'], $keys);

        // Verify scan was called with OPT_PREFIX prepended to pattern
        $this->assertSame('myapp:cache:users:*', $client->getScanCalls()[0]['pattern']);
    }

    public function testScanStripsOptPrefixFromReturnedKeys(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['prefix:cache:key1', 'prefix:cache:key2', 'prefix:cache:key3'], 'iterator' => 0],
            ],
            optPrefix: 'prefix:',
        );

        $safeScan = new SafeScan($this->createConnection($client), 'prefix:');
        $keys = iterator_to_array($safeScan->execute('cache:*'));

        // Keys should have prefix stripped so they work with other phpredis commands
        $this->assertSame(['cache:key1', 'cache:key2', 'cache:key3'], $keys);
    }

    public function testScanHandlesEmptyResults(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        $keys = iterator_to_array($safeScan->execute('cache:nonexistent:*'));

        $this->assertSame([], $keys);
    }

    public function testScanHandlesFalseResult(): void
    {
        // FakeRedisClient returns false when no more results configured
        $client = new FakeRedisClient(
            scanResults: [],  // No results configured
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        $keys = iterator_to_array($safeScan->execute('cache:*'));

        $this->assertSame([], $keys);
    }

    public function testScanIteratesMultipleBatches(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:key1', 'cache:key2'], 'iterator' => 42],  // More to scan
                ['keys' => ['cache:key3'], 'iterator' => 0],                  // Done
            ],
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        $keys = iterator_to_array($safeScan->execute('cache:*'));

        $this->assertSame(['cache:key1', 'cache:key2', 'cache:key3'], $keys);
        $this->assertSame(2, $client->getScanCallCount());
    }

    public function testScanDoesNotDoublePrefixWhenPatternAlreadyHasPrefix(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['myapp:cache:key1'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
        );

        $safeScan = new SafeScan($this->createConnection($client), 'myapp:');

        // Pattern already has prefix - should NOT add it again
        $keys = iterator_to_array($safeScan->execute('myapp:cache:*'));

        // Should strip prefix from result
        $this->assertSame(['cache:key1'], $keys);

        // Pattern should NOT be double-prefixed
        $this->assertSame('myapp:cache:*', $client->getScanCalls()[0]['pattern']);
    }

    public function testScanReturnsKeyAsIsWhenItDoesNotHavePrefix(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                // Edge case: Redis somehow returns key without expected prefix
                ['keys' => ['other:key1'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
        );

        $safeScan = new SafeScan($this->createConnection($client), 'myapp:');
        $keys = iterator_to_array($safeScan->execute('cache:*'));

        // Key should be returned as-is since it doesn't have the prefix
        $this->assertSame(['other:key1'], $keys);
    }

    public function testScanUsesCustomCount(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:key1'], 'iterator' => 0],
            ],
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        $keys = iterator_to_array($safeScan->execute('cache:*', 500));

        $this->assertSame(['cache:key1'], $keys);
        $this->assertSame(500, $client->getScanCalls()[0]['count']);
    }

    public function testScanWorksWithEmptyOptPrefix(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['cache:key1', 'cache:key2'], 'iterator' => 0],
            ],
            optPrefix: '',  // No prefix configured
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        $keys = iterator_to_array($safeScan->execute('cache:*'));

        // No stripping needed when no prefix
        $this->assertSame(['cache:key1', 'cache:key2'], $keys);
    }

    public function testScanHandlesMixedPrefixedAndUnprefixedKeys(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => ['myapp:cache:key1', 'other:key2', 'myapp:cache:key3'], 'iterator' => 0],
            ],
            optPrefix: 'myapp:',
        );

        $safeScan = new SafeScan($this->createConnection($client), 'myapp:');
        $keys = iterator_to_array($safeScan->execute('cache:*'));

        // Prefixed keys stripped, unprefixed returned as-is
        $this->assertSame(['cache:key1', 'other:key2', 'cache:key3'], $keys);
    }

    public function testScanDefaultCountIs1000(): void
    {
        $client = new FakeRedisClient(
            scanResults: [
                ['keys' => [], 'iterator' => 0],
            ],
        );

        $safeScan = new SafeScan($this->createConnection($client), '');
        iterator_to_array($safeScan->execute('cache:*'));

        $this->assertSame(1000, $client->getScanCalls()[0]['count']);
    }
}
