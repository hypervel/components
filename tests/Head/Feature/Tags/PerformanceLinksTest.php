<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\ImageType;
use Hypervel\Head\Enums\Media;
use Hypervel\Head\Facades\Head;
use Hypervel\Tests\Head\TestCase;
use InvalidArgumentException;

class PerformanceLinksTest extends TestCase
{
    public function testRendersPerformanceLinks(): void
    {
        Head::preload('/fonts/inter.woff2', as: 'font', crossorigin: true)
            ->preload('/images/hero.webp', as: 'image', type: ImageType::Webp)
            ->preload('/images/hero-dark.webp', as: 'image', type: ImageType::Webp, media: Media::Dark)
            ->prefetch('/images/next.webp')
            ->preconnect('https://fonts.example.com')
            ->dnsPrefetch('https://analytics.example.com');

        $html = Head::toHtml();
        $this->assertStringContainsString('rel="preload" href="/fonts/inter.woff2" as="font" crossorigin>', $html);
        $this->assertStringContainsString('rel="preload" href="/images/hero.webp" as="image" type="image/webp">', $html);
        $this->assertStringContainsString('rel="preload" href="/images/hero-dark.webp" as="image" type="image/webp" media="(prefers-color-scheme: dark)">', $html);
        $this->assertStringContainsString('rel="prefetch" href="/images/next.webp">', $html);
        $this->assertStringContainsString('rel="preconnect" href="https://fonts.example.com">', $html);
        $this->assertStringContainsString('rel="dns-prefetch" href="https://analytics.example.com">', $html);
    }

    public function testDetectsTheAsAttributeForPreloadedAndPrefetchedAssets(): void
    {
        Head::preloadAsset('fonts/inter.woff2')
            ->preloadAsset('images/hero.webp?v=2', media: Media::Dark)
            ->preloadAsset('css/app.css')
            ->prefetchAsset('js/checkout.js');

        $html = Head::toHtml();
        $this->assertStringContainsString('rel="preload" href="http://localhost/fonts/inter.woff2" as="font" crossorigin>', $html);
        $this->assertStringContainsString('rel="preload" href="http://localhost/images/hero.webp?v=2" as="image" media="(prefers-color-scheme: dark)">', $html);
        $this->assertStringContainsString('rel="preload" href="http://localhost/css/app.css" as="style">', $html);
        $this->assertStringContainsString('rel="prefetch" href="http://localhost/js/checkout.js" as="script">', $html);
    }

    public function testLetsAnExplicitAsAttributeOverrideAssetDetection(): void
    {
        Head::preloadAsset('fonts/inter.woff2', as: 'fetch');

        $html = Head::toHtml();
        $this->assertStringContainsString('as="fetch"', $html);
        $this->assertStringNotContainsString('crossorigin', $html);
    }

    public function testThrowsWhenAPreloadedAssetAsAttributeCannotBeDetected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect a preload [as] attribute for asset [downloads/report.xyz]. Pass one explicitly.');
        Head::preloadAsset('downloads/report.xyz');
    }

    public function testOmitsTheAsAttributeForPrefetchedAssetsItCannotDetect(): void
    {
        Head::prefetchAsset('downloads/report.xyz');

        $this->assertStringContainsString('rel="prefetch" href="http://localhost/downloads/report.xyz">', Head::toHtml());
    }
}
