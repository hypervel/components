<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\Connectors\RedisConnector;
use Hypervel\Queue\RedisQueue;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;

class QueueRedisConnectorTest extends TestCase
{
    public function testConnectUsesDefaultMigrationBatchSizeWhenOmitted(): void
    {
        $queue = (new RedisConnector(m::mock(Redis::class)))->connect([
            'queue' => 'default',
            'connection' => 'queue',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => true,
        ]);

        $this->assertSame(RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE, (new ClassInvoker($queue))->migrationBatchSize);
    }

    public function testConnectUsesConfiguredMigrationBatchSize(): void
    {
        $queue = (new RedisConnector(m::mock(Redis::class)))->connect([
            'queue' => 'default',
            'connection' => 'queue',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => true,
            'migration_batch_size' => 100,
        ]);

        $this->assertSame(100, (new ClassInvoker($queue))->migrationBatchSize);
    }
}
