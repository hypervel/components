<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Repositories\RedisMetricsRepository;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;

class RedisMetricsRepositoryTest extends UnitTestCase
{
    public function testClearUsesOneRawConnectionLeaseForTheWholeSweep(): void
    {
        [$connection, $client, $rawConnection] = $this->redisConnection();
        $client->expects('del')
            ->with('last_snapshot_at', 'measured_jobs', 'measured_queues', 'metrics:snapshot');
        $rawConnection->expects('flushByPattern')->with('queue:*');
        $rawConnection->expects('flushByPattern')->with('job:*');
        $rawConnection->expects('flushByPattern')->with('snapshot:*');

        (new RedisMetricsRepository($this->redisFactory($connection)))->clear();

        $connection->received('withConnection')->with(Argument::type('callable'), false)->times(1);
    }

    public function testMissingSnapshotHashFieldsRemainFalse(): void
    {
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('transaction')
            ->returns([[
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
