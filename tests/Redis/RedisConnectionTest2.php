<?php

declare(strict_types=1);

namespace Hypervel\Redis\Tests;

use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;
use ReflectionClass;

/**
 * Tests for RedisConnection serialization and compression detection methods.
 *
 * @internal
 * @coversNothing
 */
class RedisConnectionTest2 extends TestCase
{
    /**
     * @test
     */
    public function testSerializedReturnsTrueWhenSerializerConfigured(): void
    {
        $connection = $this->createConnectionWithOptions([
            Redis::OPT_SERIALIZER => Redis::SERIALIZER_PHP,
        ]);

        $this->assertTrue($connection->serialized());
    }

    /**
     * @test
     */
    public function testSerializedReturnsFalseWhenNoSerializer(): void
    {
        $connection = $this->createConnectionWithOptions([
            Redis::OPT_SERIALIZER => Redis::SERIALIZER_NONE,
        ]);

        $this->assertFalse($connection->serialized());
    }

    /**
     * @test
     */
    public function testCompressedReturnsTrueWhenCompressionConfigured(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis::COMPRESSION_LZF is not defined.');
        }

        $connection = $this->createConnectionWithOptions([
            Redis::OPT_COMPRESSION => Redis::COMPRESSION_LZF,
        ]);

        $this->assertTrue($connection->compressed());
    }

    /**
     * @test
     */
    public function testCompressedReturnsFalseWhenNoCompression(): void
    {
        $connection = $this->createConnectionWithOptions([
            Redis::OPT_COMPRESSION => Redis::COMPRESSION_NONE,
        ]);

        $this->assertFalse($connection->compressed());
    }

    /**
     * @test
     */
    public function testPackReturnsEmptyArrayForEmptyInput(): void
    {
        $client = m::mock(Redis::class);
        $connection = $this->createConnectionWithClient($client);

        $result = $connection->pack([]);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function testPackUsesNativePackMethod(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('_pack')
            ->with('value1')
            ->once()
            ->andReturn('packed1');
        $client->shouldReceive('_pack')
            ->with('value2')
            ->once()
            ->andReturn('packed2');

        $connection = $this->createConnectionWithClient($client);

        $result = $connection->pack(['value1', 'value2']);

        $this->assertSame(['packed1', 'packed2'], $result);
    }

    /**
     * @test
     */
    public function testPackPreservesArrayKeys(): void
    {
        $client = m::mock(Redis::class);
        $client->shouldReceive('_pack')
            ->with('value1')
            ->once()
            ->andReturn('packed1');
        $client->shouldReceive('_pack')
            ->with('value2')
            ->once()
            ->andReturn('packed2');

        $connection = $this->createConnectionWithClient($client);

        $result = $connection->pack(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertSame([
            'key1' => 'packed1',
            'key2' => 'packed2',
        ], $result);
    }

    /**
     * @test
     */
    public function testClientReturnsUnderlyingRedisInstance(): void
    {
        $client = m::mock(Redis::class);
        $connection = $this->createConnectionWithClient($client);

        $this->assertSame($client, $connection->client());
    }

    /**
     * Create a RedisConnection with a mocked client that returns specific options.
     *
     * @param array<int, int> $options Map of Redis option constants to values
     */
    private function createConnectionWithOptions(array $options): RedisConnection
    {
        $client = m::mock(Redis::class);

        foreach ($options as $option => $value) {
            $client->shouldReceive('getOption')
                ->with($option)
                ->andReturn($value);
        }

        return $this->createConnectionWithClient($client);
    }

    /**
     * Create a RedisConnection with the given client.
     */
    private function createConnectionWithClient(mixed $client): RedisConnection
    {
        /** @var RedisConnection $connection */
        $connection = m::mock(RedisConnection::class)->makePartial();

        $reflection = new ReflectionClass(RedisConnection::class);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue($connection, $client);

        return $connection;
    }
}
