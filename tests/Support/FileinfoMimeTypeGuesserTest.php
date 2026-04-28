<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\FileinfoMimeTypeGuesser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[RequiresPhpExtension('fileinfo')]
class FileinfoMimeTypeGuesserTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testGuessMimeTypeWithInvalidFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileinfoMimeTypeGuesser())
            ->guessMimeType(__DIR__ . '/unknown');
    }

    public function testGuessMimeType(): void
    {
        $mimeType = (new FileinfoMimeTypeGuesser())
            ->guessMimeType(__DIR__ . '/fixtures/test.gif');

        $this->assertEquals('image/gif', $mimeType);
    }

    public function testGuessMimeTypeInCoroutines(): void
    {
        $guesser = (new FileinfoMimeTypeGuesser());
        for ($i = 0; $i < 5; ++$i) {
            Coroutine::create(function () use ($guesser) {
                $mimeType = $guesser->guessMimeType(__DIR__ . '/fixtures/test.gif');
                $this->assertEquals('image/gif', $mimeType);
            });
        }
    }
}
