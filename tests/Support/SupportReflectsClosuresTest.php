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

    public function testItThrowsWhenFirstParameterHasNoTypeHintEvenIfLaterParameterDoes(): void
    {
        $this->expectExceptionObject(new RuntimeException('The first parameter of the given Closure is missing a type hint.'));

        ReflectsClosuresClass::reflectFirstAll(function ($a, ExampleParameter $b): void {
        });
    }

    public function testClosureReturnTypesReturnsClassReturnType(): void
    {
        $this->assertSame(
            [ExampleParameter::class],
            ReflectsClosuresClass::reflectReturnTypes(fn (): ExampleParameter => new ExampleParameter)
        );
    }

    public function testClosureReturnTypesExcludesBuiltinReturnType(): void
    {
        $this->assertSame(
            [],
            ReflectsClosuresClass::reflectReturnTypes(fn (): string => 'foo')
        );
    }

    public function testClosureReturnTypesReturnsEmptyArrayWhenNoReturnType(): void
    {
        $this->assertSame(
            [],
            ReflectsClosuresClass::reflectReturnTypes(fn () => 'foo')
        );
    }

    public function testClosureReturnTypesKeepsOnlyClassTypesFromUnionReturnType(): void
    {
        $this->assertSame(
            [ExampleParameter::class],
            ReflectsClosuresClass::reflectReturnTypes(fn (): ExampleParameter|string => new ExampleParameter)
        );

        $this->assertSame(
            [ExampleParameter::class, AnotherExampleParameter::class],
            ReflectsClosuresClass::reflectReturnTypes(fn (): ExampleParameter|AnotherExampleParameter => new ExampleParameter)
        );
    }

    public function testClosureReturnTypesReturnsEmptyArrayForIntersectionReturnType(): void
    {
        $this->assertSame(
            [],
            ReflectsClosuresClass::reflectReturnTypes(fn (): ReflectsClosuresInterfaceOne&ReflectsClosuresInterfaceTwo => new ReflectsClosuresBothInterfaces)
        );
    }

    #[DataProvider('invalidFirstParameterProvider')]
    public function testFirstParameterTypesRejectInvalidActualFirstParameter(Closure $closure): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The first parameter of the given Closure is missing a type hint.');

        ReflectsClosuresClass::reflectFirstAll($closure);
    }

    /**
     * Supply first parameters without a usable class type.
     */
    public static function invalidFirstParameterProvider(): array
    {
        return [
            'builtin' => [function (string $first, ExampleParameter $second): void {
            }],
            'variadic' => [function (ExampleParameter ...$first): void {
            }],
        ];
    }

    public function testClosureReturnTypesResolveRelativeClassNames(): void
    {
        $this->assertSame(
            [ReflectsClosuresClass::class],
            ReflectsClosuresClass::reflectReturnTypes(ReflectsClosuresClass::closureWithSelfReturnType()),
        );
        $this->assertSame(
            [ReflectsClosuresClass::class],
            ReflectsClosuresClass::reflectReturnTypes(ReflectsClosuresClass::closureWithStaticReturnType()),
        );
        $this->assertSame(
            [RelativeReturnTypeParent::class],
            ReflectsClosuresClass::reflectReturnTypes(RelativeReturnTypeClosures::returnsParent(...)),
        );
        $this->assertSame(
            [RelativeReturnTypeClosures::class],
            ReflectsClosuresClass::reflectReturnTypes(RelativeReturnTypeClosures::returnsSelf(...)),
        );
        $this->assertSame(
            [RelativeReturnTypeClosures::class],
            ReflectsClosuresClass::reflectReturnTypes(RelativeReturnTypeClosures::returnsStatic(...)),
        );
        $this->assertSame(
            [RelativeReturnTypeGrandChild::class],
            ReflectsClosuresClass::reflectReturnTypes(RelativeReturnTypeGrandChild::returnsStatic(...)),
        );
        $this->assertSame(
            [self::class],
            ReflectsClosuresClass::reflectReturnTypes(function (): self {
                return $this;
            }),
        );
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

    /**
     * Get the class names in the closure's return type.
     */
    public static function reflectReturnTypes(Closure $closure): array
    {
        return (new static)->closureReturnTypes($closure);
    }

    /**
     * Create a closure with a self return type.
     */
    public static function closureWithSelfReturnType(): Closure
    {
        return function (): self {
            return new self;
        };
    }

    /**
     * Create a closure with a static return type.
     */
    public static function closureWithStaticReturnType(): Closure
    {
        return function (): static {
            return new static;
        };
    }
}

class ExampleParameter
{
}

class AnotherExampleParameter
{
}

interface ReflectsClosuresInterfaceOne
{
}

interface ReflectsClosuresInterfaceTwo
{
}

class ReflectsClosuresBothInterfaces implements ReflectsClosuresInterfaceOne, ReflectsClosuresInterfaceTwo
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

class RelativeReturnTypeGrandChild extends RelativeReturnTypeClosures
{
}
