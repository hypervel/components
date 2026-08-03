<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Lock;
use Hypervel\Tests\TestCase;
use RuntimeException;

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
}

class FailingReleaseLock extends Lock
{
    public bool $released = false;

    public function __construct()
    {
        parent::__construct('lock', 10, 'owner');
    }

    public function acquire(): bool
    {
        return true;
    }

    public function release(): bool
    {
        $this->released = true;

        throw new RuntimeException('release failure');
    }

    public function forceRelease(): void
    {
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->owner;
    }
}
