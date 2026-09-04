<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BackedEnum;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;
use ValueError;

use function Hypervel\Support\enum_from;
use function Hypervel\Support\enum_try_from;
use function Hypervel\Support\enum_value;

include_once __DIR__ . '/Enums.php';

class SupportEnumFunctionsTest extends TestCase
{
    #[DataProvider('scalarDataProvider')]
    public function testItCanHandleEnumValue(mixed $given, mixed $expected): void
    {
        $this->assertSame($expected, enum_value($given));
    }

    public static function scalarDataProvider(): iterable
    {
        yield [TestEnum::A, 'A'];
        yield [TestBackedEnum::A, 1];
        yield [TestBackedEnum::B, 2];
        yield [TestStringBackedEnum::A, 'A'];
        yield [TestStringBackedEnum::B, 'B'];
        yield [null, null];
        yield [0, 0];
        yield ['0', '0'];
        yield [false, false];
        yield [1, 1];
        yield ['1', '1'];
        yield [true, true];
        yield [[], []];
        yield ['', ''];
        yield ['hypervel', 'hypervel'];
        yield [1337, 1337];
        yield [1.0, 1.0];
        yield [$collect = collect(), $collect];
    }

    public function testItCanFallbackToUseDefaultIfValueIsNull(): void
    {
        $this->assertSame('hypervel', enum_value(null, 'hypervel'));
        $this->assertSame('hypervel', enum_value(null, fn () => 'hypervel'));
    }

    #[DataProvider('backedEnumDataProvider')]
    public function testItCanTryBackedEnumValues(
        string $enum,
        mixed $value,
        ?BackedEnum $expected,
    ): void {
        $this->assertSame($expected, enum_try_from($enum, $value));
    }

    public static function backedEnumDataProvider(): iterable
    {
        yield 'integer' => [TestBackedEnum::class, 1, TestBackedEnum::A];
        yield 'numeric string' => [TestBackedEnum::class, '1', TestBackedEnum::A];
        yield 'trimmed numeric string' => [TestBackedEnum::class, ' 2 ', TestBackedEnum::B];
        yield 'float' => [TestBackedEnum::class, 1.5, TestBackedEnum::A];
        yield 'boolean' => [TestBackedEnum::class, true, TestBackedEnum::A];
        yield 'matching instance' => [TestBackedEnum::class, TestBackedEnum::B, TestBackedEnum::B];
        yield 'maximum integer string' => [SupportIntegerDomainEnum::class, '9223372036854775807', SupportIntegerDomainEnum::Max];
        yield 'positive overflow string' => [SupportIntegerDomainEnum::class, '9223372036854775808', null];
        yield 'minimum integer string' => [SupportIntegerDomainEnum::class, '-9223372036854775808', SupportIntegerDomainEnum::Min];
        yield 'rounded negative overflow string' => [SupportIntegerDomainEnum::class, '-9223372036854775809', SupportIntegerDomainEnum::Min];
        yield 'positive exponent overflow' => [SupportIntegerDomainEnum::class, '1e999', null];
        yield 'negative exponent overflow' => [SupportIntegerDomainEnum::class, '-1e999', null];
        yield 'positive infinity' => [SupportIntegerDomainEnum::class, INF, null];
        yield 'negative infinity' => [SupportIntegerDomainEnum::class, -INF, null];
        yield 'not a number' => [SupportIntegerDomainEnum::class, NAN, null];
        yield 'positive float boundary' => [SupportIntegerDomainEnum::class, (float) PHP_INT_MAX, null];
        yield 'minimum float boundary' => [SupportIntegerDomainEnum::class, -9.2233720368547758E18, SupportIntegerDomainEnum::Min];
        yield 'hexadecimal string' => [SupportIntegerDomainEnum::class, '0x1A', null];
        yield 'string' => [TestStringBackedEnum::class, 'A', TestStringBackedEnum::A];
        yield 'integer to string' => [SupportCoercibleStringEnum::class, 1, SupportCoercibleStringEnum::Integer];
        yield 'float to string' => [SupportCoercibleStringEnum::class, 1.5, SupportCoercibleStringEnum::Float];
        yield 'true to string' => [SupportCoercibleStringEnum::class, true, SupportCoercibleStringEnum::Integer];
        yield 'false to string' => [SupportCoercibleStringEnum::class, false, SupportCoercibleStringEnum::Empty];
        yield 'stringable' => [SupportCoercibleStringEnum::class, new SupportStringableEnumValue, SupportCoercibleStringEnum::Stringable];
        yield 'missing case' => [TestBackedEnum::class, 3, null];
        yield 'non-numeric string' => [TestBackedEnum::class, 'invalid', null];
        yield 'empty integer string' => [TestBackedEnum::class, ' ', null];
        yield 'unsupported value' => [TestStringBackedEnum::class, [], null];
        yield 'unit enum' => [TestEnum::class, 'A', null];
        yield 'missing enum' => ['MissingEnum', 'A', null];
    }

    public function testItCanCreateABackedEnum(): void
    {
        $this->assertSame(TestBackedEnum::A, enum_from(TestBackedEnum::class, '1'));
    }

    public function testEnumFromThrowsForAnInvalidBackingValue(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage(
            sprintf('"invalid" is not a valid backing value for enum %s', TestStringBackedEnum::class)
        );

        enum_from(TestStringBackedEnum::class, 'invalid');
    }

    public function testEnumFromUsesABoundedTypeNameForNonScalarValues(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage(
            sprintf('array is not a valid backing value for enum %s', TestStringBackedEnum::class)
        );

        enum_from(TestStringBackedEnum::class, ['invalid']);
    }
}

enum SupportIntegerDomainEnum: int
{
    case Min = PHP_INT_MIN;
    case Zero = 0;
    case Max = PHP_INT_MAX;
}

enum SupportCoercibleStringEnum: string
{
    case Integer = '1';
    case Float = '1.5';
    case Empty = '';
    case Stringable = 'stringable';
}

class SupportStringableEnumValue implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}
