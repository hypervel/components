<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use BadMethodCallException;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisManager;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Redis as PhpRedis;

class RedisResetIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testPooledResetIsRejectedBeforeNativePipelineFatal(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithOptions(
            'reset_pipeline',
            ['prefix' => ''],
        ));
        $pipeline = $redis->pipeline();

        $this->assertInstanceOf(PhpRedis::class, $pipeline);
        $this->assertSame(PhpRedis::PIPELINE, $pipeline->getMode());

        try {
            $redis->reset();

            $this->fail('Expected pooled RESET to be rejected.');
        } catch (BadMethodCallException $exception) {
            $this->assertStringContainsString(
                'Cannot call reset() on a pooled Redis connection',
                $exception->getMessage(),
            );
        } finally {
            $this->app->make(RedisManager::class)->releaseConnections();
        }
    }
}
