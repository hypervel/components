<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64AudioTest extends TestCase
{
    public function testAudioFromUploadPreservesContentAndMetadata(): void
    {
        $upload = UploadedFile::fake()->createWithContent('clip.mp3', 'audio bytes');

        $audio = Audio::fromUpload($upload);

        $this->assertInstanceOf(Base64Audio::class, $audio);
        $this->assertSame('audio bytes', $audio->content());
        $this->assertSame('audio/mpeg', $audio->mime);
        $this->assertSame('clip.mp3', $audio->name());
    }

    public function testBase64AudioRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 audio content cannot be empty.');

        new Base64Audio('');
    }
}
