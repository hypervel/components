<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Engine\Coroutine;
use Hypervel\Redis\RedisCancellation;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RedisClusterException;
use RedisException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;

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

    #[TestWith([true, false], 'operation cancellation')]
    #[TestWith([false, true], 'cleanup cancellation')]
    #[TestWith([true, true], 'first cancellation')]
    #[TestWith([false, false], 'ordinary cleanup precedence')]
    public function testFailureSelectionPreservesWrappedCancellation(bool $operationCanceled, bool $cleanupCanceled): void
    {
        $operation = new RuntimeException('Operation failed.', previous: $operationCanceled ? new CanceledException : null);
        $cleanup = new RuntimeException('Cleanup failed.', previous: $cleanupCanceled ? new CanceledException : null);
        $caught = null;

        try {
            RedisCancellation::throwOperationOrCleanupFailure($operation, $cleanup);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($operationCanceled ? $operation : $cleanup, $caught);
    }

    public function testItNormalizesOnlyPhpRedisFailuresCarryingACancellation(): void
    {
        $failures = [
            new RedisException('read canceled', previous: new CanceledException),
            new RedisClusterException('cluster read canceled', previous: new CanceledException),
        ];

        foreach ($failures as $failure) {
            $cancellation = RedisCancellation::cancellationFrom($failure, 'Reading from Redis was canceled.');

            $this->assertInstanceOf(CanceledException::class, $cancellation);
            $this->assertSame('Reading from Redis was canceled.', $cancellation->getMessage());
            $this->assertSame($failure, $cancellation->getPrevious());
        }

        $this->assertNull(RedisCancellation::cancellationFrom(
            new RuntimeException('failed', previous: new CanceledException),
            'Reading from Redis was canceled.',
        ));
    }

    public function testItDoesNotClassifyAFailureAfterAHandledCancellation(): void
    {
        $coroutine = Coroutine::id();
        Coroutine::create(static function () use ($coroutine): void {
            usleep(10000);
            Coroutine::cancelById($coroutine);
        });

        $this->assertFalse((new Channel(1))->pop(1));
        $this->assertTrue(Coroutine::isCanceled());
        swoole_clear_error();

        $this->assertNull(RedisCancellation::cancellationFrom(new RedisException('failed'), 'Reading from Redis was canceled.'));
    }
}
