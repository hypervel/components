<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Transport;

use Hypervel\Mcp\Transport\HeaderValue;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HeaderValueTest extends TestCase
{
    public function testLeavesAHeaderSafeValueUntouched(): void
    {
        $this->assertSame('us-west1', (string) new HeaderValue('us-west1'));
    }

    #[DataProvider('encodedValues')]
    public function testEncodesAValueThatCannotBeCarriedAsPlainAscii(string $value, string $encoded): void
    {
        $this->assertSame($encoded, (string) new HeaderValue($value));
    }

    /**
     * Provide values requiring an encoded header.
     */
    public static function encodedValues(): array
    {
        return [
            ['Hello, 世界', '=?base64?SGVsbG8sIOS4lueVjA==?='],
            [' padded ', '=?base64?IHBhZGRlZCA=?='],
            ["line1\nline2", '=?base64?bGluZTEKbGluZTI=?='],
            ['=?base64?literal?=', '=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?='],
        ];
    }

    public function testDecodesASentinelWrappedHeaderBackToItsSourceValue(): void
    {
        $this->assertSame('Hello, 世界', HeaderValue::fromHeader('=?base64?SGVsbG8sIOS4lueVjA==?=')->value);
    }

    public function testLeavesAHeaderWithoutTheSentinelAlone(): void
    {
        $this->assertSame('tools/call', HeaderValue::fromHeader('tools/call')->value);
    }

    public function testKeepsASentinelWrappingUndecodableContentAsItArrived(): void
    {
        $this->assertSame('=?base64?not base64!?=', HeaderValue::fromHeader('=?base64?not base64!?=')->value);
    }

    #[DataProvider('roundTripValues')]
    public function testRoundTripsEveryValueItEncodes(string $value): void
    {
        $this->assertSame($value, HeaderValue::fromHeader((string) new HeaderValue($value))->value);
    }

    /**
     * Provide values for header round trips.
     */
    public static function roundTripValues(): array
    {
        return [
            ['us-west1'],
            ['Hello, 世界'],
            [' padded '],
            ["line1\nline2"],
            ['=?base64?literal?='],
            ['file:///projects/myapp/config.json'],
        ];
    }

    public function testMatchesTheBodyValueItWasDecodedFrom(): void
    {
        $this->assertTrue(HeaderValue::fromHeader('=?base64?SGVsbG8sIOS4lueVjA==?=')->matches('Hello, 世界'));
        $this->assertFalse(HeaderValue::fromHeader('get_weather')->matches('some-other-tool'));
    }
}
