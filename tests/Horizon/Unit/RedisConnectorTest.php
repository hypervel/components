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
    public function testConnectUsesDefaultMigrationBatchSizeWhenOmitted(): void
    {
        $redis = m::mock(Redis::class);
        $connector = new RedisConnector($redis);

        $queue = $connector->connect([
            'queue' => 'default',
            'connection' => 'queue',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ]);

        $this->assertInstanceOf(RedisQueue::class, $queue);
        $this->assertSame(RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE, (new ClassInvoker($queue))->migrationBatchSize);
    }

    public function testConnectUsesConfiguredMigrationBatchSize(): void
    {
        $queue = (new RedisConnector(m::mock(Redis::class)))->connect([
            'queue' => 'default',
            'connection' => 'queue',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
            'migration_batch_size' => 100,
        ]);

        $this->assertSame(100, (new ClassInvoker($queue))->migrationBatchSize);
    }
}
