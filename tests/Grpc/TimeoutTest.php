<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Protocol\Timeout;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class TimeoutTest extends TestCase
{
    public function testEncodesEveryWireUnitAndMovesAtEightDigitBoundaries(): void
    {
        $this->assertSame('12345678n', Timeout::encode(0.012345678));
        $this->assertSame('123457u', Timeout::encode(0.123456789));
        $this->assertSame('12345678u', Timeout::encode(12.345678));
        $this->assertSame('123457m', Timeout::encode(123.456789));
        $this->assertSame('12345678m', Timeout::encode(12_345.678));
        $this->assertSame('123457S', Timeout::encode(123_456.789));
        $this->assertSame('12345678S', Timeout::encode(12_345_678));
        $this->assertSame('2057614M', Timeout::encode(123_456_789));
        $this->assertSame('12345678M', Timeout::encode(12_345_678 * 60));
        $this->assertSame('2057614H', Timeout::encode(123_456_789 * 60));
    }

    public function testEncodingRoundsUpSoTheWireTimeoutIsNeverShorter(): void
    {
        $this->assertSame('1n', Timeout::encode(0.0000000001));
        $this->assertSame('1000001n', Timeout::encode(0.0010000001));
        $this->assertGreaterThanOrEqual(0.0010000001, Timeout::decode('1000001n'));
    }

    public function testEncodesZeroAsAnAlreadyExpiredTimeout(): void
    {
        $this->assertSame('0n', Timeout::encode(0));
        $this->assertSame(0.0, Timeout::decode('0n'));
    }

    public function testDecodesEveryWireUnitAndLeadingZeros(): void
    {
        $this->assertSame(36_000.0, Timeout::decode('10H'));
        $this->assertSame(600.0, Timeout::decode('10M'));
        $this->assertSame(10.0, Timeout::decode('10S'));
        $this->assertEqualsWithDelta(0.01, Timeout::decode('10m'), 1e-15);
        $this->assertEqualsWithDelta(0.00001, Timeout::decode('10u'), 1e-15);
        $this->assertEqualsWithDelta(0.00000001, Timeout::decode('00000010n'), 1e-15);
    }

    public function testDecodesTheLargestValidWireTimeout(): void
    {
        $this->assertSame(359_999_996_400.0, Timeout::decode('99999999H'));
    }

    public function testRejectsMalformedWireTimeouts(): void
    {
        foreach (['', '1', 'n', '-1S', '1.5S', '9a1S', '123456789S', '1s', '1d', ' 1S', "1S\n"] as $header) {
            try {
                Timeout::decode($header);
                $this->fail("Expected gRPC timeout [{$header}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The gRPC timeout header is malformed.', $exception->getMessage());
            }
        }
    }

    public function testRejectsInvalidOrUnrepresentableDurations(): void
    {
        foreach ([-0.1, INF, NAN, 100_000_000 * 3600.0] as $seconds) {
            try {
                Timeout::encode($seconds);
                $this->fail('Expected the gRPC timeout to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
