<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Increment operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class IncrementTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testIncrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Lua script returns the incremented value
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                $this->assertStringContainsString('INCRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(15); // New value after increment

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->increment()->execute('counter', 5, ['stats']);
        $this->assertSame(15, $result);
    }
}
