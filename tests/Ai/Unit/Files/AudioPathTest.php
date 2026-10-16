<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\LocalAudio;
use Hypervel\Ai\Files\StoredAudio;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class AudioPathTest extends TestCase
{
    public function testLocalAudioRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audio file path cannot be empty.');

        new LocalAudio('');
    }

    public function testLocalAudioRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audio file path cannot be empty.');

        new LocalAudio("  \t\n");
    }

    public function testStoredAudioRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audio file path cannot be empty.');

        new StoredAudio('');
    }

    public function testStoredAudioRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audio file path cannot be empty.');

        new StoredAudio("  \t\n");
    }
}
