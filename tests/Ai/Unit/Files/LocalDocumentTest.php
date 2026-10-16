<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;

class LocalDocumentTest extends TestCase
{
    public function testADocumentCreatedFromAnUploadCanBeSerialized(): void
    {
        $document = LocalDocument::fromUploadedFile(
            UploadedFile::fake()->createWithContent('report.txt', 'I am an expense report.')
        )->withProviderOptions(['purpose' => 'assistants']);

        $unserialized = unserialize(serialize($document));

        $this->assertSame($document->path, $unserialized->path);
        $this->assertSame('report.txt', $unserialized->name());
        $this->assertSame($document->mimeType(), $unserialized->mimeType());
        $this->assertSame(['purpose' => 'assistants'], $unserialized->providerOptions('openai'));
    }
}
