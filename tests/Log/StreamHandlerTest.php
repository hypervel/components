<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use ErrorException;
use Hypervel\Engine\Channel;
use Hypervel\Log\Handlers\RotatingFileHandler;
use Hypervel\Log\Handlers\StreamHandler;
use Hypervel\Tests\TestCase;
use Monolog\Logger;
use UnexpectedValueException;

use function Hypervel\Coroutine\parallel;

class StreamHandlerTest extends TestCase
{
    private const STREAM_SCHEME = 'hypervel-log-test';

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

    public static int $openCount = 0;

    public static int $writeCount = 0;

    public static string $written = '';

    public mixed $context;

    public static function reset(): void
    {
        self::$entered = null;
        self::$release = null;
        self::$failOpenAfterYield = false;
        self::$failOpen = false;
        self::$failFirstWrite = false;
        self::$openCount = 0;
        self::$writeCount = 0;
        self::$written = '';
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

        self::$written .= $data;

        return strlen($data);
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
            'ino' => 1,
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
