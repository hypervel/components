<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class DeadlineTest extends TestCase
{
    public function testRepresentsAnAbsentDeadline(): void
    {
        $deadline = Deadline::fromTimeout(null);

        $this->assertNull($deadline->absoluteNanoseconds());
        $this->assertNull($deadline->remainingSeconds());
        $this->assertNull($deadline->encodedHeader());
        $this->assertFalse($deadline->expired());
    }

    public function testCreatesALocalAbsoluteMonotonicDeadline(): void
    {
        $before = hrtime(true);
        $deadline = Deadline::fromTimeout(0.25);
        $after = hrtime(true);

        $this->assertNotNull($deadline->absoluteNanoseconds());
        $this->assertGreaterThanOrEqual($before + 250_000_000, $deadline->absoluteNanoseconds());
        $this->assertLessThanOrEqual($after + 250_000_000, $deadline->absoluteNanoseconds());
        $this->assertFalse($deadline->expired());
    }

    public function testRejectsInvalidAndOverflowingLocalTimeouts(): void
    {
        foreach ([0.0, -0.1, INF, NAN, (float) PHP_INT_MAX] as $seconds) {
            try {
                Deadline::fromTimeout($seconds);
                $this->fail('Expected the local deadline to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSaturatesHugeValidPeerTimeouts(): void
    {
        $deadline = Deadline::fromPeerTimeout(99_999_999 * 3600.0);

        $this->assertSame(PHP_INT_MAX, $deadline->absoluteNanoseconds());
        $this->assertFalse($deadline->expired());
        $this->assertGreaterThan(0, $deadline->remainingSeconds());
    }

    public function testTreatsAZeroPeerTimeoutAsImmediatelyExpired(): void
    {
        $deadline = Deadline::fromPeerTimeout(0);

        $this->assertTrue($deadline->expired());
        $this->assertSame(0.0, $deadline->remainingSeconds());
        $this->assertNull($deadline->encodedHeader());
    }

    public function testRejectsInvalidPeerTimeouts(): void
    {
        foreach ([-0.1, INF, NAN] as $seconds) {
            try {
                Deadline::fromPeerTimeout($seconds);
                $this->fail('Expected the peer deadline to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUsesAControlledMonotonicClockAndRoundsTheHeaderUp(): void
    {
        $now = 1_000_000_000;
        $deadline = Deadline::usingClock(
            3_000_000_001,
            static function () use (&$now): int {
                return $now;
            },
        );

        $this->assertSame(3_000_000_001, $deadline->absoluteNanoseconds());
        $this->assertSame(2.000000001, $deadline->remainingSeconds());
        $this->assertSame('2000001u', $deadline->encodedHeader());
        $this->assertFalse($deadline->expired());

        $now = 3_000_000_000;

        $this->assertSame(0.000000001, $deadline->remainingSeconds());
        $this->assertSame('1n', $deadline->encodedHeader());
        $this->assertFalse($deadline->expired());

        $now = 3_000_000_001;

        $this->assertSame(0.0, $deadline->remainingSeconds());
        $this->assertNull($deadline->encodedHeader());
        $this->assertTrue($deadline->expired());
    }

    public function testRejectsANegativeAbsoluteDeadline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The absolute deadline cannot be negative.');

        Deadline::usingClock(-1, static fn (): int => 0);
    }
}
