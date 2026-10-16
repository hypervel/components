<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Files;

use Hypervel\Ai\Files\StoredAudio;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StoredFileTest extends TestCase
{
    #[DataProvider('storedFiles')]
    public function testMimeTypeIsNullWhenTheDiskCannotDetectIt(string $class): void
    {
        Storage::fake('files');

        $this->assertNull((new $class('missing.bin', 'files'))->mimeType());
    }

    /**
     * Provide the stored file types.
     */
    public static function storedFiles(): array
    {
        return [[StoredDocument::class], [StoredAudio::class]];
    }
}
