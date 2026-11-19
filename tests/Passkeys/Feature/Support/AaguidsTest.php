<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Support;

use Hypervel\Passkeys\Support\Aaguids;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use ReflectionProperty;

class AaguidsTest extends TestCase
{
    public function testItResolvesALabelWithoutLoadingIconData(): void
    {
        $this->assertSame('AliasVault', Aaguids::labelFor('a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942'));

        $this->assertNull((new ReflectionProperty(Aaguids::class, 'icons'))->getValue());
    }

    public function testItLoadsIconDataOnlyOnceAnIconIsRequested(): void
    {
        Aaguids::iconFor('a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942');

        $icons = new ReflectionProperty(Aaguids::class, 'icons');

        $this->assertIsArray($icons->getValue());
    }

    public function testItResolvesLightAndDarkIcons(): void
    {
        $aaguid = 'a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942';

        $this->assertStringStartsWith('data:image/svg+xml;base64,', Aaguids::iconFor($aaguid, theme: 'light'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', Aaguids::iconFor($aaguid, theme: 'dark'));
    }

    public function testItDefaultsToTheLightTheme(): void
    {
        $aaguid = 'a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942';

        $this->assertSame(Aaguids::iconFor($aaguid, theme: 'light'), Aaguids::iconFor($aaguid));
    }

    public function testItRejectsAnUnsupportedTheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("Unsupported icon theme [sepia]. Expected 'light' or 'dark'.");

        Aaguids::iconFor('a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942', theme: 'sepia');
    }

    public function testItReturnsNullForAnUnknownAaguid(): void
    {
        $this->assertNull(Aaguids::labelFor('does-not-exist'));
        $this->assertNull(Aaguids::iconFor('does-not-exist'));
    }

    public function testItReturnsNullForAnAaguidWithNoIcon(): void
    {
        $withoutIcon = collect(Aaguids::all())
            ->keys()
            ->first(fn (string $aaguid): bool => Aaguids::iconFor($aaguid) === null);

        $this->assertNotNull($withoutIcon);
        $this->assertNotNull(Aaguids::labelFor($withoutIcon));
    }
}
