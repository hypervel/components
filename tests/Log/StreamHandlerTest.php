<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use ErrorException;
use Hypervel\Engine\Channel;
use Hypervel\Log\Handlers\RotatingFileHandler;
use Hypervel\Log\Handlers\StreamHandler;
use Hypervel\Tests\TestCase;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use UnexpectedValueException;

use function Hypervel\Coroutine\parallel;

class StreamHandlerTest extends TestCase
{
    private const string STREAM_SCHEME = 'hypervel-log-test';

    protected function setUp(): void
    {
        parent::setUp();

        stream_wrapper_register(self::STREAM_SCHEME, LogStreamWrapper::class);
        LogStreamWrapper::reset();
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister(self::STREAM_SCHEME);

        parent::tearDown();
    }

    public function testYieldingOpenDoesNotCaptureASiblingCoroutineWarning(): void
    {
        LogStreamWrapper::$entered = new Channel(1);
        LogStreamWrapper::$release = new Channel(1);
        LogStreamWrapper::$failOpenAfterYield = true;

        $warnings = [];
        $exceptionMessage = null;
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message];

            return true;
        });

        try {
            parallel([
                function () use (&$exceptionMessage): void {
                    try {
                        (new Logger('test', [
                            new StreamHandler(self::STREAM_SCHEME . '://failing'),
                        ]))->error('message');
                    } catch (UnexpectedValueException $exception) {
                        $exceptionMessage = $exception->getMessage();
                    }
                },
                function (): void {
                    LogStreamWrapper::$entered->pop();
                    trigger_error('warning from sibling coroutine', E_USER_WARNING);
                    LogStreamWrapper::$release->push(true);
                },
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertContains([E_USER_WARNING, 'warning from sibling coroutine'], $warnings);
        $this->assertStringContainsString(self::STREAM_SCHEME . '://failing', $exceptionMessage);
        $this->assertStringNotContainsString('warning from sibling coroutine', $exceptionMessage);
    }

    public function testOpenFailureIsNormalizedWithReturningAndThrowingErrorHandlers(): void
    {
        LogStreamWrapper::$failOpen = true;
        $messages = [];

        set_error_handler(static fn (): bool => true);

        try {
            $messages[] = $this->captureOpenFailureMessage('failed record');
        } finally {
            restore_error_handler();
        }

        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $messages[] = $this->captureOpenFailureMessage('failed record');
        } finally {
            restore_error_handler();
        }

        $this->assertSame($messages[0], $messages[1]);
        $this->assertStringStartsWith(
            'The stream or file "' . self::STREAM_SCHEME . '://open-failure" could not be opened using mode "a".',
            $messages[0]
        );
    }

    public function testFailedWriteReopensOnceAndRetries(): void
    {
        LogStreamWrapper::$failFirstWrite = true;

        (new Logger('test', [
            new StreamHandler(self::STREAM_SCHEME . '://retry'),
        ]))->info('retry me');

        $this->assertSame(2, LogStreamWrapper::$openCount);
        $this->assertSame(2, LogStreamWrapper::$writeCount);
        $this->assertStringContainsString('retry me', LogStreamWrapper::$written);
    }

    public function testPartialWritesCompleteTheFormattedRecordExactlyOnce(): void
    {
        LogStreamWrapper::$maximumWriteLength = 3;
        $handler = new StreamHandler(self::STREAM_SCHEME . '://partial');
        $handler->setFormatter(new LineFormatter('%message%'));

        (new Logger('test', [$handler]))->info('complete record');

        $this->assertGreaterThan(1, LogStreamWrapper::$writeCount);
        $this->assertSame('complete record', LogStreamWrapper::$written);
    }

    public function testFailureAfterAPositivePrefixDoesNotRetryOrDuplicateThePrefix(): void
    {
        LogStreamWrapper::$failAfterBytes = 6;
        $handler = new StreamHandler(self::STREAM_SCHEME . '://prefix-failure');
        $handler->setFormatter(new LineFormatter('%message%'));

        try {
            (new Logger('test', [$handler]))->info('prefix failure');
            $this->fail('Expected the log write to fail.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString(
                'Writing to the log file "' . self::STREAM_SCHEME . '://prefix-failure" failed after 6 of 14 bytes.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, LogStreamWrapper::$openCount);
        $this->assertSame('prefix', LogStreamWrapper::$written);
    }

    public function testLockIsHeldOnceAcrossPartialWrites(): void
    {
        LogStreamWrapper::$maximumWriteLength = 2;
        $handler = new StreamHandler(self::STREAM_SCHEME . '://locked', useLocking: true);
        $handler->setFormatter(new LineFormatter('%message%'));

        (new Logger('test', [$handler]))->info('locked record');

        $this->assertSame([LOCK_EX, LOCK_UN], LogStreamWrapper::$lockOperations);
        $this->assertSame('locked record', LogStreamWrapper::$written);
    }

    public function testInodeRefreshDoesNotConsumeTheWriteFailureRetry(): void
    {
        $url = self::STREAM_SCHEME . '://inode-refresh';
        $handler = new StreamHandler($url);
        $handler->setFormatter(new LineFormatter('%message%'));
        $logger = new Logger('test', [$handler]);

        $logger->info('first');

        LogStreamWrapper::$inode = 2;
        clearstatcache(true, $url);
        $logger->info('second');

        LogStreamWrapper::$inode = 3;
        LogStreamWrapper::$zeroWritesRemaining = 1;
        clearstatcache(true, $url);
        $logger->info('third');

        $this->assertSame(4, LogStreamWrapper::$openCount);
        $this->assertSame(1, substr_count(LogStreamWrapper::$written, 'first'));
        $this->assertSame(1, substr_count(LogStreamWrapper::$written, 'second'));
        $this->assertSame(1, substr_count(LogStreamWrapper::$written, 'third'));
    }

    public function testCallerOwnedNonblockingResourceFailsWithoutClosingOrReplaying(): void
    {
        LogStreamWrapper::$failAfterBytes = 6;
        $resource = fopen(self::STREAM_SCHEME . '://caller-resource', 'w');
        $this->assertTrue(stream_set_blocking($resource, false));
        $handler = new StreamHandler($resource);
        $handler->setFormatter(new LineFormatter('%message%'));

        try {
            try {
                (new Logger('test', [$handler]))->info('caller failure');
                $this->fail('Expected the log write to fail.');
            } catch (UnexpectedValueException $exception) {
                $this->assertStringContainsString(
                    'Writing to the log stream failed after 6 of 14 bytes.',
                    $exception->getMessage(),
                );
            }

            $handler->close();

            $this->assertTrue(is_resource($resource));
            $this->assertSame('caller', LogStreamWrapper::$written);
        } finally {
            fclose($resource);
        }
    }

    public function testDirectoryCreationFailureThrowsDeterministically(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hypervel-log-parent-');
        $this->assertIsString($file);
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(UnexpectedValueException::class);
            $this->expectExceptionMessage('and it could not be created');

            (new Logger('test', [new StreamHandler($file . '/child/app.log')]))->info('message');
        } finally {
            restore_error_handler();
            @unlink($file);
        }
    }

    public function testRotatingHandlerWritesThroughTheSafeStreamBoundary(): void
    {
        $directory = sys_get_temp_dir() . '/hypervel-rotating-' . bin2hex(random_bytes(8));
        $path = $directory . '/app.log';
        $handler = new RotatingFileHandler($path, 1);

        try {
            (new Logger('test', [$handler]))->info('rotating message');
            $handler->close();

            $files = glob($directory . '/app-*.log');
            $this->assertIsArray($files);
            $this->assertCount(1, $files);
            $this->assertStringContainsString('rotating message', (string) file_get_contents($files[0]));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }

    private function captureOpenFailureMessage(string $message): string
    {
        try {
            (new Logger('test', [
                new StreamHandler(self::STREAM_SCHEME . '://open-failure'),
            ]))->error($message);
        } catch (UnexpectedValueException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected the stream open to fail.');
    }
}

class LogStreamWrapper
{
    public static ?Channel $entered = null;

    public static ?Channel $release = null;

    public static bool $failOpenAfterYield = false;

    public static bool $failOpen = false;

    public static bool $failFirstWrite = false;

    public static ?int $maximumWriteLength = null;

    public static ?int $failAfterBytes = null;

    public static int $zeroWritesRemaining = 0;

    public static int $inode = 1;

    public static int $openCount = 0;

    public static int $writeCount = 0;

    public static string $written = '';

    /**
     * @var list<int>
     */
    public static array $lockOperations = [];

    public mixed $context;

    public static function reset(): void
    {
        self::$entered = null;
        self::$release = null;
        self::$failOpenAfterYield = false;
        self::$failOpen = false;
        self::$failFirstWrite = false;
        self::$maximumWriteLength = null;
        self::$failAfterBytes = null;
        self::$zeroWritesRemaining = 0;
        self::$inode = 1;
        self::$openCount = 0;
        self::$writeCount = 0;
        self::$written = '';
        self::$lockOperations = [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        ++self::$openCount;

        if (self::$failOpen) {
            return false;
        }

        if (self::$failOpenAfterYield) {
            self::$entered?->push(true);
            self::$release?->pop();

            return false;
        }

        return true;
    }

    public function stream_write(string $data): int|false
    {
        ++self::$writeCount;

        if (self::$failFirstWrite && self::$writeCount === 1) {
            return false;
        }

        if (self::$zeroWritesRemaining > 0) {
            --self::$zeroWritesRemaining;

            return 0;
        }

        if (self::$failAfterBytes !== null) {
            $remaining = self::$failAfterBytes - strlen(self::$written);

            if ($remaining <= 0) {
                return 0;
            }

            $data = substr($data, 0, $remaining);
        }

        if (self::$maximumWriteLength !== null) {
            $data = substr($data, 0, self::$maximumWriteLength);
        }

        self::$written .= $data;

        return strlen($data);
    }

    public function stream_lock(int $operation): bool
    {
        self::$lockOperations[] = $operation;

        return true;
    }

    public function stream_set_option(int $option, int $argumentOne, ?int $argumentTwo): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return $this->stat();
    }

    public function url_stat(string $path, int $flags): array
    {
        return $this->stat();
    }

    private function stat(): array
    {
        return [
            'dev' => 0,
            'ino' => self::$inode,
            'mode' => 0100666,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => strlen(self::$written),
            'atime' => time(),
            'mtime' => time(),
            'ctime' => time(),
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}
