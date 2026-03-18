<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Recaller;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RecallerTest extends TestCase
{
    public function testIdReturnsFirstSegment()
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame('123', $recaller->id());
    }

    public function testTokenReturnsSecondSegment()
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame('token', $recaller->token());
    }

    public function testHashReturnsThirdSegment()
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame('hash', $recaller->hash());
    }

    public function testHashDoesNotIncludeFourthSegment()
    {
        $recaller = new Recaller('123|token|hash|extra');

        $this->assertSame('hash', $recaller->hash());
    }

    public function testSegmentsReturnsAllParts()
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame(['123', 'token', 'hash'], $recaller->segments());
    }

    public function testValidReturnsTrueForProperRecaller()
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertTrue($recaller->valid());
    }

    public function testValidReturnsFalseWhenNoPipes()
    {
        $recaller = new Recaller('invalid');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenOnlyTwoSegments()
    {
        $recaller = new Recaller('123|token');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenIdIsEmpty()
    {
        $recaller = new Recaller('|token|hash');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenTokenIsEmpty()
    {
        $recaller = new Recaller('123||hash');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenIdIsWhitespace()
    {
        $recaller = new Recaller(' |token|hash');

        $this->assertFalse($recaller->valid());
    }

    public function testRawStringFallsBackWhenUnserializeFails()
    {
        // The constructor attempts unserialize — a non-serialized string
        // fails unserialize and falls back to the raw string.
        $raw = '123|token|hash';
        $recaller = new Recaller($raw);

        $this->assertSame('123', $recaller->id());
        $this->assertSame('token', $recaller->token());
    }

    public function testSerializedStringIsUnserializedInConstructor()
    {
        // The constructor successfully unserializes a serialized string.
        $raw = '123|token|hash';
        $recaller = new Recaller(serialize($raw));

        $this->assertSame('123', $recaller->id());
        $this->assertSame('token', $recaller->token());
    }
}
