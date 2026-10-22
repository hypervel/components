<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Results;

use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Tests\TestCase;

class LinkTest extends TestCase
{
    public function testCreatesVersionlessHypervelDocumentationLinks(): void
    {
        $link = Link::docs('queues');

        $this->assertSame('Queues documentation', $link->label);
        $this->assertSame('https://hypervel.org/docs/queues', $link->url);
        $this->assertSame('https://hypervel.org/docs/queues', $link->agentUrl);
    }

    public function testCreatesSectionLinksWithCustomLabels(): void
    {
        $link = Link::docs('queues', 'connections-vs-queues', 'Queue connections');

        $this->assertSame('Queue connections', $link->label);
        $this->assertSame('https://hypervel.org/docs/queues#connections-vs-queues', $link->url);
        $this->assertSame('https://hypervel.org/docs/queues#connections-vs-queues', $link->agentUrl);
    }

    public function testUsesArbitraryUrlsForBothPeopleAndAgents(): void
    {
        $link = Link::to('PHP timezone list', 'https://www.php.net/manual/en/timezones.php');

        $this->assertSame('PHP timezone list', $link->label);
        $this->assertSame('https://www.php.net/manual/en/timezones.php', $link->url);
        $this->assertSame('https://www.php.net/manual/en/timezones.php', $link->agentUrl);
    }

    public function testReplacesTokensAcrossEveryLinkField(): void
    {
        $link = Link::to('{service} documentation', 'https://example.com/{service}')
            ->replace(['{service}' => 'queues']);

        $this->assertEquals(Link::to('queues documentation', 'https://example.com/queues'), $link);
    }

    public function testAttachesLinksToDiagnosticResults(): void
    {
        $link = Link::docs('queues');
        $result = DiagnosticResult::warn('Queue warning')->link($link);

        $this->assertSame([$link], $result->links);
    }

    public function testAttachesLinksFluentlyToMessageDefinitions(): void
    {
        $link = Link::docs('queues');
        $message = Message::make('Queue warning')->link($link);

        $this->assertSame([$link], $message->links);
    }
}
