<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;

/**
 * Tests for the Increment operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class IncrementTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testIncrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Lua script returns the incremented value
        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
                $this->assertStringContainsString('INCRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertSame(2, $numKeys);

                return true;
            })
            ->andReturn(15); // New value after increment

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->increment()->execute('counter', 5, ['stats']);
        $this->assertSame(15, $result);
    }
}
