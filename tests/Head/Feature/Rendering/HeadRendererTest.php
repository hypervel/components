<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Rendering;

use Hypervel\Head\Enums\OgType;
use Hypervel\Head\Enums\TwitterCard;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\Facades\Schema;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\Rendering\HeadRenderer;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Tests\Head\TestCase;

class HeadRendererTest extends TestCase
{
    public function testRendersResolvedHeadTagsInBuilderOrder(): void
    {
        Head::defaults(function (HeadBuilder $head): void {
            $head
                ->title('Acme', suffix: ' - Acme')
                ->description('Build something great.')
                ->canonical()
                ->og(siteName: 'Acme', type: OgType::Website)
                ->twitter(card: TwitterCard::SummaryWithLargeImage)
                ->preconnect('https://fonts.example.com');
        });

        Head::title('About')
            ->description('About Acme.')
            ->canonical('http://example.com/about/')
            ->preload('/fonts/inter.woff2', as: 'font', crossorigin: true)
            ->prefetch('/images/next.webp')
            ->dnsPrefetch('https://analytics.example.com')
            ->paginate(new LengthAwarePaginator(['post'], total: 30, perPage: 10, currentPage: 2, options: ['path' => '/posts']))
            ->alternates(['en' => 'https://example.com/about', 'fr' => 'https://example.com/fr/about'])
            ->feed('/feed', title: 'Acme RSS')
            ->schema(Schema::webPage()->name('About')->url('https://example.com/about'));

        $html = Head::toHtml();

        $this->assertStringContainsString('<title>About - Acme</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="About Acme.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/about">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Acme">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('rel="preload" href="/fonts/inter.woff2" as="font" crossorigin>', $html);
        $this->assertStringContainsString('rel="prefetch" href="/images/next.webp">', $html);
        $this->assertStringContainsString('rel="preconnect" href="https://fonts.example.com">', $html);
        $this->assertStringContainsString('rel="dns-prefetch" href="https://analytics.example.com">', $html);
        $this->assertStringContainsString('rel="prev" href="/posts?page=1">', $html);
        $this->assertStringContainsString('rel="next" href="/posts?page=3">', $html);
        $this->assertStringContainsString('rel="alternate" hreflang="fr" href="https://example.com/fr/about">', $html);
        $this->assertStringContainsString('rel="alternate" type="application/rss+xml" title="Acme RSS" href="/feed">', $html);
        $this->assertStringContainsString('"@type":"WebPage"', $html);
    }

    public function testDoesNotRenderInertiaKeysInBladeHtmlOutput(): void
    {
        Head::title('Dashboard')
            ->description('Dashboard overview.');

        $html = Head::toHtml();
        $this->assertStringContainsString('<title>Dashboard</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Dashboard overview.">', $html);
        $this->assertStringNotContainsString('data-inertia', $html);
    }

    public function testRendersInertiaGlobalsInBladeHtmlOutput(): void
    {
        Head::inertiaGlobals(fn (HeadBuilder $head): HeadBuilder => $head
            ->viewport('width=device-width, initial-scale=1')
            ->icon('/favicon.svg', type: 'image/svg+xml'));

        Head::title('Dashboard');

        $html = Head::toHtml();
        $this->assertStringContainsString('<title>Dashboard</title>', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', $html);
        $this->assertStringNotContainsString('data-inertia', $html);
    }

    public function testDeduplicatesInertiaElementsInOneFinalizedListUsingTheLastValueForEachOwnershipKey(): void
    {
        Head::description('Dedicated description.')
            ->meta('description', 'Generic description.');

        $elements = Head::toInertiaElements();

        $this->assertNotContains('<meta data-inertia="description" name="description" content="Dedicated description.">', $elements);
        $this->assertContains('<meta data-inertia="description" name="description" content="Generic description.">', $elements);
        $this->assertCount(1, array_filter(
            $elements,
            fn (string $element): bool => str_contains($element, 'data-inertia="description"'),
        ));
    }

    public function testRemovesExactFinalizedInertiaElementsFromAnSsrHead(): void
    {
        $html = implode("\n", [
            '<title data-inertia="title">Hypervel title</title>',
            '<meta data-inertia="description" name="description" content="Hypervel description">',
            '<meta data-inertia="client-only" name="client-only" content="Preserved">',
        ]);

        $head = app(HeadRenderer::class)->withoutInertiaElements($html, [
            '<title data-inertia="title">Hypervel title</title>',
            '<meta data-inertia="description" name="description" content="Hypervel description">',
        ]);

        $this->assertStringNotContainsString('data-inertia="title"', $head);
        $this->assertStringNotContainsString('data-inertia="description"', $head);
        $this->assertStringContainsString('<meta data-inertia="client-only" name="client-only" content="Preserved">', $head);
    }

    public function testGroupsLinkBuildersUnderTheLinksKeyWhenSerializedToAnArray(): void
    {
        Head::title('About')
            ->preload('/fonts/inter.woff2', as: 'font')
            ->paginate(new LengthAwarePaginator(['post'], total: 30, perPage: 10, currentPage: 2, options: ['path' => '/posts']))
            ->alternates(['fr' => 'https://example.com/fr/about'])
            ->feed('/feed', title: 'Acme RSS')
            ->link('manifest', '/site.webmanifest');

        $array = Head::toArray();

        $this->assertSame('About', $array['title']);
        $this->assertSame([], $array['openGraph']);
        $this->assertEqualsCanonicalizing([
            'performance', 'pagination', 'alternates', 'feeds', 'generic',
        ], array_keys($array['links']));
        $this->assertSame('/fonts/inter.woff2', $array['links']['performance']['preload'][0]['href']);
        $this->assertSame(['prev' => '/posts?page=1', 'next' => '/posts?page=3'], $array['links']['pagination']);
        $this->assertSame(['fr' => 'https://example.com/fr/about'], $array['links']['alternates']);
    }

    public function testTitleAndMetaTitleHaveIndependentInertiaOwnership(): void
    {
        Head::title('Page title')->meta('title', 'Metadata title');

        $this->assertSame([
            '<title data-inertia="title">Page title</title>',
            '<meta data-inertia="title" name="title" content="Metadata title">',
        ], Head::toInertiaElements());
    }
}
