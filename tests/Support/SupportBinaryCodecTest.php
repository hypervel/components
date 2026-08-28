<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\BinaryCodec;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

class SupportBinaryCodecTest extends TestCase
{
    private const string UTF8_SAFE_BINARY_UUID = '21107c1e-6448-43c2-b80b-40491d165946';

    public function testFormatsReturnsDefaultFormats(): void
    {
        $this->assertSame(['uuid', 'ulid'], BinaryCodec::formats());
    }

    public function testRegisterAddsCustomFormat(): void
    {
        BinaryCodec::register(
            'hex',
            static fn (Uuid|Ulid|string|null $value): ?string => null,
            static fn (?string $value): ?string => null,
        );

        $this->assertContains('hex', BinaryCodec::formats());
    }

    public function testFormatsReturnsAListAfterOverridingABuiltInFormat(): void
    {
        BinaryCodec::register(
            'uuid',
            static fn (Uuid|Ulid|string|null $value): ?string => null,
            static fn (?string $value): ?string => null,
        );
        BinaryCodec::register(
            'hex',
            static fn (Uuid|Ulid|string|null $value): ?string => null,
            static fn (?string $value): ?string => null,
        );

        $formats = BinaryCodec::formats();

        $this->assertSame(['uuid', 'ulid', 'hex'], $formats);
        $this->assertTrue(array_is_list($formats));
    }

    public function testRegisterOverridesDefaultFormat(): void
    {
        BinaryCodec::register(
            'uuid',
            static fn (Uuid|Ulid|string|null $value): ?string => 'custom-encode',
            static fn (?string $value): ?string => 'custom-decode',
        );

        $this->assertSame('custom-encode', BinaryCodec::encode('test', 'uuid'));
        $this->assertSame('custom-decode', BinaryCodec::decode('test', 'uuid'));
    }

    #[DataProvider('nullAndBlankProvider')]
    public function testEncodeReturnsNullForNullAndBlank(mixed $value): void
    {
        $this->assertNull(BinaryCodec::encode($value, 'uuid'));
        $this->assertNull(BinaryCodec::encode($value, 'ulid'));
    }

    #[DataProvider('nullAndBlankProvider')]
    public function testDecodeReturnsNullForNullAndBlank(mixed $value): void
    {
        $this->assertNull(BinaryCodec::decode($value, 'uuid'));
        $this->assertNull(BinaryCodec::decode($value, 'ulid'));
    }

    public static function nullAndBlankProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => [" \t\n"],
        ];
    }

    public function testBlankValuesDoNotReachCustomOrInvalidFormats(): void
    {
        $blankBinary = str_repeat("\0", 16);

        BinaryCodec::register(
            'custom',
            static fn (Uuid|Ulid|string|null $value): ?string => 'custom-encode',
            static fn (?string $value): ?string => 'custom-decode',
        );
        BinaryCodec::register(
            'uuid',
            static fn (Uuid|Ulid|string|null $value): ?string => 'custom-encode',
            static fn (?string $value): ?string => 'custom-decode',
        );

        $this->assertNull(BinaryCodec::encode($blankBinary, 'custom'));
        $this->assertNull(BinaryCodec::decode($blankBinary, 'custom'));
        $this->assertNull(BinaryCodec::encode($blankBinary, 'uuid'));
        $this->assertNull(BinaryCodec::decode($blankBinary, 'uuid'));
        $this->assertNull(BinaryCodec::encode($blankBinary, 'invalid'));
        $this->assertNull(BinaryCodec::decode($blankBinary, 'invalid'));
    }

    public function testEncodeThrowsOnInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Format [invalid] is invalid.');

        BinaryCodec::encode('value', 'invalid');
    }

    public function testDecodeThrowsOnInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Format [invalid] is invalid.');

        BinaryCodec::decode('value', 'invalid');
    }

    public function testUuidEncodeFromString(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertSame(Uuid::fromString($uuid)->toBinary(), BinaryCodec::encode($uuid, 'uuid'));
    }

    public function testUuidEncodeFromBinary(): void
    {
        $bytes = Uuid::fromString(self::UTF8_SAFE_BINARY_UUID)->toBinary();

        $this->assertSame($bytes, BinaryCodec::encode($bytes, 'uuid'));
    }

    public function testUuidEncodeFromInstance(): void
    {
        $uuid = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame($uuid->toBinary(), BinaryCodec::encode($uuid, 'uuid'));
    }

    public function testUuidDecodeFromBinary(): void
    {
        $uuid = self::UTF8_SAFE_BINARY_UUID;
        $bytes = Uuid::fromString($uuid)->toBinary();

        $this->assertSame($uuid, BinaryCodec::decode($bytes, 'uuid'));
    }

    public function testUuidDecodeFromString(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertSame($uuid, BinaryCodec::decode($uuid, 'uuid'));
    }

    public function testUlidEncodeFromString(): void
    {
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $this->assertSame(Ulid::fromString($ulid)->toBinary(), BinaryCodec::encode($ulid, 'ulid'));
    }

    public function testUlidEncodeFromBinary(): void
    {
        $bytes = Ulid::fromString('01ARZ3NDEKTSV4RRFFQ69G5FAV')->toBinary();

        $this->assertSame($bytes, BinaryCodec::encode($bytes, 'ulid'));
    }

    public function testUlidEncodeFromInstance(): void
    {
        $ulid = Ulid::fromString('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        $this->assertSame($ulid->toBinary(), BinaryCodec::encode($ulid, 'ulid'));
    }

    public function testUlidDecodeFromBinary(): void
    {
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $bytes = Ulid::fromString($ulid)->toBinary();

        $this->assertSame($ulid, BinaryCodec::decode($bytes, 'ulid'));
    }

    public function testUlidDecodeFromString(): void
    {
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $this->assertSame($ulid, BinaryCodec::decode($ulid, 'ulid'));
    }

    #[DataProvider('blankBuiltInBinaryProvider')]
    public function testBlankBuiltInBinaryValuesRoundTrip(string $format, string $binary): void
    {
        $text = match ($format) {
            'uuid' => Uuid::fromBinary($binary)->toString(),
            'ulid' => Ulid::fromBinary($binary)->toString(),
        };

        $this->assertSame($binary, BinaryCodec::encode($binary, $format));
        $this->assertSame($text, BinaryCodec::decode($binary, $format));
        $this->assertSame($binary, BinaryCodec::encode($text, $format));
    }

    public static function blankBuiltInBinaryProvider(): array
    {
        return [
            'nil uuid' => ['uuid', str_repeat("\0", 16)],
            'space uuid' => ['uuid', str_repeat(' ', 16)],
            'nil ulid' => ['ulid', str_repeat("\0", 16)],
            'space ulid' => ['ulid', str_repeat(' ', 16)],
        ];
    }

    public function testIsBinary(): void
    {
        // Non-string values
        $this->assertFalse(BinaryCodec::isBinary(null));
        $this->assertFalse(BinaryCodec::isBinary(123));
        $this->assertFalse(BinaryCodec::isBinary([]));

        // Empty string
        $this->assertFalse(BinaryCodec::isBinary(''));

        // Valid UTF-8 strings
        $this->assertFalse(BinaryCodec::isBinary('hello'));
        $this->assertFalse(BinaryCodec::isBinary('héllo'));
        $this->assertFalse(BinaryCodec::isBinary('日本語'));

        // Binary data with null byte
        $this->assertTrue(BinaryCodec::isBinary("hello\0world"));
        $this->assertTrue(BinaryCodec::isBinary("\0"));

        // Invalid UTF-8 sequences
        $this->assertTrue(BinaryCodec::isBinary("\xFF\xFE"));

        // Binary identifier bytes can still look like text
        $this->assertFalse(BinaryCodec::isBinary(Uuid::fromString(self::UTF8_SAFE_BINARY_UUID)->toBinary()));
    }
}
