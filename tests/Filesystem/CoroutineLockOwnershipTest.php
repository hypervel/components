<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\LockableFile;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use LogicException;
use Swoole\Coroutine as SwooleCoroutine;

class CoroutineLockOwnershipTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('CoroutineLockOwnershipTest');
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testCanceledFilesystemWaiterDoesNotReleaseTheOwner(): void
    {
        $filesystem = new CoroutineLockOwnershipFilesystem($this->tempDir . '/filesystem.lock');

        $this->assertCanceledWaiterDoesNotReleaseTheOwner(
            static fn (Closure $callback): mixed => $filesystem->runAtomic($callback),
        );
    }

    public function testCanceledLockableFileWaiterDoesNotReleaseTheOwner(): void
    {
        $file = new CoroutineLockOwnershipFile($this->tempDir . '/lockable-file.lock', 'c+');

        try {
            $this->assertCanceledWaiterDoesNotReleaseTheOwner(
                static fn (Closure $callback): mixed => $file->runAtomic($callback),
            );
        } finally {
            $file->close();
        }
    }

    /**
     * Assert a canceled waiter cannot release a lock acquired by another coroutine.
     */
    private function assertCanceledWaiterDoesNotReleaseTheOwner(Closure $atomic): void
    {
        $ownerEntered = new Channel(1);
        $releaseOwner = new Channel(1);
        $ownerExited = new Channel(1);
        $thirdEntered = new Channel(1);

        try {
            Coroutine::create(static function () use ($atomic, $ownerEntered, $releaseOwner, $ownerExited): void {
                try {
                    $atomic(static function () use ($ownerEntered, $releaseOwner): void {
                        $ownerEntered->push(true);
                        $releaseOwner->pop();
                    });
                } finally {
                    $ownerExited->push(true);
                }
            });

            $this->assertTrue($ownerEntered->pop(1.0));

            $waiter = Coroutine::create(static function () use ($atomic): void {
                $atomic(static function (): void {
                    throw new LogicException('The canceled waiter must not acquire the lock.');
                });
            });

            $this->assertTrue(SwooleCoroutine::cancel($waiter, true));

            Coroutine::create(static function () use ($atomic, $thirdEntered): void {
                $atomic(static fn (): bool => $thirdEntered->push(true));
            });

            $this->assertFalse($thirdEntered->pop(0.005));

            $releaseOwner->push(true);

            $this->assertTrue($ownerExited->pop(1.0));
            $this->assertTrue($thirdEntered->pop(1.0));
        } finally {
            if ($releaseOwner->getLength() === 0) {
                $releaseOwner->push(true, 0.001);
            }
        }
    }
}

class CoroutineLockOwnershipFilesystem extends Filesystem
{
    public function __construct(private string $lockPath)
    {
    }

    /**
     * Execute a callback through the filesystem atomic boundary.
     */
    public function runAtomic(Closure $callback): mixed
    {
        return $this->atomic($this->lockPath, static fn (): mixed => $callback());
    }
}

class CoroutineLockOwnershipFile extends LockableFile
{
    /**
     * Execute a callback through the lockable-file atomic boundary.
     */
    public function runAtomic(Closure $callback): mixed
    {
        return $this->atomic($callback);
    }
}
