<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Tests\TestCase;

class RankedDocumentTest extends TestCase
{
    public function testRankedDocumentStoresIndexDocumentAndScore(): void
    {
        $document = new RankedDocument(0, 'test document content', 0.95);

        $this->assertSame(0, $document->index);
        $this->assertSame('test document content', $document->document);
        $this->assertSame(0.95, $document->score);
    }

    public function testRankedDocumentToArrayReturnsAllProperties(): void
    {
        $document = new RankedDocument(2, 'relevant content', 0.85);

        $array = $document->toArray();

        $this->assertSame(2, $array['index']);
        $this->assertSame('relevant content', $array['document']);
        $this->assertSame(0.85, $array['score']);
    }

    public function testRankedDocumentJsonSerializeReturnsToArray(): void
    {
        $document = new RankedDocument(1, 'search result', 0.75);

        $json = $document->jsonSerialize();

        $this->assertSame(1, $json['index']);
        $this->assertSame('search result', $json['document']);
        $this->assertSame(0.75, $json['score']);
    }

    public function testRankedDocumentToStringReturnsDocumentContent(): void
    {
        $document = new RankedDocument(0, 'document text content', 0.5);

        $this->assertSame('document text content', (string) $document);
    }
}
