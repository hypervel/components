<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Context\CoroutineContext;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\Tags\Canonical;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CanonicalTest extends TestCase
{
    public function testDoesNotRenderACanonicalUrlByDefault(): void
    {
        $this->assertStringNotContainsString('rel="canonical"', Head::toHtml());
    }

    public function testCarriesInheritedCanonicalOptionsIntoLaterCanonicalUrls(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->canonical(forceHttps: false, trailingSlash: true));

        Route::get('/about', fn (): string => Head::toHtml())
            ->withHead(canonical: '/about');

        $this->get('/about')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/about/">', false);
    }

    #[DataProvider('canonicalUrls')]
    public function testNormalizesOnlyTheOriginAndPath(?string $url, ?string $origin, bool $forceHttps, bool $trailingSlash, string $expected): void
    {
        Head::resolveCanonicalOriginUsing(fn (): ?string => $origin);
        $request = Request::create('http://alias.test:8080/page/?utm_source=example');

        $this->assertSame($expected, Canonical::make($url, $forceHttps, $trailingSlash)->render($request));
    }

    /**
     * Supply canonical path, query, fragment, and origin combinations.
     *
     * @return iterable<string, array{?string, ?string, bool, bool, string}>
     */
    public static function canonicalUrls(): iterable
    {
        yield 'automatic custom origin' => [null, 'https://team.test:9443', true, false, 'https://team.test:9443/page'];
        yield 'automatic request origin' => [null, null, true, false, 'https://alias.test:8080/page'];
        yield 'relative custom origin' => ['/about/', 'https://team.test', true, false, 'https://team.test/about'];
        yield 'relative request origin' => ['about', null, false, false, 'http://alias.test:8080/about'];
        yield 'explicit external origin' => ['http://external.test:8081/about/', 'https://team.test', true, false, 'https://external.test:8081/about'];
        yield 'root query' => ['http://external.test/?page=2', null, true, false, 'https://external.test/?page=2'];
        yield 'root without slash' => ['http://external.test?page=2#top', null, true, false, 'https://external.test/?page=2#top'];
        yield 'path query add slash' => ['/about?next=%2Fdocs%3Fa%3D1#section', 'https://team.test', true, true, 'https://team.test/about/?next=%2Fdocs%3Fa%3D1#section'];
        yield 'path query remove slash' => ['/about/?page=2#section', 'https://team.test', true, false, 'https://team.test/about?page=2#section'];
        yield 'query ending slash' => ['/about?next=/docs/', 'https://team.test', true, false, 'https://team.test/about?next=/docs/'];
        yield 'fragment ending slash' => ['/about#section/', 'https://team.test', true, false, 'https://team.test/about#section/'];
        yield 'encoded path' => ['/a%2Fb?x=one+two&x=%26#%23', 'http://team.test:9000', false, true, 'http://team.test:9000/a%2Fb/?x=one+two&x=%26#%23'];
    }

    public function testAutomaticCanonicalPreservesTheRequestBasePath(): void
    {
        Head::resolveCanonicalOriginUsing(fn (): string => 'https://team.test');
        $request = Request::create('http://alias.test/base/index.php/page?tracking=1', server: [
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
            'SCRIPT_NAME' => '/base/index.php',
            'PHP_SELF' => '/base/index.php/page',
        ]);

        $this->assertSame('/base/index.php', $request->getBaseUrl());
        $this->assertSame('https://team.test/base/index.php/page', Canonical::make()->render($request));
    }

    public function testExplicitAbsoluteUrlsDoNotResolveAnOrigin(): void
    {
        $calls = 0;
        Head::resolveCanonicalOriginUsing(function () use (&$calls): string {
            ++$calls;

            return 'https://team.test';
        });

        $this->assertSame('https://external.test/about/', Canonical::make('http://external.test/about', trailingSlash: true)->render(null));
        $this->assertSame(0, $calls);
    }

    public function testAutomaticCanonicalNeedsARequestButExplicitPathsCanUseTheOriginAlone(): void
    {
        Head::resolveCanonicalOriginUsing(fn (): string => 'https://team.test');

        $this->assertNull(Canonical::make()->render(null));
        $this->assertSame('https://team.test/about', Canonical::make('/about')->render(null));
    }

    public function testOriginResolverReadsCurrentContextAndCanBeCleared(): void
    {
        Head::resolveCanonicalOriginUsing(fn (): ?string => CoroutineContext::get('head.test.origin'));
        $request = Request::create('http://alias.test/about');
        $canonical = Canonical::make();

        CoroutineContext::set('head.test.origin', 'https://first.test');
        $this->assertSame('https://first.test/about', $canonical->render($request));
        CoroutineContext::set('head.test.origin', 'https://second.test');
        $this->assertSame('https://second.test/about', $canonical->render($request));
        CoroutineContext::set('head.test.origin', null);
        $this->assertSame('https://alias.test/about', $canonical->render($request));

        CoroutineContext::set('head.test.origin', 'https://third.test');
        Head::resolveCanonicalOriginUsing(null);
        $this->assertSame('https://alias.test/about', $canonical->render($request));
    }

    public function testManagerUsesTheApplicationUrlOutsideHttp(): void
    {
        config(['app.url' => 'http://application.test:8080/base']);
        Head::canonical();
        $this->assertSame('https://application.test:8080/base', Head::toArray()['canonical']);

        Head::resolveCanonicalOriginUsing(fn (): string => 'https://team.test');
        $this->assertSame('https://team.test/base', Head::toArray()['canonical']);

        Head::canonical('/about');
        $this->assertSame('https://team.test/about', Head::toArray()['canonical']);
    }
}
