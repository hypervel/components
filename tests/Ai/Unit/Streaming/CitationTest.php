<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Streaming\Events\Citation;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Tests\TestCase;

class CitationTest extends TestCase
{
    public function testCombineCollectsTheSourcesARunCited(): void
    {
        $citations = Citation::combine([
            $this->citation('https://hypervel.org/docs', 'Hypervel Documentation'),
            $this->citation('https://hypervel.org/docs/mcp', 'Hypervel MCP'),
        ]);

        $this->assertCount(2, $citations);
        $this->assertSame('https://hypervel.org/docs', $citations->first()->url);
        $this->assertSame('Hypervel Documentation', $citations->first()->title);
    }

    public function testCombineKeepsEveryMentionTheWayAGeneratedResponseDoes(): void
    {
        // The generated path records every mention, so the streamed path does too...
        $citations = Citation::combine([
            $this->citation('https://hypervel.org/docs', 'Hypervel Documentation'),
            $this->citation('https://hypervel.org/docs/mcp', 'Hypervel MCP'),
            $this->citation('https://hypervel.org/docs', 'Hypervel Documentation'),
        ]);

        $this->assertCount(3, $citations);
        $this->assertSame([
            'https://hypervel.org/docs',
            'https://hypervel.org/docs/mcp',
            'https://hypervel.org/docs',
        ], $citations->pluck('url')->all());
    }

    public function testCombineIgnoresEventsThatAreNotCitations(): void
    {
        $citations = Citation::combine([
            new TextDelta(uniqid(), 'message-1', 'Hypervel MCP ships a server.', time()),
            $this->citation('https://hypervel.org/docs/mcp'),
        ]);

        $this->assertCount(1, $citations);
    }

    public function testCombineReturnsNothingWhenTheRunCitedNothing(): void
    {
        $this->assertEmpty(Citation::combine([
            new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
        ]));
    }

    /**
     * Create a citation event.
     */
    protected function citation(string $url, ?string $title = null): Citation
    {
        return new Citation(uniqid(), 'message-1', new UrlCitation($url, $title), time());
    }
}
