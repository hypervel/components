<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class Base64AudioTest extends TestCase
{
    #[DataProvider('audioClasses')]
    public function testAudioFromUploadPreservesContentAndMetadata(string $class): void
    {
        $upload = UploadedFile::fake()->createWithContent('clip.mp3', 'audio bytes');

        $audio = $class::fromUpload($upload);

        $this->assertInstanceOf(Base64Audio::class, $audio);
        $this->assertSame('audio bytes', $audio->content());
        $this->assertSame('audio/mpeg', $audio->mime);
        $this->assertSame('clip.mp3', $audio->name());
    }

    /**
     * Provide upload entry points.
     */
    public static function audioClasses(): array
    {
        return [[Audio::class], [Base64Audio::class]];
    }

    public function testBase64AudioRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 audio content cannot be empty.');

        new Base64Audio('');
    }
}
