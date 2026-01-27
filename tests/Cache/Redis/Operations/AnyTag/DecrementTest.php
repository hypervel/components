<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Decrement operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class DecrementTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testDecrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                $this->assertStringContainsString('DECRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(5); // New value after decrement

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']);
        $this->assertSame(5, $result);
    }
}
