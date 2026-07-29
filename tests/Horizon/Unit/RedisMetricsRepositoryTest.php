<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Repositories\RedisMetricsRepository;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisMetricsRepositoryTest extends UnitTestCase
{
    public function testClearUsesOneRawConnectionLeaseForTheWholeSweep(): void
    {
        $rawConnection = m::mock(RedisConnection::class);
        $rawConnection->shouldReceive('del')
            ->once()
            ->with('last_snapshot_at', 'measured_jobs', 'measured_queues', 'metrics:snapshot');
        $rawConnection->shouldReceive('flushByPattern')->once()->with('queue:*');
        $rawConnection->shouldReceive('flushByPattern')->once()->with('job:*');
        $rawConnection->shouldReceive('flushByPattern')->once()->with('snapshot:*');

        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('withConnection')
            ->once()
            ->withArgs(function (callable $callback, bool $transform) use ($rawConnection): bool {
                $this->assertFalse($transform);
                $callback($rawConnection);

                return true;
            });

        (new RedisMetricsRepository($this->redisFactory($connection)))->clear();
    }

    public function testMissingSnapshotHashFieldsRemainFalse(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturn([[
                'throughput' => false,
                'runtime' => false,
            ], 0]);

        $repository = new TestRedisMetricsRepository($this->redisFactory($connection));

        $this->assertSame([
            'throughput' => false,
            'runtime' => false,
        ], $repository->baseSnapshotDataForTest('queue:default'));
    }
}

class TestRedisMetricsRepository extends RedisMetricsRepository
{
    public function baseSnapshotDataForTest(string $key): array
    {
        return $this->baseSnapshotData($key);
    }
}
