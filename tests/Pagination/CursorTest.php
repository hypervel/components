<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\Cursor;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

class CursorTest extends TestCase
{
    public function testCanEncodeAndDecodeSuccessfully(): void
    {
        $cursor = new Cursor([
            'id' => 422,
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
        ], true);

        $this->assertEquals($cursor, Cursor::fromEncoded($cursor->encode()));
    }

    public function testCanGetParams(): void
    {
        $cursor = new Cursor([
            'id' => 422,
            'created_at' => ($now = CarbonImmutable::now()->toDateTimeString()),
        ], true);

        $this->assertEquals([$now, 422], $cursor->parameters(['created_at', 'id']));
    }

    public function testCanGetMixedParams(): void
    {
        $cursor = new Cursor([
            'active' => true,
            'score' => 4.25,
        ]);

        $this->assertSame([true, 4.25], $cursor->parameters(['active', 'score']));

        $decodedCursor = Cursor::fromEncoded($cursor->encode());

        $this->assertNotNull($decodedCursor);
        $this->assertSame([true, 4.25], $decodedCursor->parameters(['active', 'score']));
    }

    public function testCanGetBackedEnumParam(): void
    {
        $cursor = new Cursor(['status' => CursorTestStatus::Active]);

        $this->assertSame(CursorTestStatus::Active, $cursor->parameter('status'));
    }

    public function testCanGetParam(): void
    {
        $cursor = new Cursor([
            'id' => 422,
            'created_at' => ($now = CarbonImmutable::now()->toDateTimeString()),
        ], true);

        $this->assertEquals($now, $cursor->parameter('created_at'));
    }

    public function testPointsToNextItems(): void
    {
        $cursor = new Cursor(['id' => 1], true);

        $this->assertTrue($cursor->pointsToNextItems());
        $this->assertFalse($cursor->pointsToPreviousItems());
    }

    public function testPointsToPreviousItems(): void
    {
        $cursor = new Cursor(['id' => 1], false);

        $this->assertFalse($cursor->pointsToNextItems());
        $this->assertTrue($cursor->pointsToPreviousItems());
    }

    public function testToArray(): void
    {
        $cursor = new Cursor(['id' => 422, 'name' => 'test'], true);

        $this->assertSame([
            'id' => 422,
            'name' => 'test',
            '_pointsToNextItems' => true,
        ], $cursor->toArray());

        $cursor = new Cursor(['id' => 10], false);

        $this->assertSame([
            'id' => 10,
            '_pointsToNextItems' => false,
        ], $cursor->toArray());
    }

    public function testFromEncodedReturnsNullForNonStringInput(): void
    {
        $this->assertNull(Cursor::fromEncoded(null));
        $this->assertNull(Cursor::fromEncoded(123));
        $this->assertNull(Cursor::fromEncoded(['cursor']));
    }

    public function testFromEncodedReturnsNullForInvalidJson(): void
    {
        $this->assertNull(Cursor::fromEncoded(base64_encode('not-json')));
    }

    public function testFromEncodedReturnsNullForInvalidString(): void
    {
        $this->assertNull(Cursor::fromEncoded('not-valid-json!@#'));
    }

    public function testFromEncodedReturnsNullWhenDecodedPayloadIsNotAnArray(): void
    {
        $this->assertNull(Cursor::fromEncoded(base64_encode(json_encode('scalar', JSON_THROW_ON_ERROR))));
        $this->assertNull(Cursor::fromEncoded(base64_encode(json_encode(null, JSON_THROW_ON_ERROR))));
    }

    public function testFromEncodedReturnsNullWhenPointsToNextItemsKeyIsMissing(): void
    {
        $payload = base64_encode(json_encode(['id' => 422], JSON_THROW_ON_ERROR));

        $this->assertNull(Cursor::fromEncoded($payload));
    }

    public function testFromEncodedReturnsNullWhenPointsToNextItemsIsNotBoolean(): void
    {
        foreach ([null, 0, 1, '0', '1', []] as $direction) {
            $payload = base64_encode(json_encode([
                'id' => 422,
                '_pointsToNextItems' => $direction,
            ], JSON_THROW_ON_ERROR));

            $this->assertNull(Cursor::fromEncoded($payload));
        }
    }

    public function testFromEncodedReturnsNullForStructuredParameters(): void
    {
        foreach ([[5, 9], [['value' => 3]], []] as $parameter) {
            $payload = base64_encode(json_encode([
                'id' => $parameter,
                '_pointsToNextItems' => true,
            ], JSON_THROW_ON_ERROR));

            $this->assertNull(Cursor::fromEncoded($payload));
        }
    }

    public function testConstructorRejectsArrayParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cursor parameter [id] must not be an array.');

        new Cursor(['id' => [5, 9]]);
    }

    public function testEncodeThrowsForInvalidUtf8(): void
    {
        $this->expectException(JsonException::class);

        (new Cursor(['value' => "\xB1\x31"]))->encode();
    }

    public function testParameterThrowsForMissingKey(): void
    {
        $cursor = new Cursor(['id' => 1], true);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unable to find parameter [missing] in pagination item.');

        $cursor->parameter('missing');
    }
}

enum CursorTestStatus: string
{
    case Active = 'active';
}
