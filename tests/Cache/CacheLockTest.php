<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Lock;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class CacheLockTest extends TestCase
{
    public function testGetPreservesCallbackFailureWhenReleaseAlsoFails(): void
    {
        $lock = new FailingReleaseLock;

        try {
            $lock->get(fn () => throw new RuntimeException('callback failure'));

            $this->fail('Expected the callback failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failure', $exception->getMessage());
        }

        $this->assertTrue($lock->released);
    }

    public function testGetPropagatesReleaseFailureAfterSuccessfulCallback(): void
    {
        $lock = new FailingReleaseLock;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('release failure');

        $lock->get(fn () => 'result');
    }

    public function testGetPreservesCallbackCancellationWhenReleaseIsCanceled(): void
    {
        $callbackCancellation = new CanceledException('callback canceled');
        $lock = new FailingReleaseLock(new CanceledException('release canceled'));

        try {
            $lock->get(fn () => throw $callbackCancellation);
            $this->fail('Expected the callback cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($callbackCancellation, $exception);
        }

        $this->assertTrue($lock->released);
    }

    public function testGetReleaseCancellationSupersedesOrdinaryCallbackFailure(): void
    {
        $releaseCancellation = new CanceledException('release canceled');
        $lock = new FailingReleaseLock($releaseCancellation);

        try {
            $lock->get(fn () => throw new RuntimeException('callback failure'));
            $this->fail('Expected the release cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($releaseCancellation, $exception);
        }
    }

    public function testBlockPreservesCallbackFailureWhenReleaseAlsoFails(): void
    {
        $lock = new FailingReleaseLock;

        try {
            $lock->block(0, fn () => throw new RuntimeException('callback failure'));

            $this->fail('Expected the callback failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failure', $exception->getMessage());
        }

        $this->assertTrue($lock->released);
    }

    public function testBlockPropagatesReleaseFailureAfterSuccessfulCallback(): void
    {
        $lock = new FailingReleaseLock;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('release failure');

        $lock->block(0, fn () => 'result');
    }

    public function testBlockPreservesCallbackCancellationWhenReleaseIsCanceled(): void
    {
        $callbackCancellation = new CanceledException('callback canceled');
        $lock = new FailingReleaseLock(new CanceledException('release canceled'));

        try {
            $lock->block(0, fn () => throw $callbackCancellation);
            $this->fail('Expected the callback cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($callbackCancellation, $exception);
        }

        $this->assertTrue($lock->released);
    }

    public function testBlockReleaseCancellationSupersedesOrdinaryCallbackFailure(): void
    {
        $releaseCancellation = new CanceledException('release canceled');
        $lock = new FailingReleaseLock($releaseCancellation);

        try {
            $lock->block(0, fn () => throw new RuntimeException('callback failure'));
            $this->fail('Expected the release cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($releaseCancellation, $exception);
        }
    }
}

class FailingReleaseLock extends Lock
{
    public bool $released = false;

    private Throwable $releaseFailure;

    public function __construct(?Throwable $releaseFailure = null)
    {
        parent::__construct('lock', 10, 'owner');

        $this->releaseFailure = $releaseFailure ?? new RuntimeException('release failure');
    }

    public function acquire(): bool
    {
        return true;
    }

    public function release(): bool
    {
        $this->released = true;

        throw $this->releaseFailure;
    }

    public function forceRelease(): void
    {
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->owner;
    }
}
