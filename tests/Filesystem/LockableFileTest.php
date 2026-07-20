<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Contracts\Filesystem\LockTimeoutException;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\LockableFile;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;

class LockableFileTest extends TestCase
{
    private const STREAM_SCHEME = 'hypervel-lockable-file-test';

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('LockableFileTest');
        mkdir($this->tempDir, 0777, true);

        LockableFileTestStreamWrapper::reset();
        stream_wrapper_register(self::STREAM_SCHEME, LockableFileTestStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister(self::STREAM_SCHEME);
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testBlockingLocksSerializeAccess(): void
    {
        $path = $this->tempDir . '/blocking.lock';
        $owner = new LockableFile($path, 'c+');
        $owner->getExclusiveLock();
        $started = new Channel(1);
        $acquired = new Channel(1);

        try {
            Coroutine::create(static function () use ($path, $started, $acquired): void {
                $waiter = new LockableFile($path, 'c+');
                $started->push(true);

                try {
                    $waiter->getExclusiveLock(true);
                } finally {
                    $waiter->close();
                }

                $acquired->push(true);
            });

            $this->assertTrue($started->pop(1.0));
            $this->assertFalse($acquired->pop(0.01));

            $owner->releaseLock();

            $this->assertTrue($acquired->pop(1.0));
        } finally {
            $owner->close();
        }
    }

    public function testNonblockingLocksDoNotWaitBehindBlockingWaiters(): void
    {
        $path = $this->tempDir . '/nonblocking.lock';
        $owner = new LockableFile($path, 'c+');
        $owner->getExclusiveLock();
        $waiterStarted = new Channel(1);
        $waiterFinished = new Channel(1);
        $attemptFinished = new Channel(1);

        try {
            Coroutine::create(static function () use ($path, $waiterStarted, $waiterFinished): void {
                $waiter = new LockableFile($path, 'c+');
                $waiterStarted->push(true);

                try {
                    $waiter->getExclusiveLock(true);
                } finally {
                    $waiter->close();
                    $waiterFinished->push(true);
                }
            });

            $this->assertTrue($waiterStarted->pop(1.0));
            usleep(10_000);

            Coroutine::create(static function () use ($path, $attemptFinished): void {
                $attempt = new LockableFile($path, 'c+');

                try {
                    $attempt->getExclusiveLock();
                    $attemptFinished->push(false);
                } catch (LockTimeoutException) {
                    $attemptFinished->push(true);
                } finally {
                    $attempt->close();
                }
            });

            $this->assertTrue($attemptFinished->pop(0.1));
        } finally {
            $owner->close();
        }

        $this->assertTrue($waiterFinished->pop(1.0));
    }

    public function testCancelingBlockingWaiterDoesNotReleaseOwnerLock(): void
    {
        $path = $this->tempDir . '/canceled.lock';
        $owner = new LockableFile($path, 'c+');
        $owner->getExclusiveLock();
        $waiterStarted = new Channel(1);
        $waiterFinished = new Channel(1);

        try {
            $waiterId = Coroutine::create(static function () use ($path, $waiterStarted, $waiterFinished): void {
                $waiter = new LockableFile($path, 'c+');
                $waiterStarted->push(true);

                try {
                    $waiter->getExclusiveLock(true);
                } catch (CanceledException) {
                    // Expected terminal cancellation while waiting for the native lock.
                } finally {
                    $waiter->close();
                    $waiterFinished->push(true);
                }
            });

            $this->assertTrue($waiterStarted->pop(1.0));
            usleep(10_000);
            $this->assertTrue(SwooleCoroutine::cancel($waiterId, true));
            $this->assertTrue($waiterFinished->pop(1.0));

            $attempt = new LockableFile($path, 'c+');

            try {
                $this->expectException(LockTimeoutException::class);
                $attempt->getExclusiveLock();
            } finally {
                $attempt->close();
            }
        } finally {
            $owner->close();
        }
    }

    public function testSizeUsesTheOpenFileHandle(): void
    {
        $path = $this->tempDir . '/size.txt';
        file_put_contents($path, 'old');

        $file = new LockableFile($path, 'r+');

        try {
            rename($path, $path . '.open');
            file_put_contents($path, 'replacement');

            $this->assertSame(3, $file->size());
        } finally {
            $file->close();
        }
    }

    public function testWriteCompletesPartialWrites(): void
    {
        LockableFileTestStreamWrapper::$maximumWrite = 2;
        $file = new LockableFileWithoutDirectoryCheck(self::STREAM_SCHEME . '://partial', 'w+');

        $file->write('abcdef')->close();

        $this->assertSame('abcdef', LockableFileTestStreamWrapper::$contents);
    }

    public function testWriteFailsWhenTheStreamMakesNoProgress(): void
    {
        LockableFileTestStreamWrapper::$maximumWrite = 2;
        LockableFileTestStreamWrapper::$zeroWriteAfter = 2;
        $file = new LockableFileWithoutDirectoryCheck(self::STREAM_SCHEME . '://zero-write', 'w+');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to write to file');

            $file->write('abcdef');
        } finally {
            $file->close();
        }
    }

    public function testCloseStillClosesTheHandleWhenUnlockFails(): void
    {
        LockableFileTestStreamWrapper::$failUnlock = true;
        $file = new LockableFileWithoutDirectoryCheck(self::STREAM_SCHEME . '://unlock-failure', 'w+');
        $file->getExclusiveLock();

        try {
            $file->close();
            $this->fail('The failed unlock should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unable to release file lock', $exception->getMessage());
        }

        $this->assertTrue(LockableFileTestStreamWrapper::$closed);
    }

    public function testConstructorFailsWhenTheDirectoryCannotBeCreated(): void
    {
        $parent = $this->tempDir . '/file';
        file_put_contents($parent, 'contents');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create directory');

        new LockableFile($parent . '/child', 'c+');
    }
}

class LockableFileWithoutDirectoryCheck extends LockableFile
{
    /**
     * Skip directory creation for the test stream wrapper.
     */
    protected function ensureDirectoryExists(string $path): void
    {
    }
}

class LockableFileTestStreamWrapper
{
    public mixed $context;

    public static string $contents = '';

    public static int $maximumWrite = PHP_INT_MAX;

    public static ?int $zeroWriteAfter = null;

    public static bool $failUnlock = false;

    public static bool $closed = false;

    public static function reset(): void
    {
        self::$contents = '';
        self::$maximumWrite = PHP_INT_MAX;
        self::$zeroWriteAfter = null;
        self::$failUnlock = false;
        self::$closed = false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$zeroWriteAfter !== null && strlen(self::$contents) >= self::$zeroWriteAfter) {
            return 0;
        }

        $written = min(strlen($data), self::$maximumWrite);
        self::$contents .= substr($data, 0, $written);

        return $written;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return $operation !== LOCK_UN || ! self::$failUnlock;
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}
