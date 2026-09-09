<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Recaller;
use Hypervel\Tests\TestCase;

class RecallerTest extends TestCase
{
    public function testIdReturnsFirstSegment(): void
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame('123', $recaller->id());
    }

    public function testTokenReturnsSecondSegment(): void
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame('token', $recaller->token());
    }

    public function testHashReturnsThirdSegment(): void
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertSame('hash', $recaller->hash());
    }

    public function testHashDoesNotIncludeFourthSegment(): void
    {
        $recaller = new Recaller('123|token|hash|extra');

        $this->assertSame('hash', $recaller->hash());
    }

    public function testSegmentsReturnsAllParts(): void
    {
        $recaller = new Recaller('123|token|hash|extra');

        $this->assertSame(['123', 'token', 'hash', 'extra'], $recaller->segments());
        $this->assertTrue($recaller->valid());
    }

    public function testValidReturnsTrueForProperRecaller(): void
    {
        $recaller = new Recaller('123|token|hash');

        $this->assertTrue($recaller->valid());
    }

    public function testValidReturnsFalseWhenNoPipes(): void
    {
        $recaller = new Recaller('invalid');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenOnlyTwoSegments(): void
    {
        $recaller = new Recaller('123|token');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenIdIsEmpty(): void
    {
        $recaller = new Recaller('|token|hash');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenTokenIsEmpty(): void
    {
        $recaller = new Recaller('123||hash');

        $this->assertFalse($recaller->valid());
    }

    public function testValidReturnsFalseWhenIdIsWhitespace(): void
    {
        $recaller = new Recaller(' |token|hash');

        $this->assertFalse($recaller->valid());
    }

    public function testPlainCookieStringPreservesIdentifierAndToken(): void
    {
        $raw = '123|token|hash';
        $recaller = new Recaller($raw);

        $this->assertSame('123', $recaller->id());
        $this->assertSame('token', $recaller->token());
    }
}
