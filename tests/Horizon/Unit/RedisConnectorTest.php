<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Horizon\Connectors\RedisConnector;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisConnectorTest extends UnitTestCase
{
    public function testConnectUsesOptionalMemberDefaultsWhenOmitted(): void
    {
        $redis = m::mock(Redis::class);
        $connector = new RedisConnector($redis, 'queue');

        $queue = $connector->connect([
            'queue' => 'default',
        ]);
        $properties = new ClassInvoker($queue);

        $this->assertInstanceOf(RedisQueue::class, $queue);
        $this->assertSame('queue', $properties->connection);
        $this->assertSame(RedisQueue::DEFAULT_RETRY_AFTER, $properties->retryAfter);
        $this->assertNull($properties->blockFor);
        $this->assertTrue($properties->dispatchAfterCommit);
        $this->assertSame(RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE, $properties->migrationBatchSize);
    }

    public function testConnectUsesConfiguredMigrationBatchSize(): void
    {
        $queue = (new RedisConnector(m::mock(Redis::class)))->connect([
            'queue' => 'default',
            'migration_batch_size' => 100,
        ]);

        $this->assertSame(100, (new ClassInvoker($queue))->migrationBatchSize);
    }
}
