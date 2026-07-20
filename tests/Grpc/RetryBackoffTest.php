<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Client\RetryBackoff;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class RetryBackoffTest extends TestCase
{
    public function testUsesASeededCappedExponentialSequenceWithTwentyPercentJitter(): void
    {
        $backoff = new RetryBackoff(
            new RetryPolicy(
                maxAttempts: 5,
                initialBackoff: 1,
                maxBackoff: 2,
                backoffMultiplier: 2,
            ),
            new Randomizer(new Mt19937(1234)),
        );

        $first = $backoff->nextDelay();
        $second = $backoff->nextDelay();
        $third = $backoff->nextDelay();

        $this->assertEqualsWithDelta(1.1696228108216047, $first, 1e-15);
        $this->assertEqualsWithDelta(1.867475848942668, $second, 1e-15);
        $this->assertEqualsWithDelta(2.3793677564080786, $third, 1e-15);
        $this->assertGreaterThanOrEqual(0.8, $first);
        $this->assertLessThanOrEqual(1.2, $first);
        $this->assertGreaterThanOrEqual(1.6, $second);
        $this->assertLessThanOrEqual(2.4, $second);
        $this->assertGreaterThanOrEqual(1.6, $third);
        $this->assertLessThanOrEqual(2.4, $third);
    }

    public function testCapsTheActualSleepAtTheRemainingDeadline(): void
    {
        $backoff = new RetryBackoff(
            new RetryPolicy(maxAttempts: 2),
            new Randomizer(new Mt19937(1234)),
        );

        $this->assertSame(0.05, $backoff->nextDelay(0.05));
        $this->assertSame(0.0, $backoff->nextDelay(0));
    }

    public function testRejectsAnInvalidRemainingDeadline(): void
    {
        foreach ([-0.1, INF, NAN] as $remainingSeconds) {
            try {
                (new RetryBackoff(new RetryPolicy(maxAttempts: 2)))
                    ->nextDelay($remainingSeconds);
                $this->fail('Expected the remaining deadline to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidPushbackOverridesTheDelayAndResetsTheSequence(): void
    {
        $backoff = new RetryBackoff(
            new RetryPolicy(
                maxAttempts: 5,
                initialBackoff: 1,
                maxBackoff: 2,
                backoffMultiplier: 2,
            ),
            new Randomizer(new Mt19937(1234)),
        );

        $backoff->nextDelay();
        $backoff->nextDelay();

        $this->assertSame(0.25, $backoff->pushbackDelay('250'));
        $this->assertEqualsWithDelta(1.1896838782040393, $backoff->nextDelay(), 1e-15);
        $this->assertSame(0.0, $backoff->pushbackDelay('000'));
        $this->assertEqualsWithDelta(0.9097715517432028, $backoff->nextDelay(), 1e-15);
        $this->assertSame(PHP_INT_MAX / 1000, $backoff->pushbackDelay((string) PHP_INT_MAX));
    }

    public function testNegativeMalformedRepeatedCombinedAndOverflowingPushbackStopsRetry(): void
    {
        foreach ([
            '-1',
            '1.5',
            'invalid',
            ['100'],
            '100,200',
            (string) PHP_INT_MAX . '0',
        ] as $pushback) {
            $backoff = new RetryBackoff(new RetryPolicy(maxAttempts: 2));

            $this->assertNull($backoff->pushbackDelay($pushback));
        }
    }
}
