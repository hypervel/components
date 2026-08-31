<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Engine\Coroutine;
use Hypervel\Redis\RedisCancellation;
use Hypervel\Tests\TestCase;
use RedisClusterException;
use RedisException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;

class RedisCancellationTest extends TestCase
{
    public function testItPreservesExactCancellation(): void
    {
        $cancellation = new CanceledException('canceled');

        $this->assertSame($cancellation, RedisCancellation::cancellationFrom($cancellation, 'boundary canceled'));
    }

    public function testItDoesNotClassifyOrdinaryFailures(): void
    {
        $this->assertNull(RedisCancellation::cancellationFrom(new RedisException('failed'), 'boundary canceled'));
        $this->assertNull(RedisCancellation::cancellationFrom(new RedisClusterException('failed'), 'boundary canceled'));
        $this->assertNull(RedisCancellation::cancellationFrom(new RuntimeException('failed'), 'boundary canceled'));
    }

    public function testItNormalizesOnlyNativePhpRedisFailuresAtTheCancellationBoundary(): void
    {
        $blocker = new Channel(1);
        $cancellations = [];
        $failures = [
            new RedisException('read canceled'),
            new RedisClusterException('cluster read canceled'),
        ];

        $coroutine = Coroutine::create(function () use ($blocker, $failures, &$cancellations): void {
            try {
                $blocker->pop();
            } catch (CanceledException) {
                foreach ($failures as $failure) {
                    $cancellations[] = RedisCancellation::cancellationFrom($failure, 'Reading from Redis was canceled.');
                }

                $cancellations[] = RedisCancellation::cancellationFrom(
                    new RuntimeException('failed'),
                    'Reading from Redis was canceled.',
                );
            }
        });

        $this->assertTrue(Coroutine::cancelById($coroutine->getId(), throwException: true));
        $this->assertCount(3, $cancellations);
        $this->assertInstanceOf(CanceledException::class, $cancellations[0]);
        $this->assertSame('Reading from Redis was canceled.', $cancellations[0]->getMessage());
        $this->assertSame($failures[0], $cancellations[0]->getPrevious());
        $this->assertInstanceOf(CanceledException::class, $cancellations[1]);
        $this->assertSame('Reading from Redis was canceled.', $cancellations[1]->getMessage());
        $this->assertSame($failures[1], $cancellations[1]->getPrevious());
        $this->assertNull($cancellations[2]);
    }
}
