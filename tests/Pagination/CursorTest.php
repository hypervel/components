<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\Cursor;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use UnexpectedValueException;

class CursorTest extends TestCase
{
    public function testCanEncodeAndDecodeSuccessfully()
    {
        $cursor = new Cursor([
            'id' => 422,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], true);

        $this->assertEquals($cursor, Cursor::fromEncoded($cursor->encode()));
    }

    public function testCanGetParams()
    {
        $cursor = new Cursor([
            'id' => 422,
            'created_at' => ($now = Carbon::now()->toDateTimeString()),
        ], true);

        $this->assertEquals([$now, 422], $cursor->parameters(['created_at', 'id']));
    }

    public function testCanGetParam()
    {
        $cursor = new Cursor([
            'id' => 422,
            'created_at' => ($now = Carbon::now()->toDateTimeString()),
        ], true);

        $this->assertEquals($now, $cursor->parameter('created_at'));
    }

    public function testPointsToNextItems()
    {
        $cursor = new Cursor(['id' => 1], true);

        $this->assertTrue($cursor->pointsToNextItems());
        $this->assertFalse($cursor->pointsToPreviousItems());
    }

    public function testPointsToPreviousItems()
    {
        $cursor = new Cursor(['id' => 1], false);

        $this->assertFalse($cursor->pointsToNextItems());
        $this->assertTrue($cursor->pointsToPreviousItems());
    }

    public function testToArray()
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

    public function testFromEncodedReturnsNullForNull()
    {
        $this->assertNull(Cursor::fromEncoded(null));
    }

    public function testFromEncodedReturnsNullForInvalidString()
    {
        $this->assertNull(Cursor::fromEncoded('not-valid-json!@#'));
    }

    public function testParameterThrowsForMissingKey()
    {
        $cursor = new Cursor(['id' => 1], true);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unable to find parameter [missing] in pagination item.');

        $cursor->parameter('missing');
    }
}
