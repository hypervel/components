<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Telescope\ExtractProperties;
use Hypervel\Telescope\Telescope;
use Hypervel\Tests\TestCase;
use JsonSerializable;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

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

    public function testSerializationCancellationEscapesInsteadOfPurgingTheValue(): void
    {
        $failure = new RuntimeException('Serialization cleanup failed.', previous: new CanceledException);
        $caught = null;

        try {
            ExtractProperties::from(new PropertyTarget(new ThrowingJsonSerializable($failure)));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
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
    /**
     * Supply a failure from an application's serializer.
     */
    public function __construct(private ?Throwable $failure = null)
    {
    }

    public function jsonSerialize(): mixed
    {
        throw $this->failure ?? new RuntimeException('Serialization failed.');
    }
}
