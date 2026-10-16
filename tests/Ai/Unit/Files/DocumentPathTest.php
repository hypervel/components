<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class DocumentPathTest extends TestCase
{
    public function testLocalDocumentRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document file path cannot be empty.');

        new LocalDocument('');
    }

    public function testLocalDocumentRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document file path cannot be empty.');

        new LocalDocument("  \t\n");
    }

    public function testStoredDocumentRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document file path cannot be empty.');

        new StoredDocument('');
    }

    public function testStoredDocumentRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document file path cannot be empty.');

        new StoredDocument("  \t\n");
    }
}
