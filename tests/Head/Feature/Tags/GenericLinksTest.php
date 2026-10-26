<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\ImageType;
use Hypervel\Head\Enums\Media;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;
use InvalidArgumentException;

class GenericLinksTest extends TestCase
{
    public function testRendersGenericLinkTags(): void
    {
        Head::link('search', '/opensearch.xml', ['type' => 'application/opensearchdescription+xml', 'title' => 'Acme Search'])
            ->link('me', 'https://social.example.com/@acme')
            ->link('icon', '/favicon.ico', ['type' => ImageType::Ico]);

        $html = Head::toHtml();
        $this->assertStringContainsString('rel="search" href="/opensearch.xml" type="application/opensearchdescription+xml" title="Acme Search">', $html);
        $this->assertStringContainsString('rel="me" href="https://social.example.com/@acme">', $html);
        $this->assertStringContainsString('rel="icon" href="/favicon.ico" type="image/x-icon">', $html);
    }

    public function testRendersLinkAliases(): void
    {
        Head::favicon('/favicon.svg', type: ImageType::Svg)
            ->icon('/favicon-32x32.png', type: ImageType::Png, sizes: '32x32')
            ->appleTouchIcon('/apple-touch-icon.png', sizes: '180x180')
            ->maskIcon('/safari-pinned-tab.svg', color: '#111827')
            ->manifest('/site.webmanifest')
            ->appleTouchStartupImage('/launch.png', media: Media::Portrait);

        $html = Head::toHtml();
        $this->assertStringContainsString('rel="icon" href="/favicon.svg" type="image/svg+xml">', $html);
        $this->assertStringContainsString('rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">', $html);
        $this->assertStringContainsString('rel="mask-icon" href="/safari-pinned-tab.svg" color="#111827">', $html);
        $this->assertStringContainsString('rel="manifest" href="/site.webmanifest">', $html);
        $this->assertStringContainsString('rel="apple-touch-startup-image" href="/launch.png" media="(orientation: portrait)">', $html);
    }

    public function testRejectsLinkAttributesWithUnsafeNames(): void
    {
        Head::link('stylesheet', '/theme.css', ['onerror x' => 'alert(1)']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name.');
        Head::toHtml();
    }

    public function testResolvesLinkAliasesFromRouteAttributes(): void
    {
        Route::get('/app', fn (): string => Head::toHtml())->withHead(
            icon: [
                ['href' => '/favicon.svg', 'type' => ImageType::Svg],
                ['href' => '/favicon-32x32.png', 'type' => ImageType::Png, 'sizes' => '32x32'],
            ],
            favicon: ['href' => '/favicon-dark.svg', 'type' => ImageType::Svg, 'media' => Media::Dark],
            appleTouchIcon: ['href' => '/apple-touch-icon.png', 'sizes' => '180x180'],
            maskIcon: ['href' => '/safari-pinned-tab.svg', 'color' => '#111827'],
            manifest: '/site.webmanifest',
            appleTouchStartupImage: ['href' => '/launch.png', 'media' => Media::Portrait],
        );

        $this->get('/app')
            ->assertOk()
            ->assertSee('rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
            ->assertSee('rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">', false)
            ->assertSee('rel="icon" href="/favicon-dark.svg" type="image/svg+xml" media="(prefers-color-scheme: dark)">', false)
            ->assertSee('rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">', false)
            ->assertSee('rel="mask-icon" href="/safari-pinned-tab.svg" color="#111827">', false)
            ->assertSee('rel="manifest" href="/site.webmanifest">', false)
            ->assertSee('rel="apple-touch-startup-image" href="/launch.png" media="(orientation: portrait)">', false);
    }

    public function testReplacesInheritedManifestsWhilePreservingMultipleIcons(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->manifest('/default.webmanifest')->icon('/icon.svg'));
        Head::resolveDefaultsUsing(fn (HeadBuilder $head): HeadBuilder => $head->manifest('/team.webmanifest')->icon('/icon.png'));
        Head::manifest('/page.webmanifest');

        $links = Head::toArray()['links']['generic'];
        $manifests = array_values(array_filter($links, fn (array $link): bool => $link['rel'] === 'manifest'));
        $icons = array_values(array_filter($links, fn (array $link): bool => $link['rel'] === 'icon'));

        $this->assertSame([['rel' => 'manifest', 'href' => '/page.webmanifest', 'attributes' => []]], $manifests);
        $this->assertSame(['/icon.svg', '/icon.png'], array_column($icons, 'href'));
        $this->assertSame(1, substr_count(Head::toHtml(), 'rel="manifest"'));
        $this->assertContains('<link data-inertia="link:manifest" rel="manifest" href="/page.webmanifest">', Head::toInertiaElements());
    }
}
