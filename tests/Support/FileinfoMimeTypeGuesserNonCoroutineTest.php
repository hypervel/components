<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use ErrorException;
use Hypervel\Support\FileinfoMimeTypeGuesser;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('fileinfo')]
class FileinfoMimeTypeGuesserNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testGuessMimeTypeReturnsNullWhenFileDisappearsBeforeNativeRead(): void
    {
        $scheme = 'finfo-disappeared';

        $this->assertTrue(stream_wrapper_register($scheme, DisappearingFileStreamWrapper::class));

        // PHPUnit replaces Hypervel's production error handler, so reproduce its warning-to-exception conversion here.
        set_error_handler(
            static fn (int $severity, string $message, string $file, int $line): never => throw new ErrorException(
                $message,
                0,
                $severity,
                $file,
                $line,
            )
        );

        try {
            $this->assertNull((new FileinfoMimeTypeGuesser)->guessMimeType($scheme . '://file'));
        } finally {
            restore_error_handler();
            stream_wrapper_unregister($scheme);
        }
    }
}

class DisappearingFileStreamWrapper
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    public function url_stat(string $path, int $flags): array
    {
        return [
            2 => 0100444,
            7 => 1,
            'mode' => 0100444,
            'size' => 1,
        ];
    }
}
