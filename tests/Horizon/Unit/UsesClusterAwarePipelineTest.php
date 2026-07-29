<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Repositories\UsesClusterAwarePipeline;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class UsesClusterAwarePipelineTest extends UnitTestCase
{
    public function testStandaloneConnectionUsesPipeline(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('pipeline')->once()->andReturn(['pipeline']);
        $connection->shouldNotReceive('transaction');

        $this->assertSame(
            ['pipeline'],
            (new ClusterAwarePipelineHarness($connection))->execute(static function (): void {
            }),
        );
    }

    public function testClusterConnectionUsesTransaction(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('transaction')->once()->andReturn(['transaction']);
        $connection->shouldNotReceive('pipeline');

        $this->assertSame(
            ['transaction'],
            (new ClusterAwarePipelineHarness($connection))->execute(static function (): void {
            }),
        );
    }
}

class ClusterAwarePipelineHarness
{
    use UsesClusterAwarePipeline;

    public function __construct(
        private RedisProxy $redis
    ) {
    }

    public function execute(callable $callback): array
    {
        return $this->pipeline($callback);
    }

    protected function connection(): RedisProxy
    {
        return $this->redis;
    }
}
