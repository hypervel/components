<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Tests\TestCase;

class UrlCitationTest extends TestCase
{
    public function testUrlCitationStoresUrlAndTitle(): void
    {
        $citation = new UrlCitation('https://example.com', 'Example');

        $this->assertSame('https://example.com', $citation->url);
        $this->assertSame('Example', $citation->title);
        $this->assertNull($citation->startIndex);
        $this->assertNull($citation->endIndex);
    }

    public function testUrlCitationStoresSpanIndicesWhenProvided(): void
    {
        $citation = new UrlCitation('https://example.com', 'Example', startIndex: 12, endIndex: 45);

        $this->assertSame(12, $citation->startIndex);
        $this->assertSame(45, $citation->endIndex);
    }

    public function testUrlCitationToArrayReturnsAllFields(): void
    {
        $citation = new UrlCitation('https://hypervel.org', 'Hypervel', startIndex: 0, endIndex: 7);

        $this->assertSame([
            'url' => 'https://hypervel.org',
            'title' => 'Hypervel',
            'start_index' => 0,
            'end_index' => 7,
        ], $citation->toArray());
    }

    public function testUrlCitationJsonSerializeReturnsToArray(): void
    {
        $citation = new UrlCitation('https://google.com');

        $this->assertSame([
            'url' => 'https://google.com',
            'title' => null,
            'start_index' => null,
            'end_index' => null,
        ], $citation->jsonSerialize());
    }
}
