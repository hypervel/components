<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;

/**
 * Tests for the Decrement operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class DecrementTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testDecrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $client->shouldReceive('evalSha')
            ->once()
            ->andReturn(false);
        $client->shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $args, $numKeys) {
                $this->assertStringContainsString('DECRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertSame(2, $numKeys);

                return true;
            })
            ->andReturn(5); // New value after decrement

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']);
        $this->assertSame(5, $result);
    }
}
