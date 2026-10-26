<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Facades\Head;
use Hypervel\Tests\Head\TestCase;

class PwaTest extends TestCase
{
    public function testRendersPwaHeadTags(): void
    {
        Head::pwa(
            name: 'Acme',
            manifest: '/site.webmanifest',
            themeColor: '#0f172a',
            appleTouchIcon: '/apple-touch-icon.png',
            appleWebAppStatusBarStyle: 'black',
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="application-name" content="Acme">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Acme">', $html);
        $this->assertStringContainsString('<meta name="mobile-web-app-capable" content="yes">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-status-bar-style" content="black">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#0f172a">', $html);
        $this->assertStringContainsString('rel="manifest" href="/site.webmanifest">', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">', $html);
    }
}
