<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Hypervel\Mcp\Enums\IconTheme;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Attributes\Icon as IconAttribute;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class IconTest extends TestCase
{
    public function testAcceptsHttpsUrls(): void
    {
        $icon = new Icon('https://example.com/icon.png', mimeType: 'image/png', sizes: ['48x48']);

        $this->assertSame('https://example.com/icon.png', $icon->src);
        $this->assertSame('image/png', $icon->mimeType);
        $this->assertSame(['48x48'], $icon->sizes);
        $this->assertNull($icon->theme);
    }

    public function testAcceptsDataUris(): void
    {
        $icon = new Icon('data:image/png;base64,iVBORw0KGgo=');

        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $icon->src);
    }

    #[DataProvider('themes')]
    public function testAcceptsLightAndDarkThemes(IconTheme $theme): void
    {
        $icon = new Icon('https://example.com/icon.png', theme: $theme);
        $this->assertSame($theme, $icon->theme);
    }

    /**
     * Provide supported icon themes.
     */
    public static function themes(): array
    {
        return [[IconTheme::Light], [IconTheme::Dark]];
    }

    public function testOmitsNullAndEmptyFieldsInToArray(): void
    {
        $icon = new Icon('https://example.com/icon.png');

        $this->assertSame(['src' => 'https://example.com/icon.png'], $icon->toArray());
    }

    public function testEmitsAllSetFieldsInToArray(): void
    {
        $icon = new Icon(
            src: 'https://example.com/icon.svg',
            mimeType: 'image/svg+xml',
            sizes: ['any'],
            theme: IconTheme::Dark,
        );

        $this->assertSame([
            'src' => 'https://example.com/icon.svg',
            'mimeType' => 'image/svg+xml',
            'sizes' => ['any'],
            'theme' => 'dark',
        ], $icon->toArray());
    }

    public function testResolvesIconAttributeRelativePathsViaAsset(): void
    {
        config(['app.url' => 'https://app.test']);
        URL::useOrigin('https://app.test');
        URL::forceScheme('https');

        $attribute = new IconAttribute('icons/server.png', mimeType: 'image/png');

        $this->assertSame('https://app.test/icons/server.png', $attribute->toIcon()->src);
        $this->assertSame('image/png', $attribute->toIcon()->mimeType);
    }

    public function testPassesIconAttributeHttpsUrlsThroughUnchanged(): void
    {
        $attribute = new IconAttribute('https://example.com/icon.png');

        $this->assertSame('https://example.com/icon.png', $attribute->toIcon()->src);
    }
}
