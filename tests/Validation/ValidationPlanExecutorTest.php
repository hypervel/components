<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Enums\CheckType;
use Hypervel\Validation\Enums\SizeMode;
use Hypervel\Validation\InlineCheck;
use Hypervel\Validation\RulePlan\ExposedExecutorValidator;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testSizeMinWithStringMode()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::SizeMin, ['n' => '3', 'mode' => SizeMode::String]);

        $this->assertTrue($validator->publicExecuteInline($check, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'hi', 'field'));
    }

    public function testSizeMaxWithNumericMode()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::SizeMax, ['n' => '100', 'mode' => SizeMode::Numeric]);

        $this->assertTrue($validator->publicExecuteInline($check, '50', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '150', 'field'));
    }

    public function testSizeBetweenWithArrayMode()
    {
        $validator = $this->makeValidator();
        $check = new InlineCheck(CheckType::SizeBetween, ['min' => '1', 'max' => '3', 'mode' => SizeMode::Array]);

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

    public function testInAndNotIn()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::In, ['a', 'b', 'c']);
        $this->assertTrue($validator->publicExecuteInline($check, 'a', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'd', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, ['a'], 'field'));

        $check = new InlineCheck(CheckType::NotIn, ['a', 'b']);
        $this->assertTrue($validator->publicExecuteInline($check, 'c', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'a', 'field'));
    }

    public function testDateChecks()
    {
        $validator = $this->makeValidator();

        $this->assertTrue($validator->publicExecuteInline(new InlineCheck(CheckType::IsDate), '2025-01-01', 'field'));
        $this->assertFalse($validator->publicExecuteInline(new InlineCheck(CheckType::IsDate), 'not-a-date', 'field'));
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

    // --- Native size comparison tests ---

    public function testSizeComparisonWithIntegerThreshold()
    {
        $validator = $this->makeValidator();

        $stringMax = new InlineCheck(CheckType::SizeMax, ['n' => '5', 'mode' => SizeMode::String]);
        $this->assertTrue($validator->publicExecuteInline($stringMax, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($stringMax, 'hello!', 'field'));

        $arrayMin = new InlineCheck(CheckType::SizeMin, ['n' => '2', 'mode' => SizeMode::Array]);
        $this->assertTrue($validator->publicExecuteInline($arrayMin, [1, 2], 'field'));
        $this->assertFalse($validator->publicExecuteInline($arrayMin, [1], 'field'));
    }

    public function testSizeComparisonWithDecimalThresholdUsesNativeComparison()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeMax, ['n' => '3.5', 'mode' => SizeMode::String]);
        $this->assertTrue($validator->publicExecuteInline($check, 'abc', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'abcd', 'field'));
    }

    public function testSizeExactWithDecimalThresholdRejectsIntegerSize()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeExact, ['n' => '3.5', 'mode' => SizeMode::String]);
        $this->assertFalse($validator->publicExecuteInline($check, 'abc', 'field'));
    }

    public function testSizeBetweenWithIntegerThresholds()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeBetween, ['min' => '2', 'max' => '5', 'mode' => SizeMode::String]);
        $this->assertTrue($validator->publicExecuteInline($check, 'hi', 'field'));
        $this->assertTrue($validator->publicExecuteInline($check, 'hello', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'h', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'helloo', 'field'));
    }

    public function testSizeBetweenWithDecimalThresholdUsesNativeComparison()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeBetween, ['min' => '1', 'max' => '3.5', 'mode' => SizeMode::String]);
        $this->assertTrue($validator->publicExecuteInline($check, 'abc', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, 'abcd', 'field'));
    }

    public function testNumericModeSizeStillUsesBigNumber()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeMax, ['n' => '100', 'mode' => SizeMode::Numeric]);
        $this->assertTrue($validator->publicExecuteInline($check, '50', 'field'));
        $this->assertFalse($validator->publicExecuteInline($check, '150', 'field'));
    }

    public function testSizeExactWithArrayMode()
    {
        $validator = $this->makeValidator();

        $check = new InlineCheck(CheckType::SizeExact, ['n' => '3', 'mode' => SizeMode::Array]);
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
    public function publicExecuteInline(InlineCheck $check, mixed $value, string $attribute): bool
    {
        return $this->executeInline($check, $value, $attribute);
    }
}
