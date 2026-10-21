<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\Media;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadManager;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\Support\Header;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;

class ThemeColorTest extends TestCase
{
    public function testRendersThemeColorTags(): void
    {
        Head::themeColor('#0f172a');

        $this->assertStringContainsString('<meta name="theme-color" content="#0f172a">', Head::toHtml());
    }

    public function testRendersMediaSpecificThemeColorsFromMediaEnumValues(): void
    {
        Head::themeColor('#ffffff', media: Media::Light)
            ->themeColor('#111827', media: Media::Dark);

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">', $html);
    }

    public function testProvidesCommonMediaQueryValues(): void
    {
        $this->assertSame('(prefers-color-scheme: light)', Media::Light->value);
        $this->assertSame('(prefers-color-scheme: dark)', Media::Dark->value);
        $this->assertSame('(orientation: portrait)', Media::Portrait->value);
        $this->assertSame('(orientation: landscape)', Media::Landscape->value);
    }

    public function testResolvesThemeColorsFromRouteAttributes(): void
    {
        Route::get('/product', fn (): string => Head::toHtml())->withHead(
            themeColor: '#0f172a',
        );

        $this->get('/product')
            ->assertOk()
            ->assertSee('<meta name="theme-color" content="#0f172a">', false);
    }

    public function testSharesStableInertiaKeysForThemeColors(): void
    {
        Route::get('/dashboard', fn (): Response => Inertia::render('Dashboard'))->withHead(
            themeColor: '#0f172a',
        );

        $this->get('/dashboard', [Header::INERTIA => 'true'])
            ->assertOk()
            ->assertJsonPath('props.' . HeadManager::INERTIA_PROP . '.0', '<meta data-inertia="themeColor" name="theme-color" content="#0f172a">');
    }
}
