<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Routing;

use Hypervel\Head\Enums\ImageType;
use Hypervel\Head\Enums\OgType;
use Hypervel\Head\Enums\TwitterCard;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\Routing\RouteAttributeParser;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;

class RouteHeadTest extends TestCase
{
    public function testCascadesDefaultsRouteGroupsRoutesAndControllerMutations(): void
    {
        Head::defaults(function (HeadBuilder $head): void {
            $head
                ->title('Acme', suffix: ' - Acme')
                ->description('Default description.');
        });

        Route::withHead(description: 'Admin description.', robots: 'noindex, nofollow')
            ->prefix('admin')
            ->group(function (): void {
                Route::get('/dashboard', function (): string {
                    Head::title('Runtime dashboard');

                    return Head::toHtml();
                })->withHead(title: 'Dashboard');
            });

        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<title>Runtime dashboard - Acme</title>', false)
            ->assertSee('<meta name="description" content="Admin description.">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function testKeepsRouteGroupHeadMetadataAsASeparateCascadeLayer(): void
    {
        Route::withHead(
            description: 'Admin reports',
            og: ['siteName' => 'Acme'],
        )
            ->prefix('admin')
            ->group(function (): void {
                Route::get('/dashboard', fn (): string => Head::toHtml())
                    ->withHead(title: 'Dashboard', og: ['title' => 'Dashboard']);
            });

        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<title>Dashboard</title>', false)
            ->assertSee('<meta name="description" content="Admin reports">', false)
            ->assertSee('<meta property="og:site_name" content="Acme">', false)
            ->assertSee('<meta property="og:title" content="Dashboard">', false);
    }

    public function testStoresCacheFriendlyHeadMetadataOnViewAndControllerRoutes(): void
    {
        $view = Route::view('/contact', 'contact')->withHead(
            title: 'Contact',
            description: 'Get in touch.',
        );

        $controller = Route::get('/about', fn (): string => 'About')->withHead(
            title: 'About',
        );

        $this->assertSame([[
            'title' => 'Contact',
            'description' => 'Get in touch.',
        ]], array_values($view->getMetadata(RouteAttributeParser::HEAD)));
        $this->assertSame([[
            'title' => 'About',
        ]], array_values($controller->getMetadata(RouteAttributeParser::HEAD)));
    }

    public function testStoresHeadMetadataOnResourceAndSingletonRoutes(): void
    {
        Route::resource('posts', 'PostController')->withHead(
            robots: 'index, follow',
        );

        Route::singleton('profile', 'ProfileController')->withHead(
            title: 'Your Profile',
        );

        $posts = Route::getRoutes()->getByName('posts.index');
        $profile = Route::getRoutes()->getByName('profile.show');

        $this->assertSame([[
            'robots' => 'index, follow',
        ]], array_values($posts?->getMetadata(RouteAttributeParser::HEAD)));
        $this->assertSame([[
            'title' => 'Your Profile',
        ]], array_values($profile?->getMetadata(RouteAttributeParser::HEAD)));
    }

    public function testParsesRouteHeadDataUsingTheFluentFieldShapes(): void
    {
        Route::get('/product', fn (): string => Head::toHtml())->withHead(
            title: ['value' => 'Product', 'suffix' => ' - Store'],
            canonical: ['auto' => true, 'forceHttps' => false],
            og: ['type' => OgType::Website, 'image' => 'https://example.com/og.jpg'],
            ogImage: [
                ['url' => 'https://example.com/structured.jpg', 'alt' => 'Structured image', 'width' => 1200, 'type' => ImageType::Jpeg],
            ],
            twitter: ['card' => TwitterCard::SummaryWithLargeImage],
            twitterImage: ['url' => 'https://example.com/twitter.jpg', 'alt' => 'Twitter image'],
            meta: ['product:price:amount' => '99.00'],
            link: [
                ['rel' => 'manifest', 'href' => '/manifest.json'],
            ],
        );

        $this->get('/product')
            ->assertOk()
            ->assertSee('<title>Product - Store</title>', false)
            ->assertSee('<link rel="canonical" href="http://localhost/product">', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('property="og:image" content="https://example.com/og.jpg">', false)
            ->assertSee('property="og:image:alt" content="Structured image">', false)
            ->assertSee('property="og:image:type" content="image/jpeg">', false)
            ->assertSee('property="og:image:width" content="1200">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta name="twitter:image:alt" content="Twitter image">', false)
            ->assertSee('<meta property="product:price:amount" content="99.00">', false)
            ->assertSee('rel="manifest" href="/manifest.json">', false);
    }

    public function testDoesNotAcceptSnakeCaseRouteHeadDataAliases(): void
    {
        Route::get('/legacy-inputs', fn (): string => Head::toHtml())->withHead(
            canonical: ['auto' => true, 'force_https' => false],
            og: ['title' => 'Legacy', 'site_name' => 'Legacy'],
            ogImage: [
                ['url' => 'https://example.com/image.jpg', 'secure_url' => 'https://secure.example.com/image.jpg'],
            ],
        );

        $this->get('/legacy-inputs')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://localhost/legacy-inputs">', false)
            ->assertSee('property="og:image" content="https://example.com/image.jpg">', false)
            ->assertDontSee('<meta property="og:site_name" content="Legacy">', false)
            ->assertDontSee('property="og:image:secure_url" content="https://secure.example.com/image.jpg">', false);
    }

    public function testParsesSingleRepeatableRouteHeadDataValues(): void
    {
        Route::get('/assets', fn (): string => Head::toHtml())->withHead(
            preload: ['href' => '/fonts/inter.woff2', 'as' => 'font', 'crossorigin' => true],
            prefetch: '/images/next.webp',
            preconnect: ['href' => 'https://fonts.example.com', 'crossorigin' => true],
            dnsPrefetch: 'https://analytics.example.com',
            feed: ['href' => '/feed.atom', 'title' => 'Acme Atom', 'type' => 'atom'],
        );

        $this->get('/assets')
            ->assertOk()
            ->assertSee('rel="preload" href="/fonts/inter.woff2" as="font" crossorigin>', false)
            ->assertSee('rel="prefetch" href="/images/next.webp">', false)
            ->assertSee('rel="preconnect" href="https://fonts.example.com" crossorigin>', false)
            ->assertSee('rel="dns-prefetch" href="https://analytics.example.com">', false)
            ->assertSee('rel="alternate" type="application/atom+xml" title="Acme Atom" href="/feed.atom">', false);
    }

    public function testThrowsForUnknownRouteHeadDataKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown route head attribute [heading].');
        Route::get('/unknown-head-key', fn (): string => Head::toHtml())->metadata([
            RouteAttributeParser::HEAD => [
                'layer' => ['heading' => 'Dashboard'],
            ],
        ]);

        $this->withoutExceptionHandling()->get('/unknown-head-key');
    }

    #[TestWith(['/feed', '<link rel="alternate" type="application/rss+xml" href="/feed">'])]
    #[TestWith([['/feed', '/news'], '<link rel="alternate" type="application/rss+xml" href="/feed">' . "\n" . '<link rel="alternate" type="application/rss+xml" href="/news">'])]
    #[TestWith([['href' => '/feed.atom', 'type' => 'atom'], '<link rel="alternate" type="application/atom+xml" href="/feed.atom">'])]
    #[TestWith([['href' => '/feed', 'title' => null], '<link rel="alternate" type="application/rss+xml" href="/feed">'])]
    #[TestWith([['/feed' => 'News'], '<link rel="alternate" type="application/rss+xml" title="News" href="/feed">'])]
    public function testParsesFeedRouteValues(string|array $feed, string $expected): void
    {
        Route::get('/feeds', fn (): string => Head::toHtml())->withHead(feed: $feed);

        $this->get('/feeds')->assertOk()->assertContent($expected);
    }

    #[TestWith(['preload', ['/app.js' => 'script']])]
    #[TestWith(['prefetch', ['/app.js' => 'script']])]
    #[TestWith(['preconnect', ['https://cdn.example.com' => 'anonymous']])]
    #[TestWith(['feed', ['href' => '/feed', 'title' => 42]])]
    public function testRejectsInvalidRouteLinkValues(string $key, array $value): void
    {
        Route::get('/invalid-link', fn (): string => Head::toHtml())->withHead(...[$key => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid value for route head attribute [{$key}].");
        $this->withoutExceptionHandling()->get('/invalid-link');
    }

    public function testPaginationIsNotAdvertisedAsARouteAttribute(): void
    {
        Route::get('/pagination', fn (): string => Head::toHtml())->withHead(extensions: ['pagination' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown route head attribute [pagination].');
        $this->withoutExceptionHandling()->get('/pagination');
    }

    public function testThrowsForInvalidValuesOnKnownRouteHeadDataKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for route head attribute [ogImage].');
        Route::get('/invalid-head-value', fn (): string => Head::toHtml())->withHead(
            ogImage: ['alt' => 'Missing URL'],
        );

        $this->withoutExceptionHandling()->get('/invalid-head-value');
    }

    public function testThrowsWhenRouteHeadDataTriesToSuppressCanonicalUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for route head attribute [canonical].');
        Route::get('/invalid-canonical', fn (): string => Head::toHtml())->metadata([
            RouteAttributeParser::HEAD => [
                'layer' => ['canonical' => false],
            ],
        ]);

        $this->withoutExceptionHandling()->get('/invalid-canonical');
    }

    public function testThrowsWhenRouteCanonicalDataUsesTheRemovedNoneOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for route head attribute [canonical].');
        Route::get('/invalid-canonical-none', fn (): string => Head::toHtml())->withHead(
            canonical: ['none' => true],
        );

        $this->withoutExceptionHandling()->get('/invalid-canonical-none');
    }
}
