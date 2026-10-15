<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64DocumentTest extends TestCase
{
    public function testUploadPreservesNameAndContent(): void
    {
        $document = Base64Document::fromUpload(UploadedFile::fake()->createWithContent('report.txt', 'Report'));

        $this->assertSame('report.txt', $document->name());
        $this->assertSame('Report', $document->content());
    }

    public function testBase64DocumentRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 document content cannot be empty.');

        new Base64Document('');
    }
}
