<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\Media;
use Hypervel\Head\Facades\Head;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;

class MetaTagsTest extends TestCase
{
    public function testRendersGenericMetaTags(): void
    {
        Head::meta('format-detection', 'telephone=no')
            ->meta('article:author', 'Taylor Otwell')
            ->meta('weird:namespace', 'Value', property: true);

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="format-detection" content="telephone=no">', $html);
        $this->assertStringContainsString('<meta property="article:author" content="Taylor Otwell">', $html);
        $this->assertStringContainsString('<meta property="weird:namespace" content="Value">', $html);
    }

    public function testRendersMetaAliases(): void
    {
        Head::applicationName('Acme')
            ->colorScheme('light dark')
            ->referrer('strict-origin-when-cross-origin')
            ->viewport('width=device-width, initial-scale=1')
            ->appleWebAppTitle('Acme')
            ->webAppCapable()
            ->appleWebAppStatusBarStyle('black');

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="application-name" content="Acme">', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('<meta name="referrer" content="strict-origin-when-cross-origin">', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Acme">', $html);
        $this->assertStringContainsString('<meta name="mobile-web-app-capable" content="yes">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-status-bar-style" content="black">', $html);
    }

    public function testRendersMediaSpecificMetaTags(): void
    {
        Head::meta('theme-color', '#ffffff', media: '(prefers-color-scheme: light)')
            ->meta('theme-color', '#111827', media: '(prefers-color-scheme: dark)');

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">', $html);
    }

    public function testOverlaysMetaTagsByKeyPropertyAndMediaQuery(): void
    {
        Head::meta('theme-color', '#ffffff')
            ->meta('theme-color', '#111827', media: '(prefers-color-scheme: dark)')
            ->meta('theme-color', '#f8fafc');

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="theme-color" content="#f8fafc">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">', $html);
        $this->assertStringNotContainsString('<meta name="theme-color" content="#ffffff">', $html);
    }

    public function testSerializesMediaSpecificMetaTagsToTheHeadArray(): void
    {
        Head::meta('theme-color', '#ffffff')
            ->meta('theme-color', '#111827', media: '(prefers-color-scheme: dark)');

        $this->assertSame([
            ['key' => 'theme-color', 'content' => '#ffffff'],
            ['key' => 'theme-color', 'content' => '#111827', 'media' => '(prefers-color-scheme: dark)'],
        ], Head::toArray()['meta']);
    }

    public function testResolvesMediaSpecificMetaTagsFromRouteAttributes(): void
    {
        Route::get('/dashboard', fn (): string => Head::toHtml())->withHead(
            meta: [
                ['key' => 'theme-color', 'content' => '#ffffff', 'media' => '(prefers-color-scheme: light)'],
                ['key' => 'theme-color', 'content' => '#111827', 'media' => '(prefers-color-scheme: dark)'],
            ],
        );

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', false)
            ->assertSee('<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">', false);
    }

    public function testResolvesMediaEnumValuesFromRouteAttributes(): void
    {
        Route::get('/dashboard', fn (): string => Head::toHtml())->withHead(
            meta: [
                ['key' => 'theme-color', 'content' => '#ffffff', 'media' => Media::Light],
                ['key' => 'theme-color', 'content' => '#111827', 'media' => Media::Dark],
            ],
        );

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', false)
            ->assertSee('<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">', false);
    }

    public function testResolvesMetaAliasesFromRouteAttributes(): void
    {
        Route::get('/settings', fn (): string => Head::toHtml())->withHead(
            applicationName: 'Acme',
            colorScheme: 'light dark',
            referrer: 'strict-origin',
            viewport: 'width=device-width, initial-scale=1',
            appleWebAppTitle: 'Acme',
            webAppCapable: true,
            appleWebAppStatusBarStyle: 'black-translucent',
        );

        $this->get('/settings')
            ->assertOk()
            ->assertSee('<meta name="application-name" content="Acme">', false)
            ->assertSee('<meta name="color-scheme" content="light dark">', false)
            ->assertSee('<meta name="referrer" content="strict-origin">', false)
            ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="Acme">', false)
            ->assertSee('<meta name="mobile-web-app-capable" content="yes">', false)
            ->assertSee('<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">', false);
    }
}
