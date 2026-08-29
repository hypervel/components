<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Telescope\ExtractProperties;
use Hypervel\Telescope\Telescope;
use Hypervel\Tests\TestCase;
use JsonSerializable;
use RuntimeException;

class ExtractPropertiesTest extends TestCase
{
    public function testNestedObjectStateRoundTripsAtTheMaximumNestingDepth(): void
    {
        $nested = $this->nestedValue(511);
        $target = new PropertyTarget((object) ['nested' => $nested]);

        $properties = ExtractProperties::from($target);

        $this->assertSame($nested, $properties['value']['properties']['nested']);
    }

    public function testObservedValuesUsePartialOutputAndInvalidUtf8Substitution(): void
    {
        $properties = ExtractProperties::from(new PropertyTarget([
            'number' => NAN,
            'text' => "invalid\xB1",
            'object' => (object) ['number' => INF],
        ]));

        $this->assertSame([
            'number' => 0,
            'text' => 'invalid�',
            'object' => ['number' => 0],
        ], $properties['value']);
    }

    public function testValuesBeyondTheJsonNestingLimitArePurged(): void
    {
        $properties = ExtractProperties::from(new PropertyTarget($this->nestedValue(513)));

        $this->assertSame(Telescope::PURGED_VALUE, $properties['value']);
    }

    public function testThrowingSerializersArePurged(): void
    {
        $properties = ExtractProperties::from(new PropertyTarget(new ThrowingJsonSerializable));

        $this->assertSame([
            'class' => ThrowingJsonSerializable::class,
            'properties' => Telescope::PURGED_VALUE,
        ], $properties['value']);
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

class ThrowingJsonSerializable implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('Serialization failed.');
    }
}
