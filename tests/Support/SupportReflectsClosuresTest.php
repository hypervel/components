<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Closure;
use Hypervel\Support\Traits\ReflectsClosures;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class SupportReflectsClosuresTest extends TestCase
{
    public function testReflectsClosures(): void
    {
        $this->assertParameterTypes([ExampleParameter::class], function (ExampleParameter $one) {
            // assert the Closure isn't actually executed
            throw new RuntimeException;
        });

        $this->assertParameterTypes([], function () {
        });

        $this->assertParameterTypes([null], function ($one) {
        });

        $this->assertParameterTypes([null, ExampleParameter::class], function ($one, ?ExampleParameter $two = null) {
        });

        $this->assertParameterTypes([null, ExampleParameter::class], function (string $one, ?ExampleParameter $two) {
        });

        // Because the parameter is variadic, the closure will always receive an array.
        $this->assertParameterTypes([null], function (ExampleParameter ...$vars) {
        });
    }

    public function testItReturnsTheFirstParameterType(): void
    {
        $type = ReflectsClosuresClass::reflectFirst(function (ExampleParameter $a) {
        });

        $this->assertInstanceOf($type, new ExampleParameter);
    }

    public function testItThrowsWhenNoParameters(): void
    {
        $this->expectException(RuntimeException::class);

        ReflectsClosuresClass::reflectFirst(function () {
        });
    }

    public function testItThrowsWhenNoFirstParameterType(): void
    {
        $this->expectException(RuntimeException::class);

        ReflectsClosuresClass::reflectFirst(function ($a, ExampleParameter $b) {
        });
    }

    public function testItWorksWithUnionTypes(): void
    {
        $types = ReflectsClosuresClass::reflectFirstAll(function (ExampleParameter $a, $b) {
        });

        $this->assertEquals([
            ExampleParameter::class,
        ], $types);

        $closure = require __DIR__ . '/Fixtures/UnionTypesClosure.php';

        $types = ReflectsClosuresClass::reflectFirstAll($closure);

        $this->assertEquals([
            ExampleParameter::class,
            AnotherExampleParameter::class,
        ], $types);
    }

    public function testItWorksWithUnionTypesWithNoTypeHints(): void
    {
        $this->expectException(RuntimeException::class);

        ReflectsClosuresClass::reflectFirstAll(function ($a, $b) {
        });
    }

    public function testItWorksWithUnionTypesWithNoArguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The given Closure has no parameters.');

        ReflectsClosuresClass::reflectFirstAll(function () {
        });
    }

    #[DataProvider('invalidFirstParameterProvider')]
    public function testFirstParameterTypesRejectInvalidActualFirstParameter(Closure $closure): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The first parameter of the given Closure is missing a type hint.');

        ReflectsClosuresClass::reflectFirstAll($closure);
    }

    public static function invalidFirstParameterProvider(): array
    {
        return [
            'untyped' => [function ($first, ExampleParameter $second) {
            }],
            'builtin' => [function (string $first, ExampleParameter $second) {
            }],
            'variadic' => [function (ExampleParameter ...$first) {
            }],
        ];
    }

    public function testClosureReturnTypesRejectRelativeClassNames(): void
    {
        $this->assertSame([], ReflectsClosuresClass::reflectReturns(RelativeReturnTypeClosures::returnsParent(...)));
        $this->assertSame([], ReflectsClosuresClass::reflectReturns(RelativeReturnTypeClosures::returnsSelf(...)));
        $this->assertSame([], ReflectsClosuresClass::reflectReturns(RelativeReturnTypeClosures::returnsStatic(...)));
    }

    private function assertParameterTypes(array $expected, Closure $closure): void
    {
        $types = ReflectsClosuresClass::reflect($closure);

        $this->assertSame($expected, $types);
    }
}

class ReflectsClosuresClass
{
    use ReflectsClosures;

    public static function reflect(Closure $closure): array
    {
        return array_values((new static)->closureParameterTypes($closure));
    }

    public static function reflectFirst(Closure $closure): string
    {
        return (new static)->firstClosureParameterType($closure);
    }

    public static function reflectFirstAll(Closure $closure): array
    {
        return (new static)->firstClosureParameterTypes($closure);
    }

    public static function reflectReturns(Closure $closure): array
    {
        return (new static)->closureReturnTypes($closure);
    }
}

class ExampleParameter
{
}

class AnotherExampleParameter
{
}

class RelativeReturnTypeParent
{
}

class RelativeReturnTypeClosures extends RelativeReturnTypeParent
{
    public static function returnsParent(): parent
    {
        return new parent;
    }

    public static function returnsSelf(): self
    {
        return new self;
    }

    public static function returnsStatic(): static
    {
        return new static;
    }
}
