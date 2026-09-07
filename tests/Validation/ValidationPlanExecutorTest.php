<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Support\Json;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\RulePlan\ExposedExecutorValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;
use Symfony\Component\HttpFoundation\File\File;

class ValidationPlanExecutorTest extends TestCase
{
    #[DataProvider('typeCheckCases')]
    public function testTypeChecks(CheckType $type, mixed $value, bool $expected)
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck($type);

        $this->assertSame($expected, $validator->publicExecuteInline($check, $value, 'field'));
    }

    public static function typeCheckCases(): iterable
    {
        yield 'TypeString passes string' => [CheckType::TypeString, 'hello', true];
        yield 'TypeString fails int' => [CheckType::TypeString, 42, false];
        yield 'TypeNumeric passes int' => [CheckType::TypeNumeric, 42, true];
        yield 'TypeNumeric passes numeric string' => [CheckType::TypeNumeric, '42.5', true];
        yield 'TypeNumeric fails alpha' => [CheckType::TypeNumeric, 'abc', false];
        yield 'TypeInteger passes int-like string' => [CheckType::TypeInteger, '42', true];
        yield 'TypeInteger fails float-like string' => [CheckType::TypeInteger, '42.5', false];
        yield 'TypeIntegerStrict passes int' => [CheckType::TypeIntegerStrict, 42, true];
        yield 'TypeIntegerStrict fails string' => [CheckType::TypeIntegerStrict, '42', false];
        yield 'TypeBoolean passes true' => [CheckType::TypeBoolean, true, true];
        yield 'TypeBoolean passes 0' => [CheckType::TypeBoolean, 0, true];
        yield 'TypeBoolean passes string 1' => [CheckType::TypeBoolean, '1', true];
        yield 'TypeBoolean fails string yes' => [CheckType::TypeBoolean, 'yes', false];
        yield 'TypeArray passes array' => [CheckType::TypeArray, [1, 2], true];
        yield 'TypeArray fails string' => [CheckType::TypeArray, 'array', false];
    }

    #[DataProvider('formatCheckCases')]
    public function testFormatChecks(CheckType $type, mixed $value, bool $expected)
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck($type);

        $this->assertSame($expected, $validator->publicExecuteInline($check, $value, 'field'));
    }

    public static function formatCheckCases(): iterable
    {
        yield 'Ip passes valid' => [CheckType::Ip, '127.0.0.1', true];
        yield 'Ip fails invalid' => [CheckType::Ip, 'not-an-ip', false];
        yield 'Ipv4 passes' => [CheckType::Ipv4, '192.168.1.1', true];
        yield 'Ipv4 fails ipv6' => [CheckType::Ipv4, '::1', false];
        yield 'Ipv6 passes' => [CheckType::Ipv6, '::1', true];
        yield 'Ipv6 fails ipv4' => [CheckType::Ipv6, '192.168.1.1', false];
        yield 'Uuid passes' => [CheckType::Uuid, '550e8400-e29b-41d4-a716-446655440000', true];
        yield 'Uuid fails' => [CheckType::Uuid, 'not-uuid', false];
        yield 'Ulid passes' => [CheckType::Ulid, '01ARZ3NDEKTSV4RRFFQ69G5FAV', true];
        yield 'Ulid fails' => [CheckType::Ulid, 'not-ulid', false];
        yield 'Json passes' => [CheckType::Json, '{"key":"value"}', true];
        yield 'Json fails' => [CheckType::Json, '{invalid}', false];
        yield 'Ascii passes' => [CheckType::Ascii, 'hello', true];
        yield 'Ascii fails' => [CheckType::Ascii, 'héllo', false];
        yield 'HexColor passes' => [CheckType::HexColor, '#ff0000', true];
        yield 'HexColor fails' => [CheckType::HexColor, 'red', false];
        yield 'MacAddress passes' => [CheckType::MacAddress, '00:1B:44:11:3A:B7', true];
        yield 'MacAddress fails' => [CheckType::MacAddress, 'not-mac', false];
    }

    public function testJsonCheckUsesTheSupportNestingLimit(): void
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::Json);
        $value = 'leaf';

        for ($index = 0; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $this->assertTrue($validator->publicExecuteInline($check, Json::encode($value), 'field'));

        $value = ['value' => $value];

        $this->assertFalse($validator->publicExecuteInline(
            $check,
            json_encode($value, JSON_THROW_ON_ERROR, Json::MAXIMUM_NESTING_DEPTH + 1),
            'field'
        ));
    }

    public function testJsonCheckAcceptsStringableValuesAndRejectsResources(): void
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::Json);
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return '{"valid":true}';
            }
        };
        $resource = fopen('php://memory', 'r');

        try {
            $this->assertTrue($validator->publicExecuteInline($check, $stringable, 'field'));
            $this->assertFalse($validator->publicExecuteInline($check, $resource, 'field'));
        } finally {
            fclose($resource);
        }
    }

    public function testPreflightSafetyPartitionCoversEveryInlineCheckType(): void
    {
        $safe = [
            CheckType::TypeString, CheckType::TypeNumeric, CheckType::TypeInteger,
            CheckType::TypeIntegerStrict, CheckType::TypeBoolean, CheckType::TypeArray,
            CheckType::Email, CheckType::Url, CheckType::Ip, CheckType::Ipv4,
            CheckType::Ipv6, CheckType::Uuid, CheckType::Ulid, CheckType::Json,
            CheckType::Ascii, CheckType::HexColor, CheckType::MacAddress,
            CheckType::Alpha, CheckType::AlphaAscii, CheckType::AlphaDash,
            CheckType::AlphaDashAscii, CheckType::AlphaNum, CheckType::AlphaNumAscii,
            CheckType::Lowercase, CheckType::Uppercase,
            CheckType::SizeMin, CheckType::SizeMax, CheckType::SizeBetween,
            CheckType::SizeExact, CheckType::Digits, CheckType::DigitsBetween,
            CheckType::MinDigits, CheckType::MaxDigits,
            CheckType::StartsWith, CheckType::EndsWith, CheckType::DoesntStartWith,
            CheckType::DoesntEndWith, CheckType::In, CheckType::NotIn,
            CheckType::IsDate, CheckType::DateFormat,
        ];
        $unsafe = [
            CheckType::Regex, CheckType::NotRegex, CheckType::DateAfter,
            CheckType::DateBefore, CheckType::DateAfterOrEq, CheckType::DateBeforeOrEq,
            CheckType::DateEquals, CheckType::MultipleOf,
        ];
        $reviewedNames = array_map(
            static fn (CheckType $type): string => $type->name,
            [...$safe, ...$unsafe],
        );

        $this->assertCount(count(array_unique($reviewedNames)), $reviewedNames);
        $this->assertEqualsCanonicalizing(
            array_map(static fn (CheckType $type): string => $type->name, CheckType::cases()),
            $reviewedNames,
        );

        $validator = $this->makeValidator();

        foreach ($safe as $type) {
            $param = in_array($type, [
                CheckType::SizeMin,
                CheckType::SizeMax,
                CheckType::SizeBetween,
                CheckType::SizeExact,
            ], true) ? ['numeric' => false] : null;

            $this->assertTrue($validator->publicCanPreflightInline(new InlineCheck($type, $param), 'value'));
        }

        foreach ($unsafe as $type) {
            $this->assertFalse($validator->publicCanPreflightInline(new InlineCheck($type), 'value'));
        }
    }

    public function testPreflightRejectsObjectsAndResourcesWithoutInvokingThem(): void
    {
        $validator = $this->makeValidator();
        $stringable = new class implements Stringable {
            public int $casts = 0;

            public function __toString(): string
            {
                ++$this->casts;

                return 'value';
            }
        };
        $file = new class(__FILE__, false) extends File {
            public int $reads = 0;

            /**
             * Get the file size and record the read.
             */
            public function getSize(): int|false
            {
                ++$this->reads;

                return parent::getSize();
            }
        };
        $resource = fopen('php://memory', 'r');

        try {
            $this->assertFalse($validator->publicCanPreflightInline(
                new InlineCheck(CheckType::TypeString),
                $stringable,
            ));
            $this->assertFalse($validator->publicCanPreflightInline(
                new InlineCheck(CheckType::SizeMax, ['numeric' => false]),
                $file,
            ));
            $this->assertFalse($validator->publicCanPreflightInline(
                new InlineCheck(CheckType::TypeString),
                $resource,
            ));
            $this->assertSame(0, $stringable->casts);
            $this->assertSame(0, $file->reads);
        } finally {
            fclose($resource);
        }
    }

    public function testPreflightAllowsArraysButRejectsUnsafeNumericSizeValues(): void
    {
        $validator = $this->makeValidator();
        $arraySize = new InlineCheck(CheckType::SizeMax, ['numeric' => false]);
        $numericSize = new InlineCheck(CheckType::SizeMax, ['numeric' => true]);

        $this->assertTrue($validator->publicCanPreflightInline($arraySize, ['value']));
        $this->assertTrue($validator->publicCanPreflightInline($numericSize, '100'));
        $this->assertFalse($validator->publicCanPreflightInline($numericSize, '1e2'));
        $this->assertFalse($validator->publicCanPreflightInline($numericSize, INF));
        $this->assertFalse($validator->publicCanPreflightInline($numericSize, NAN));
    }

    #[DataProvider('charClassCases')]
    public function testCharacterClassChecks(CheckType $type, mixed $value, bool $expected)
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck($type);

        $this->assertSame($expected, $validator->publicExecuteInline($check, $value, 'field'));
    }

    public static function charClassCases(): iterable
    {
        yield 'Alpha passes' => [CheckType::Alpha, 'hello', true];
        yield 'Alpha fails with numbers' => [CheckType::Alpha, 'hello1', false];
        yield 'AlphaAscii passes' => [CheckType::AlphaAscii, 'hello', true];
        yield 'AlphaAscii fails unicode' => [CheckType::AlphaAscii, 'héllo', false];
        yield 'AlphaDash passes' => [CheckType::AlphaDash, 'hello-world_1', true];
        yield 'AlphaDash fails space' => [CheckType::AlphaDash, 'hello world', false];
        yield 'AlphaNum passes' => [CheckType::AlphaNum, 'hello123', true];
        yield 'AlphaNum fails dash' => [CheckType::AlphaNum, 'hello-123', false];
        yield 'Lowercase passes' => [CheckType::Lowercase, 'hello', true];
        yield 'Lowercase fails' => [CheckType::Lowercase, 'Hello', false];
        yield 'Uppercase passes' => [CheckType::Uppercase, 'HELLO', true];
        yield 'Uppercase fails' => [CheckType::Uppercase, 'Hello', false];
    }

    public function testSizeMinWithStringValue(): void
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::SizeMin, [
            'numeric' => false,
            'threshold' => ['raw' => '3', 'integer' => 3],
        ]);

        $this->assertTrue($validator->publicExecuteInline($check, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'hi', 'field'));
    }

    public function testSizeMaxWithNumericSemantics(): void
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::SizeMax, [
            'numeric' => true,
            'threshold' => ['raw' => '100', 'integer' => 100],
        ]);

        $this->assertTrue($validator->publicExecuteInline($check, '50', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '150', 'field'));
    }

    public function testSizeBetweenWithArrayValue(): void
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::SizeBetween, [
            'numeric' => false,
            'minimum' => ['raw' => '1', 'integer' => 1],
            'maximum' => ['raw' => '3', 'integer' => 3],
        ]);

        $this->assertTrue($validator->publicExecuteInline($check, [1, 2], 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, [1, 2, 3, 4], 'field'));
    }

    public function testDigits()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::Digits, 5);

        $this->assertTrue($validator->publicExecuteInline($check, '12345', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '1234', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '123.5', 'field'));
    }

    public function testDigitsBetween()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::DigitsBetween, [3, 5]);

        $this->assertTrue($validator->publicExecuteInline($check, '1234', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '12', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '123456', 'field'));
    }

    public function testRegex()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::Regex, '/^[a-z]+$/');

        $this->assertTrue($validator->publicExecuteInline($check, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'Hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 123, 'field'));
    }

    public function testStartsEndsWith()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::StartsWith, ['foo', 'bar']);
        $this->assertTrue($validator->publicExecuteInline($check, 'foobar', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'bazbar', 'field'));

        $check = new InlineCheck(CheckType::EndsWith, ['bar']);
        $this->assertTrue($validator->publicExecuteInline($check, 'foobar', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'foobaz', 'field'));
    }

    public function testInAndNotIn(): void
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::In, ['a', 'b', 'c']);
        $this->assertTrue($validator->publicExecuteInline($check, 'a', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'd', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, ['a'], 'field'));

        $check = new InlineCheck(CheckType::NotIn, ['a', 'b']);
        $this->assertTrue($validator->publicExecuteInline($check, 'c', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'a', 'field'));
        $this->assertTrue($validator->publicExecuteInline($check, ['a'], 'field'));

        $this->assertFalse($validator->publicExecuteInline(
            new InlineCheck(CheckType::In, ['0e123']),
            '0e456',
            'field'
        ));
        $this->assertTrue($validator->publicExecuteInline(
            new InlineCheck(CheckType::NotIn, ['0e123']),
            '0e456',
            'field'
        ));
        $this->assertTrue($validator->publicExecuteInline(
            new InlineCheck(CheckType::In, ['1']),
            1,
            'field'
        ));
    }

    #[DataProvider('guardedCheckCases')]
    public function testGuardedChecks(CheckType $type, mixed $parameters, mixed $value, bool $expected): void
    {
        $validator = $this->makeValidator();

        $this->assertSame(
            $expected,
            $validator->publicExecuteInline(new InlineCheck($type, $parameters), $value, 'field')
        );
    }

    public static function guardedCheckCases(): iterable
    {
        yield 'hex color rejects integer' => [CheckType::HexColor, null, 123, false];
        yield 'lowercase rejects integer' => [CheckType::Lowercase, null, 123, false];
        yield 'uppercase rejects boolean' => [CheckType::Uppercase, null, true, false];
        yield 'digits accepts integer' => [CheckType::Digits, 3, 123, true];
        yield 'digits rejects array' => [CheckType::Digits, 3, ['123'], false];
        yield 'digits between rejects object' => [CheckType::DigitsBetween, [1, 3], new \stdClass, false];
        yield 'minimum digits rejects null' => [CheckType::MinDigits, 2, null, false];
        yield 'maximum digits rejects boolean' => [CheckType::MaxDigits, 2, true, false];
        yield 'starts with accepts integer' => [CheckType::StartsWith, ['12'], 123, true];
        yield 'starts with rejects array' => [CheckType::StartsWith, ['12'], [123], false];
        yield 'ends with rejects boolean' => [CheckType::EndsWith, ['1'], true, false];
        yield 'does not start with rejects array' => [CheckType::DoesntStartWith, ['12'], [123], false];
        yield 'does not end with rejects object' => [CheckType::DoesntEndWith, ['3'], new \stdClass, false];
    }

    public function testDateChecks(): void
    {
        $validator = $this->makeValidator();

        $this->assertTrue($validator->publicExecuteInline(new InlineCheck(CheckType::IsDate), '2025-01-01', 'field'));
        $this->assertFalse($validator->publicExecuteInline(new InlineCheck(CheckType::IsDate), 'not-a-date', 'field'));
    }

    public function testDateComparisonsRejectInvalidOperands(): void
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::DateBefore, ['target' => '2025-01-02', 'format' => null]);

        $this->assertTrue($validator->publicExecuteInline($check, '2025-01-01', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, [], 'field'));
        $this->assertFalse($validator->publicExecuteInline(
            new InlineCheck(CheckType::DateBefore, ['target' => [], 'format' => null]),
            '2025-01-01',
            'field'
        ));
        $this->assertFalse($validator->publicExecuteInline(
            new InlineCheck(CheckType::DateEquals, ['target' => null, 'format' => null]),
            null,
            'field'
        ));
        $this->assertTrue($validator->publicExecuteInline(
            new InlineCheck(CheckType::DateBefore, ['target' => 20250102, 'format' => 'Ymd']),
            20250101,
            'field'
        ));
    }

    public function testDateFormatWithMultipleFormats()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::DateFormat, ['Y-m-d H:i:s', 'H:i:s']);

        $this->assertTrue($validator->publicExecuteInline($check, '2025-01-01 12:00:00', 'field'));
        $this->assertTrue($validator->publicExecuteInline($check, '12:00:00', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '2025-01-01', 'field'));
    }

    public function testMultipleOf()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::MultipleOf, '5');

        $this->assertTrue($validator->publicExecuteInline($check, 10, 'field'));
        $this->assertTrue($validator->publicExecuteInline($check, '15', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 7, 'field'));
    }

    public function testSizeComparisonWithIntegerThreshold()
    {
        $validator = $this->makeValidator();

        $stringMax = new InlineCheck(CheckType::SizeMax, [
            'numeric' => false,
            'threshold' => ['raw' => '5', 'integer' => 5],
        ]);
        $this->assertTrue($validator->publicExecuteInline($stringMax, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($stringMax, 'hello!', 'field'));

        $arrayMin = new InlineCheck(CheckType::SizeMin, [
            'numeric' => false,
            'threshold' => ['raw' => '2', 'integer' => 2],
        ]);
        $this->assertTrue($validator->publicExecuteInline($arrayMin, [1, 2], 'field'));
        $this->assertFalse($validator->publicExecuteInline($arrayMin, [1], 'field'));
    }

    public function testSizeComparisonWithDecimalThresholdUsesExactComparison(): void
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeMax, [
            'numeric' => false,
            'threshold' => ['raw' => '3.5', 'integer' => null],
        ]);
        $this->assertTrue($validator->publicExecuteInline($check, 'abc', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'abcd', 'field'));
    }

    public function testSizeExactWithDecimalThresholdRejectsIntegerSize()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeExact, [
            'numeric' => false,
            'threshold' => ['raw' => '3.5', 'integer' => null],
        ]);
        $this->assertFalse($validator->publicExecuteInline($check, 'abc', 'field'));
    }

    public function testSizeBetweenWithIntegerThresholds()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeBetween, [
            'numeric' => false,
            'minimum' => ['raw' => '2', 'integer' => 2],
            'maximum' => ['raw' => '5', 'integer' => 5],
        ]);
        $this->assertTrue($validator->publicExecuteInline($check, 'hi', 'field'));
        $this->assertTrue($validator->publicExecuteInline($check, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'h', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'helloo', 'field'));
    }

    public function testSizeBetweenWithDecimalThresholdUsesExactComparison(): void
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeBetween, [
            'numeric' => false,
            'minimum' => ['raw' => '1', 'integer' => 1],
            'maximum' => ['raw' => '3.5', 'integer' => null],
        ]);
        $this->assertTrue($validator->publicExecuteInline($check, 'abc', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'abcd', 'field'));
    }

    public function testNumericSizeUsesBigNumber(): void
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeMax, [
            'numeric' => true,
            'threshold' => ['raw' => '100', 'integer' => 100],
        ]);
        $this->assertTrue($validator->publicExecuteInline($check, '50', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '150', 'field'));
    }

    public function testSizeExactWithArrayValue(): void
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeExact, [
            'numeric' => false,
            'threshold' => ['raw' => '3', 'integer' => 3],
        ]);
        $this->assertTrue($validator->publicExecuteInline($check, [1, 2, 3], 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, [1, 2], 'field'));
    }

    private function makeValidator(): ExposedExecutorValidator
    {
        $translator = new Translator(new ArrayLoader, 'en');

        return new ExposedExecutorValidator($translator, [], []);
    }
}

namespace Hypervel\Validation\RulePlan;

use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\Validator;

/**
 * Test subclass that exposes the protected executeInline() method.
 */
class ExposedExecutorValidator extends Validator
{
    public function publicCanPreflightInline(InlineCheck $check, mixed $value): bool
    {
        return $this->canPreflightInline($check, $value);
    }

    public function publicExecuteInline(InlineCheck $check, mixed $value, string $attribute): bool
    {
        return $this->executeInline($check, $value, $attribute);
    }
}
