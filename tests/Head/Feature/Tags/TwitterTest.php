<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\TwitterCard;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Tests\Head\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class TwitterTest extends TestCase
{
    public function testRendersTwitterImageTags(): void
    {
        Head::twitter(card: TwitterCard::SummaryWithLargeImage, image: 'https://example.com/twitter.jpg')
            ->twitterImage('https://example.com/twitter-alt.jpg', alt: 'Twitter alt');

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="https://example.com/twitter-alt.jpg">', $html);
        $this->assertStringContainsString('<meta name="twitter:image:alt" content="Twitter alt">', $html);
    }

    public function testDoesNotRenderTwitterFallbackTagsByDefault(): void
    {
        Head::title('Gallery')
            ->description('Gallery description.')
            ->ogImage('https://example.com/gallery.jpg', alt: 'Gallery image');

        $html = Head::toHtml();
        $this->assertStringNotContainsString('name="twitter:title"', $html);
        $this->assertStringNotContainsString('name="twitter:description"', $html);
        $this->assertStringNotContainsString('name="twitter:image"', $html);

        $this->assertSame([], Head::toArray()['twitter']);
    }

    public function testFallsBackFromDocumentTagsToTwitterTagsWhenConfigured(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->twitter());

        Head::title('Gallery')
            ->description('Gallery description.')
            ->ogImage('https://example.com/gallery.jpg', alt: 'Gallery image');

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="twitter:title" content="Gallery">', $html);
        $this->assertStringContainsString('<meta name="twitter:description" content="Gallery description.">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="https://example.com/gallery.jpg">', $html);
        $this->assertStringContainsString('<meta name="twitter:image:alt" content="Gallery image">', $html);

        $this->assertSame([
            'url' => 'https://example.com/gallery.jpg',
            'alt' => 'Gallery image',
        ], Head::toArray()['twitter']['image']);
    }

    public function testPrefersExplicitTwitterImageTagsOverOpenGraphImageTags(): void
    {
        Head::ogImage('https://example.com/gallery.jpg', alt: 'Gallery image')
            ->twitterImage('https://example.com/twitter.jpg', alt: 'Twitter image');

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="twitter:image" content="https://example.com/twitter.jpg">', $html);
        $this->assertStringContainsString('<meta name="twitter:image:alt" content="Twitter image">', $html);
        $this->assertStringNotContainsString('<meta name="twitter:image" content="https://example.com/gallery.jpg">', $html);

        $this->assertSame([
            'url' => 'https://example.com/twitter.jpg',
            'alt' => 'Twitter image',
        ], Head::toArray()['twitter']['image']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPreservesZeroInExplicitAndInferredProperties(bool $explicit): void
    {
        if ($explicit) {
            Head::twitter(title: '0', description: '0');
        } else {
            Head::title('0')->description('0')->twitter();
        }

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="twitter:title" content="0">', $html);
        $this->assertStringContainsString('<meta name="twitter:description" content="0">', $html);
        $this->assertSame(['title' => '0', 'description' => '0'], Head::toArray()['twitter']);
    }
}
