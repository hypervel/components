<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Repositories\UsesClusterAwarePipeline;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;

class UsesClusterAwarePipelineTest extends UnitTestCase
{
    public function testStandaloneConnectionUsesPipeline(): void
    {
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('isCluster')->returns(false);
        $connection->expects('pipeline')->returns(['pipeline']);
        $connection->expects('transaction')->never();

        $this->assertSame(
            ['pipeline'],
            (new ClusterAwarePipelineHarness($connection))->execute(static function (): void {
            }),
        );
    }

    public function testClusterConnectionUsesTransaction(): void
    {
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('isCluster')->returns(true);
        $connection->expects('transaction')->returns(['transaction']);
        $connection->expects('pipeline')->never();

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
