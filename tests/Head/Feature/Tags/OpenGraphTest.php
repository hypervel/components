<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\ImageType;
use Hypervel\Head\Enums\OgType;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class OpenGraphTest extends TestCase
{
    public function testRendersOpenGraphMediaTags(): void
    {
        Head::og(type: OgType::Article, image: 'https://example.com/hero.jpg')
            ->ogImage(
                'https://example.com/gallery.jpg',
                alt: 'Gallery image',
                width: 1200,
                height: 630,
                type: ImageType::Jpeg,
                secureUrl: 'https://secure.example.com/gallery.jpg',
            );

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('property="og:image" content="https://example.com/hero.jpg">', $html);
        $this->assertStringContainsString('property="og:image" content="https://example.com/gallery.jpg">', $html);
        $this->assertStringContainsString('property="og:image:secure_url" content="https://secure.example.com/gallery.jpg">', $html);
        $this->assertStringContainsString('property="og:image:type" content="image/jpeg">', $html);
        $this->assertStringContainsString('property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('property="og:image:height" content="630">', $html);
        $this->assertStringContainsString('property="og:image:alt" content="Gallery image">', $html);
    }

    public function testReplacesDefaultLayerOgImagesWithPageLevelOgImages(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->ogImage('https://example.com/brand.png', alt: 'Brand card'));

        Head::ogImage('https://example.com/product.jpg', alt: 'Product photo');

        $html = Head::toHtml();
        $this->assertStringContainsString('property="og:image" content="https://example.com/product.jpg">', $html);
        $this->assertStringNotContainsString('https://example.com/brand.png', $html);
    }

    public function testFallsBackToDefaultLayerOgImagesWhenNoHigherLayerDefinesAny(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->ogImage('https://example.com/brand.png'));

        Head::title('About');

        $this->assertStringContainsString('property="og:image" content="https://example.com/brand.png">', Head::toHtml());
    }

    public function testKeepsDefaultLayerOgVideosWhenAHigherLayerOnlyDefinesImages(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head
            ->ogImage('https://example.com/brand.png')
            ->ogVideo('https://example.com/tour.mp4'));

        Head::ogImage('https://example.com/product.jpg');

        $html = Head::toHtml();
        $this->assertStringContainsString('property="og:image" content="https://example.com/product.jpg">', $html);
        $this->assertStringContainsString('property="og:video" content="https://example.com/tour.mp4">', $html);
        $this->assertStringNotContainsString('https://example.com/brand.png', $html);
    }

    public function testMergesOgImagesBetweenPageLevelLayers(): void
    {
        Head::ogImage('https://example.com/first.jpg')
            ->ogImage('https://example.com/second.jpg');

        $html = Head::toHtml();
        $this->assertStringContainsString('property="og:image" content="https://example.com/first.jpg">', $html);
        $this->assertStringContainsString('property="og:image" content="https://example.com/second.jpg">', $html);
    }

    public function testReplacesDefaultMediaIndependentlyAcrossRouteAndPageLayers(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head
            ->ogImage('/default.png')->ogVideo('/default.mp4')->ogAudio('/default.mp3'));

        Route::withHead(ogVideo: '/group.mp4')->group(function (): void {
            Route::get('/media', function (): array {
                Head::ogAudio('/page.mp3')->ogImage('/page.png');

                return Head::toArray();
            })->withHead(ogImage: '/route.png');
        });

        $this->getJson('/media')->assertOk()
            ->assertJsonPath('openGraph.images', [['url' => '/route.png'], ['url' => '/page.png']])
            ->assertJsonPath('openGraph.videos', [['url' => '/group.mp4']])
            ->assertJsonPath('openGraph.audios', [['url' => '/page.mp3']]);
    }

    public function testRepeatedAndRuntimeDefaultsRetainFallbackMediaProvenance(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->ogImage('/first.png')->ogAudio('/audio.mp3'));
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->ogImage('/second.png')->ogVideo('/video.mp4'));
        Head::resolveDefaultsUsing(fn (HeadBuilder $head): HeadBuilder => $head->ogImage('/runtime.png'));
        Head::ogImage('/page.png');

        $data = Head::toArray()['openGraph'];
        $this->assertSame([['url' => '/page.png']], $data['images']);
        $this->assertSame([['url' => '/video.mp4']], $data['videos']);
        $this->assertSame([['url' => '/audio.mp3']], $data['audios']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPreservesZeroInExplicitAndInferredProperties(bool $explicit): void
    {
        if ($explicit) {
            Head::og(title: '0', description: '0');
        } else {
            Head::title('0')->description('0')->og();
        }

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta property="og:title" content="0">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="0">', $html);
        $this->assertSame(['title' => '0', 'description' => '0'], Head::toArray()['openGraph']);
    }
}
