<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Support;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;

/**
 * @internal
 * @coversNothing
 */
class SerializationTest extends TestCase
{
    private Serialization $serialization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serialization = new Serialization();
    }

    public function testSerializeReturnsRawValueWhenSerializerConfigured(): void
    {
        $connection = $this->createConnection(serialized: true);

        $this->assertSame('test-value', $this->serialization->serialize($connection, 'test-value'));
        $this->assertSame(123, $this->serialization->serialize($connection, 123));
        $this->assertSame(['foo' => 'bar'], $this->serialization->serialize($connection, ['foo' => 'bar']));
    }

    public function testSerializePhpSerializesWhenNoSerializerConfigured(): void
    {
        $connection = $this->createConnection(serialized: false);

        $this->assertSame(serialize('test-value'), $this->serialization->serialize($connection, 'test-value'));
        $this->assertSame(serialize(['foo' => 'bar']), $this->serialization->serialize($connection, ['foo' => 'bar']));
    }

    public function testSerializeReturnsRawNumericValues(): void
    {
        $connection = $this->createConnection(serialized: false);

        // Numeric values are returned raw for performance optimization
        $this->assertSame(123, $this->serialization->serialize($connection, 123));
        $this->assertSame(45.67, $this->serialization->serialize($connection, 45.67));
        $this->assertSame(0, $this->serialization->serialize($connection, 0));
        $this->assertSame(-99, $this->serialization->serialize($connection, -99));
    }

    public function testSerializeHandlesSpecialFloatValues(): void
    {
        $connection = $this->createConnection(serialized: false);

        // INF, -INF, and NaN should be serialized, not returned raw
        $this->assertSame(serialize(INF), $this->serialization->serialize($connection, INF));
        $this->assertSame(serialize(-INF), $this->serialization->serialize($connection, -INF));
        // NaN comparison is tricky - it serializes to a special representation
        $result = $this->serialization->serialize($connection, NAN);
        $this->assertIsString($result);
        $this->assertStringContainsString('NAN', $result);
    }

    public function testUnserializeReturnsNullForNullInput(): void
    {
        $connection = $this->createConnection(serialized: false);

        $this->assertNull($this->serialization->unserialize($connection, null));
    }

    public function testUnserializeReturnsNullForFalseInput(): void
    {
        $connection = $this->createConnection(serialized: false);

        $this->assertNull($this->serialization->unserialize($connection, false));
    }

    public function testUnserializeReturnsRawValueWhenSerializerConfigured(): void
    {
        $connection = $this->createConnection(serialized: true);

        $this->assertSame('test-value', $this->serialization->unserialize($connection, 'test-value'));
        $this->assertSame(['foo' => 'bar'], $this->serialization->unserialize($connection, ['foo' => 'bar']));
    }

    public function testUnserializePhpUnserializesWhenNoSerializerConfigured(): void
    {
        $connection = $this->createConnection(serialized: false);

        $this->assertSame('test-value', $this->serialization->unserialize($connection, serialize('test-value')));
        $this->assertSame(['foo' => 'bar'], $this->serialization->unserialize($connection, serialize(['foo' => 'bar'])));
    }

    public function testUnserializeReturnsNumericValuesRaw(): void
    {
        $connection = $this->createConnection(serialized: false);

        $this->assertSame(123, $this->serialization->unserialize($connection, 123));
        $this->assertSame(45.67, $this->serialization->unserialize($connection, 45.67));
        // Numeric strings are also returned raw
        $this->assertSame('123', $this->serialization->unserialize($connection, '123'));
        $this->assertSame('45.67', $this->serialization->unserialize($connection, '45.67'));
    }

    public function testSerializeForLuaUsesPackWhenSerializerConfigured(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('serialized')->andReturn(true);
        $connection->shouldReceive('pack')
            ->with(['test-value'])
            ->andReturn(['packed-value']);

        $this->assertSame('packed-value', $this->serialization->serializeForLua($connection, 'test-value'));
    }

    public function testSerializeForLuaAppliesCompressionWhenEnabled(): void
    {
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($client);
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_LZF);
        $client->shouldReceive('_serialize')
            ->with(serialize('test-value'))
            ->andReturn('compressed-value');

        $this->assertSame('compressed-value', $this->serialization->serializeForLua($connection, 'test-value'));
    }

    public function testSerializeForLuaReturnsPhpSerializedWhenNoSerializerOrCompression(): void
    {
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($client);
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        $this->assertSame(serialize('test-value'), $this->serialization->serializeForLua($connection, 'test-value'));
    }

    public function testSerializeForLuaCastsNumericValuesToString(): void
    {
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($client);
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        // Numeric values should be cast to string for Lua ARGV
        $this->assertSame('123', $this->serialization->serializeForLua($connection, 123));
        $this->assertSame('45.67', $this->serialization->serializeForLua($connection, 45.67));
    }

    public function testSerializeForLuaCastsNumericToStringWithCompression(): void
    {
        $connection = m::mock(RedisConnection::class);
        $client = m::mock(Redis::class);

        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($client);
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_LZF);
        // When compression is enabled, numeric strings get passed through _serialize
        $client->shouldReceive('_serialize')
            ->with('123')
            ->andReturn('compressed-123');

        $this->assertSame('compressed-123', $this->serialization->serializeForLua($connection, 123));
    }

    /**
     * Create a mock RedisConnection with the given serialized flag.
     */
    private function createConnection(bool $serialized = false): RedisConnection
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('serialized')->andReturn($serialized);

        return $connection;
    }
}
