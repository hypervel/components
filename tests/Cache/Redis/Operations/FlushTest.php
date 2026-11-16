<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\Flush;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;

/**
 * Tests for the Flush operation.
 */
class FlushTest extends RedisCacheTestCase
{
    public function testFlushesCached(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('flushdb')->returns(true);

        $redis = $this->createStore($connection);
        $result = $redis->flush();
        $this->assertTrue($result);
    }

    public function testFlushEnablesTransformsAndPropagatesFailure(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('flushdb')->returns(false);
        $context = Double::for(StoreContext::class);
        $context->expects('withConnection')
            ->with(Argument::type('callable'), true)
            ->resolves(fn (callable $callback) => $callback($connection->shouldTransform(true)));

        $this->assertFalse((new Flush($context))->execute());
    }
}
