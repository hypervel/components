<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Telescope\ExtractProperties;
use Hypervel\Tests\TestCase;
use JsonException;

class ExtractPropertiesTest extends TestCase
{
    public function testNestedObjectStateRoundTripsAtTheMaximumNestingDepth(): void
    {
        $nested = $this->nestedValue(511);
        $target = new PropertyTarget((object) ['nested' => $nested]);

        $properties = ExtractProperties::from($target);

        $this->assertSame($nested, $properties['value']['properties']['nested']);
    }

    public function testUnencodablePropertyRaisesTheNativeJsonException(): void
    {
        $this->expectException(JsonException::class);

        ExtractProperties::from(new PropertyTarget(NAN));
    }

    private function nestedValue(int $depth): array
    {
        $value = 'leaf';

        for ($index = 0; $index < $depth; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }
}

class PropertyTarget
{
    public function __construct(public mixed $value)
    {
    }
}
